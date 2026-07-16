# iPXE — binaire `undionly.kpxe` (avec fond graphique)

Le fichier `undionly.kpxe` de ce dossier est un binaire **iPXE recompilé** pour prendre en
charge l'**image de fond** du menu PXE (`console --picture`). Le binaire iPXE standard des
distributions ne l'inclut pas : pour les plateformes BIOS, iPXE fait `#undef CONSOLE_CMD`
(la commande `console` est retirée) et le support framebuffer/PNG n'est pas activé.

## Fonctions activées (par rapport au build par défaut)

- `CONSOLE_CMD` — réactive la commande `console` (désactivée sur BIOS par défaut) → permet
  `console --picture …`.
- `CONSOLE_FRAMEBUFFER` — console graphique VESA (fbcon/vesafb) nécessaire à l'affichage image.
- `IMAGE_PNG` — décodage des images PNG (déjà par défaut, conservé explicitement).
- `#define KEYBOARD_MAP fr` (dans `config/local/console.h`) — clavier **AZERTY** par défaut.
  ⚠️ La variable make `KEYMAP=fr` N'EST PAS utilisée par le Makefile iPXE (piège). Le vrai
  réglage est `KEYBOARD_MAP` : `config.c` fait `REQUIRE_KEYMAP(KEYBOARD_MAP)`, donc seul ce
  keymap est lié et devient le clavier actif (le défaut interne reste `us` sinon).

Un script prêt à l'emploi reproduit exactement ce binaire : **`build-ipxe.sh`** (dans ce dossier).

## Reproduire le binaire

```sh
sudo apt-get install -y build-essential liblzma-dev git
git clone --depth 1 https://github.com/ipxe/ipxe.git
cd ipxe/src
mkdir -p config/local
printf '#define IMAGE_PNG\n#define CONSOLE_CMD\n' > config/local/general.h
printf '#define CONSOLE_FRAMEBUFFER\n#undef KEYBOARD_MAP\n#define KEYBOARD_MAP fr\n' > config/local/console.h
cat > embed.ipxe <<'EOF'
#!ipxe
dhcp || echo DHCP failed
chain http://${next-server}:2080/boot/boot.ipxe || shell
EOF
make bin/undionly.kpxe EMBED=embed.ipxe
# → bin/undionly.kpxe  (à copier dans /srv/tftp et dans services/tftp/ du dépôt)
```

## Chaîne d'amorçage

1. Le client BIOS PXE reçoit `undionly.kpxe` (dnsmasq, tag `bios`).
2. Le script embarqué relance un DHCP (userclass `iPXE`) puis `chain` vers
   `http://<passerelle>:2080/boot/boot.ipxe`.
3. `boot.ipxe` pose le fond (`console --picture …/boot/menu-bg.png`) puis charge `menu.php`.

## Contraintes de l'image de fond

- Format **PNG 24 bits, 8 bits/canal, non entrelacé** (le décodeur iPXE ne gère pas le 16 bits
  ni l'entrelacement).
- Résolution conseillée **1024×768** (mode VESA standard). Voir `services/tftp/menu-bg.png`.
