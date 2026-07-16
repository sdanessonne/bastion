#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — pilotage contrôlé des services système (liste blanche stricte).
# Appelé par la console d'administration via sudo. Le script (et non sudoers)
# est la frontière de sécurité : seuls ces services et ces actions sont permis.
# Usage : proxyfibre-service <start|stop|restart|reload> <service>
#         proxyfibre-service logs <service> [nb_lignes]
set -eu

ALLOWED="opennds freeradius mariadb apache2 dnsmasq chrony nftables proxyfibre-weblog proxyfibre-walledgarden samba-ad-dc proxyfibre-kms clamav-daemon clamav-freshclam"

action="${1:-}"
svc="${2:-}"

case " start stop restart reload logs " in
    *" $action "*) : ;;
    *) echo "action refusee: $action" >&2; exit 2 ;;
esac
case " $ALLOWED " in
    *" $svc "*) : ;;
    *) echo "service refuse: $svc" >&2; exit 2 ;;
esac

if [ "$action" = "logs" ]; then
    # Lecture seule du journal du service (nb de lignes borné).
    lines="${3:-40}"
    case "$lines" in ''|*[!0-9]*) lines=40 ;; esac
    [ "$lines" -gt 300 ] && lines=300
    exec journalctl -u "$svc" -n "$lines" --no-pager -o short-iso
fi

if [ "$svc" = "apache2" ]; then
    # Apache héberge la console : on découple l'action du processus web courant
    # (sinon la réponse HTTP serait tuée avant d'arriver au navigateur).
    systemd-run --on-active=2 --timer-property=AccuracySec=200ms \
        /bin/systemctl "$action" apache2 >/dev/null 2>&1
    echo "planifie"
else
    exec systemctl "$action" "$svc"
fi
