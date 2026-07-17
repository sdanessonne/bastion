#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Mesure de la capacité de la ligne Internet (passerelle → Internet).
#
# ── POURQUOI CE SCRIPT ──────────────────────────────────────────────────────
# La passerelle NE PEUT PAS connaître la capacité de sa ligne. /sys/class/net/<if>/speed
# donne la vitesse du lien ETHERNET vers la box (souvent 1000, parfois -1 comme ici) :
# une fibre 1 Gb/s et un ADSL 8 Mb/s y afficheraient la même valeur. La box n'expose
# rien d'interrogeable de façon standard. Seule une mesure réelle répond.
#
# ── DEUX PIÈGES QUI FAUSSENT TOUTE MESURE DE DÉBIT ─────────────────────────
# 1. UN SEUL FLUX TCP SOUS-ESTIME LA LIGNE. Mesuré ici : 892 ko/s sur un flux contre
#    1,3 Mo/s sur quatre — soit 46 % d'écart. La fenêtre de congestion d'un flux unique
#    ne monte pas assez vite, surtout sur une ligne à latence élevée. On parallélise.
# 2. SOMMER LE DÉBIT RAPPORTÉ PAR CHAQUE curl EST FAUX : chacun rend une MOYENNE sur SA
#    propre durée de vie, et les flux ne démarrent ni ne finissent ensemble. On mesure
#    donc sur les COMPTEURS DE L'INTERFACE, qui comptent tout — en-têtes compris — sur
#    une fenêtre unique. Mesuré : 16,5 Mbit/s par les compteurs contre 10,4 en sommant
#    curl.
#
# ── CE QUE LA MESURE INCLUT, ET POURQUOI C'EST VOULU ───────────────────────
# Les compteurs comptent AUSSI le trafic des postes pendant le test. C'est correct : on
# cherche ce que la LIGNE encaisse, pas ce que le test consomme. Si les postes occupent
# déjà 90 % de la ligne, nos flux n'obtiennent que les 10 % restants — mais le total
# mesuré reste la capacité réelle.
#
# ── COÛT ────────────────────────────────────────────────────────────────────
# Le test SATURE délibérément la ligne pendant ~8 s dans chaque sens. Les postes en
# souffriront le temps du test. À lancer hors des heures de service.
#
# Usage : speedtest-wan.sh run | state | log
set -uo pipefail

UNIT=proxyfibre-speedtest
DUREE="${SPEEDTEST_DUREE:-8}"      # secondes par sens
FLUX="${SPEEDTEST_FLUX:-4}"        # flux parallèles
DB="mysql -N -B radius"

# Sources de téléchargement : miroirs Debian, déjà utilisés par apt et présents dans le
# walled garden. Aucune dépendance ni tiers nouveau. Plusieurs URL pour ne pas dépendre
# d'un seul miroir lent au moment du test.
DL_URLS=(
  "https://deb.debian.org/debian/dists/trixie/main/installer-amd64/current/images/netboot/mini.iso"
  "https://deb.debian.org/debian/dists/trixie/main/installer-amd64/current/images/netboot/netboot.tar.gz"
  "https://cdimage.debian.org/cdimage/archive/README.txt"
)
# Téléversement : il FAUT un serveur qui accepte des données — aucun miroir Debian ne le
# fait. speed.cloudflare.com est l'endpoint public standard, sans compte ni clé. Seuls
# des octets ALÉATOIRES y sont envoyés : aucune donnée du service ne sort.
UP_URL="${SPEEDTEST_UP_URL:-https://speed.cloudflare.com/__up}"

WAN_IF="$( . /etc/proxyfibre/net.env 2>/dev/null; echo "${WAN_IF:-enp0s3}" )"
CPT="/sys/class/net/${WAN_IF}/statistics"

occupe() { systemctl is-active --quiet "$UNIT" 2>/dev/null; }
reg() { $DB -e "INSERT INTO pf_settings (k,v) VALUES ('$1','$2') ON DUPLICATE KEY UPDATE v=VALUES(v);" 2>/dev/null; }
lire() { $DB -e "SELECT v FROM pf_settings WHERE k='$1' LIMIT 1" 2>/dev/null; }

case "${1:-}" in
    run)
        occupe && { echo "deja-en-cours"; exit 0; }
        systemctl reset-failed "$UNIT" 2>/dev/null || true
        systemd-run --unit="$UNIT" --collect --no-block \
            --description="Bastion - mesure de la ligne Internet" \
            --property=RuntimeMaxSec=180 \
            /usr/local/sbin/proxyfibre-speedtest _run >/dev/null 2>&1 \
            && echo "lance" || { echo "ERREUR: lancement impossible" >&2; exit 1; }
        ;;

    state)
        actif=false; occupe && actif=true
        # « lire » rend une chaîne VIDE quand la clé n'existe pas — et mysql SORT EN
        # SUCCÈS, donc un « || echo 0 » ne se déclenche jamais. Le JSON contenait alors
        # « "down":, » : invalide, et la console cassait tant qu'aucune mesure n'avait
        # été faite. On force le défaut par expansion de paramètre.
        d=$(lire wan_speed_down); u=$(lire wan_speed_up)
        a=$(lire wan_speed_at);   e=$(lire wan_speed_err)
        printf '{"en_cours":%s,"down":%s,"up":%s,"date":%s,"if":"%s","erreur":"%s"}\n' \
            "$actif" "${d:-0}" "${u:-0}" "${a:-0}" "$WAN_IF" \
            "$(printf '%s' "${e:-}" | sed 's/\\/\\\\/g; s/"/\\"/g')"
        ;;

    # Exécuté DANS l'unité transitoire ; non autorisé par la liste blanche sudo.
    _run)
        reg wan_speed_err ""
        echo "=== Mesure de la ligne — $(date '+%d/%m/%Y %H:%M:%S') ==="
        echo "Interface WAN : ${WAN_IF} · ${FLUX} flux parallèles · ${DUREE} s par sens"
        echo "ATTENTION : la ligne est saturée pendant le test, les postes en pâtissent."

        # ── DESCENDANT ──────────────────────────────────────────────────────
        echo; echo "--- Descendant ---"
        r1=$(cat "$CPT/rx_bytes"); t1=$(date +%s.%N)
        for i in $(seq 1 "$FLUX"); do
            u="${DL_URLS[$(( (i-1) % ${#DL_URLS[@]} ))]}"
            ( curl -s -o /dev/null --max-time "$DUREE" "$u" 2>/dev/null ) &
        done
        wait
        r2=$(cat "$CPT/rx_bytes"); t2=$(date +%s.%N)
        DOWN=$(python3 -c "dt=$t2-$t1; d=$r2-$r1; print(int(d/dt) if dt>0.5 else 0)")
        echo "  $(python3 -c "print(f'{$DOWN/1048576:.2f} Mo/s  soit  {$DOWN*8/1e6:.1f} Mbit/s')")"

        # ── MONTANT ─────────────────────────────────────────────────────────
        echo; echo "--- Montant ---"
        # Octets ALÉATOIRES : incompressibles (un fichier de zéros serait compressé par
        # le réseau ou le serveur et gonflerait artificiellement la mesure).
        TMP=$(mktemp /tmp/pf-up.XXXXXX); head -c 8388608 /dev/urandom > "$TMP"
        u1=$(cat "$CPT/tx_bytes"); s1=$(date +%s.%N)
        for i in $(seq 1 "$FLUX"); do
            ( curl -s -o /dev/null --max-time "$DUREE" -X POST --data-binary "@$TMP" "$UP_URL" 2>/dev/null ) &
        done
        wait
        u2=$(cat "$CPT/tx_bytes"); s2=$(date +%s.%N)
        rm -f "$TMP"
        UP=$(python3 -c "dt=$s2-$s1; d=$u2-$u1; print(int(d/dt) if dt>0.5 else 0)")
        echo "  $(python3 -c "print(f'{$UP/1048576:.2f} Mo/s  soit  {$UP*8/1e6:.1f} Mbit/s')")"

        # Un résultat nul signale une panne, pas une ligne à zéro : ne pas l'enregistrer
        # comme une capacité, l'administrateur croirait sa ligne morte.
        if [ "${DOWN:-0}" -le 0 ]; then
            echo; echo "ECHEC: aucun octet reçu — la passerelle a-t-elle accès à Internet ?"
            reg wan_speed_err "Aucun octet reçu : vérifiez l'accès Internet de la passerelle."
            exit 1
        fi
        reg wan_speed_down "$DOWN"
        reg wan_speed_up   "${UP:-0}"
        reg wan_speed_at   "$(date +%s)"
        [ "${UP:-0}" -le 0 ] && reg wan_speed_err "Montant non mesuré : ${UP_URL} injoignable."
        echo; echo "--- Terminé ---"
        ;;

    log) journalctl -u "$UNIT" --no-pager -n 60 -o cat 2>/dev/null ;;
    *) echo "Usage: $0 run|state|log" >&2; exit 2 ;;
esac
