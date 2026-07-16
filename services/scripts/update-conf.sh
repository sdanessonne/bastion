#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Écrit la configuration du dépôt Git de Bastion (/etc/proxyfibre/update.env).
#
# Script DÉDIÉ plutôt qu'un « tee » ou un « sh -c » autorisé en sudo : ces derniers
# donneraient au serveur web le droit d'écrire N'IMPORTE QUEL fichier en root — une
# escalade de privilèges immédiate. Ici, seul ce fichier peut être écrit, et les
# valeurs sont validées avant.
#
# Les valeurs arrivent sur l'ENTRÉE STANDARD, une par ligne (url, branche, jeton) :
# passées en arguments, l'URL et surtout le JETON apparaîtraient dans « ps » et dans
# les journaux d'audit, visibles de tous les utilisateurs de la machine.
#
# Usage : printf '%s\n' "<url>" "<branche>" "<jeton>" | update-conf.sh
set -uo pipefail

CONF=/etc/proxyfibre/update.env

IFS= read -r url    || url=""
IFS= read -r branche || branche=""
IFS= read -r jeton  || jeton=""

# URL : uniquement https:// ou ssh git@…. On refuse « file:// » et les chemins locaux,
# qui permettraient de faire lire n'importe quel dépôt du disque au processus root.
if [ -n "$url" ] && ! printf '%s' "$url" | grep -qE '^(https://[A-Za-z0-9._~:/?#@!$&()*+,;=%-]+|git@[A-Za-z0-9._-]+:[A-Za-z0-9._/-]+)$'; then
    echo "ERREUR: URL invalide — attendu https://… ou git@hote:depot.git" >&2; exit 2
fi
# Branche : un nom Git ordinaire. Sans ce filtre, une valeur comme « ;rm -rf / » se
# retrouverait dans un fichier ensuite « sourcé » par le script de mise à jour.
if [ -n "$branche" ] && ! printf '%s' "$branche" | grep -qE '^[A-Za-z0-9._/-]{1,100}$'; then
    echo "ERREUR: nom de branche invalide" >&2; exit 2
fi
if [ -n "$jeton" ] && ! printf '%s' "$jeton" | grep -qE '^[A-Za-z0-9._-]{1,200}$'; then
    echo "ERREUR: jeton invalide (caractères inattendus)" >&2; exit 2
fi
[ -z "$branche" ] && branche=main

umask 077
{
    echo "# Engendré par la console Bastion — ne pas éditer à la main."
    printf 'GIT_REPO="%s"\n'   "$url"
    printf 'GIT_BRANCH="%s"\n' "$branche"
    printf 'GIT_TOKEN="%s"\n'  "$jeton"
} > "$CONF"
chmod 600 "$CONF"
echo "ok"
