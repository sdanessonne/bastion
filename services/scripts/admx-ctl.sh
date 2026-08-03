#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Modèles d'administration ADMX dans le magasin central du SYSVOL.
#
# ── À QUOI SERT LE MAGASIN CENTRAL ────────────────────────────────────────────
# Sans lui, chaque poste d'administration n'affiche que les modèles présents sur
# SA machine : un collègue qui ouvre l'éditeur de stratégies depuis un autre PC
# ne verrait pas les réglages Firefox, et pire — il pourrait en écraser sans le
# savoir. Le magasin central place les modèles DANS le domaine : tout le monde
# voit la même chose, depuis n'importe quel poste.
#
# Chemin : \\<domaine>\SYSVOL\<domaine>\Policies\PolicyDefinitions
#
# ── POURQUOI SEULEMENT DEUX LANGUES ───────────────────────────────────────────
# Le paquet Mozilla contient une trentaine de traductions. Le SYSVOL est
# RÉPLIQUÉ vers chaque contrôleur de domaine et parcouru à chaque ouverture de
# l'éditeur : y déposer vingt-huit langues que personne ne lira ralentit tout le
# monde pour rien. On garde le français et l'anglais — ce dernier parce que
# l'éditeur y retombe quand une chaîne manque en français.
#
# Usage :  admx-ctl.sh init                 crée le magasin central
#          admx-ctl.sh firefox [fichier.zip] installe les modèles Firefox
#          admx-ctl.sh list                 ce qui est installé
set -uo pipefail

[ "$(id -u)" = "0" ] || { echo "ERREUR : à lancer en root." >&2; exit 1; }

REALM=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
[ -n "$REALM" ] || { echo "ERREUR : domaine introuvable — Active Directory est-il provisionné ?" >&2; exit 2; }
STORE="/var/lib/samba/sysvol/${REALM}/Policies/PolicyDefinitions"

case "${1:-list}" in

init)
    install -d -m 755 "$STORE" "$STORE/fr-FR" "$STORE/en-US"
    echo "magasin central : $STORE"
    ;;

firefox)
    src="${2:-}"
    tmp=$(mktemp -d /tmp/admx.XXXXXX) || exit 3
    # Le répertoire temporaire est retiré quoi qu'il arrive : il contient une
    # archive de plusieurs mégaoctets et le script a plusieurs sorties d'erreur.
    trap 'rm -rf "$tmp"' EXIT

    if [ -n "$src" ]; then
        [ -r "$src" ] || { echo "ECHEC: archive illisible ($src)"; exit 4; }
        cp "$src" "$tmp/p.zip"
        echo "archive locale : $(basename "$src")"
    else
        # La version la plus récente est DÉCOUVERTE, jamais figée : un numéro en
        # dur garantit un 404 tôt ou tard — c'est déjà arrivé sur l'image Debian
        # de ce projet.
        echo "recherche de la dernière version…"
        api=$(curl -fsSL --max-time 30 https://api.github.com/repos/mozilla/policy-templates/releases/latest 2>/dev/null)
        url=$(printf '%s' "$api" | sed -n 's/.*"browser_download_url": *"\([^"]*policy_templates_v[^"]*\.zip\)".*/\1/p' | head -1)
        ver=$(printf '%s' "$api" | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p' | head -1)
        [ -n "$url" ] || { echo "ECHEC: version introuvable en ligne. Fournissez l'archive en argument."; exit 5; }
        echo "version $ver"
        # « -L » : sans lui on enregistre la page de redirection en croyant avoir
        # l'archive, et curl annonce 100 % — constaté sur ce projet.
        curl -fsSL --max-time 300 -o "$tmp/p.zip" "$url" || { echo "ECHEC: téléchargement impossible"; exit 6; }
    fi

    # On vérifie que c'est une ARCHIVE, pas une page d'erreur : « file » le dit
    # en une commande, et l'unzip échouerait plus loin avec un message obscur.
    file -b "$tmp/p.zip" | grep -qi 'zip' || { echo "ECHEC: le fichier reçu n'est pas une archive ZIP"; exit 7; }

    command -v unzip >/dev/null 2>&1 || { echo "ECHEC: unzip absent (apt install unzip)"; exit 8; }
    unzip -q -o "$tmp/p.zip" -d "$tmp/x" || { echo "ECHEC: archive illisible"; exit 9; }

    # L'archive contient un dossier « windows » avec les .admx et les langues.
    base=$(find "$tmp/x" -type f -name 'firefox.admx' -printf '%h\n' 2>/dev/null | head -1)
    [ -n "$base" ] || { echo "ECHEC: firefox.admx introuvable dans l'archive"; exit 10; }

    install -d -m 755 "$STORE" "$STORE/fr-FR" "$STORE/en-US"
    n=0
    for f in firefox.admx mozilla.admx; do
        [ -f "$base/$f" ] || continue
        install -m 644 "$base/$f" "$STORE/$f" && n=$((n+1))
    done
    [ "$n" -gt 0 ] || { echo "ECHEC: aucun modèle installé"; exit 11; }

    m=0
    for lang in fr-FR en-US; do
        for f in firefox.adml mozilla.adml; do
            [ -f "$base/$lang/$f" ] || continue
            install -m 644 "$base/$lang/$f" "$STORE/$lang/$f" && m=$((m+1))
        done
    done

    # ── LES DROITS DU SYSVOL ─────────────────────────────────────────────────
    # Des fichiers déposés à la main portent les droits de root, pas ceux que le
    # domaine attend. Les postes d'administration liraient alors un magasin
    # partiellement inaccessible — l'éditeur n'afficherait rien, sans dire
    # pourquoi. « sysvolreset » remet les ACL conformes.
    samba-tool ntacl sysvolreset >/dev/null 2>&1 \
        && echo "droits du SYSVOL rétablis" \
        || echo "ATTENTION: sysvolreset a échoué — vérifiez les droits du magasin"

    echo "OK: $n modèle(s) ADMX et $m traduction(s) installés dans $STORE"
    ;;

list)
    if [ ! -d "$STORE" ]; then
        echo '{"store":"","admx":0,"adml":0,"existe":false}'
        exit 0
    fi
    a=$(ls "$STORE"/*.admx 2>/dev/null | wc -l)
    l=$(find "$STORE" -name '*.adml' 2>/dev/null | wc -l)
    ff=false; [ -f "$STORE/firefox.admx" ] && ff=true
    printf '{"store":"%s","admx":%s,"adml":%s,"firefox":%s,"existe":true}\n' "$STORE" "$a" "$l" "$ff"
    ;;

*)
    echo "usage: admx-ctl.sh init | firefox [archive.zip] | list" >&2; exit 2 ;;
esac
