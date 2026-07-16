#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Recompile iPXE (undionly.kpxe) avec : fond graphique PNG, console framebuffer,
# commande `console` (désactivée par défaut sur BIOS) et clavier FRANÇAIS (AZERTY).
# Produit /tmp/ipxe/src/bin/undionly.kpxe. Voir README-ipxe.md.
set -eu
apt-get install -y -qq build-essential liblzma-dev git >/dev/null 2>&1 || true
cd /tmp && rm -rf ipxe
git clone --depth 1 https://github.com/ipxe/ipxe.git
cd ipxe/src
mkdir -p config/local
# IMAGE_PNG (fond), CONSOLE_CMD (commande console pour --picture), PARAM_CMD (params/param,
# utilisés par menu.php pour l'envoi des paramètres). CONSOLE_CMD et PARAM_CMD sont
# désactivés par défaut sur BIOS (#undef dans config/general.h) → à réactiver.
printf '#define IMAGE_PNG\n#define CONSOLE_CMD\n#define PARAM_CMD\n' > config/local/general.h
# CONSOLE_FRAMEBUFFER : fond graphique. KEYBOARD_MAP fr : clavier AZERTY par défaut
# (config.c fait REQUIRE_KEYMAP(KEYBOARD_MAP) → seul ce keymap est lié → il devient le
# clavier actif. La variable make « KEYMAP= » n'est PAS utilisée par le Makefile iPXE).
printf '#define CONSOLE_FRAMEBUFFER\n#undef KEYBOARD_MAP\n#define KEYBOARD_MAP fr\n' > config/local/console.h
cat > embed.ipxe <<'EOF'
#!ipxe
dhcp || echo DHCP failed
chain http://${next-server}:2080/boot/boot.ipxe || shell
EOF
make -j"$(nproc)" bin/undionly.kpxe EMBED=embed.ipxe
echo "BUILD_DONE $(ls -la bin/undionly.kpxe | awk '{print $5}') octets"
