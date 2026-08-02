#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Déconnexion d'un client du portail captif.
#
# ── POURQUOI CE SCRIPT EXISTE, ALORS QUE « ndsctl deauth » SUFFISAIT ─────────
# Il ne suffisait pas. MESURÉ sur la passerelle : après un « ndsctl deauth », le
# client disparaît bien de la liste et ses règles de pare-feu sont retirées —
# mais 46 connexions restaient VIVANTES dans le suivi de connexions du noyau.
# Le pare-feu ne filtre que les NOUVELLES connexions ; celles déjà établies
# continuent de passer. Le navigateur les réutilise (keep-alive), les pages
# continuent de s'afficher, et l'agent se croit encore connecté alors que le
# portail le considère déconnecté.
# Pour une passerelle qui journalise les accès à fin légale, c'est plus qu'une
# gêne : le trafic continue sous une session officiellement close.
#
# On purge donc le suivi de connexions APRÈS la déauthentification. L'ordre
# compte : purger d'abord laisserait le client rétablir aussitôt ses connexions,
# puisqu'il serait encore autorisé.
set -u

IP="${1:-}"
case "$IP" in
    *[!0-9.]*|'') echo "adresse invalide" >&2; exit 2 ;;
esac
# Quatre nombres de 0 à 255 : on ne fait pas confiance à ce qui vient du web,
# même déjà validé côté PHP.
echo "$IP" | grep -qE '^((25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])$' \
    || { echo "adresse invalide" >&2; exit 2; }

sortie=$(ndsctl deauth "$IP" 2>&1)
rc=$?

# La purge est tentée même si la déauthentification a échoué : un client déjà
# absent de la liste d'OpenNDS peut malgré tout garder des connexions ouvertes.
purgees=0
if command -v conntrack >/dev/null 2>&1; then
    purgees=$(conntrack -D -s "$IP" 2>&1 | grep -c 'flow entries' || true)
    conntrack -D -d "$IP" >/dev/null 2>&1 || true
fi

# Ce qui reste réellement suivi, LU DANS LE NOYAU. On ne se contente pas du
# compte-rendu de l'outil : c'est l'état du système qui fait foi.
restant=$(grep -c "src=$IP \|dst=$IP " /proc/net/nf_conntrack 2>/dev/null || echo 0)

case "$sortie" in
    *deauthenticated*) echo "deconnecte: $IP (connexions restantes: $restant)"; exit 0 ;;
    *"not found"*)     echo "absent: $IP n'etait pas authentifie (connexions restantes: $restant)"; exit 0 ;;
    *)                 echo "ECHEC: $sortie" >&2; exit 1 ;;
esac
