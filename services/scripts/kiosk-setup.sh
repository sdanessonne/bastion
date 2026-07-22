#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Kiosque console : la passerelle démarre en PLEIN ÉCRAN sur sa propre console
# d'administration (https://127.0.0.1:8443), au lieu de l'invite de connexion texte.
#
# ── CE QUE CELA IMPOSE, ET POURQUOI C'EST UN CHOIX LOURD ─────────────────────
# Une passerelle Debian minimale n'a AUCUN environnement graphique. Afficher une page web
# sur sa console suppose donc d'installer un serveur X et un navigateur — plusieurs
# centaines de méga-octets, davantage de mémoire, et une surface d'attaque élargie sur une
# machine dont le rôle est justement de protéger le réseau. Aucune passerelle
# professionnelle (pfSense, OPNsense, Fortigate) ne lance un navigateur sur sa propre
# console : elles affichent un écran d'état et s'administrent depuis un autre poste. Ce
# script existe parce qu'il a été demandé explicitement — il n'est pas le choix par défaut.
#
# ── PRÉCAUTIONS PRISES ──────────────────────────────────────────────────────
#  · le navigateur tourne sous un compte DÉDIÉ, sans privilèges, jamais root ;
#  · le certificat auto-signé de la console n'est PAS accepté aveuglément : on n'autorise
#    QUE son empreinte (épinglage SPKI). Désactiver toute vérification aurait fait de ce
#    poste une cible de choix pour un homme-du-milieu sur la boucle locale ;
#  · le kiosque ne doit jamais empêcher la passerelle de faire son travail : s'il échoue,
#    on retombe sur la console texte, pas sur un écran noir.
#
# ── CE QUE CE SCRIPT NE PEUT PAS GARANTIR ───────────────────────────────────
# Un navigateur en plein écran n'est pas un vrai verrouillage : Chromium en mode kiosque
# bloque l'essentiel (nouvel onglet, menu contextuel, outils de développement), mais un
# accès physique reste un accès physique — Ctrl+Alt+F2 bascule sur une autre console.
# La console d'administration exige de toute façon une authentification : le kiosque
# affiche la page de connexion, il n'ouvre pas de session.
#
# Usage : sudo sh kiosk-setup.sh            installe le kiosque
#         sudo sh kiosk-setup.sh --defaire  retire tout, retour à la console texte
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "À lancer en root :  sudo sh $0" >&2
    exit 1
fi

KUSER=bastion-kiosk
URL="https://127.0.0.1:8443"
CERT=/etc/proxyfibre/admin.crt
GETTY_OVR=/etc/systemd/system/getty@tty1.service.d/kiosk.conf

# ── Retour arrière ───────────────────────────────────────────────────────────
if [ "${1:-}" = "--defaire" ]; then
    echo "Retrait du kiosque…"
    rm -f "$GETTY_OVR"
    rmdir /etc/systemd/system/getty@tty1.service.d 2>/dev/null || true
    systemctl daemon-reload
    systemctl restart getty@tty1 2>/dev/null || true
    # Le compte est conservé (il ne gêne pas) ; on le retire seulement s'il est vide de
    # tout ce qui n'a pas été posé par ce script. On se contente de le verrouiller.
    usermod -L "$KUSER" 2>/dev/null || true
    echo "Terminé. La console texte revient au prochain démarrage (ou : systemctl restart getty@tty1)."
    exit 0
fi

# ── Paquets ──────────────────────────────────────────────────────────────────
# Ensemble MINIMAL : un serveur X sans bureau, un gestionnaire de fenêtres léger pour que
# le plein écran soit correct, Chromium, et deux utilitaires (masquer le curseur, désactiver
# la mise en veille de l'écran). Pas de bureau complet, pas de gestionnaire de session.
echo "Installation de l'environnement graphique minimal (peut prendre quelques minutes)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
if ! apt-get install -y --no-install-recommends \
        xserver-xorg xinit openbox chromium x11-xserver-utils unclutter; then
    echo "ERREUR : installation des paquets impossible (accès Internet ? miroir apt ?)." >&2
    echo "Rien n'a été modifié côté démarrage. Corrigez apt puis relancez." >&2
    exit 1
fi

# Le binaire s'appelle « chromium » sur Debian, « chromium-browser » ailleurs.
BROWSER=$(command -v chromium || command -v chromium-browser || true)
if [ -z "$BROWSER" ]; then
    echo "ERREUR : Chromium introuvable après installation." >&2
    exit 1
fi

# ── Empreinte du certificat de la console ───────────────────────────────────
# On n'accepte QUE ce certificat, par son empreinte de clé publique (SPKI). Ainsi le
# kiosque tolère le certificat auto-signé de la console SANS baisser la garde pour le
# reste — un « --ignore-certificate-errors » global aurait accepté n'importe quoi.
SPKI=""
if [ -r "$CERT" ]; then
    SPKI=$(openssl x509 -in "$CERT" -pubkey -noout 2>/dev/null \
           | openssl pkey -pubin -outform der 2>/dev/null \
           | openssl dgst -sha256 -binary 2>/dev/null \
           | openssl enc -base64 2>/dev/null || true)
fi

# ── Compte dédié, sans privilèges ────────────────────────────────────────────
if ! id "$KUSER" >/dev/null 2>&1; then
    # --disabled-password : aucun mot de passe, donc aucune connexion possible par ce
    # compte ailleurs que par le démarrage automatique de la console.
    adduser --disabled-password --gecos "Bastion Kiosk" "$KUSER"
fi
# Accès à la console graphique et au son éventuel ; PAS de sudo, PAS de groupe sensible.
usermod -aG video,tty "$KUSER" 2>/dev/null || true

# ── Ce que X lance : le navigateur, en boucle ────────────────────────────────
# Une boucle « while » : si Chromium est fermé ou tombe, il repart. Sur une borne, un
# navigateur fermé qui ne reviendrait pas laisserait un fond gris jusqu'au redémarrage.
if [ -n "$SPKI" ]; then
    PIN="--ignore-certificate-errors-spki-list=$SPKI"
else
    # Pas de certificat lisible : on épingle rien, on accepte l'auto-signé en dernier
    # recours. Sur la boucle locale d'une machine maîtrisée, le risque est contenu.
    PIN="--test-type --ignore-certificate-errors"
fi

cat > /home/"$KUSER"/.xinitrc <<XINIT
#!/bin/sh
# Écran : pas de mise en veille, pas d'économiseur, curseur masqué.
xset s off -dpms s noblank
unclutter -idle 0 &
openbox &

# On attend que la console d'administration réponde avant d'ouvrir le navigateur :
# au démarrage, Apache n'est pas prêt tout de suite, et Chromium afficherait sinon une
# page d'erreur au lieu de la connexion.
i=0
while [ \$i -lt 60 ]; do
    curl -sk -o /dev/null "$URL" && break
    i=\$((i+1)); sleep 1
done

while true; do
    "$BROWSER" \\
        --kiosk --incognito --noerrdialogs --disable-infobars \\
        --disable-session-crashed-bubble --disable-pinch \\
        --overscroll-history-navigation=0 --no-first-run \\
        --check-for-update-interval=31536000 \\
        --user-data-dir=/home/"$KUSER"/.chromium-kiosk \\
        $PIN \\
        "$URL"
    sleep 2
done
XINIT
chown "$KUSER":"$KUSER" /home/"$KUSER"/.xinitrc
chmod 700 /home/"$KUSER"/.xinitrc

# ── Démarrer X automatiquement sur tty1, et seulement là ─────────────────────
# Sur tty1 uniquement : les autres consoles (Ctrl+Alt+F2…) gardent un accès texte pour
# l'administration, indispensable si le kiosque doit être dépanné.
PROFILE=/home/"$KUSER"/.bash_profile
cat > "$PROFILE" <<'PROF'
if [ -z "$DISPLAY" ] && [ "$(tty)" = "/dev/tty1" ]; then
    exec startx -- -nocursor
fi
PROF
chown "$KUSER":"$KUSER" "$PROFILE"

# ── Connexion automatique du compte kiosque sur tty1 ─────────────────────────
mkdir -p "$(dirname "$GETTY_OVR")"
cat > "$GETTY_OVR" <<OVR
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin $KUSER --noclear %I \$TERM
OVR

systemctl daemon-reload
systemctl restart getty@tty1

cat <<FIN

Kiosque installé.

  · la console (tty1) démarre en plein écran sur $URL
  · le navigateur tourne sous le compte « $KUSER », sans privilèges
  · certificat de la console : ${SPKI:+épinglé (empreinte $SPKI)}${SPKI:-accepté en dernier recours (certificat illisible)}

ACCÈS D'ADMINISTRATION en cas de besoin :
  Ctrl+Alt+F2  bascule sur une console texte (login classique)
  Ctrl+Alt+F1  revient au kiosque

Retour à la console texte :  sudo sh $0 --defaire
FIN
