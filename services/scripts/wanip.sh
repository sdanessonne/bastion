#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Quelle adresse le commissariat présente-t-il au reste d'Internet ?
#
# ── POURQUOI CE N'EST PAS UNE SEULE ADRESSE ───────────────────────────────────
# La question paraît simple, elle ne l'est pas : depuis que la sortie par tunnel
# existe, il y en a DEUX à la fois. Les postes ordinaires sortent par la box, ceux
# des groupes marqués « VPN » par le tunnel. Afficher une seule valeur laisserait
# croire que tout le monde présente la même — et un enquêteur pourrait croire
# travailler couvert alors qu'il sort en clair, ou l'inverse.
#
# ── POURQUOI IL FAUT INTERROGER L'EXTÉRIEUR ───────────────────────────────────
# L'adresse locale du port WAN (10.91.22.250 ici) N'EST PAS celle vue de
# l'extérieur : une box fait de la traduction d'adresse en amont. Seul un service
# distant peut dire ce qu'il voit. C'est le seul appel sortant de ce script, il
# ne transmet rien d'autre que la requête elle-même, et il est mis en cache.
#
# ── ET SURTOUT : JAMAIS PENDANT L'AFFICHAGE D'UNE PAGE ────────────────────────
# Deux requêtes réseau avec délai d'attente au milieu du rendu du tableau de
# bord, ce serait rendre la console lente pour afficher une information de
# confort. Le relevé est donc fait par la minuterie des métriques (chaque
# minute), et la console se contente de LIRE le cache.
#
# Usage :  wanip.sh refresh   (relève et met en cache — pour la minuterie)
#          wanip.sh state     (lit le cache, n'appelle rien)
set -uo pipefail

CACHE=/dev/shm/pf-wanip.json
IF=bastionvpn
TTL=600          # au-delà, la valeur est signalée comme périmée plutôt que tue

# Service d'écho : une seule adresse en réponse, pas de page, pas de suivi.
# Deux sources, pour qu'une indisponibilité ne fasse pas passer la sortie pour
# inconnue alors qu'elle fonctionne.
echo_ip() {
    local args=("$@") ip
    for u in https://api.ipify.org https://ifconfig.me/ip; do
        ip=$(curl -s --max-time 6 "${args[@]}" "$u" 2>/dev/null | tr -d ' \r\n')
        # On valide la FORME : un portail captif en amont, une page d'erreur ou
        # un blocage renverraient du HTML que l'on afficherait tel quel comme
        # « adresse publique ».
        if printf '%s' "$ip" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$'; then
            printf '%s' "$ip"; return 0
        fi
    done
    return 1
}

case "${1:-state}" in

refresh)
    direct=$(echo_ip || true)

    # L'adresse du tunnel n'est relevée QUE s'il est réellement établi : une
    # poignée de main de moins de 5 min. Sinon on interrogerait une interface
    # morte et l'on attendrait le délai d'expiration pour rien, chaque minute.
    vpn=""
    if ip link show "$IF" >/dev/null 2>&1; then
        hs=$(wg show "$IF" latest-handshakes 2>/dev/null | awk '{print $2; exit}')
        if [ -n "${hs:-}" ] && [ "$hs" != "0" ] && [ $(( $(date +%s) - hs )) -lt 300 ]; then
            vpn=$(echo_ip --interface "$IF" || true)
        fi
    fi

    # Écriture atomique : la console lit ce fichier à tout instant ; la surprendre
    # au milieu d'une écriture lui donnerait un JSON tronqué, donc une carte vide
    # sans explication.
    tmp="${CACHE}.tmp"
    printf '{"direct":"%s","vpn":"%s","ts":%s}\n' "$direct" "$vpn" "$(date +%s)" > "$tmp" 2>/dev/null \
        && mv -f "$tmp" "$CACHE" 2>/dev/null
    ;;

state)
    if [ ! -r "$CACHE" ]; then
        echo '{"direct":"","vpn":"","ts":0,"age":-1,"perime":true}'
        exit 0
    fi
    j=$(cat "$CACHE" 2>/dev/null)
    ts=$(printf '%s' "$j" | sed -n 's/.*"ts":\([0-9]*\).*/\1/p')
    age=$(( $(date +%s) - ${ts:-0} ))
    perime=false; [ "$age" -gt "$TTL" ] && perime=true
    # Une valeur périmée est RENDUE, accompagnée de son âge — elle reste utile,
    # à condition qu'on sache qu'elle date. La taire afficherait « inconnu » alors
    # qu'on sait quelque chose.
    printf '%s\n' "${j%\}}" | tr -d '\n'
    printf ',"age":%s,"perime":%s}\n' "$age" "$perime"
    ;;

*)
    echo "usage: wanip.sh refresh|state" >&2; exit 2 ;;
esac
