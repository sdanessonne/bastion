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
    # mysql -N sépare déjà les colonnes par une VRAIE tabulation ; on lit donc les 3 colonnes
    # directement (read assigne le reste de la ligne à « label », espaces compris). Pas de
    # CONCAT('\t') : selon le mode SQL, « \t » ressort en backslash-t littéral, pas en tabulation.
    mysql -N radius -e "SELECT mac, ip, COALESCE(label,'') FROM pf_dhcp ORDER BY INET_ATON(ip)" 2>/dev/null | \
    while read -r mac ip label; do
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

  config)
    # Applique le SCOPE DHCP (plage + durée du bail) depuis pf_settings, en modifiant la ligne
    # « dhcp-range » de proxyfibre.conf EN PLACE (une seule ligne, pas de conflit). La passerelle
    # et le DNS servi aux clients restent inchangés (le DNS = la passerelle, pour le filtrage).
    # Garde-fou : on valide, on teste la config (dnsmasq --test), et on ANNULE si elle est invalide.
    BASE=/etc/dnsmasq.d/proxyfibre.conf
    [ -f "$BASE" ] || { echo "ERROR: $BASE introuvable" >&2; exit 1; }
    rs=$(mysql -N radius -e "SELECT v FROM pf_settings WHERE k='dhcp_range_start'" 2>/dev/null)
    re=$(mysql -N radius -e "SELECT v FROM pf_settings WHERE k='dhcp_range_end'"   2>/dev/null)
    lease=$(mysql -N radius -e "SELECT v FROM pf_settings WHERE k='dhcp_lease'"    2>/dev/null)
    [ -n "$rs" ]    || rs=192.168.182.10
    [ -n "$re" ]    || re=192.168.182.254
    [ -n "$lease" ] || lease=1h
    # Validation stricte (2ᵉ rempart après le PHP).
    echo "$rs"    | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$'  || { echo "ERROR: debut de plage invalide" >&2; exit 2; }
    echo "$re"    | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$'  || { echo "ERROR: fin de plage invalide" >&2; exit 2; }
    echo "$lease" | grep -qE '^([0-9]+[smhd]|infinite)$'      || { echo "ERROR: bail invalide" >&2; exit 2; }
    # Masque + sous-réseau repris de la config existante (jamais changés depuis le web).
    cur=$(grep -m1 -E '^#?dhcp-range=' "$BASE" | sed 's/^#\{0,1\}dhcp-range=//')
    mask=$(printf '%s' "$cur" | cut -d, -f3)
    echo "$mask" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || mask=255.255.255.0
    router=$(grep -m1 -oE '^listen-address=[0-9.]+' "$BASE" | cut -d= -f2)
    [ -n "$router" ] || router=192.168.182.1
    pre=$(printf '%s' "$router" | sed -E 's/\.[0-9]+$/./')     # ex. « 192.168.182. »
    case "$rs" in "$pre"*) : ;; *) echo "ERROR: debut hors du sous-reseau $pre" >&2; exit 2 ;; esac
    case "$re" in "$pre"*) : ;; *) echo "ERROR: fin hors du sous-reseau $pre" >&2; exit 2 ;; esac
    # Modification EN PLACE + validation + retour arrière si dnsmasq refuse.
    cp "$BASE" "$BASE.bak"
    sed -i -E "s|^#?dhcp-range=.*|dhcp-range=$rs,$re,$mask,$lease|" "$BASE"
    if dnsmasq --test >/dev/null 2>&1; then
        rm -f "$BASE.bak"
        systemctl restart dnsmasq >/dev/null 2>&1 || true
        echo "config DHCP appliquee ($rs-$re, bail $lease)"
    else
        mv "$BASE.bak" "$BASE"
        echo "ERROR: configuration dnsmasq invalide — changement annule" >&2
        exit 1
    fi
    ;;

  *) echo "usage: dhcp apply|config" >&2; exit 2 ;;
esac
