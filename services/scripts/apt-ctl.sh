#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Mise à jour Debian, pilotée depuis la console d'administration.
#
# ── SÉCURITÉ : POURQUOI AUCUN NOM DE PAQUET N'EST ACCEPTÉ ────────────────────
# Ce script tourne en root via sudo, appelé par le serveur web. Accepter un nom de
# paquet en argument reviendrait à offrir « apt install n'importe quoi » à toute
# personne capable de faire exécuter une requête à la console : une exécution de
# code arbitraire en root. Les verbes ci-dessous sont donc FERMÉS, sans paramètre,
# et la liste blanche sudo les énumère un par un (voir deploy.sh).
#
# ── POURQUOI --force-confold ET --force-confdef ─────────────────────────────
# Bastion MODIFIE des fichiers de configuration appartenant à des paquets :
# smb.conf (keepalive, map to guest), dnsmasq.conf (zone AD, walled garden),
# opennds.conf (FAS), clients.conf (secret RADIUS). Une mise à jour non interactive
# SANS ces options remplacerait ces fichiers par ceux du mainteneur : le domaine,
# le DNS et le portail captif tomberaient d'un coup, silencieusement.
#   --force-confold : garder NOTRE fichier en cas de conflit.
#   --force-confdef : si le fichier n'a pas été touché, laisser le mainteneur décider.
# Ensemble : jamais de question posée, jamais de modification perdue.
#
# ── POURQUOI « upgrade » ET PAS « full-upgrade » ────────────────────────────
# « upgrade » ne SUPPRIME jamais un paquet. « full-upgrade » peut en retirer pour
# résoudre une dépendance — sur une passerelle en service, il pourrait désinstaller
# opennds ou samba pour satisfaire un conflit. Les paquets non mis à jour par ce
# choix sont signalés (« retenus ») plutôt que forcés.
#
# Usage : apt-ctl.sh check | list | apply | state | log
set -uo pipefail

STATE_DIR=/var/lib/proxyfibre
UNIT=proxyfibre-apt-apply
UNIT_CHECK=proxyfibre-apt-check
LAST_CHECK="$STATE_DIR/apt-last-check"
LAST_APPLY="$STATE_DIR/apt-last-apply"
# Progression machine d'apt. En mémoire (/run) : elle n'a aucun intérêt après coup et
# ne doit pas survivre à un redémarrage.
PROGRESS=/run/proxyfibre-apt.progress
mkdir -p "$STATE_DIR" 2>/dev/null || true

export DEBIAN_FRONTEND=noninteractive
# apt-listchanges est installé sur cette machine et PEUT suspendre une mise à jour
# non interactive en attendant une lecture. On le neutralise explicitement.
export APT_LISTCHANGES_FRONTEND=none
APT_OPTS=(-o Dpkg::Options::=--force-confold
          -o Dpkg::Options::=--force-confdef
          -o Dpkg::Use-Pty=0
          # apt échoue d'emblée si un autre apt tient le verrou. Ici, l'inventaire est
          # recalculé à chaque affichage de page : une collision avec une recherche ou une
          # installation en cours est banale. On ATTEND le verrou plutôt que d'échouer.
          -o DPkg::Lock::Timeout=120)

# Une seule opération apt à la fois — recherche ET installation confondues : elles
# partagent le verrou d'apt, et lancer l'une pendant l'autre faisait échouer la seconde.
occupe() {
    systemctl is-active --quiet "$UNIT" 2>/dev/null && return 0
    systemctl is-active --quiet "$UNIT_CHECK" 2>/dev/null && return 0
    return 1
}

json_escape() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }

# ── Inventaire des mises à jour en attente ──────────────────────────────────
# « apt-get -s upgrade » (simulation) n'écrit rien sur le disque et donne la liste
# exacte de ce qui serait installé, avec le dépôt d'origine : c'est ce dernier qui
# permet de distinguer une mise à jour de SÉCURITÉ d'une mise à jour ordinaire.
inventaire() {
    local sim; sim="$(apt-get -s upgrade "${APT_OPTS[@]}" 2>/dev/null)"
    local total secu retenus
    total=$(printf '%s\n' "$sim" | grep -c '^Inst ')
    secu=$(printf '%s\n' "$sim"  | grep '^Inst ' | grep -ci 'security')
    retenus=$(printf '%s\n' "$sim" | sed -n 's/^\([0-9]\+\) not upgraded.*/\1/p; s/^\([0-9]\+\) non mis à jour.*/\1/p' | head -1)
    printf '%s|%s|%s' "${total:-0}" "${secu:-0}" "${retenus:-0}"
}

liste_json() {
    local sim; sim="$(apt-get -s upgrade "${APT_OPTS[@]}" 2>/dev/null)"
    printf '['
    local first=1
    while IFS= read -r l; do
        [ -z "$l" ] && continue
        # Format : Inst <paquet> [<version actuelle>] (<nouvelle version> <depot> [arch])
        # Les motifs sont ancrés sur le PREMIER crochet / la PREMIÈRE parenthèse : sed est
        # glouton, donc « .*\[ » filerait jusqu'au dernier crochet — celui de l'architecture
        # — et la « version actuelle » vaudrait « amd64 ».
        local pkg cur new secu
        pkg=$(printf '%s' "$l" | awk '{print $2}')
        cur=$(printf '%s' "$l" | sed -n 's/^[^[]*\[\([^]]*\)\].*/\1/p')
        new=$(printf '%s' "$l" | sed -n 's/^[^(]*(\([^ )]*\).*/\1/p')
        secu=false
        printf '%s' "$l" | grep -qi 'security' && secu=true
        [ $first -eq 1 ] || printf ','
        first=0
        printf '{"pkg":"%s","cur":"%s","new":"%s","secu":%s}' \
            "$(json_escape "$pkg")" "$(json_escape "$cur")" "$(json_escape "$new")" "$secu"
    done < <(printf '%s\n' "$sim" | grep '^Inst ')
    printf ']'
}

case "${1:-}" in
    # Rafraîchit l'index des paquets — EN TÂCHE DE FOND. Mesuré : 21 s sur cette
    # liaison, et davantage sur une ligne de commissariat chargée. Dans la requête PHP,
    # cela frôlerait l'expiration (30 s par défaut) et tiendrait le verrou de session
    # pendant tout ce temps, figeant la navigation de l'administrateur.
    check)
        occupe && { echo "deja-en-cours"; exit 0; }
        systemctl reset-failed "$UNIT_CHECK" 2>/dev/null || true
        # PAS de « Type=oneshot » : pour ce type, systemd ne tient le démarrage pour
        # terminé qu'à la SORTIE du processus — systemd-run ATTEND donc la fin (mesuré :
        # 15 s), exactement ce qu'on cherche à éviter. Le type « simple » (défaut) rend la
        # main aussitôt, et « is-active » vaut « active » tant que la commande tourne.
        systemd-run --unit="$UNIT_CHECK" --collect --no-block \
            --description="Bastion - recherche de mises a jour" \
            --property=RuntimeMaxSec=600 \
            /usr/local/sbin/proxyfibre-apt _check >/dev/null 2>&1 \
            && echo "lance" || { echo "ERREUR: lancement impossible" >&2; exit 1; }
        ;;

    # Exécuté DANS l'unité transitoire ; non autorisé par la liste blanche sudo.
    _check)
        if sortie=$(apt-get update -qq "${APT_OPTS[@]}" 2>&1); then
            date +%s > "$LAST_CHECK"; echo "Index des paquets à jour."
        else
            printf '%s\n' "$sortie"
            if printf '%s' "$sortie" | grep -qiE 'lock|verrou'; then
                echo "ECHEC: apt est déjà utilisé par une autre opération."
            else
                echo "ECHEC: dépôts Debian injoignables — vérifiez l'accès Internet de la passerelle."
            fi
            exit 1
        fi
        ;;

    state)
        IFS='|' read -r total secu retenus <<< "$(inventaire)"
        actif=false; systemctl is-active --quiet "$UNIT" 2>/dev/null && actif=true
        chk=false;   systemctl is-active --quiet "$UNIT_CHECK" 2>/dev/null && chk=true
        reboot=false; [ -f /var/run/reboot-required ] && reboot=true
        # Jauge : les deux phases d'apt sont fondues en une seule barre MONOTONE. Chacune
        # compte de 0 à 100 de son côté ; une barre qui recule à mi-course serait plus
        # déroutante qu'utile. Le téléchargement prend le premier tiers, l'installation
        # les deux autres. -1 = aucune opération en cours (barre masquée).
        pct=-1; phase=""
        if [ -s "$PROGRESS" ]; then
            ligne=$(tail -1 "$PROGRESS" 2>/dev/null)
            brut=$(printf '%s' "$ligne" | cut -d: -f3 | cut -d. -f1)
            phase=$(printf '%s' "$ligne" | cut -d: -f4-)
            case "$ligne" in
                dlstatus:*) pct=$(( ${brut:-0} * 30 / 100 )) ;;
                pmstatus:*) pct=$(( 30 + ${brut:-0} * 70 / 100 )) ;;
            esac
        fi
        printf '{"total":%s,"secu":%s,"retenus":%s,"en_cours":%s,"check_en_cours":%s,"reboot":%s,"pct":%s,"phase":"%s","dernier_check":%s,"derniere_maj":%s,"version":"%s"}\n' \
            "$total" "$secu" "$retenus" "$actif" "$chk" "$reboot" "$pct" "$(json_escape "$phase")" \
            "$(cat "$LAST_CHECK" 2>/dev/null || echo 0)" \
            "$(cat "$LAST_APPLY" 2>/dev/null || echo 0)" \
            "$(json_escape "$(. /etc/os-release 2>/dev/null && echo "${PRETTY_NAME:-Debian}")")"
        ;;

    list) liste_json; echo ;;

    # Lancement en TÂCHE DE FOND. Une mise à jour prend plusieurs minutes : la lancer
    # dans la requête PHP la ferait expirer, et l'arrêt d'Apache (souvent redémarré par
    # la mise à jour elle-même) TUERAIT apt en plein travail — dpkg resterait à moitié
    # configuré. L'unité transitoire survit à la requête et à Apache.
    apply)
        occupe && { echo "deja-en-cours"; exit 0; }
        systemctl reset-failed "$UNIT" 2>/dev/null || true
        systemd-run --unit="$UNIT" --collect --no-block \
            --description="Bastion - mise a jour Debian" \
            --property=RuntimeMaxSec=3600 \
            /usr/local/sbin/proxyfibre-apt _run >/dev/null 2>&1 \
            && echo "lance" || { echo "ERREUR: lancement impossible" >&2; exit 1; }
        ;;

    # Exécuté DANS l'unité transitoire, jamais par le web : la liste blanche sudo
    # n'autorise pas ce verbe (voir deploy.sh).
    _run)
        echo "=== Mise à jour Bastion — $(date '+%d/%m/%Y %H:%M:%S') ==="
        if ! sortie=$(apt-get update -qq "${APT_OPTS[@]}" 2>&1); then
            printf '%s\n' "$sortie"
            # Ne PAS annoncer un problème réseau pour un conflit de verrou : l'administrateur
            # partirait chercher une panne de liaison qui n'existe pas.
            if printf '%s' "$sortie" | grep -qiE 'lock|verrou'; then
                echo "ECHEC: apt est déjà utilisé par une autre opération. Réessayez dans une minute."
            else
                echo "ECHEC: dépôts Debian injoignables — vérifiez l'accès Internet de la passerelle."
            fi
            exit 1
        fi
        echo "--- Paquets concernés ---"
        apt-get -s upgrade "${APT_OPTS[@]}" 2>/dev/null | grep '^Inst ' || echo "(aucun)"
        echo "--- Installation ---"
        # APT::Status-Fd fait écrire à apt une progression MACHINE, une ligne par étape :
        #   <type>:<paquet>:<pourcentage>:<description>
        # « dlstatus » = téléchargement, « pmstatus » = installation. On l'envoie sur un
        # descripteur DÉDIÉ (3) et non sur la sortie standard, pour ne pas noyer le journal
        # que lit l'administrateur sous des centaines de lignes de pourcentages.
        : > "$PROGRESS"; chmod 644 "$PROGRESS" 2>/dev/null
        if apt-get -y upgrade "${APT_OPTS[@]}" -o APT::Status-Fd=3 3>>"$PROGRESS"; then
            date +%s > "$LAST_APPLY"
            rm -f "$PROGRESS"
            echo "--- Terminé ---"
            [ -f /var/run/reboot-required ] && echo "REDÉMARRAGE REQUIS : $(cat /var/run/reboot-required.pkgs 2>/dev/null | tr '\n' ' ')"
            # Un « upgrade » a pu redémarrer des services : on vérifie que l'essentiel
            # tourne toujours et on le DIT, plutôt que de laisser l'admin le découvrir.
            echo "--- État des services critiques ---"
            for s in opennds freeradius mariadb dnsmasq apache2 samba-ad-dc; do
                printf '  %-18s %s\n' "$s" "$(systemctl is-active "$s" 2>/dev/null)"
            done
            exit 0
        fi
        echo "ECHEC de la mise à jour — voir les lignes ci-dessus."
        exit 1
        ;;

    # Journal des deux unités : la console y lit la progression en direct.
    log) journalctl -u "$UNIT" -u "$UNIT_CHECK" --no-pager -n 300 -o cat 2>/dev/null ;;

    *) echo "Usage: $0 check|list|apply|state|log" >&2; exit 2 ;;
esac
