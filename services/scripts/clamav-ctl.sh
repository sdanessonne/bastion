#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — pilotage contrôlé de l'antivirus ClamAV (appelé par la console admin via sudo).
# Usage : proxyfibre-clamav update
#         proxyfibre-clamav scan <répertoire autorisé>
set -eu

action="${1:-}"

case "$action" in
    update)
        # Met à jour la base virale (libère d'abord le verrou du service freshclam).
        systemctl stop clamav-freshclam 2>/dev/null || true
        freshclam --stdout || true
        systemctl start clamav-freshclam 2>/dev/null || true
        ;;
    scan)
        dir="${2:-}"
        case "$dir" in
            /srv/partage|/srv/partage/*|/var/www|/var/www/*|/srv/pxe|/srv/pxe/*) : ;;
            *) echo "chemin refuse: $dir" >&2; exit 2 ;;
        esac
        [ -d "$dir" ] || { echo "repertoire introuvable: $dir" >&2; exit 2; }
        if systemctl is-active --quiet clamav-daemon; then
            clamdscan --fdpass --multiscan --infected "$dir"
        else
            clamscan -r --infected "$dir"
        fi
        ;;
    *)
        echo "usage: proxyfibre-clamav update | scan <dir>" >&2
        exit 2
        ;;
esac
