#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Bibliothèque d'images master (/srv/pxe/images) : lister, mesurer la place, supprimer.
#
# La suppression est DÉFINITIVE et porte sur des fichiers de plusieurs dizaines de gigaoctets :
# les garde-fous sont donc volontairement stricts et NON contournables depuis la console —
#   * le nom est réduit à son dernier segment (aucune traversée de répertoire possible) ;
#   * seule une EXTENSION D'IMAGE reconnue est acceptée ;
#   * les fichiers de réponse « unattend-*.xml » sont protégés : ce ne sont pas des images, et
#     les supprimer casserait l'installation automatisée de Windows ;
#   * on vérifie que la cible est bien un fichier ordinaire du dossier (jamais un lien).
#
# Usage : image-ctl.sh list | space | delete <nom>

set -eu
DIR=/srv/pxe/images
action="${1:-}"; name="${2:-}"

# Extensions considérées comme des images de déploiement.
is_image() {
  case "$(printf '%s' "$1" | tr 'A-Z' 'a-z')" in
    *.wim|*.swm|*.esd|*.iso|*.img|*.vhd|*.vhdx|*.gho|*.tib) return 0 ;;
    *) return 1 ;;
  esac
}

case "$action" in
  list)
    [ -d "$DIR" ] || exit 0
    # nom TAB octets TAB date de modification (epoch)
    for f in "$DIR"/*; do
      [ -f "$f" ] || continue
      b=$(basename "$f")
      is_image "$b" || continue
      printf '%s\t%s\t%s\n' "$b" "$(stat -c %s "$f" 2>/dev/null || echo 0)" "$(stat -c %Y "$f" 2>/dev/null || echo 0)"
    done ;;

  space)
    # total TAB disponible (octets) du volume qui porte la bibliothèque
    df -B1 --output=size,avail "$DIR" 2>/dev/null | awk 'NR==2{printf "%s\t%s\n", $1, $2}' ;;

  delete)
    # Dernier segment uniquement : « ../../etc/passwd » devient « passwd », donc introuvable ici.
    base=$(basename -- "$name")
    case "$base" in
      ''|'.'|'..') echo "ERROR: nom invalide" >&2; exit 2 ;;
    esac
    # Ceinture : aucun séparateur ne doit subsister.
    case "$base" in
      */*|*'\'*) echo "ERROR: nom invalide" >&2; exit 2 ;;
    esac
    is_image "$base" || { echo "ERROR: ce fichier n'est pas une image master" >&2; exit 3; }
    case "$(printf '%s' "$base" | tr 'A-Z' 'a-z')" in
      unattend-*) echo "ERROR: fichier de reponse protege" >&2; exit 3 ;;
    esac
    target="$DIR/$base"
    [ -f "$target" ] || { echo "ERROR: image introuvable" >&2; exit 1; }
    [ -L "$target" ] && { echo "ERROR: lien symbolique refuse" >&2; exit 3; }
    sz=$(stat -c %s "$target" 2>/dev/null || echo 0)
    rm -f -- "$target" || { echo "ERROR: suppression impossible" >&2; exit 1; }
    echo "image $base supprimee ($sz octets liberes)" ;;

  *) echo "usage: image-ctl.sh list|space|delete <nom>" >&2; exit 2 ;;
esac
