#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion Central — installation de la console de supervision départementale.
# À exécuter sur la MACHINE CENTRALE dédiée (Debian + Apache + PHP + MariaDB).
# Elle interroge les passerelles de site via leur API (pull). Le registre des
# sites est local (table pf_central_sites) ; les identifiants de connexion
# réutilisent la table pf_admins.
#
# Usage : sudo ./setup-central.sh   (variables surchargées via config.env)
set -euo pipefail
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
[ -f "${REPO_DIR}/provisioning/config.env" ] && . "${REPO_DIR}/provisioning/config.env"

DB_NAME="${DB_NAME:-radius}"
DB_USER="${DB_USER:-radius}"
DB_PASS="${DB_PASS:-}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-ChangeMe}"

echo "[central] Paquets…"
apt-get update -qq
apt-get install -y apache2 php php-mysql php-curl mariadb-server >/dev/null
a2enmod ssl >/dev/null 2>&1 || true

echo "[central] Application web…"
install -d -m755 /var/www/central/assets
cp -r "${REPO_DIR}/central/." /var/www/central/
# Réutilise le thème et l'icône de la console admin.
cp "${REPO_DIR}/admin/assets/admin.css"         /var/www/central/assets/central.css
cp "${REPO_DIR}/admin/assets/bastion-icon.svg"  /var/www/central/assets/bastion-icon.svg 2>/dev/null || true
cp "${REPO_DIR}/portal/assets/favicon.ico"      /var/www/central/assets/favicon.ico 2>/dev/null || true
find /var/www/central -type f -exec chmod 644 {} +
find /var/www/central -type d -exec chmod 755 {} +
chown -R www-data:www-data /var/www/central

echo "[central] Identifiants base (central.env)…"
install -D -m640 /dev/null /etc/proxyfibre/central.env
printf 'DB_NAME="%s"\nDB_USER="%s"\nDB_PASS="%s"\n' "$DB_NAME" "$DB_USER" "$DB_PASS" > /etc/proxyfibre/central.env
chgrp www-data /etc/proxyfibre/central.env

echo "[central] Base : registre des sites + compte admin…"
mysql "${DB_NAME}" -e "CREATE TABLE IF NOT EXISTS pf_central_sites (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, commissariat VARCHAR(120),
  base_url VARCHAR(255) NOT NULL, token VARCHAR(255) NOT NULL, enabled TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS pf_admins (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) UNIQUE, password_hash VARCHAR(255));"
AH="$(PF_AP="$ADMIN_PASS" php -r "echo password_hash(getenv('PF_AP'), PASSWORD_DEFAULT);")"
mysql "${DB_NAME}" -e "INSERT INTO pf_admins (username,password_hash) VALUES ('${ADMIN_USER}','${AH}')
  ON DUPLICATE KEY UPDATE password_hash='${AH}';"

echo "[central] Certificat auto-signé + vhost 9443…"
if [ ! -f /etc/proxyfibre/admin.crt ]; then
  install -d /etc/proxyfibre
  openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
    -keyout /etc/proxyfibre/admin.key -out /etc/proxyfibre/admin.crt \
    -subj "/CN=bastion-central" >/dev/null 2>&1
fi
cp "${REPO_DIR}/services/apache/central-ssl.conf" /etc/apache2/sites-available/proxyfibre-central-ssl.conf
a2ensite proxyfibre-central-ssl >/dev/null 2>&1 || true
apache2ctl configtest
systemctl reload apache2

echo "[central] Terminé. Console : https://<ip-central>:9443/  (compte ${ADMIN_USER})."
echo "         Ajoutez vos passerelles dans « Sites / passerelles » (URL admin + jeton api_token du site)."
