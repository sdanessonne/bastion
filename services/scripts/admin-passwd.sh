#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Réinitialisation du mot de passe d'un administrateur de la console.
#
# ── POURQUOI CE SCRIPT EXISTE ─────────────────────────────────────────────────
# Le déploiement réécrivait le mot de passe de la console à chaque exécution, ce
# qui faisait office de « secours » involontaire : un mot de passe oublié
# revenait tout seul à la valeur d'installation. C'était surtout un défaut de
# sécurité — le mot de passe d'origine redevenait valide après chaque mise à
# jour, alors que l'administrateur croyait l'avoir changé.
#
# Le déploiement ne touche donc plus à un compte existant. Il fallait en
# contrepartie un vrai chemin de secours, sans quoi on se serait simplement
# verrouillé dehors : c'est ce script. La différence tient en un mot —
# il faut le VOULOIR.
#
# ── LE MOT DE PASSE N'EST NI EN ARGUMENT, NI AFFICHÉ ──────────────────────────
# Un mot de passe passé en argument apparaît dans « ps », dans l'historique du
# shell et dans les journaux d'audit du système. Il est donc saisi à l'invite,
# en aveugle, et confirmé — une faute de frappe verrouillerait la console.
#
# Usage :  proxyfibre-admin-passwd [utilisateur]     (défaut : admin)
set -euo pipefail

USER_ADM="${1:-admin}"
DB=radius

[ "$(id -u)" = "0" ] || { echo "ERREUR: à lancer avec sudo." >&2; exit 1; }
printf '%s' "$USER_ADM" | grep -Eq '^[A-Za-z0-9._-]+$' || { echo "ERREUR: nom d'utilisateur invalide." >&2; exit 2; }

existe=$(mysql -N -B "$DB" -e "SELECT COUNT(*) FROM pf_admins WHERE username='${USER_ADM}';" 2>/dev/null || echo 0)
if [ "${existe:-0}" = "0" ]; then
    echo "ERREUR: le compte « ${USER_ADM} » n'existe pas dans la console." >&2
    echo "Comptes existants :" >&2
    mysql -N -B "$DB" -e "SELECT username FROM pf_admins ORDER BY username;" 2>/dev/null | sed 's/^/  - /' >&2
    exit 3
fi

echo "Réinitialisation du mot de passe de « ${USER_ADM} » (console d'administration)."
echo "La saisie n'est pas affichée."

read -r -s -p "Nouveau mot de passe      : " p1; echo
read -r -s -p "Confirmation              : " p2; echo

[ "$p1" = "$p2" ] || { echo "ERREUR: les deux saisies diffèrent — rien n'a été modifié." >&2; exit 4; }

# Longueur minimale volontairement stricte : ce compte donne accès à l'annuaire,
# aux journaux de navigation et à la configuration réseau du commissariat.
if [ "${#p1}" -lt 12 ]; then
    echo "ERREUR: 12 caractères minimum (ce compte ouvre l'annuaire, les journaux et le réseau)." >&2
    exit 5
fi

# Le condensat est calculé par PHP, avec le MÊME algorithme que la console —
# calculer autrement produirait un condensat que « password_verify » refuserait,
# et le compte serait inutilisable sans que rien n'explique pourquoi.
hash=$(PF_AP="$p1" php -r "echo password_hash(getenv('PF_AP'), PASSWORD_DEFAULT);")
unset p1 p2

case "$hash" in
    '$2y$'*) : ;;
    *) echo "ERREUR: condensat inattendu — rien n'a été modifié." >&2; exit 6 ;;
esac

mysql "$DB" -e "UPDATE pf_admins SET password_hash='${hash}' WHERE username='${USER_ADM}';"

# ── LE FICHIER D'INSTALLATION DEVIENT MENSONGER ──────────────────────────────
# /etc/proxyfibre/admin-pass.env contient le mot de passe d'origine EN CLAIR. Une
# fois le mot de passe changé, ce fichier ne décrit plus rien — mais il continue
# de ressembler à un identifiant valide pour qui le lit. On le marque plutôt que
# de le laisser tromper le prochain administrateur.
ENVF=/etc/proxyfibre/admin-pass.env
if [ -f "$ENVF" ] && [ "$USER_ADM" = "admin" ]; then
    if ! grep -q '^# PERIME' "$ENVF" 2>/dev/null; then
        sed -i '1i # PERIME : le mot de passe a ete change depuis la console ou par proxyfibre-admin-passwd.\n# La valeur ci-dessous est celle de l INSTALLATION et n est plus valide.' "$ENVF"
    fi
fi

echo "Mot de passe de « ${USER_ADM} » mis à jour."
echo "Le déploiement ne le réécrira pas : il ne touche plus à un compte existant."
logger -t bastion-admin "mot de passe console reinitialise pour ${USER_ADM}" 2>/dev/null || true
