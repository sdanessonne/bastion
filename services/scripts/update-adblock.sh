#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — active/met à jour ou désactive le blocage des publicités et traqueurs.
# Télécharge une liste de domaines publicitaires reconnue et l'applique via dnsmasq
# (fichier séparé du blocage manuel). Exécuté en root (via sudo ou cron).
# Usage : proxyfibre-update-adblock enable|disable
set -uo pipefail

OUT="/etc/dnsmasq.d/proxyfibre-adblock.conf"
SRC="${ADBLOCK_SOURCE:-https://raw.githubusercontent.com/StevenBlack/hosts/master/hosts}"
ACTION="${1:-enable}"
DB="mysql -N -B radius"

set_state() { $DB -e "CREATE TABLE IF NOT EXISTS pf_settings (k VARCHAR(64) PRIMARY KEY, v TEXT); REPLACE INTO pf_settings (k,v) VALUES $1;" 2>/dev/null; }

if [ "$ACTION" = "disable" ]; then
    rm -f "$OUT"
    set_state "('adblock_enabled','0')"
    systemctl restart dnsmasq
    echo "adblock: désactivé"
    exit 0
fi

# enable / update
RAW="$(mktemp)"; LIST="$(mktemp)"
trap 'rm -f "$RAW" "$LIST"' EXIT

if ! curl -fsSL --max-time 90 "$SRC" -o "$RAW"; then
    echo "adblock: échec du téléchargement ($SRC)" >&2
    exit 1
fi

# Convertit les lignes hosts « 0.0.0.0 domaine » en « address=/domaine/0.0.0.0 »
awk '/^0\.0\.0\.0[ \t]+/ { d=$2; sub(/[ \t\r].*/,"",d); if (d != "0.0.0.0" && d ~ /\./) print "address=/" d "/0.0.0.0" }' "$RAW" \
    | sort -u > "$LIST"
COUNT=$(wc -l < "$LIST")

if [ "$COUNT" -lt 100 ]; then
    echo "adblock: liste trop courte ($COUNT), abandon" >&2
    exit 1
fi

{ echo "# Bastion — blocage publicités/traqueurs (GÉNÉRÉ, source: $SRC)"; cat "$LIST"; } > "$OUT"
chmod 644 "$OUT"
set_state "('adblock_enabled','1'),('adblock_count','$COUNT'),('adblock_updated',NOW())"
systemctl restart dnsmasq
echo "adblock: activé ($COUNT domaines)"
