#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# ─────────────────────────────────────────────────────────────────────────────
# Bastion — écran de démarrage graphique (splash Plymouth).
#
# Installe un thème Plymouth « Bastion » (fond marine + logo + « BASTION » + respiration)
# affiché pendant le démarrage ET l'arrêt du serveur, sur la console (fenêtre VirtualBox ou
# écran physique). N'AFFECTE PAS la sécurité — c'est du marquage produit.
#
# Usage : sudo ./setup-splash.sh            applique le splash
#         sudo ./setup-splash.sh --defaire  retire le splash (revient au boot silencieux)
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "À lancer en root : sudo ./setup-splash.sh" >&2; exit 1; }

THEME=bastion
DIR=/usr/share/plymouth/themes/$THEME
GRUB=/etc/default/grub
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Retrait : on enlève 'splash' de GRUB (le thème peut rester, il ne s'affiche plus). ──
if [ "${1:-}" = "--defaire" ]; then
  sed -i -E 's/ *\bsplash\b//g' "$GRUB" 2>/dev/null || true
  update-grub >/dev/null 2>&1 || true
  echo "Splash retiré — le prochain démarrage sera silencieux."
  exit 0
fi

echo "== Bastion — installation de l'écran de démarrage =="

# ── Dépendances (déjà présentes sur une passerelle Bastion, install au cas où). ──
export DEBIAN_FRONTEND=noninteractive
need=""
command -v plymouthd >/dev/null 2>&1 || need="$need plymouth plymouth-themes"
command -v convert   >/dev/null 2>&1 || need="$need imagemagick"
[ -f /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf ] || need="$need fonts-dejavu-core"
if [ -n "$need" ]; then
  echo "Installation de :$need"
  apt-get update -qq && apt-get install -y --no-install-recommends $need >/dev/null
fi

# ── Logo source : le PNG de marque déjà présent (dépôt ou webroot). ──
LOGO_SRC=""
for c in "$SCRIPT_DIR/../portal/assets/icon-512.png" /var/www/html/portal/assets/icon-512.png \
         "$SCRIPT_DIR/../portal/assets/icon-192.png"; do
  [ -f "$c" ] && { LOGO_SRC="$c"; break; }
done

mkdir -p "$DIR"

# Logo (redimensionné) + wordmark généré (BASTION + sous-titre) sur fond transparent.
if [ -n "$LOGO_SRC" ]; then
  convert "$LOGO_SRC" -resize 240x240 "$DIR/logo.png"
else
  # Pas de logo trouvé : on fabrique un bouclier simple.
  convert -size 240x240 xc:none -fill '#38bdf8' -draw "roundrectangle 40,20 200,220 30,30" "$DIR/logo.png"
fi
FONT=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf
FONTR=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf
[ -f "$FONT" ]  || FONT=DejaVu-Sans-Bold
[ -f "$FONTR" ] || FONTR=DejaVu-Sans
convert -background none -gravity center \
  \( -fill '#e2e8f0' -font "$FONT"  -pointsize 60 label:'BASTION' \) \
  \( -fill '#7dd3fc' -font "$FONTR" -pointsize 24 label:'Passerelle securisee' \) \
  -append "$DIR/word.png"

# ── Thème Plymouth (module « script »). ──
cat > "$DIR/$THEME.plymouth" <<EOF
[Plymouth Theme]
Name=Bastion
Description=Ecran de demarrage Bastion
ModuleName=script

[script]
ImageDir=$DIR
ScriptFile=$DIR/$THEME.script
EOF

cat > "$DIR/$THEME.script" <<'EOF'
# Fond marine Bastion (léger dégradé).
Window.SetBackgroundTopColor(0.043, 0.067, 0.125);
Window.SetBackgroundBottomColor(0.024, 0.039, 0.078);

logo.image  = Image("logo.png");
logo.sprite = Sprite(logo.image);
word.image  = Image("word.png");
word.sprite = Sprite(word.image);

fun place() {
    W = Window.GetWidth(); H = Window.GetHeight();
    logo.sprite.SetX(W / 2 - logo.image.GetWidth()  / 2);
    logo.sprite.SetY(H / 2 - logo.image.GetHeight() / 2 - 70);
    word.sprite.SetX(W / 2 - word.image.GetWidth()  / 2);
    word.sprite.SetY(H / 2 + logo.image.GetHeight() / 2 - 40);
}
place();

# Le logo « respire » doucement pendant le démarrage.
tick = 0;
fun refresh() {
    tick++;
    logo.sprite.SetOpacity(0.75 + 0.25 * Math.Sin(tick / 22));
}
Plymouth.SetRefreshFunction(refresh);

# Message (ex. invite de phrase secrète LUKS) affiché sous le titre.
msg.sprite = Sprite();
fun display_message(text) {
    msg.image = Image.Text(text, 0.83, 0.87, 0.92);
    msg.sprite.SetImage(msg.image);
    msg.sprite.SetX(Window.GetWidth() / 2 - msg.image.GetWidth() / 2);
    msg.sprite.SetY(Window.GetHeight() * 0.72);
}
fun hide_message(text) { msg.sprite.SetImage(Image.Text("", 0, 0, 0)); }
Plymouth.SetDisplayMessageFunction(display_message);
Plymouth.SetHideMessageFunction(hide_message);
EOF

chmod 644 "$DIR"/*

# ── Activer le thème (reconstruit l'initramfs) + ajouter « splash » au démarrage. ──
plymouth-set-default-theme -R "$THEME" >/dev/null 2>&1 || plymouth-set-default-theme "$THEME"

cp "$GRUB" "$GRUB.bak-splash" 2>/dev/null || true
if ! grep -qE '^GRUB_CMDLINE_LINUX_DEFAULT=.*\bsplash\b' "$GRUB"; then
  sed -i -E 's/^(GRUB_CMDLINE_LINUX_DEFAULT="[^"]*)"/\1 splash"/' "$GRUB"
fi
# Garder le framebuffer pour que Plymouth ait une surface graphique jusqu'au bout.
grep -q '^GRUB_GFXPAYLOAD_LINUX=' "$GRUB" || echo 'GRUB_GFXPAYLOAD_LINUX=keep' >> "$GRUB"
update-grub >/dev/null 2>&1 || true

echo
echo "✅ Écran de démarrage « Bastion » installé et activé."
echo "   Il s'affichera au PROCHAIN redémarrage (console/fenêtre VirtualBox)."
echo "   Aperçu immédiat (si un écran est branché) : sudo plymouthd ; sudo plymouth --show-splash ; sleep 4 ; sudo plymouth --quit"
echo "   Retrait : sudo ./setup-splash.sh --defaire"
