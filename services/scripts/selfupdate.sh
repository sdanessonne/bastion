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
# Progression pour la jauge de la console : un fichier « pct|libellé » que « _apply » et
# « _check » écrivent à chaque étape, et que « state » relit. La jauge suit donc le travail
# RÉELLEMENT accompli (récupération, vérification, déploiement…) — elle ne défile pas toute
# seule pour faire joli.
PROGRESS="$STATE_DIR/git-progress"
mkdir -p "$STATE_DIR" 2>/dev/null || true

GIT_REPO=""; GIT_BRANCH="main"; GIT_TOKEN=""
[ -r "$CONF" ] && . "$CONF"

json_escape() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/ /g'; }

# Marque une étape : écrit la progression pour la jauge ET l'affiche dans le journal (le
# « --- libellé --- » que l'administrateur voit défiler reste là, la jauge s'ajoute).
etape() {
    printf '%s|%s\n' "$1" "$2" > "$PROGRESS" 2>/dev/null || true
    echo "--- $2 ---"
}

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
        # Progression courante, pour la jauge. Fichier « pct|libellé » ; on borne le
        # pourcentage à un entier de 0 à 100 et on échappe le libellé comme le reste.
        prog_pct=0; prog_lib=""
        if [ -r "$PROGRESS" ]; then
            _l=$(cat "$PROGRESS" 2>/dev/null)
            prog_pct=${_l%%|*}; prog_lib=${_l#*|}
            case "$prog_pct" in ''|*[!0-9]*) prog_pct=0 ;; esac
            [ "$prog_pct" -gt 100 ] 2>/dev/null && prog_pct=100
        fi
        printf '{"pret":%s,"clone":%s,"en_cours":%s,"repo":"%s","branche":"%s","jeton":%s,"local":"%s","distant":"%s","retard":%s,"sujet":"%s","date":"%s","derniere_maj":%s,"progres":%s,"etape":"%s"}\n' \
            "$pret" "$clone" "$actif" \
            "$(json_escape "$GIT_REPO")" "$(json_escape "$GIT_BRANCH")" \
            "$([ -n "$GIT_TOKEN" ] && echo true || echo false)" \
            "$(json_escape "${local_c:-}")" "$(json_escape "${remote_c:-}")" "${retard:-0}" \
            "$(json_escape "${sujet:-}")" "$(json_escape "${date_c:-}")" \
            "$(cat "$LAST" 2>/dev/null || echo 0)" \
            "${prog_pct:-0}" "$(json_escape "${prog_lib:-}")"
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
        printf '40|Recherche de mises à jour\n' > "$PROGRESS" 2>/dev/null || true
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
        printf '5|Démarrage\n' > "$PROGRESS" 2>/dev/null || true
        echo "=== Mise à jour de Bastion — $(date '+%d/%m/%Y %H:%M:%S') ==="
        [ -d "$REPO_DIR/.git" ] || { echo "ECHEC: dépôt non rattaché — lancez d'abord une recherche."; exit 1; }
        git -C "$REPO_DIR" fetch --prune origin "$GIT_BRANCH" 2>&1 || { echo "ECHEC: dépôt injoignable"; exit 1; }

        avant=$(git -C "$REPO_DIR" rev-parse --short HEAD 2>/dev/null || echo "-")
        etape 15 "Récupération du code"
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
        etape 35 "Vérification du dépôt"
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

        etape 60 "Déploiement du code"
        # Console et portail. Les uploads de l'intranet vivent dans l'arborescence web
        # et ne sont PAS dans le dépôt : --delete les effacerait.
        rsync -a --delete --exclude='inc/config.local.php' "$REPO_DIR/admin/"  /var/www/admin/  2>&1 | tail -2
        rsync -a --delete --exclude='intranet/uploads' "$REPO_DIR/portal/" /var/www/html/portal/ 2>&1 | tail -2
        # Le menu PXE est SERVI depuis /boot/menu.php (chaîné par boot.ipxe), pas depuis /portal/ :
        # sans cette copie, une mise à jour du menu ne prendrait jamais effet côté amorçage réseau.
        if [ -d /var/www/html/boot ] && [ -f "$REPO_DIR/portal/menu.php" ]; then
            install -m644 "$REPO_DIR/portal/menu.php" /var/www/html/boot/menu.php 2>/dev/null && echo "  menu PXE (/boot) synchronisé"
        fi
        # Preseed d'installation Debian chiffrée (LUKS) — servi sur /boot (port 2080), aucun secret dedans.
        [ -d /var/www/html/boot ] && [ -f "$REPO_DIR/services/tftp/preseed-crypto.cfg" ] && \
            install -m644 "$REPO_DIR/services/tftp/preseed-crypto.cfg" /var/www/html/boot/preseed-crypto.cfg 2>/dev/null || true
        # Script de rattachement au domaine : le poste le télécharge à sa 1re ouverture de session
        # (le fichier de réponse ne contient qu'un amorçage, jamais d'identifiants).
        [ -d /var/www/html/boot ] && [ -f "$REPO_DIR/services/tftp/join-domain.ps1" ] && \
            install -m644 "$REPO_DIR/services/tftp/join-domain.ps1" /var/www/html/boot/join-domain.ps1 2>/dev/null || true
        # Vignette de compte Windows : le jeton d'inventaire y est substitue a la publication,
        # comme pour le collecteur (le script est servi en anonyme, il ne doit rien contenir
        # d'autre que ce jeton deja porte par les postes).
        if [ -d /var/www/html/boot ] && [ -f "$REPO_DIR/services/tftp/photo-tile.ps1" ]; then
            install -m644 "$REPO_DIR/services/tftp/photo-tile.ps1" /var/www/html/boot/photo-tile.ps1 2>/dev/null || true
            _tk=$(mysql -N -e "SELECT v FROM pf_settings WHERE k='inventory_token'" radius 2>/dev/null | head -1)
            [ -n "$_tk" ] && sed -i "s|__TOKEN__|$_tk|" /var/www/html/boot/photo-tile.ps1 2>/dev/null || true
        fi
        # Adapter le script au domaine REEL de cette installation (il est ecrit pour bastion.pn.int).
        if [ -f /var/www/html/boot/join-domain.ps1 ]; then
            _rj=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
            [ -n "$_rj" ] && sed -i "s/bastion\.pn\.int/$_rj/gI" /var/www/html/boot/join-domain.ps1 2>/dev/null || true
        fi
        # Fichiers de réponse Windows : déposés à l'installation PXE, mais ils évoluent avec le
        # produit — sans cette copie, une correction n'atteindrait jamais les postes déjà déployés.
        if [ -d /srv/pxe/images ]; then
            _rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
            for _u in uefi bios; do
                [ -f "$REPO_DIR/services/tftp/unattend-${_u}.xml" ] || continue
                install -m644 "$REPO_DIR/services/tftp/unattend-${_u}.xml" "/srv/pxe/images/unattend-${_u}.xml" 2>/dev/null || true
                [ -n "$_rl" ] && sed -i "s/bastion\.pn\.int/$_rl/gI" "/srv/pxe/images/unattend-${_u}.xml" 2>/dev/null || true
            done
        fi
        find /var/www/admin /var/www/html/portal -type f -exec chmod 644 {} + 2>/dev/null
        find /var/www/admin /var/www/html/portal -type d -exec chmod 755 {} + 2>/dev/null
        chmod 640 /var/www/admin/watchdog.php /var/www/admin/logseal.php 2>/dev/null
        echo "  console + portail synchronisés"

        etape 85 "Installation des scripts et rechargement"
        # Scripts privilégiés appelés par la console. On (ré)installe TOUT le jeu à chaque
        # mise à jour. Auparavant seuls quatre l'étaient : une correction d'un autre script
        # (proxyfibre-ad, -power, -syspasswd…) ne se déployait JAMAIS par Git et exigeait une
        # réinstallation manuelle en SSH — pire, des fonctions livrées par mise à jour (mot de
        # passe système, redémarrage du serveur) restaient muettes, leur binaire n'ayant
        # jamais été posé. Le nom d'installation ne suit pas celui du fichier ; le mode plus
        # strict (750) de syspasswd est respecté.
        # ATTENTION : ceci installe les BINAIRES seulement. Les entrées sudo, les cron et les
        # unités systemd d'une fonction NOUVELLE restent posés par deploy.sh (ou son installeur
        # dédié, ex. install-console.sh) — la mise à jour Git ne les rejoue délibérément pas.
        installes=0
        while read -r mode name src; do
            [ -n "${mode:-}" ] || continue
            [ -f "$REPO_DIR/$src" ] || continue
            install -m"$mode" "$REPO_DIR/$src" "/usr/local/sbin/proxyfibre-$name" && installes=$((installes+1))
        done <<'SCRIPTS'
755 netguard             services/scripts/netguard.sh
755 qos                  services/scripts/qos-ctl.sh
755 issue                services/scripts/issue-banner.sh
755 brand                services/scripts/boot-brand.sh
755 make-web-cert        services/scripts/make-web-cert.sh
755 apply-filter         services/scripts/apply-filter.sh
755 update-adblock       services/scripts/update-adblock.sh
755 service              services/scripts/service-ctl.sh
755 apt                  services/scripts/apt-ctl.sh
755 update-conf          services/scripts/update-conf.sh
750 syspasswd            services/scripts/syspasswd.sh
755 power                services/scripts/power-ctl.sh
755 speedtest            services/scripts/speedtest-wan.sh
755 clamav               services/scripts/clamav-ctl.sh
755 ad                   services/scripts/ad-ctl.sh
755 gpo-apply            services/scripts/gpo-apply.py
755 gpo-apps             services/scripts/gpo-apps.py
755 gpo-kms              services/scripts/gpo-kms.py
755 gpo-drives           services/scripts/gpo-drives.py
755 gpo-wmi              services/scripts/gpo-wmi.py
755 gpo-inventory        services/scripts/gpo-inventory.py
755 gpo-bitlocker        services/scripts/gpo-bitlocker.py
755 gpo-health           services/scripts/gpo-health.py
755 gpo-timesync         services/scripts/gpo-timesync.py
755 voucher-gc           services/scripts/voucher-gc.php
755 time                 services/scripts/time-ctl.sh
755 share-quota          services/scripts/share-quota.sh
755 share-dfree          services/scripts/share-dfree.sh
755 metrics-sample       services/scripts/metrics-sample.php
755 backup               services/scripts/backup-ctl.sh
755 habilitation         services/scripts/habilitation-ctl.sh
755 sign                 services/scripts/sign-ctl.sh
755 purge-logs           services/scripts/purge-logs.sh
755 walledgarden-refresh services/scripts/walledgarden-refresh.sh
755 weblog-ingest        services/scripts/weblog-ingest.sh
755 account-expiry       services/scripts/account-expiry.sh
755 dhcp                 services/scripts/dhcp-ctl.sh
755 image                services/scripts/image-ctl.sh
755 quarantine           services/scripts/quarantine-ctl.sh
755 selftest             services/scripts/selftest.sh
755 anomaly              services/scripts/anomaly-ctl.sh
SCRIPTS
        # MODULES Python importés par les générateurs de GPO : déployés sous leur VRAI nom
        # (et non « proxyfibre-… »), sinon « import psfile » ne les trouverait pas. Sans eux,
        # gpo-apps/kms/drives/timesync/inventory/bitlocker échouent dès l'import.
        for m in psfile.py check-scripts.py; do
            [ -f "$REPO_DIR/services/scripts/$m" ] && install -m755 "$REPO_DIR/services/scripts/$m" "/usr/local/sbin/$m"
        done
        # OUTILS POSTE : publiés sur le partage « Commun », d'où les administrateurs les
        # lancent. Sans cette étape ils n'étaient copiés QU'À LA MAIN : une correction
        # poussée sur Git ne parvenait jamais aux postes, et l'ancienne version continuait
        # de tourner (c'est ce qui est arrivé au correctif « w32tm /resync »).
        # testparm écrit une bannière sur sa sortie : ne garder que la DERNIÈRE ligne,
        # sinon la variable contient du texte parasite et le test de répertoire échoue
        # en silence — l'étape serait sautée sans que personne ne le voie.
        COMMUN=$(testparm -s --section-name=Commun --parameter-name=path 2>/dev/null | tr -d '\r' | grep '^/' | tail -1)
        [ -n "${COMMUN:-}" ] || COMMUN=/srv/partage/commun
        if [ -d "$COMMUN" ] && [ -d "$REPO_DIR/services/tools" ]; then
            n=0
            for t in "$REPO_DIR"/services/tools/*; do
                [ -f "$t" ] || continue
                install -m666 "$t" "$COMMUN/$(basename "$t")" && n=$((n+1))
            done
            echo "  $n outil(s) poste publié(s) sur $COMMUN"
        else
            echo "  ATTENTION : partage Commun introuvable ($COMMUN) — outils poste NON publiés"
        fi
        # custombinauth : appelé par OpenNDS à chaque (dé)connexion (quotas + journalisation),
        # hors /usr/local/sbin. Fait partie du code, doit suivre les mises à jour.
        [ -f "$REPO_DIR/services/opennds/custombinauth.sh" ] && install -m755 "$REPO_DIR/services/opennds/custombinauth.sh" /usr/lib/opennds/custombinauth.sh
        # selfupdate.sh lui-même, EN DERNIER : le processus courant tourne déjà en mémoire
        # (unité transitoire), réécrire le fichier ne le perturbe pas — la prochaine exécution
        # prendra la nouvelle version.
        [ -f "$REPO_DIR/services/scripts/selfupdate.sh" ] && install -m755 "$REPO_DIR/services/scripts/selfupdate.sh" /usr/local/sbin/proxyfibre-selfupdate
        echo "  ${installes} scripts installés"

        if ls "$REPO_DIR"/services/systemd/*.service >/dev/null 2>&1; then
            install -m644 "$REPO_DIR"/services/systemd/*.service "$REPO_DIR"/services/systemd/*.timer /etc/systemd/system/ 2>/dev/null
            systemctl daemon-reload
            # Activer les minuteries NOUVELLEMENT livrées : sans cela, un .timer arrivé par
            # mise à jour (ex. recherche quotidienne de mise à jour) resterait inerte jusqu'à
            # un deploy.sh complet, que la mise à jour depuis Git ne rejoue justement pas.
            for t in "$REPO_DIR"/services/systemd/*.timer; do
                [ -f "$t" ] && systemctl enable --now "$(basename "$t")" >/dev/null 2>&1 || true
            done
            echo "  unités systemd rechargées"
        fi

        # Apache garde le bytecode PHP en cache : sans rechargement, l'ancienne console
        # continuerait de s'exécuter. « reload » et non « restart » : pas de coupure.
        systemctl reload apache2 2>/dev/null && echo "  serveur web rechargé"

        # Contrôle anti-régression rapide (NON bloquant) : signale immédiatement une page
        # cassée ou un service arrêté par la mise à jour, plutôt que de le découvrir en prod.
        # Le rapport complet est conservé dans /var/log/proxyfibre-selftest.log.
        if [ -x /usr/local/sbin/proxyfibre-selftest ]; then
            if /usr/local/sbin/proxyfibre-selftest quick >/var/log/proxyfibre-selftest.log 2>&1; then
                echo "  auto-test : OK ($(sed -n 's/^Résumé : //p' /var/log/proxyfibre-selftest.log))"
            else
                echo "  auto-test : ÉCHEC — $(sed -n 's/^Résumé : //p' /var/log/proxyfibre-selftest.log) (voir /var/log/proxyfibre-selftest.log)"
            fi
        fi

        date +%s > "$LAST"
        etape 100 "Terminé"
        git -C "$REPO_DIR" log -1 --pretty='  version %h — %s (%cr)' 2>/dev/null
        ;;

    # Clé PUBLIQUE de la passerelle, à déposer sur GitHub en « deploy key ».
    # Engendrée au premier appel : une installation neuve n'en a pas, et l'administrateur
    # ne doit pas avoir à ouvrir une session SSH pour la récupérer.
    # La clé PRIVÉE ne sort jamais d'ici (600, root).
    pubkey)
        K=/root/.ssh/id_ed25519
        if [ ! -f "$K" ]; then
            mkdir -p /root/.ssh && chmod 700 /root/.ssh
            ssh-keygen -t ed25519 -f "$K" -N "" -q \
                -C "bastion-$(hostname 2>/dev/null || echo passerelle)-lecture-seule" || {
                    echo "ERREUR: impossible d'engendrer la clé" >&2; exit 1; }
            chmod 600 "$K"
        fi
        # Empreinte de l'hôte enregistrée d'avance : sans elle, ssh POSERAIT une question
        # à la première connexion et l'unité systemd se figerait, sans rien afficher.
        touch /root/.ssh/known_hosts; chmod 600 /root/.ssh/known_hosts
        for h in github.com gitlab.com; do
            grep -q "^${h} " /root/.ssh/known_hosts 2>/dev/null || \
                ssh-keyscan -t ed25519 -T 5 "$h" >> /root/.ssh/known_hosts 2>/dev/null
        done
        cat "${K}.pub"
        ;;

    # Éprouve l'accès au dépôt : la clé est-elle acceptée, et donne-t-elle ce dépôt ?
    testssh)
        [ -n "$GIT_REPO" ] || { echo "Aucun dépôt configuré."; exit 1; }
        git_env
        if sortie=$(git ls-remote --heads "$GIT_REPO" 2>&1); then
            n=$(printf '%s\n' "$sortie" | grep -c 'refs/heads/')
            b=$(printf '%s\n' "$sortie" | sed -n 's|.*refs/heads/||p' | paste -sd', ' -)
            echo "OK: dépôt accessible — ${n} branche(s) : ${b}"
        else
            printf '%s\n' "$sortie" | head -3
            case "$sortie" in
                *"Permission denied"*|*"publickey"*)
                    echo "ECHEC: clé refusée — la clé publique ci-dessus est-elle bien ajoutée en « deploy key » sur le dépôt ?" ;;
                *"not found"*|*"does not exist"*|*"Repository not found"*)
                    echo "ECHEC: dépôt introuvable — vérifiez l'adresse." ;;
                *"Host key verification"*)
                    echo "ECHEC: hôte inconnu — relancez l'affichage de la clé pour enregistrer son empreinte." ;;
                *) echo "ECHEC: dépôt inaccessible." ;;
            esac
            exit 1
        fi
        ;;

    log) journalctl -u "$UNIT" --no-pager -n 300 -o cat 2>/dev/null ;;

    *) echo "Usage: $0 state|check|apply|log|pubkey|testssh" >&2; exit 2 ;;
esac
