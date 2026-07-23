#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Réservations DHCP (IP fixe par adresse MAC). Régénère le fichier de configuration dnsmasq
# à partir de la table pf_dhcp, puis recharge dnsmasq. Appelé par la console (liste blanche
# sudo : seul le verbe « apply » est autorisé). Aucune donnée n'est passée en argument : la
# source fait autorité, ce qui interdit toute injection depuis le web.
set -u
CONF=/etc/dnsmasq.d/proxyfibre-reservations.conf

case "${1:-}" in
  apply)
    tmp=$(mktemp)
    echo "# Bastion — réservations DHCP (généré automatiquement, ne pas éditer)" > "$tmp"
    # Chaque ligne est REVALIDÉE ici (MAC + IPv4) avant d'entrer dans la configuration :
    # la validation PHP ne suffit pas comme unique rempart.
    mysql -N radius -e "SELECT CONCAT(mac,'\t',ip,'\t',COALESCE(label,'')) FROM pf_dhcp ORDER BY ip" 2>/dev/null | \
    while IFS="$(printf '\t')" read -r mac ip label; do
        echo "$mac" | grep -qiE '^([0-9a-f]{2}:){5}[0-9a-f]{2}$' || continue
        echo "$ip"  | grep -qE  '^([0-9]{1,3}\.){3}[0-9]{1,3}$'   || continue
        name=$(printf '%s' "$label" | tr -cd 'A-Za-z0-9._-')
        if [ -n "$name" ]; then
            echo "dhcp-host=$mac,$ip,$name" >> "$tmp"
        else
            echo "dhcp-host=$mac,$ip" >> "$tmp"
        fi
    done
    install -m644 "$tmp" "$CONF"
    rm -f "$tmp"
    # dnsmasq ne relit les baux statiques (dhcp-host) qu'au redémarrage, pas au SIGHUP.
    systemctl restart dnsmasq >/dev/null 2>&1 || true
    echo "reservations DHCP appliquees"
    ;;
  *) echo "usage: dhcp apply" >&2; exit 2 ;;
esac
