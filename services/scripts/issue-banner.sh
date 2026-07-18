#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Bannière de la console locale : génère /etc/issue, l'écran affiché AVANT le prompt de
# connexion sur les consoles physiques (tty1…tty6).
#
# ── POURQUOI CET ÉCRAN ──────────────────────────────────────────────────────
# Sans lui, un exploitant qui branche un écran sur la passerelle ne lit que
# « Debian GNU/Linux 13 dc tty1 ». Rien ne lui dit que c'est un Bastion, ni où joindre la
# console d'administration. Cet écran répond aux trois questions qu'il se pose en arrivant :
# quelle machine, à quelle adresse, et de qui elle relève.
#
# ── LES ADRESSES NE SONT PAS ÉCRITES EN DUR ─────────────────────────────────
# On pose les séquences « \4{interface} » d'agetty, qu'il substitue À CHAQUE affichage du
# prompt. Une adresse figée au démarrage mentirait dès qu'elle change (bail DHCP, câble
# déplacé) — et un exploitant qui tape une mauvaise URL cherchera la panne du mauvais côté.
#
# ── CE QU'ON N'AFFICHE PAS ──────────────────────────────────────────────────
# Cet écran est visible AVANT toute authentification. On y met donc ce qui aide
# l'exploitant (identité, adresses de service) et rien qui aide un attaquant : ni versions
# de composants, ni état des services, ni compte, ni volumétrie.
#
# Usage : proxyfibre-issue [--ascii]
#         --ascii : logo en ASCII strict, pour une console dont la police ne rend pas les
#                   caractères semi-graphiques (voir la note en fin de fichier).
set -eu

ASCII=0
[ "${1:-}" = "--ascii" ] && ASCII=1

# /etc/issue appartient à root. Sans ce contrôle, « set -e » ferait sortir le script sur un
# échec de mktemp, sans rien dire — et l'exploitant chercherait pourquoi son écran n'a pas
# changé.
if [ "$(id -u)" -ne 0 ]; then
    echo "proxyfibre-issue : à lancer en root (sudo)." >&2
    exit 1
fi

# Interfaces : posées par le provisioning dans un fichier lisible de tous (644).
LAN_IF="enp0s8"
WAN_IF="enp0s3"
# shellcheck disable=SC1091
[ -r /etc/proxyfibre/net.env ] && . /etc/proxyfibre/net.env

# Octet d'échappement RÉEL (0x1B). agetty ne traduit pas « \e » : écrire la séquence
# littéralement afficherait « \e[96m » à l'écran au lieu de colorer.
E=$(printf '\033')
BLEU="${E}[96m"      # cyan clair — le bleu de l'identité Bastion
BLANC="${E}[1;97m"
GRIS="${E}[90m"
JAUNE="${E}[93m"
NORM="${E}[0m"

TMP=$(mktemp /etc/issue.XXXXXX)
# Le fichier est lu par agetty, qui tourne en root : 644 suffit et reste lisible.
chmod 644 "$TMP"

if [ "$ASCII" -eq 1 ]; then
    # En repli, TOUT passe en ASCII : une police qui ne rend pas les blocs ne rendra pas
    # davantage les filets ni les puces. Un repli à moitié cassé ne sert à rien.
    SEP='--------------------------------------------------------------------'
    PUCE='-'
    COPY='(c)'
    TIRET='-'
    ECU_1=' ##  ##  ## '
    ECU_2=' ###########'
    ECU_3=' ####   ####'
    ECU_4=' ####   ####'
    ECU_5=' ####   ####'
    ECU_6='  #########'
    NOM_1='  ____    _    ____ _____ ___ ___  _   _ '
    NOM_2=' | __ )  / \  / ___|_   _|_ _/ _ \| \ | |'
    NOM_3=" |  _ \\ / _ \\ \\___ \\ | |  | | | | |  \\| |"
    NOM_4=' | |_) / ___ \ ___) || |  | | |_| | |\  |'
    NOM_5=' |____/_/   \_\____/ |_| |___\___/|_| \_|'
    NOM_6=''
else
    SEP='────────────────────────────────────────────────────────────────────'
    PUCE='·'
    COPY='©'
    TIRET='—'
    # Écu à créneaux, porte ouverte au centre — la marque de Bastion, en semi-graphique.
    # Six lignes, pas sept : la bannière doit tenir dans un écran de 25 lignes AVEC le
    # prompt, sinon elle défile et c'est le haut du logo qui sort de l'écran.
    ECU_1=' ██  ██  ██ '
    ECU_2=' ███████████'
    ECU_3=' ████   ████'
    ECU_4=' ████   ████'
    ECU_5=' ▀███   ███▀'
    ECU_6='  ▀███████▀ '
    NOM_1='██████╗  █████╗ ███████╗████████╗██╗ ██████╗ ███╗   ██╗'
    NOM_2='██╔══██╗██╔══██╗██╔════╝╚══██╔══╝██║██╔═══██╗████╗  ██║'
    NOM_3='██████╔╝███████║███████╗   ██║   ██║██║   ██║██╔██╗ ██║'
    NOM_4='██╔══██╗██╔══██║╚════██║   ██║   ██║██║   ██║██║╚██╗██║'
    NOM_5='██████╔╝██║  ██║███████║   ██║   ██║╚██████╔╝██║ ╚████║'
    NOM_6='╚═════╝ ╚═╝  ╚═╝╚══════╝   ╚═╝   ╚═╝ ╚═════╝ ╚═╝  ╚═══╝'
fi

{
printf '\n'
printf '  %s%s%s   %s%s%s\n' "$BLEU" "$ECU_1" "$NORM" "$BLANC" "$NOM_1" "$NORM"
printf '  %s%s%s   %s%s%s\n' "$BLEU" "$ECU_2" "$NORM" "$BLANC" "$NOM_2" "$NORM"
printf '  %s%s%s   %s%s%s\n' "$BLEU" "$ECU_3" "$NORM" "$BLANC" "$NOM_3" "$NORM"
printf '  %s%s%s   %s%s%s\n' "$BLEU" "$ECU_4" "$NORM" "$BLANC" "$NOM_4" "$NORM"
printf '  %s%s%s   %s%s%s\n' "$BLEU" "$ECU_5" "$NORM" "$BLANC" "$NOM_5" "$NORM"
printf '  %s%s%s   %s%s%s\n' "$BLEU" "$ECU_6" "$NORM" "$BLANC" "$NOM_6" "$NORM"
printf '\n'
printf '  %sContrôle d'\''accès au réseau %s Passerelle de sécurité%s\n' "$GRIS" "$PUCE" "$NORM"
printf '  %s%s%s\n' "$GRIS" "$SEP" "$NORM"
printf '   %sConsole d'\''administration%s   %shttps://\\4{%s}:8443%s\n' "$GRIS" "$NORM" "$BLEU" "$LAN_IF" "$NORM"
printf '   %sPortail captif%s              %shttp://\\4{%s}:2080%s\n'  "$GRIS" "$NORM" "$BLEU" "$LAN_IF" "$NORM"
printf '   %sMachine%s                     %s\\n%s   %s(\\l %s réseau \\4{%s})%s\n' \
       "$GRIS" "$NORM" "$BLANC" "$NORM" "$GRIS" "$PUCE" "$WAN_IF" "$NORM"
printf '  %s%s%s\n' "$GRIS" "$SEP" "$NORM"
printf '\n'
printf '  %s%s 2026 Mickaël MONESTIER (Mle 110.480) %s Tous droits réservés.%s\n' "$GRIS" "$COPY" "$TIRET" "$NORM"
printf '  %sAccès réservé aux personnes autorisées. Toute intrusion est passible%s\n' "$JAUNE" "$NORM"
printf '  %sde poursuites (art. 323-1 du Code pénal).%s\n' "$JAUNE" "$NORM"
printf '\n'
# Remise à zéro finale INDISPENSABLE : sans elle, le prompt « login: » et tout ce que
# l'exploitant tape ensuite héritent de la dernière couleur posée.
printf '%s' "$NORM"
} > "$TMP"

mv -f "$TMP" /etc/issue

# ── NOTE SUR LES CARACTÈRES SEMI-GRAPHIQUES ─────────────────────────────────
# Le logo utilise des blocs (█ ▀) et des filets (═ ║ ╔). La console Debian les rend
# correctement avec sa police par défaut en UTF-8. Si l'écran affiche des losanges ou des
# caractères accentués isolés, c'est la police de console qui est en cause, pas ce script :
#   dpkg-reconfigure console-setup      (jeu « Latin1/5 », police « Fixed » ou « Terminus »)
# ou, sans y toucher, régénérer la bannière en ASCII strict :
#   proxyfibre-issue --ascii
