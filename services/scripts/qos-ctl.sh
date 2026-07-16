#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Limitation de débit par poste (QoS), pilotée par les groupes.
#
# ── POURQUOI CE SCRIPT ────────────────────────────────────────────────────────
# pf_groups porte depuis toujours down_rate_kbps / up_rate_kbps, et le hook BinAuth
# les transmet à OpenNDS — mais OpenNDS NE MET PAS EN FORME LE TRAFIC : son binaire
# ne contient aucune référence à tc/htb (vérifié). Ses champs *_rate_limit_threshold
# servent à DÉCONNECTER un client trop rapide, pas à lisser son débit. Les valeurs
# réglées dans la console n'avaient donc aucun effet : un seul poste pouvait saturer
# la ligne du commissariat. Ce script applique la limite pour de vrai.
#
# ── COMMENT ───────────────────────────────────────────────────────────────────
# Descente (vers le poste) : HTB à la sortie du LAN, une classe par poste.
# Montée  (depuis le poste) : le trafic entrant du LAN est redirigé vers une
#   interface IFB, où l'on applique le même HTB. On préfère l'IFB à un « police »
#   en entrée : le police JETTE les paquets excédentaires (TCP s'effondre puis
#   remonte, en dents de scie), alors que HTB les MET EN FILE — le débit est lissé
#   et la navigation reste fluide.
#
# ── IDENTIFIANT DE CLASSE ─────────────────────────────────────────────────────
# Le LAN est un /24 : le dernier octet identifie le poste de façon unique. La classe
# vaut 100+octet (101..354), donc DÉTERMINISTE — « tc class replace » crée ou met à
# jour sans qu'on ait à retrouver un identifiant. Au plus 254 classes, jamais plus.
#
# Usage : qos-ctl.sh init | add <ip> <down_kbps> <up_kbps> | del <ip> | status | reset
set -euo pipefail

ENV_FILE=/etc/proxyfibre/net.env
[ -r "$ENV_FILE" ] && . "$ENV_FILE"
LAN_IF="${LAN_IF:-enp0s8}"
IFB="${QOS_IFB:-ifb-bastion}"
# Plafond « illimité » des classes par défaut : au-dessus de tout débit réel de LAN.
CEIL_MAX="${QOS_CEIL_MAX:-1000mbit}"
DEFAULT_CLS=9999

TC=/sbin/tc

# Réglage de fq_codel ADAPTÉ AU DÉBIT de la classe.
#
# fq_codel vise par défaut 5 ms de latence de file. Or, à 500 kbit/s, transmettre un
# seul paquet de 1500 octets prend déjà 24 ms : l'objectif est inatteignable, fq_codel
# jetterait donc des paquets en continu et TCP s'effondrerait. La règle admise est de
# viser au moins le temps d'émission d'un paquet, et un intervalle dix fois plus long.
codel_for() {
    local kbit="${1:-0}" t i
    case "$kbit" in ''|*[!0-9]*) echo ""; return ;; esac
    [ "$kbit" -le 0 ] && { echo ""; return; }
    t=$(( 1500 * 8 / kbit + 1 ))            # ms pour émettre un paquet plein
    [ "$t" -lt 5 ] && t=5
    i=$(( t * 10 )); [ "$i" -lt 100 ] && i=100
    echo "target ${t}ms interval ${i}ms"
}

# Réserve de jetons ≈ 100 ms de débit (débit_kbit × 1000 / 8 / 10), plancher 16 ko.
burst_for() {
    local kbit="${1:-0}" b
    case "$kbit" in ''|*[!0-9]*) echo 32k; return ;; esac
    [ "$kbit" -le 0 ] && { echo 32k; return; }
    b=$(( kbit * 1000 / 80 ))
    [ "$b" -lt 16384 ] && b=16384
    echo "${b}"
}

cls_of_ip() {
    local last="${1##*.}"
    case "$last" in ''|*[!0-9]*) return 1 ;; esac
    [ "$last" -ge 1 ] && [ "$last" -le 254 ] || return 1
    echo $((100 + last))
}

# Racine HTB sur les deux sens. Idempotent : ne refait rien si déjà en place.
init() {
    modprobe ifb numifbs=0 2>/dev/null || true
    ip link show "$IFB" >/dev/null 2>&1 || ip link add "$IFB" type ifb
    ip link set "$IFB" up 2>/dev/null || true

    for dev in "$LAN_IF" "$IFB"; do
        if ! $TC qdisc show dev "$dev" | grep -q 'htb 1:'; then
            $TC qdisc replace dev "$dev" root handle 1: htb default $DEFAULT_CLS
            # « quantum » explicite sur les classes à très haut débit : sans lui, HTB le
            # déduit de rate/r2q, obtient une valeur énorme et avertit à chaque appel
            # (« quantum of class ... is big »). 60 ko est un compromis usuel.
            $TC class replace dev "$dev" parent 1:  classid 1:1 htb rate "$CEIL_MAX" ceil "$CEIL_MAX" quantum 60000
            # Classe par défaut : tout ce qui n'est PAS un poste identifié passe sans
            # limite. Sans elle, HTB étranglerait le trafic de la passerelle elle-même.
            $TC class replace dev "$dev" parent 1:1 classid 1:$DEFAULT_CLS htb rate "$CEIL_MAX" ceil "$CEIL_MAX" quantum 60000
        fi
    done

    # Trafic ENTRANT du LAN redirigé vers l'IFB, où il devient « sortant » et donc
    # façonnable par HTB (on ne peut pas mettre en forme en entrée directement).
    $TC qdisc show dev "$LAN_IF" | grep -q 'ingress' || $TC qdisc add dev "$LAN_IF" handle ffff: ingress
    if ! $TC filter show dev "$LAN_IF" parent ffff: 2>/dev/null | grep -q mirred; then
        $TC filter add dev "$LAN_IF" parent ffff: protocol ip prio 10 u32 \
            match u32 0 0 action mirred egress redirect dev "$IFB"
    fi
}

# Un filtre par poste et par sens ; posé UNE fois, il survit aux reconnexions.
# Seule la classe est mise à jour ensuite — c'est ce qui rend « add » idempotent.
ensure_filter() {
    local dev="$1" match="$2" ip="$3" cls="$4"
    $TC filter show dev "$dev" parent 1: 2>/dev/null | grep -q "flowid 1:${cls}\b" && return 0
    $TC filter add dev "$dev" protocol ip parent 1: prio 1 u32 \
        match ip "$match" "${ip}/32" flowid "1:${cls}"
}

add() {
    local ip="$1" down="${2:-0}" up="${3:-0}" cls
    cls=$(cls_of_ip "$ip") || { echo "IP hors du LAN : $ip" >&2; return 1; }
    init

    # 0 = pas de limite pour ce groupe : on rend la classe au plafond plutôt que de
    # la supprimer, sinon le filtre pointerait sur une classe inexistante.
    local d="${down}kbit" u="${up}kbit"
    [ "$down" -le 0 ] 2>/dev/null && d="$CEIL_MAX"
    [ "$up"   -le 0 ] 2>/dev/null && u="$CEIL_MAX"

    # « burst » : réserve de jetons. tc le calcule par défaut sur rate/HZ, ce qui donne
    # ~1600 octets — à peine 6 ms de débit, soit moins qu'un aller-retour vers Internet
    # (~30 ms) : TCP ne peut alors pas garder le tuyau plein. On dimensionne sur ~100 ms
    # de débit, plancher 16 ko. Trop grand, il laisserait passer des pointes ; 100 ms est
    # le compromis usuel.
    #
    # PRÉCISION MESURÉE (source locale, pour écarter la variabilité d'un serveur distant) :
    # 96 % de la limite à 500 kbit/s, 95 % à 2 et 4 Mbit/s. Mesurer contre un serveur
    # Internet donne des résultats trompeurs — sa propre variabilité domine le résultat.
    $TC class replace dev "$LAN_IF" parent 1:1 classid "1:${cls}" htb rate "$d" ceil "$d" \
        burst "$(burst_for "$down")" cburst "$(burst_for "$down")"
    $TC class replace dev "$IFB"    parent 1:1 classid "1:${cls}" htb rate "$u" ceil "$u" \
        burst "$(burst_for "$up")" cburst "$(burst_for "$up")"
    # fq_codel dans la classe : répartit équitablement entre les connexions du poste
    # et écrase la latence d'attente (bufferbloat) quand la classe est saturée.
    # Réglé sur le débit de la classe (voir codel_for) : au réglage par défaut, il
    # étranglait les petits débits.
    $TC qdisc replace dev "$LAN_IF" parent "1:${cls}" fq_codel $(codel_for "$down") 2>/dev/null || true
    $TC qdisc replace dev "$IFB"    parent "1:${cls}" fq_codel $(codel_for "$up")   2>/dev/null || true

    ensure_filter "$LAN_IF" dst "$ip" "$cls"   # descente : destination = le poste
    ensure_filter "$IFB"    src "$ip" "$cls"   # montée   : source = le poste
    logger -t bastion-qos "poste ${ip} : descente ${d}, montee ${u}"
}

# Déconnexion : on relâche la limite plutôt que de démonter classe et filtre.
# Supprimer la classe laisserait un filtre orphelin, et l'IP sera de toute façon
# reconfigurée à la prochaine authentification.
del() {
    local ip="$1" cls
    cls=$(cls_of_ip "$ip") || return 0
    $TC class replace dev "$LAN_IF" parent 1:1 classid "1:${cls}" htb rate "$CEIL_MAX" ceil "$CEIL_MAX" 2>/dev/null || true
    $TC class replace dev "$IFB"    parent 1:1 classid "1:${cls}" htb rate "$CEIL_MAX" ceil "$CEIL_MAX" 2>/dev/null || true
    logger -t bastion-qos "poste ${ip} : limite relachee"
}

status() {
    echo "interface LAN : $LAN_IF     interface montee : $IFB"
    printf '%-18s %-14s %-14s %s\n' 'POSTE' 'DESCENTE' 'MONTEE' 'VOLUME DESCENDU'
    local f cls ip d u b
    while read -r f; do
        cls="${f##*1:}"
        [ "$cls" = "$DEFAULT_CLS" ] || [ "$cls" = 1 ] && continue
        ip="192.168.182.$((cls - 100))"
        d=$($TC -s class show dev "$LAN_IF" classid "1:${cls}" 2>/dev/null | sed -n 's/.*ceil \([0-9A-Za-z]*\).*/\1/p' | head -1)
        u=$($TC -s class show dev "$IFB"    classid "1:${cls}" 2>/dev/null | sed -n 's/.*ceil \([0-9A-Za-z]*\).*/\1/p' | head -1)
        b=$($TC -s class show dev "$LAN_IF" classid "1:${cls}" 2>/dev/null | sed -n 's/ Sent \([0-9]*\) bytes.*/\1/p' | head -1)
        printf '%-18s %-14s %-14s %s octets\n' "$ip" "${d:-?}" "${u:-?}" "${b:-0}"
    done < <($TC class show dev "$LAN_IF" 2>/dev/null | sed -n 's/.*leaf \([0-9]*\):.*/1:\1/p; s/class htb \(1:[0-9]*\).*/\1/p' | sort -u)
}

reset() {
    $TC qdisc del dev "$LAN_IF" root 2>/dev/null || true
    $TC qdisc del dev "$LAN_IF" ingress 2>/dev/null || true
    $TC qdisc del dev "$IFB" root 2>/dev/null || true
    ip link del "$IFB" 2>/dev/null || true
    echo "QoS retiree."
}

case "${1:-}" in
    init)   init; echo "QoS initialisee (${LAN_IF} + ${IFB})." ;;
    add)    shift; add "$@" ;;
    del)    shift; del "$@" ;;
    status) status ;;
    reset)  reset ;;
    *) echo "Usage: $0 init|add <ip> <down_kbps> <up_kbps>|del <ip>|status|reset" >&2; exit 2 ;;
esac
