#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Affichage et réglage du SERVEUR DE TEMPS (chrony) depuis la console.
#   time-ctl.sh status            — état lisible (clé=valeur) + sources
#   time-ctl.sh set <serveur>     — change la source amont (nom/IP) et redémarre chrony
#   time-ctl.sh resync            — force une resynchronisation immédiate
# La passerelle sert l'heure au LAN (allow) : c'est la référence de temps du domaine.
set -u
CONF=/etc/chrony/conf.d/proxyfibre.conf
action="${1:-status}"; arg="${2:-}"

cur_server() { grep -oE '^server +[^ ]+' "$CONF" 2>/dev/null | awk '{print $2}' | head -1; }

case "$action" in
  status)
    echo "localtime=$(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo "timezone=$(timedatectl show -p Timezone --value 2>/dev/null)"
    echo "synchronized=$(timedatectl show -p NTPSynchronized --value 2>/dev/null)"
    echo "server=$(cur_server)"
    echo "serving=$(grep -qE '^allow ' "$CONF" 2>/dev/null && echo yes || echo no)"
    echo "ntp_active=$(systemctl is-active chrony 2>/dev/null)"
    chronyc tracking 2>/dev/null | awk -F': +' '
      /Reference ID/ {print "refid=" $2}
      /Stratum/      {print "stratum=" $2}
      /System time/  {print "offset=" $2}'
    # Sources : « ^*/^-/^? … nom stratum poll reach lastrx sample »
    chronyc sources 2>/dev/null | awk 'NF>=6 && $1 ~ /^[\^=#]/ {
        printf "source\t%s\t%s\t%s\t%s\t%s\n", $1, $2, $3, $5, $6 }'
    ;;
  set)
    s=$(printf '%s' "$arg" | tr -cd 'A-Za-z0-9.:-')
    [ -n "$s" ] || { echo "ERROR: nom de serveur invalide" >&2; exit 2; }
    [ -f "$CONF" ] || { echo "ERROR: configuration chrony absente" >&2; exit 1; }
    cp "$CONF" "$CONF.bak" 2>/dev/null || true
    if grep -qE '^server .* prefer' "$CONF"; then
      sed -i -E "s|^server .* prefer|server $s iburst prefer|" "$CONF"
    elif grep -qE '^server ' "$CONF"; then
      sed -i -E "0,/^server .*/s||server $s iburst prefer|" "$CONF"
    else
      echo "server $s iburst prefer" >> "$CONF"
    fi
    if systemctl restart chrony >/dev/null 2>&1; then
      echo "ok server=$s"
    else
      cp "$CONF.bak" "$CONF" 2>/dev/null || true; systemctl restart chrony >/dev/null 2>&1 || true
      echo "ERROR: redemarrage de chrony echoue (ancienne config retablie)" >&2; exit 1
    fi
    ;;
  resync)
    chronyc burst 4/4 >/dev/null 2>&1 || true
    sleep 1
    chronyc makestep >/dev/null 2>&1 || true
    echo "resynchronisation lancee"
    ;;
  *) echo "usage: time status | set <serveur> | resync" >&2; exit 2 ;;
esac
