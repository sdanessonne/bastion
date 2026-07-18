#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Démarrage aux couleurs de Bastion : menu d'amorçage masqué, démarrage silencieux, et
# aucune mention de la distribution sous-jacente sur les écrans que voit un utilisateur.
#
# ── CE QUE CE SCRIPT FAIT, ET CE QU'IL NE FAIT PAS ──────────────────────────
# Il empêche les écrans de démarrage et de connexion d'AFFICHER la distribution. C'est du
# marquage produit, pas une mesure de sécurité : quiconque obtient un interpréteur de
# commandes sur la machine la reconnaît en une commande. Le présenter autrement serait
# mentir sur ce qui est protégé.
#
# ── /etc/os-release N'EST PAS TOUCHÉ, ET C'EST DÉLIBÉRÉ ─────────────────────
# apt, les mises à jour de sécurité automatiques, le pilotage systemd et les scripts de
# Bastion lui-même lisent ce fichier. Le falsifier pour gagner une ligne cosmétique
# casserait la chaîne de mise à jour d'une passerelle de sécurité. Le jeu n'en vaut pas
# la chandelle.
#
# Usage : proxyfibre-brand            applique
#         proxyfibre-brand --defaire  revient à l'état d'origine (sauvegardes)
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "proxyfibre-brand : à lancer en root (sudo)." >&2
    exit 1
fi

GRUB_CONF=/etc/default/grub
SAUVE=/var/backups/proxyfibre-brand

# ── Retour arrière ───────────────────────────────────────────────────────────
if [ "${1:-}" = "--defaire" ]; then
    if [ -f "$SAUVE/grub" ]; then
        cp -f "$SAUVE/grub" "$GRUB_CONF"
        echo "Configuration d'amorçage restaurée."
        update-grub >/dev/null 2>&1 || update-grub2 >/dev/null 2>&1 || true
    else
        echo "Aucune sauvegarde trouvée dans $SAUVE." >&2
        exit 1
    fi
    [ -f "$SAUVE/motd" ] && cp -f "$SAUVE/motd" /etc/motd
    [ -f "$SAUVE/issue.net" ] && cp -f "$SAUVE/issue.net" /etc/issue.net
    echo "Terminé. Redémarrez pour retrouver le menu d'amorçage d'origine."
    exit 0
fi

mkdir -p "$SAUVE"
# Sauvegarde UNE SEULE FOIS : relancer le script ne doit pas écraser l'original par une
# version déjà modifiée — on perdrait le seul moyen de revenir en arrière.
[ -f "$SAUVE/grub" ]      || cp -f "$GRUB_CONF" "$SAUVE/grub"
[ -f "$SAUVE/motd" ]      || { [ -f /etc/motd ] && cp -f /etc/motd "$SAUVE/motd"; } || true
[ -f "$SAUVE/issue.net" ] || { [ -f /etc/issue.net ] && cp -f /etc/issue.net "$SAUVE/issue.net"; } || true

# ── Réglage idempotent d'une clé de /etc/default/grub ────────────────────────
# La clé peut être absente, présente, ou commentée : on couvre les trois cas, sinon un
# second passage empilerait des doublons et c'est la DERNIÈRE ligne qui gagnerait.
set_grub() {
    cle="$1"; val="$2"
    if grep -qE "^[#[:space:]]*${cle}=" "$GRUB_CONF"; then
        sed -i "s|^[#[:space:]]*${cle}=.*|${cle}=${val}|" "$GRUB_CONF"
    else
        printf '%s=%s\n' "$cle" "$val" >> "$GRUB_CONF"
    fi
}

# Nom affiché partout où GRUB nomme le système : entrées du menu, options avancées, mode
# de secours. Sans cela, le menu — s'il est appelé — annoncerait la distribution.
set_grub GRUB_DISTRIBUTOR '"Bastion"'

# Menu masqué et sans attente : la machine démarre directement.
set_grub GRUB_TIMEOUT 0
set_grub GRUB_TIMEOUT_STYLE hidden

# Démarrage silencieux :
#   quiet loglevel=3        — pas de journal du noyau à l'écran
#   systemd.show_status=false — pas de liste de services « [ OK ] Started … »
#   vt.global_cursor_default=0 — pas de curseur clignotant sur l'écran vide
# Rien n'est perdu : tout reste consultable avec « journalctl -b ».
set_grub GRUB_CMDLINE_LINUX_DEFAULT '"quiet loglevel=3 systemd.show_status=false vt.global_cursor_default=0"'

# ── Écrans de connexion ──────────────────────────────────────────────────────
# /etc/issue est déjà pris en charge par proxyfibre-issue (bannière Bastion).
# /etc/issue.net sert aux connexions réseau : Debian y écrit son nom. On l'aligne.
cat > /etc/issue.net <<'TXT'
Bastion — Contrôle d'accès au réseau
Accès réservé aux personnes autorisées. Toute intrusion est passible de
poursuites (art. 323-1 du Code pénal).
TXT
chmod 644 /etc/issue.net

# Le message du jour de Debian mentionne la distribution et sa licence. On le remplace.
cat > /etc/motd <<'TXT'

  Bastion — passerelle de contrôle d'accès au réseau
  © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés.

TXT
chmod 644 /etc/motd

# Debian génère un motd dynamique via /etc/update-motd.d : ses scripts peuvent réafficher
# le nom de la distribution. On les neutralise sans les supprimer (retrait du bit
# d'exécution), pour qu'un « --defaire » reste possible et qu'une mise à jour du paquet
# les retrouve intacts.
if [ -d /etc/update-motd.d ]; then
    for f in /etc/update-motd.d/*; do
        [ -f "$f" ] && [ -x "$f" ] && chmod -x "$f"
    done
fi

update-grub >/dev/null 2>&1 || update-grub2 >/dev/null 2>&1 || {
    echo "ATTENTION : update-grub a échoué. La configuration est écrite mais PAS activée." >&2
    echo "Lancez « update-grub » à la main et lisez son message." >&2
    exit 1
}

cat <<'FIN'
Démarrage Bastion appliqué.

  · menu d'amorçage masqué, démarrage immédiat
  · messages du noyau et des services silencieux (journalctl -b les conserve)
  · écrans de connexion aux couleurs de Bastion

ACCÈS DE SECOURS — à connaître AVANT d'en avoir besoin :
  maintenez la touche MAJ (Shift) pendant le démarrage pour rappeler le menu.
  C'est le seul moyen de démarrer un noyau précédent si une mise à jour casse
  l'amorçage. Vérifiez-le une fois, à froid, plutôt que le jour d'une panne.

Retour à l'état d'origine :  sudo proxyfibre-brand --defaire
FIN
