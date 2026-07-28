#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Fabrique l'ISO d'installation MUETTE de Bastion : Debian netinst + préréglage + amorçage
# automatique. Le poste installe Debian sur un disque CHIFFRÉ, puis déploie Bastion depuis
# le dépôt Git, sans aucune question.
#
# ── OÙ SONT LES SECRETS ──────────────────────────────────────────────────────
# Ils ne sont PAS dans le dépôt. Ils vivent dans « iso-secrets.env », à côté de ce script,
# ignoré par Git (voir .gitignore). Ce script le crée au premier lancement avec les valeurs
# retenues pour ce commissariat, et vous pouvez l'éditer ensuite.
#
# L'ISO FABRIQUÉE, ELLE, CONTIENT CES SECRETS EN CLAIR : c'est la contrepartie inévitable
# d'une installation sans aucune saisie. Conservez-la comme une clé.
#
# Usage : ./build-iso.sh [chemin/vers/debian-netinst.iso]
set -euo pipefail

ICI=$(cd "$(dirname "$0")" && pwd)
SECRETS="$ICI/iso-secrets.env"
SORTIE="$ICI/bastion-installation.iso"
DEPOT_DEFAUT="https://github.com/sdanessonne/bastion.git"

# ── Secrets (hors dépôt) ────────────────────────────────────────────────────
if [ ! -f "$SECRETS" ]; then
    echo
    echo "  Première fabrication : les secrets sont demandés une seule fois, puis conservés"
    echo "  dans $SECRETS (permissions 600, HORS DÉPÔT — ils ne partiront jamais sur Git)."
    echo
    printf "  Mot de passe du compte BASTION : "; read -rs _mdp; echo
    [ -n "$_mdp" ] || { echo "  ERREUR : mot de passe vide."; exit 1; }
    printf "  Phrase secrète du disque chiffré (Entrée = identique) : "; read -rs _luks; echo
    [ -n "$_luks" ] || _luks="$_mdp"
    printf "  Dépôt Git [%s] : " "$DEPOT_DEFAUT"; read -r _dep
    [ -n "$_dep" ] || _dep="$DEPOT_DEFAUT"
    umask 077
    {   echo "# Bastion — secrets de fabrication de l'ISO. FICHIER NON VERSIONNÉ."
        echo "# Conservez-le en lieu sûr ; supprimez-le si vous n'en avez plus besoin."
        printf 'MDP_BASTION=%q\n' "$_mdp"
        printf 'MDP_ROOT=%q\n'    "$_mdp"
        printf 'LUKS=%q\n'        "$_luks"
        printf 'DEPOT=%q\n'       "$_dep"
    } > "$SECRETS"
    chmod 600 "$SECRETS"
    unset _mdp _luks _dep
    echo "  → Secrets enregistrés."
fi
# shellcheck disable=SC1090
. "$SECRETS"
: "${MDP_BASTION:?mot de passe du compte BASTION manquant}"
: "${LUKS:?phrase secrète du disque manquante}"
DEPOT="${DEPOT:-$DEPOT_DEFAUT}"

# ── Outils ──────────────────────────────────────────────────────────────────
for o in xorriso cpio gzip; do
    command -v "$o" >/dev/null || { echo "ERREUR : « $o » manquant. Installez : sudo apt install xorriso cpio"; exit 1; }
done

# ── Image Debian de départ ──────────────────────────────────────────────────
SRC="${1:-}"
if [ -z "$SRC" ]; then
    SRC="$ICI/debian-netinst.iso"
    if [ ! -f "$SRC" ]; then
        # Le NOM du fichier contient le numéro de version, qui change à chaque publication :
        # le figer garantit un 404 tôt ou tard — c'est arrivé dès la première fabrication.
        # On le découvre donc dans le fichier de sommes de contrôle, dont le nom est stable.
        BASE="https://cdimage.debian.org/debian-cd/current/amd64/iso-cd"
        echo "→ Recherche de l'image Debian netinst la plus récente…"
        SUMS=$(curl -fsSL "$BASE/SHA256SUMS" 2>/dev/null) || SUMS=""
        NOM=$(printf '%s\n' "$SUMS" | awk '$2 ~ /netinst\.iso$/ {print $2; exit}')
        [ -n "$NOM" ] || { echo "ERREUR : image Debian introuvable en ligne. Fournissez l'ISO en argument."; exit 1; }
        echo "   $NOM"
        curl -fL --progress-bar -o "$SRC" "$BASE/$NOM" \
            || { echo "ERREUR : téléchargement impossible. Fournissez l'ISO en argument."; exit 1; }
        # Intégrité : on ne fabrique pas un support d'installation système à partir d'un
        # fichier non contrôlé.
        ATT=$(printf '%s\n' "$SUMS" | awk -v n="$NOM" '$2==n {print $1}')
        if [ -n "$ATT" ]; then
            OBT=$(sha256sum "$SRC" | awk '{print $1}')
            [ "$ATT" = "$OBT" ] || { echo "ERREUR : somme de contrôle incorrecte — image corrompue ou altérée."; rm -f "$SRC"; exit 1; }
            echo "   somme de contrôle vérifiée."
        fi
    fi
fi
[ -f "$SRC" ] || { echo "ERREUR : image introuvable ($SRC)"; exit 1; }

# ── Répertoire de travail ───────────────────────────────────────────────────
# PAS /tmp par défaut : fabriquer une image COMPLÈTE demande de la place pour
# l'arborescence extraite, les médias embarqués et l'image finale — près de 20 Go
# quand la source Windows est incluse. Sur cette passerelle, / n'a que 8 Go libres
# alors que /srv/pxe en a 31. On choisit donc le point de montage le plus large,
# sauf indication contraire par la variable TRAVAIL.
if [ -z "${TRAVAIL:-}" ]; then
    TRAVAIL=/tmp
    for c in /srv/pxe /var/tmp /tmp; do
        [ -d "$c" ] || continue
        libre=$(df -B1 --output=avail "$c" 2>/dev/null | tail -1)
        actuel=$(df -B1 --output=avail "$TRAVAIL" 2>/dev/null | tail -1)
        [ "${libre:-0}" -gt "${actuel:-0}" ] && TRAVAIL="$c"
    done
fi
mkdir -p "$TRAVAIL"
echo "→ Répertoire de travail : $TRAVAIL ($(df -h --output=avail "$TRAVAIL" | tail -1 | tr -d ' ') libres)"
TRAV=$(mktemp -d -p "$TRAVAIL"); trap 'rm -rf "$TRAV"' EXIT
echo "→ Extraction de l'image…"
xorriso -osirrox on -indev "$SRC" -extract / "$TRAV/iso" >/dev/null 2>&1
chmod -R u+w "$TRAV/iso"

# ── Préréglage, avec les secrets injectés ───────────────────────────────────
echo "→ Injection du préréglage…"
esc() { printf '%s' "$1" | sed -e 's/[\/&|]/\\&/g'; }
sed -e "s|__MDP_BASTION__|$(esc "$MDP_BASTION")|g" \
    -e "s|__MDP_ROOT__|$(esc "${MDP_ROOT:-$MDP_BASTION}")|g" \
    -e "s|__LUKS__|$(esc "$LUKS")|g" \
    -e "s|__DEPOT__|$(esc "$DEPOT")|g" \
    "$ICI/preseed.cfg" > "$TRAV/preseed.cfg"

# Le préréglage est glissé DANS l'initrd de l'installateur : c'est la seule méthode qui
# fonctionne sans aucune question, y compris avant la configuration réseau.
echo "→ Insertion dans l'initrd…"
mkdir -p "$TRAV/ird" && cd "$TRAV/ird"
gzip -d < "$TRAV/iso/install.amd/initrd.gz" | cpio -id --quiet
cp "$TRAV/preseed.cfg" preseed.cfg
find . | cpio -o -H newc --quiet | gzip -9 > "$TRAV/iso/install.amd/initrd.gz"
cd "$ICI"

# ── Menu d'amorçage : démarrage automatique, sans intervention ──────────────
echo "→ Configuration de l'amorçage automatique…"
cat > "$TRAV/iso/isolinux/txt.cfg" <<'EOF'
default bastion
label bastion
	menu label ^Installer Bastion (automatique)
	kernel /install.amd/vmlinuz
	append auto=true priority=critical vga=788 initrd=/install.amd/initrd.gz preseed/file=/preseed.cfg --- quiet
EOF
sed -i 's/^timeout .*/timeout 30/' "$TRAV/iso/isolinux/isolinux.cfg" 2>/dev/null || true
# Même chose pour l'amorçage UEFI (GRUB).
if [ -f "$TRAV/iso/boot/grub/grub.cfg" ]; then
    cat > "$TRAV/iso/boot/grub/grub.cfg" <<'EOF'
set default=0
set timeout=3
menuentry "Installer Bastion (automatique)" {
    linux /install.amd/vmlinuz auto=true priority=critical preseed/file=/preseed.cfg --- quiet
    initrd /install.amd/initrd.gz
}
EOF
fi

# ── Médias embarqués : l'image « complète » ─────────────────────────────────
# Par défaut Bastion ne fournit QUE son propre logiciel : la source d'installation
# Windows appartient au service, elle n'a pas à être redistribuée. Mais pour un pack
# remis clé en main à un commissariat qui dispose de sa licence en volume, tout
# réunir sur un seul support évite une manipulation et une clé à ne pas oublier.
#
# Les fichiers présents sont copiés dans /bastion/ de l'image, et récupérés PENDANT
# l'installation (voir copier.sh ci-dessous), avant même le premier démarrage.
#   Par défaut on n'embarque QUE la source Windows : c'est elle qui rend le parc
#   déployable, et elle seule justifie le poids. Y ajouter l'image Ubuntu ferait
#   passer le support de 9 à 15 Go pour un amorçage secondaire, et demanderait
#   30 Go d'espace de travail. Pour tout embarquer :
#       MEDIAS="/srv/pxe/iso/win11.iso /srv/pxe/iso/ubuntu.iso /srv/pxe/images/master.wim" ./build-iso.sh
#   Pour n'embarquer RIEN (image légère, médias apportés sur une clé à part) :
#       MEDIAS=" " ./build-iso.sh
COMPLET=0
mkdir -p "$TRAV/iso/bastion"
for src in "${MEDIAS-/srv/pxe/iso/win11.iso}"; do
    for f in $src; do
        [ -f "$f" ] || continue
        echo "→ Média embarqué : $(basename "$f") ($(du -h "$f" | cut -f1))"
        cp "$f" "$TRAV/iso/bastion/$(basename "$f")"
        COMPLET=1
    done
done

if [ "$COMPLET" = 1 ]; then
    # Récupération PENDANT l'installation : le support est encore monté sur /cdrom,
    # et le système cible est accessible sous /target. Au premier démarrage le
    # support pourrait déjà avoir été retiré — on ne compte donc pas dessus.
    # Ce script est appelé par le préréglage ; l'écrire ici plutôt que dans le
    # préréglage évite d'y empiler des guillemets et des échappements fragiles.
    cat > "$TRAV/iso/bastion/copier.sh" <<'CPEOF'
#!/bin/sh
# Recopie les médias du support d'installation vers le système en cours d'installation.
# Sans effet — et sans erreur — si le support n'en contient pas.
set -u
SRC=/cdrom/bastion
[ -d "$SRC" ] || SRC=/media/bastion
[ -d "$SRC" ] || exit 0
mkdir -p /target/srv/pxe/iso /target/srv/pxe/images
for n in win11.iso ubuntu.iso; do
    [ -f "$SRC/$n" ] || continue
    cp "$SRC/$n" "/target/srv/pxe/iso/$n.part" && mv "/target/srv/pxe/iso/$n.part" "/target/srv/pxe/iso/$n"
done
if [ -f "$SRC/master.wim" ]; then
    cp "$SRC/master.wim" /target/srv/pxe/images/master.wim.part \
      && mv /target/srv/pxe/images/master.wim.part /target/srv/pxe/images/master.wim
fi
exit 0
CPEOF
    chmod +x "$TRAV/iso/bastion/copier.sh"
else
    rmdir "$TRAV/iso/bastion" 2>/dev/null || true
fi

# ── Fabrication ─────────────────────────────────────────────────────────────
# « -iso-level 3 » est INDISPENSABLE dès qu'un fichier dépasse 4 Go : l'ISO 9660 de
# niveau 1 ou 2 ne sait pas le représenter, et il serait tronqué SANS ERREUR. La
# source Windows en fait près de 8. Le niveau 3 le découpe en plusieurs extents, que
# Linux — donc l'installateur Debian, seul lecteur de cette image — recolle.
#
# PAS de « -udf » : l'émulation mkisofs de xorriso ne connaît pas cette option et
# refuse tout net (« Unsupported option '-udf' »). Elle n'apporterait rien ici.
echo "→ Fabrication de l'image…"
cd "$TRAV/iso"
# Les erreurs de xorriso ne sont PAS avalées : elles l'étaient, et une fabrication
# ratée ne laissait qu'un journal s'arrêtant sur « Fabrication de l'image… », sans
# la moindre explication. On garde la sortie, on ne masque que le bavardage normal.
JRN="$TRAV/xorriso.log"
if ! xorriso -as mkisofs -r -V "BASTION" -o "$SORTIE" \
        -iso-level 3 \
        -isohybrid-mbr /usr/lib/ISOLINUX/isohdpfx.bin \
        -c isolinux/boot.cat -b isolinux/isolinux.bin \
        -no-emul-boot -boot-load-size 4 -boot-info-table \
        -eltorito-alt-boot -e boot/grub/efi.img -no-emul-boot \
        -isohybrid-gpt-basdat . > "$JRN" 2>&1; then
    echo "  L'amorçage hybride a échoué, seconde tentative sans lui :"
    grep -iE "FAILURE|SORRY|aborting" "$JRN" | head -5 | sed 's/^/    /'
    if ! xorriso -as mkisofs -r -V "BASTION" -o "$SORTIE" \
            -iso-level 3 \
            -c isolinux/boot.cat -b isolinux/isolinux.bin \
            -no-emul-boot -boot-load-size 4 -boot-info-table . > "$JRN" 2>&1; then
        echo
        echo "  ERREUR : fabrication de l'image impossible."
        grep -iE "FAILURE|SORRY|aborting|No such" "$JRN" | head -10 | sed 's/^/    /'
        cp "$JRN" "$ICI/xorriso-echec.log" 2>/dev/null && \
            echo "    Journal complet : $ICI/xorriso-echec.log"
        exit 1
    fi
fi
[ -f "$SORTIE" ] || { echo "  ERREUR : aucune image produite."; exit 1; }

# CONTRÔLE : le fichier le plus gros de l'image est-il ressorti INTACT ? Un dépassement
# de la limite ISO 9660 tronque sans rien dire ; on le vérifie plutôt que d'y croire.
if [ "$COMPLET" = 1 ]; then
    echo "→ Contrôle des médias embarqués…"
    # « -lsl » donne la taille réelle en 5e champ. Un premier jet lisait « report_lba »
    # et en tirait un chiffre sans rapport : le contrôle criait à la troncature sur une
    # image parfaitement saine. Un contrôle qui se trompe est pire que pas de contrôle.
    for f in "$TRAV/iso/bastion/"*.iso "$TRAV/iso/bastion/"*.wim; do
        [ -f "$f" ] || continue
        n=$(basename "$f"); att=$(stat -c%s "$f")
        obt=$(xorriso -indev "$SORTIE" -cd /bastion -lsl -- 2>/dev/null \
              | awk -v n="'$n'" '$9==n {print $5; exit}')
        obt=${obt:-0}
        if [ "$obt" != "$att" ]; then
            echo "  ERREUR : $n fait $obt octets dans l'image au lieu de $att."
            echo "  L'image est inutilisable — elle n'est pas conservée."
            rm -f "$SORTIE"; exit 1
        fi
        echo "   $n : $att octets, intact."
    done
fi
cd "$ICI"

echo
echo "  ✔ Image prête : $SORTIE"
echo "    $(du -h "$SORTIE" | cut -f1)"
echo
echo "  ATTENTION : cette image contient le mot de passe du compte BASTION et la phrase"
echo "  secrète du disque, en clair. C'est le prix d'une installation sans aucune saisie."
echo "  Conservez-la comme une clé : coffre ou armoire forte, jamais un partage ouvert."
echo
echo "  Écriture sur clé USB :  sudo dd if=$SORTIE of=/dev/sdX bs=4M status=progress conv=fsync"
