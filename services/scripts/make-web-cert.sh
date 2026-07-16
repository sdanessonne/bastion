#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Génère l'autorité de certification « Bastion » et le certificat serveur des pages
# web (console, portail, page de blocage), valides jusqu'en 2040. La CA publique est
# exposée pour être approuvée sur les postes (déploiement possible via GPO).
#   make-web-cert.sh [LAN_IP] [DC_IP]
set -eu

D=/etc/proxyfibre
LAN_IP="${1:-192.168.182.1}"
DC_IP="${2:-192.168.182.2}"
REALM=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z' || true)
[ -n "$REALM" ] || REALM=bastion.pn.int
# Nombre de jours jusqu'à fin 2040.
DAYS=$(( ( $(date -d 2040-12-31 +%s) - $(date +%s) ) / 86400 ))
SAN="DNS:${REALM},DNS:*.${REALM},DNS:bastion,DNS:localhost,IP:${LAN_IP},IP:${DC_IP},IP:127.0.0.1"
mkdir -p "$D"

# ── Autorité de certification Bastion (créée une seule fois) ──
if [ ! -f "$D/bastion-ca.crt" ]; then
    openssl req -x509 -newkey rsa:4096 -nodes -days "$DAYS" \
        -keyout "$D/bastion-ca.key" -out "$D/bastion-ca.crt" \
        -subj "/O=Bastion/OU=Securite reseau/CN=Bastion - Autorite de certification" \
        -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
        -addext "keyUsage=critical,keyCertSign,cRLSign" >/dev/null 2>&1
    chmod 600 "$D/bastion-ca.key"; chmod 644 "$D/bastion-ca.crt"
fi

# ── Certificat serveur signé par la CA ──
csr=$(mktemp); ext=$(mktemp)
openssl req -newkey rsa:2048 -nodes -keyout "$D/bastion.key" -out "$csr" \
    -subj "/O=Bastion/OU=Passerelle securisee/CN=Bastion" \
    -addext "subjectAltName=${SAN}" >/dev/null 2>&1
printf 'subjectAltName=%s\nextendedKeyUsage=serverAuth\nbasicConstraints=CA:FALSE\n' "$SAN" > "$ext"
openssl x509 -req -in "$csr" -CA "$D/bastion-ca.crt" -CAkey "$D/bastion-ca.key" -CAcreateserial \
    -days "$DAYS" -sha256 -extfile "$ext" -out "$D/bastion.crt" >/dev/null 2>&1
cat "$D/bastion-ca.crt" >> "$D/bastion.crt"     # chaîne complète pour Apache
chmod 640 "$D/bastion.key"; chgrp www-data "$D/bastion.key" 2>/dev/null || true
chmod 644 "$D/bastion.crt"
rm -f "$csr" "$ext"

# ── CA publique téléchargeable (à approuver sur les postes) ──
install -d -m755 /var/www/html/apps 2>/dev/null || true
install -m644 "$D/bastion-ca.crt" /var/www/html/apps/bastion-ca.crt 2>/dev/null || true

echo "Certificat Bastion généré (valide jusqu'à $(openssl x509 -in "$D/bastion.crt" -noout -enddate | cut -d= -f2))"
