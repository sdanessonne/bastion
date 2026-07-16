#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Mise à jour de Bastion depuis son dépôt Git (GitHub ou autre).
#
# ── CE QUE FAIT « apply », ET CE QU'IL NE FAIT PAS ──────────────────────────
# Il synchronise le CODE : console d'administration, portail, scripts, unités
# systemd. Il NE rejoue PAS deploy.sh, qui réécrit les configurations de service
# et redémarre tout : sur une passerelle en production, ce serait une coupure et un
# risque disproportionnés pour une simple mise à jour applicative. Un changement
# d'infrastructure reste une opération délibérée, en SSH.
#
# ── SÉCURITÉ ────────────────────────────────────────────────────────────────
# Aucun argument libre n'est accepté : l'URL et la branche viennent d'un fichier de
# configuration écrit par la console, jamais de la ligne de commande. La liste
# blanche sudo énumère les verbes un par un.
# Le jeton d'accès (dépôt privé) est stocké hors dépôt, en 600, et n'est JAMAIS
# écrit dans l'URL du « remote » — sinon il apparaîtrait dans .git/config, dans les
# journaux et dans la sortie de « git remote -v ».
#
# Usage : selfupdate.sh state | check | apply | log
set -uo pipefail

CONF=/etc/proxyfibre/update.env
REPO_DIR=/home/proxyfibre/proxyFibre
UNIT=proxyfibre-selfupdate
STATE_DIR=/var/lib/proxyfibre
LAST="$STATE_DIR/git-last-apply"
mkdir -p "$STATE_DIR" 2>/dev/null || true

GIT_REPO=""; GIT_BRANCH="main"; GIT_TOKEN=""
[ -r "$CONF" ] && . "$CONF"

json_escape() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/ /g'; }

# Le jeton est passé par un « credential helper » éphémère plutôt que collé dans
# l'URL : il ne touche ni .git/config, ni les journaux, ni « git remote -v ».
git_env() {
    export GIT_TERMINAL_PROMPT=0            # sans cela, git ATTEND un mot de passe et se fige
    export GIT_ASKPASS=/bin/true

    # « propriétaire douteux » : git REFUSE d'opérer en root sur un dépôt appartenant à
    # un autre utilisateur (ici proxyfibre) — une protection contre le détournement de
    # dépôt. Le piège : dans une unité systemd, HOME est VIDE, donc git ne lit aucune
    # configuration globale et l'exception ne peut pas y être écrite. On la passe donc
    # par l'environnement, seul canal qui fonctionne ici.
    # C'est sans danger : le chemin est fixe et appartient au compte de service, il n'est
    # pas fourni par l'utilisateur.
    local n=0
    _cfg() { eval "export GIT_CONFIG_KEY_${n}=\"\$1\"; export GIT_CONFIG_VALUE_${n}=\"\$2\""; n=$((n+1)); }
    _cfg safe.directory "$REPO_DIR"

    if [ -n "$GIT_TOKEN" ]; then
        # Le jeton transite par un « credential helper » éphémère : il ne touche ni
        # .git/config, ni les journaux, ni la sortie de « git remote -v ».
        _cfg credential.helper "!f(){ echo username=x-access-token; echo password=${GIT_TOKEN}; }; f"
    fi
    export GIT_CONFIG_COUNT=$n
}

occupe() { systemctl is-active --quiet "$UNIT" 2>/dev/null; }

case "${1:-}" in
    state)
        # Indispensable ici AUSSI : « state » interroge le dépôt, et sans l'exception
        # « safe.directory » git refuserait en root — les versions locale et distante
        # revenaient vides, sans le moindre message.
        git_env
        actif=false; occupe && actif=true
        pret=false; [ -n "$GIT_REPO" ] && pret=true
        clone=false; [ -d "$REPO_DIR/.git" ] && clone=true
        local_c=""; remote_c=""; retard=0; sujet=""; date_c=""
        if $clone; then
            local_c=$(git -C "$REPO_DIR" rev-parse --short HEAD 2>/dev/null)
            remote_c=$(git -C "$REPO_DIR" rev-parse --short "origin/${GIT_BRANCH}" 2>/dev/null)
            if [ -n "$local_c" ]; then
                sujet=$(git -C "$REPO_DIR" log -1 --pretty=%s 2>/dev/null)
                date_c=$(git -C "$REPO_DIR" log -1 --pretty=%cI 2>/dev/null)
                [ -n "$remote_c" ] && retard=$(git -C "$REPO_DIR" rev-list --count "HEAD..origin/${GIT_BRANCH}" 2>/dev/null)
            elif [ -n "$remote_c" ]; then
                # Dépôt tout juste rattaché : aucun commit local, donc pas de HEAD — et
                # « rev-list HEAD..origin » échouait sans rien dire, d'où un retard affiché
                # à 0 alors que RIEN n'était synchronisé. Ici, tout le dépôt est en attente.
                retard=$(git -C "$REPO_DIR" rev-list --count "origin/${GIT_BRANCH}" 2>/dev/null)
                sujet=$(git -C "$REPO_DIR" log -1 --pretty=%s "origin/${GIT_BRANCH}" 2>/dev/null)
            fi
        fi
        printf '{"pret":%s,"clone":%s,"en_cours":%s,"repo":"%s","branche":"%s","jeton":%s,"local":"%s","distant":"%s","retard":%s,"sujet":"%s","date":"%s","derniere_maj":%s}\n' \
            "$pret" "$clone" "$actif" \
            "$(json_escape "$GIT_REPO")" "$(json_escape "$GIT_BRANCH")" \
            "$([ -n "$GIT_TOKEN" ] && echo true || echo false)" \
            "$(json_escape "${local_c:-}")" "$(json_escape "${remote_c:-}")" "${retard:-0}" \
            "$(json_escape "${sujet:-}")" "$(json_escape "${date_c:-}")" \
            "$(cat "$LAST" 2>/dev/null || echo 0)"
        ;;

    check|apply)
        [ -n "$GIT_REPO" ] || { echo "ERREUR: aucun dépôt configuré" >&2; exit 1; }
        occupe && { echo "deja-en-cours"; exit 0; }
        systemctl reset-failed "$UNIT" 2>/dev/null || true
        systemd-run --unit="$UNIT" --collect --no-block \
            --description="Bastion - mise a jour depuis Git" \
            --property=RuntimeMaxSec=900 \
            /usr/local/sbin/proxyfibre-selfupdate "_$1" >/dev/null 2>&1 \
            && echo "lance" || { echo "ERREUR: lancement impossible" >&2; exit 1; }
        ;;

    # ── Exécutés DANS l'unité transitoire ; non autorisés par sudo ────────────
    _check)
        git_env
        if [ ! -d "$REPO_DIR/.git" ]; then
            # Premier rattachement : le dossier existe déjà et n'est pas vide, donc
            # « git clone » refuserait. On initialise sur place et on aligne le contenu
            # sur le dépôt, qui fait autorité.
            echo "Rattachement au dépôt ${GIT_REPO} (branche ${GIT_BRANCH})…"
            git -C "$REPO_DIR" init -q 2>/dev/null || { echo "ECHEC: git init"; exit 1; }
            git -C "$REPO_DIR" remote remove origin 2>/dev/null
            git -C "$REPO_DIR" remote add origin "$GIT_REPO" || { echo "ECHEC: remote"; exit 1; }
        fi
        echo "Interrogation du dépôt…"
        if ! sortie=$(git -C "$REPO_DIR" fetch --prune origin "$GIT_BRANCH" 2>&1); then
            printf '%s\n' "$sortie"
            case "$sortie" in
                *"could not read"*|*"Authentication"*|*"403"*)
                    echo "ECHEC: dépôt privé ou jeton invalide — vérifiez le jeton d'accès." ;;
                *"not found"*|*"404"*|*"Repository not found"*)
                    echo "ECHEC: dépôt introuvable — vérifiez l'URL." ;;
                *"Could not resolve"*|*"unable to access"*)
                    echo "ECHEC: dépôt injoignable — vérifiez l'accès Internet de la passerelle." ;;
                *) echo "ECHEC: interrogation du dépôt impossible." ;;
            esac
            exit 1
        fi
        printf '%s\n' "$sortie"
        echo "Dépôt interrogé. $(git -C "$REPO_DIR" rev-list --count "HEAD..origin/${GIT_BRANCH}" 2>/dev/null || echo '?') version(s) de retard."
        ;;

    _apply)
        git_env
        echo "=== Mise à jour de Bastion — $(date '+%d/%m/%Y %H:%M:%S') ==="
        [ -d "$REPO_DIR/.git" ] || { echo "ECHEC: dépôt non rattaché — lancez d'abord une recherche."; exit 1; }
        git -C "$REPO_DIR" fetch --prune origin "$GIT_BRANCH" 2>&1 || { echo "ECHEC: dépôt injoignable"; exit 1; }

        avant=$(git -C "$REPO_DIR" rev-parse --short HEAD 2>/dev/null || echo "-")
        echo "--- Récupération du code ---"
        # « reset --hard » et non « pull » : le dépôt fait autorité, et une modification
        # locale accidentelle sur la passerelle bloquerait un merge en plein milieu d'une
        # mise à jour. On aligne franchement.
        if ! git -C "$REPO_DIR" reset --hard "origin/${GIT_BRANCH}" 2>&1; then
            echo "ECHEC: alignement sur origin/${GIT_BRANCH} impossible"; exit 1
        fi
        apres=$(git -C "$REPO_DIR" rev-parse --short HEAD)
        echo "  ${avant} → ${apres}"
        [ "$avant" = "$apres" ] && echo "  (déjà à jour)"

        # ── GARDE-FOU AVANT TOUTE SYNCHRONISATION ───────────────────────────────
        # rsync --delete fait aveuglément confiance à la source : un dépôt incomplet,
        # une mauvaise branche ou une URL erronée VIDE l'installation.
        # VÉCU : une synchronisation depuis un dépôt de test a supprimé 12 pages de la
        # console (ad.php, apps.php, sauvegarde.php…) et l'intégralité de l'intranet.
        # L'administrateur a vu ses menus tomber en erreur et a cru avoir perdu ses droits.
        # On refuse donc de synchroniser depuis un dépôt qui ne ressemble pas à Bastion.
        echo "--- Vérification du dépôt ---"
        for f in admin/index.php admin/inc/layout.php admin/inc/config.php portal/fas.php; do
            [ -f "$REPO_DIR/$f" ] || {
                echo "ECHEC: ce dépôt ne ressemble pas à Bastion ($f manquant)."
                echo "       Synchronisation ANNULÉE — l'installation est intacte."
                echo "       Vérifiez l'adresse du dépôt et la branche."
                exit 1
            }
        done
        n=$(ls "$REPO_DIR"/admin/*.php 2>/dev/null | wc -l)
        if [ "${n:-0}" -lt 15 ]; then
            echo "ECHEC: seulement ${n} page(s) dans le dépôt, au moins 15 attendues."
            echo "       Synchronisation ANNULÉE — l'installation est intacte."
            exit 1
        fi

        # Le contrôle DÉCISIF : combien de fichiers la synchronisation SUPPRIMERAIT-elle ?
        # Compter les fichiers de la source ne suffit pas — dans l'incident vécu, le dépôt
        # en contenait 20, un nombre parfaitement crédible, et 12 pages ont pourtant été
        # effacées parce qu'elles n'y figuraient pas. Seule la simulation le révèle.
        # Une mise à jour normale ne supprime qu'une poignée de fichiers renommés ou
        # retirés ; une dizaine signale un dépôt qui ne correspond pas à l'installation.
        sup=0
        for d in "admin/:/var/www/admin/:inc/config.local.php" "portal/:/var/www/html/portal/:intranet/uploads"; do
            src="$REPO_DIR/${d%%:*}"; rest="${d#*:}"; dst="${rest%%:*}"; exc="${rest#*:}"
            k=$(rsync -a --delete --dry-run --itemize-changes --exclude="$exc" "$src" "$dst" 2>/dev/null \
                | grep -c '^\*deleting')
            sup=$((sup + k))
        done
        if [ "$sup" -gt 8 ]; then
            echo "ECHEC: cette synchronisation supprimerait ${sup} fichiers de l'installation."
            echo "       C'est anormal pour une mise à jour — le dépôt ne correspond pas."
            echo "       Synchronisation ANNULÉE — l'installation est intacte."
            echo "       Vérifiez l'adresse du dépôt et la branche."
            exit 1
        fi
        echo "  dépôt cohérent (${n} pages, ${sup} fichier(s) obsolète(s) à retirer)"

        echo "--- Déploiement du code ---"
        # Console et portail. Les uploads de l'intranet vivent dans l'arborescence web
        # et ne sont PAS dans le dépôt : --delete les effacerait.
        rsync -a --delete --exclude='inc/config.local.php' "$REPO_DIR/admin/"  /var/www/admin/  2>&1 | tail -2
        rsync -a --delete --exclude='intranet/uploads' "$REPO_DIR/portal/" /var/www/html/portal/ 2>&1 | tail -2
        find /var/www/admin /var/www/html/portal -type f -exec chmod 644 {} + 2>/dev/null
        find /var/www/admin /var/www/html/portal -type d -exec chmod 755 {} + 2>/dev/null
        chmod 640 /var/www/admin/watchdog.php /var/www/admin/logseal.php 2>/dev/null
        echo "  console + portail synchronisés"

        # Scripts privilégiés : le nom d'installation ne suit pas celui du fichier.
        for s in netguard qos apt selfupdate; do
            case "$s" in
                netguard) f=netguard.sh ;; qos) f=qos-ctl.sh ;;
                apt) f=apt-ctl.sh ;; selfupdate) f=selfupdate.sh ;;
            esac
            [ -f "$REPO_DIR/services/scripts/$f" ] && install -m755 "$REPO_DIR/services/scripts/$f" "/usr/local/sbin/proxyfibre-$s"
        done
        echo "  scripts installés"

        if ls "$REPO_DIR"/services/systemd/*.service >/dev/null 2>&1; then
            install -m644 "$REPO_DIR"/services/systemd/*.service "$REPO_DIR"/services/systemd/*.timer /etc/systemd/system/ 2>/dev/null
            systemctl daemon-reload
            echo "  unités systemd rechargées"
        fi

        # Apache garde le bytecode PHP en cache : sans rechargement, l'ancienne console
        # continuerait de s'exécuter. « reload » et non « restart » : pas de coupure.
        systemctl reload apache2 2>/dev/null && echo "  serveur web rechargé"

        date +%s > "$LAST"
        echo "--- Terminé ---"
        git -C "$REPO_DIR" log -1 --pretty='  version %h — %s (%cr)' 2>/dev/null
        ;;

    log) journalctl -u "$UNIT" --no-pager -n 300 -o cat 2>/dev/null ;;

    *) echo "Usage: $0 state|check|apply|log" >&2; exit 2 ;;
esac
