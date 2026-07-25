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
        echo "→ Téléchargement de l'image Debian netinst…"
        curl -fL --progress-bar -o "$SRC" \
            "https://cdimage.debian.org/debian-cd/current/amd64/iso-cd/debian-12.11.0-amd64-netinst.iso" \
            || { echo "ERREUR : téléchargement impossible. Fournissez l'ISO en argument."; exit 1; }
    fi
fi
[ -f "$SRC" ] || { echo "ERREUR : image introuvable ($SRC)"; exit 1; }

TRAV=$(mktemp -d); trap 'rm -rf "$TRAV"' EXIT
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

# ── Fabrication ─────────────────────────────────────────────────────────────
echo "→ Fabrication de l'image…"
cd "$TRAV/iso"
xorriso -as mkisofs -r -V "BASTION" -o "$SORTIE" \
    -isohybrid-mbr /usr/lib/ISOLINUX/isohdpfx.bin \
    -c isolinux/boot.cat -b isolinux/isolinux.bin \
    -no-emul-boot -boot-load-size 4 -boot-info-table \
    -eltorito-alt-boot -e boot/grub/efi.img -no-emul-boot \
    -isohybrid-gpt-basdat . >/dev/null 2>&1 \
  || xorriso -as mkisofs -r -V "BASTION" -o "$SORTIE" \
       -c isolinux/boot.cat -b isolinux/isolinux.bin \
       -no-emul-boot -boot-load-size 4 -boot-info-table . >/dev/null 2>&1
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
