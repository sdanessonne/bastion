#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# État des mises à jour en attente, en une seule lecture.
#
# ── POURQUOI UN CACHE ─────────────────────────────────────────────────────────
# Le tableau de bord est la page la plus ouverte de la console. Y interroger apt
# et le dépôt Git à chaque affichage ajouterait deux exécutions de script à
# chaque clic — sur une console dont la lenteur a déjà été signalée, ce serait
# aggraver le mal pour afficher un compteur.
# Le relevé est donc fait par la minuterie des métriques, qui tourne déjà chaque
# minute, et la console se contente de LIRE.
#
# Usage :  maj-state.sh refresh   (relève et met en cache)
#          maj-state.sh state     (lit le cache, n'exécute rien)
set -uo pipefail

CACHE=/dev/shm/pf-maj.json
TTL=900     # au-delà, la valeur est signalée comme périmée plutôt que tue

case "${1:-state}" in

refresh)
    apt=$(/usr/local/sbin/proxyfibre-apt state 2>/dev/null)
    git=$(/usr/local/sbin/proxyfibre-selfupdate state 2>/dev/null)
    # Un JSON illisible ne remplace pas un cache valide : mieux vaut une valeur
    # d'il y a deux minutes qu'un compteur à zéro qui ferait croire à jour.
    printf '%s' "$apt" | grep -q '"total"'  || exit 0
    n_apt=$(printf '%s' "$apt"  | sed -n 's/.*"total":\([0-9]*\).*/\1/p')
    n_sec=$(printf '%s' "$apt"  | sed -n 's/.*"secu":\([0-9]*\).*/\1/p')
    n_git=$(printf '%s' "$git"  | sed -n 's/.*"retard":\([0-9]*\).*/\1/p')
    reboot=$(printf '%s' "$apt" | grep -q '"reboot":true' && echo true || echo false)

    tmp="${CACHE}.tmp"
    printf '{"apt":%s,"secu":%s,"git":%s,"reboot":%s,"ts":%s}\n' \
        "${n_apt:-0}" "${n_sec:-0}" "${n_git:-0}" "$reboot" "$(date +%s)" > "$tmp" 2>/dev/null \
        && mv -f "$tmp" "$CACHE" 2>/dev/null
    ;;

state)
    if [ ! -r "$CACHE" ]; then
        echo '{"apt":0,"secu":0,"git":0,"reboot":false,"ts":0,"age":-1,"connu":false}'
        exit 0
    fi
    j=$(cat "$CACHE" 2>/dev/null)
    ts=$(printf '%s' "$j" | sed -n 's/.*"ts":\([0-9]*\).*/\1/p')
    age=$(( $(date +%s) - ${ts:-0} ))
    # « connu » distingue « rien en attente » de « on n'a pas encore regardé ».
    # Les confondre afficherait « à jour » sur une machine qui n'a jamais vérifié.
    printf '%s,"age":%s,"connu":%s}\n' "${j%\}}" "$age" \
        "$([ "$age" -le "$TTL" ] && echo true || echo false)"
    ;;

*)
    echo "usage: maj-state.sh refresh|state" >&2; exit 2 ;;
esac
