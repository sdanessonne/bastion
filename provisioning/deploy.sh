#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# ─────────────────────────────────────────────────────────────────────────────
# Bastion — déploiement de la Phase 1 sur Debian 13 (OpenNDS + FreeRADIUS +
# dnsmasq + Apache/PHP). Idempotent. À lancer sur la passerelle : sudo ./deploy.sh
#
# Remplace l'ancien install.sh (basé CoovaChilli, indisponible sur Debian 13).
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/config.env"
SECRETS_ENV="/etc/proxyfibre/secrets.env"

log(){ printf '\033[1;32m[Bastion]\033[0m %s\n' "$*"; }
die(){ printf '\033[1;31m[Bastion]\033[0m %s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "Lancer en root (sudo)."

# ── Secrets ──────────────────────────────────────────────────────────────────
mkdir -p /etc/proxyfibre
gen(){ head -c 32 /dev/urandom | sha256sum | cut -c1-64; }
# Mot de passe lisible et TYPOGRAPHIABLE : il sera tapé à la main. Alphabet sans
# caractère ambigu (ni O/0, ni I/l/1), présenté en groupes de 5.
#
# La composition est IMPOSÉE, pas espérée. MESURÉ sur 2000 tirages : un tirage
# purement aléatoire sur cet alphabet ne contient AUCUN chiffre dans 4,5 % des cas
# (il n'y a que 8 chiffres pour 55 caractères). Samba refuse alors le mot de passe et
# la création du domaine échoue — une fois sur 22, au hasard, sans rien d'explicable.
# On force donc 2 majuscules, 2 minuscules et 2 chiffres, on complète depuis
# /dev/urandom, puis on mélange (sans quoi les 6 premiers caractères suivraient
# toujours le même motif). ~100 bits d'entropie.
genpass(){
  local maj='ABCDEFGHJKMNPQRSTUVWXYZ' min='abcdefghijkmnpqrstuvwxyz' chi='23456789'
  local tout p='' i
  tout="${maj}${min}${chi}"
  for i in 1 2; do p+="$(tr -dc "$maj" < /dev/urandom | head -c1)"; done
  for i in 1 2; do p+="$(tr -dc "$min" < /dev/urandom | head -c1)"; done
  for i in 1 2; do p+="$(tr -dc "$chi" < /dev/urandom | head -c1)"; done
  p+="$(tr -dc "$tout" < /dev/urandom | head -c 14)"
  printf '%s' "$p" | fold -w1 | shuf | tr -d '\n' | sed 's/.\{5\}/&-/g; s/-$//'
}
if [[ -f "$SECRETS_ENV" ]]; then source "$SECRETS_ENV"; else
  RADIUS_SECRET="$(gen)"; FAS_KEY="$(gen)"; DB_PASS="$(gen)"
  umask 077
  printf 'RADIUS_SECRET="%s"\nFAS_KEY="%s"\nDB_PASS="%s"\n' "$RADIUS_SECRET" "$FAS_KEY" "$DB_PASS" > "$SECRETS_ENV"
  log "Secrets générés → $SECRETS_ENV"
fi

# Mot de passe de la console : ENGENDRÉ s'il n'est pas fourni, jamais codé en dur.
# Un mot de passe par défaut inscrit dans le dépôt serait connu de quiconque lit le
# code : toute passerelle installée sans le changer serait ouverte. Et un défaut
# VIDE serait pire encore — la console n'aurait plus de mot de passe du tout.
# Il est conservé pour que ré-exécuter deploy.sh ne le change pas sous les pieds
# de l'administrateur.
ADMIN_PASS_ENV=/etc/proxyfibre/admin-pass.env
ADMIN_PASS_GENERE=0
if [[ -z "${ADMIN_PASS:-}" ]]; then
  if [[ -f "$ADMIN_PASS_ENV" ]]; then
    source "$ADMIN_PASS_ENV"
  else
    ADMIN_PASS="$(genpass)"; ADMIN_PASS_GENERE=1
    umask 077
    printf 'ADMIN_PASS="%s"\n' "$ADMIN_PASS" > "$ADMIN_PASS_ENV"
  fi
fi

# ── Détection du répertoire FreeRADIUS (3.0 / 3.2) ───────────────────────────
RLMDIR="$(ls -d /etc/freeradius/3.* 2>/dev/null | sort -V | tail -1)"
[[ -d "$RLMDIR" ]] || die "Répertoire FreeRADIUS introuvable."
# Groupe de service (Debian: 'freerad' ; certaines distros: 'freeradius' ou 'radiusd').
FR_GROUP="$(for g in freerad freeradius radiusd; do getent group "$g" >/dev/null && { echo "$g"; break; }; done)"
FR_GROUP="${FR_GROUP:-root}"
log "FreeRADIUS : $RLMDIR (groupe $FR_GROUP)"

# ── Interface LAN : IP statique (requise par OpenNDS) ────────────────────────
if ! ip -4 addr show "$LAN_IF" | grep -q "${LAN_IP}/"; then
  if command -v nmcli >/dev/null && nmcli -t dev status | grep -q "^${LAN_IF}:"; then
    nmcli con delete proxyfibre-lan >/dev/null 2>&1 || true
    nmcli con add type ethernet ifname "$LAN_IF" con-name proxyfibre-lan \
      ipv4.method manual ipv4.addresses "${LAN_IP}/${LAN_CIDR}" \
      ipv4.never-default yes connection.autoconnect yes >/dev/null
    nmcli con up proxyfibre-lan >/dev/null
  else
    ip addr add "${LAN_IP}/${LAN_CIDR}" dev "$LAN_IF" 2>/dev/null || true
    ip link set "$LAN_IF" up
  fi
  log "IP LAN ${LAN_IP}/${LAN_CIDR} sur ${LAN_IF}"
fi

# ── Routage + NAT (nftables — Debian 13 n'a pas iptables par défaut) ─────────
install -D -m644 "${REPO_DIR}/services/sysctl/proxyfibre.conf" /etc/sysctl.d/99-proxyfibre.conf
sysctl --system >/dev/null
# ── Garde réseau : NAT + DNS forcé + repli sur panne ─────────────────────────
# Table dédiée (n'interfère pas avec celles d'OpenNDS). Le fichier nat.nft est
# désormais ENGENDRÉ par netguard.sh : l'état du repli doit être calculé d'après
# l'état réel d'OpenNDS, ce qu'un fichier statique ne peut pas faire.
install -D -m755 "${REPO_DIR}/services/scripts/netguard.sh" /usr/local/sbin/proxyfibre-netguard
install -D -m644 "${REPO_DIR}/services/filter/doh-resolvers.txt" /etc/proxyfibre/doh-resolvers.txt
# Limitation de débit par poste. pf_groups portait déjà down_rate_kbps/up_rate_kbps et
# le hook BinAuth les transmettait à OpenNDS — mais OpenNDS NE MET PAS EN FORME le trafic
# (aucune référence à tc/htb dans son binaire) : ces réglages n'avaient aucun effet.
install -D -m755 "${REPO_DIR}/services/scripts/qos-ctl.sh" /usr/local/sbin/proxyfibre-qos
cat > /etc/proxyfibre/net.env <<ENV
WAN_IF="${WAN_IF}"
LAN_IF="${LAN_IF}"
LAN_IP="${LAN_IP}"
LAN_NET="${LAN_NET}"
LAN_CIDR="${LAN_CIDR}"
AD_DNS_IP="${AD_DNS_IP:-192.168.182.2}"
ENV
chmod 644 /etc/proxyfibre/net.env
/usr/local/sbin/proxyfibre-netguard apply
# Persistance au reboot. Au démarrage, OpenNDS n'a pas encore confirmé : le garde
# pose le repli, donc le LAN reste fermé tant que le portail n'est pas opérationnel.
cat > /etc/systemd/system/proxyfibre-nat.service <<'UNIT'
[Unit]
Description=Bastion — garde reseau (NAT, DNS force, repli sur panne)
After=network-online.target
Wants=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/proxyfibre-netguard apply
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
UNIT

# REPLI SUR PANNE du portail captif — drop-in, pour ne pas toucher l'unité du paquet.
# Restart=always et NON on-failure : le plantage vécu (deux IP détectées sur
# l'interface passerelle) faisait sortir OpenNDS avec status=0, une sortie PROPRE.
# systemd tenait donc l'arrêt pour normal et ne redémarrait rien — le portail restait
# mort et le réseau OUVERT, sans authentification ni filtrage, sans aucun signal.
mkdir -p /etc/systemd/system/opennds.service.d
cat > /etc/systemd/system/opennds.service.d/bastion-failclose.conf <<'UNIT'
[Unit]
StartLimitIntervalSec=300
StartLimitBurst=5

[Service]
Restart=always
RestartSec=5
# Le trafic LAN->WAN n'est autorisé QU'APRÈS confirmation du démarrage du portail,
# et recoupé dès son arrêt — y compris un arrêt volontaire depuis la console.
# Si le portail échoue 5 fois en 5 min, systemd renonce : le repli RESTE actif.
ExecStartPost=/usr/local/sbin/proxyfibre-netguard open
ExecStopPost=/usr/local/sbin/proxyfibre-netguard close
UNIT
systemctl daemon-reload
systemctl enable proxyfibre-nat >/dev/null 2>&1 || true
log "Garde reseau OK (NAT ${WAN_IF}, DNS force vers ${LAN_IP}, repli sur panne actif)"

# ── MariaDB + schéma RADIUS + utilisateur de test ────────────────────────────
systemctl enable --now mariadb >/dev/null 2>&1 || true
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
SCHEMA="${RLMDIR}/mods-config/sql/main/mysql/schema.sql"
[[ -f "$SCHEMA" ]] && mysql "${DB_NAME}" < "$SCHEMA"
sed -e "s/\${TEST_USER}/${TEST_USER}/g" -e "s/\${TEST_PASS}/${TEST_PASS}/g" \
    "${REPO_DIR}/services/freeradius/testuser.sql" | mysql "${DB_NAME}"
log "Base RADIUS + utilisateur '${TEST_USER}' OK"

# ── FreeRADIUS : client localhost + backend SQL (MariaDB) ────────────────────
# Les comptes sont lus depuis radcheck/radusergroup (gérables via la console admin).
sed "s/\${RADIUS_SECRET}/${RADIUS_SECRET}/g" \
    "${REPO_DIR}/services/freeradius/clients.conf" > "${RLMDIR}/clients.conf"
chgrp "$FR_GROUP" "${RLMDIR}/clients.conf"
sed "s|__DB_PASS__|${DB_PASS}|" "${REPO_DIR}/services/freeradius/sql" > "${RLMDIR}/mods-enabled/sql"
chgrp "$FR_GROUP" "${RLMDIR}/mods-enabled/sql"
# L'utilisateur de test est inséré dans radcheck (voir testuser.sql ci-dessus) ;
# on le retire du fichier "files" pour que SQL fasse foi.
sed -i "/^${TEST_USER}[[:space:]]/d" "${RLMDIR}/mods-config/files/authorize" 2>/dev/null || true
log "FreeRADIUS configuré (backend SQL activé)"

# ── Portail (Apache + PHP) sur le port 2080 ──────────────────────────────────
install -d -m755 /var/www/html/portal
cp -r "${REPO_DIR}/portal/." /var/www/html/portal/
# favicon à la racine du docroot (requête /favicon.ico par défaut des navigateurs)
[ -f /var/www/html/portal/assets/favicon.ico ] && cp /var/www/html/portal/assets/favicon.ico /var/www/html/favicon.ico
# Médiathèque de l'intranet CMS (uploads d'images), inscriptible par le serveur web.
install -d -o www-data -g www-data -m775 /var/www/html/portal/intranet/uploads
find /var/www/html/portal -type f -exec chmod 644 {} +
find /var/www/html/portal -type d -exec chmod 755 {} +
install -D -m640 /dev/null /etc/proxyfibre/portal.env
printf 'FAS_KEY="%s"\nRADIUS_SECRET="%s"\n' "$FAS_KEY" "$RADIUS_SECRET" > /etc/proxyfibre/portal.env
chgrp www-data /etc/proxyfibre/portal.env
# Autoriser Apache (www-data) à interroger OpenNDS pour le tableau de bord utilisateur.
cat > /etc/sudoers.d/proxyfibre-portal <<'SUD'
www-data ALL=(root) NOPASSWD: /usr/bin/ndsctl json, /usr/bin/ndsctl deauth *
SUD
chmod 440 /etc/sudoers.d/proxyfibre-portal
# Apache écoute sur 2080 (OpenNDS réserve 80/443 pour la capture).
grep -q '^Listen 2080$' /etc/apache2/ports.conf || sed -i 's/^Listen 80$/Listen 2080/' /etc/apache2/ports.conf
# Désactiver PrivateTmp : sinon Apache a un /tmp isolé et PHP ne voit pas la
# socket /tmp/ndsctl.sock d'OpenNDS (nécessaire au tableau de bord utilisateur).
install -d /etc/systemd/system/apache2.service.d
printf '[Service]\nPrivateTmp=false\n' > /etc/systemd/system/apache2.service.d/override.conf
systemctl daemon-reload
install -m644 "${REPO_DIR}/services/apache/proxyfibre.conf" /etc/apache2/sites-available/proxyfibre.conf
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite proxyfibre >/dev/null 2>&1 || true
# Portail en HTTPS (port 2443) : certificat auto-signé (CN + SAN = IP LAN) + vhost SSL.
# OpenNDS redirige en HTTP:2080 ; fas.php rebondit vers HTTPS:2443 (voir https_guard.php).
a2enmod ssl >/dev/null 2>&1 || true
# Certificat Bastion (CA + serveur, valide jusqu'en 2040) : console, portail, blocage.
install -m755 "${REPO_DIR}/services/scripts/make-web-cert.sh" /usr/local/sbin/proxyfibre-make-web-cert
/usr/local/sbin/proxyfibre-make-web-cert "${LAN_IP}" "${DC_IP:-192.168.182.2}" || true
grep -q '^Listen 2443$' /etc/apache2/ports.conf || echo "Listen 2443" >> /etc/apache2/ports.conf
install -m644 "${REPO_DIR}/services/apache/portal-ssl.conf" /etc/apache2/sites-available/proxyfibre-portal-ssl.conf
a2ensite proxyfibre-portal-ssl >/dev/null 2>&1 || true
log "Portail FAS déployé (http://${LAN_IP}:2080/portal/fas.php)"

# ── OpenNDS (config UCI /etc/config/opennds — Debian ignore le fichier legacy) ─
mkdir -p /etc/config
sed -e "s|__LAN_IF__|${LAN_IF}|g" -e "s|__LAN_IP__|${LAN_IP}|g" -e "s|__FAS_KEY__|${FAS_KEY}|g" \
    "${REPO_DIR}/services/opennds/opennds.uci" | sed 's/\r$//' > /etc/config/opennds
log "OpenNDS configuré en UCI (GatewayInterface=${LAN_IF}, FAS 2080)"

# ── dnsmasq (DHCP + DNS du LAN) ──────────────────────────────────────────────
sed -e "s|__LAN_IF__|${LAN_IF}|g" -e "s|__LAN_IP__|${LAN_IP}|g" \
    -e "s|__DHCP_START__|${DHCP_START}|g" -e "s|__DHCP_END__|${DHCP_END}|g" \
    -e "s|__LAN_MASK__|${LAN_MASK}|g" \
    "${REPO_DIR}/services/dnsmasq/proxyfibre.conf" > /etc/dnsmasq.d/proxyfibre.conf

# ── Console d'administration (Apache vhost séparé, port 8080) ────────────────
install -d -m755 /var/www/admin
cp -r "${REPO_DIR}/admin/." /var/www/admin/
[ -f /var/www/admin/assets/favicon.ico ] && cp /var/www/admin/assets/favicon.ico /var/www/admin/favicon.ico
find /var/www/admin -type f -exec chmod 644 {} +
find /var/www/admin -type d -exec chmod 755 {} +
# Dossier des médias téléversés par l'admin (aperçu du fond d'écran, etc.) : accessible en écriture à www-data.
install -d -o www-data -g www-data -m755 /var/www/admin/media
install -D -m640 /dev/null /etc/proxyfibre/admin.env
printf 'DB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\n' "$DB_NAME" "$DB_USER" "$DB_PASS" > /etc/proxyfibre/admin.env
chgrp www-data /etc/proxyfibre/admin.env
# Table + compte administrateur par défaut (avec double authentification TOTP)
mysql "${DB_NAME}" -e "CREATE TABLE IF NOT EXISTS pf_admins (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) UNIQUE, password_hash VARCHAR(255), totp_secret VARCHAR(64) DEFAULT NULL, totp_enabled TINYINT(1) NOT NULL DEFAULT 0);"
# Migration des installations antérieures (colonnes 2FA)
mysql "${DB_NAME}" -e "ALTER TABLE pf_admins ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) DEFAULT NULL, ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0;" 2>/dev/null || true
ADMIN_HASH="$(PF_AP="$ADMIN_PASS" php -r "echo password_hash(getenv('PF_AP'), PASSWORD_DEFAULT);")"
mysql "${DB_NAME}" -e "INSERT INTO pf_admins (username,password_hash) VALUES ('${ADMIN_USER}','${ADMIN_HASH}') ON DUPLICATE KEY UPDATE password_hash='${ADMIN_HASH}';"
# Jeton d'API pour le serveur central (généré une seule fois s'il n'existe pas)
mysql "${DB_NAME}" -e "CREATE TABLE IF NOT EXISTS pf_settings (k VARCHAR(64) PRIMARY KEY, v TEXT);
INSERT IGNORE INTO pf_settings (k,v) VALUES ('api_token', SHA2(CONCAT(RAND(),UUID(),NOW(6)),256));"
grep -q '^Listen 8080$' /etc/apache2/ports.conf || echo "Listen 8080" >> /etc/apache2/ports.conf
sed -e "s|__LAN_NET__|${LAN_NET}|g" -e "s|__LAN_CIDR__|${LAN_CIDR}|g" \
    "${REPO_DIR}/services/apache/admin.conf" > /etc/apache2/sites-available/proxyfibre-admin.conf
a2ensite proxyfibre-admin >/dev/null 2>&1 || true
# HTTPS auto-signé pour la console admin (port 8443).
if [[ ! -f /etc/proxyfibre/admin.crt ]]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
    -keyout /etc/proxyfibre/admin.key -out /etc/proxyfibre/admin.crt \
    -subj "/CN=proxyfibre-admin" >/dev/null 2>&1 || true
  chmod 640 /etc/proxyfibre/admin.key 2>/dev/null || true
fi
a2enmod ssl >/dev/null 2>&1 || true
grep -q '^Listen 8443$' /etc/apache2/ports.conf || echo "Listen 8443" >> /etc/apache2/ports.conf
sed -e "s|__LAN_NET__|${LAN_NET}|g" -e "s|__LAN_CIDR__|${LAN_CIDR}|g" \
    "${REPO_DIR}/services/apache/admin-ssl.conf" > /etc/apache2/sites-available/proxyfibre-admin-ssl.conf 2>/dev/null && \
  a2ensite proxyfibre-admin-ssl >/dev/null 2>&1 || true
log "Console admin déployée (port 8080, compte ${ADMIN_USER})"

# ── Phases 3-5 : filtrage, quotas/horaires, journalisation ───────────────────
# Tables
mysql "${DB_NAME}" <<'SQL'
CREATE TABLE IF NOT EXISTS pf_blocklist (id INT AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(255) UNIQUE, category VARCHAR(64) DEFAULT 'manuel', added_by VARCHAR(64), added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS pf_groups (groupname VARCHAR(64) PRIMARY KEY, session_timeout_min INT DEFAULT 0, down_rate_kbps INT DEFAULT 0, up_rate_kbps INT DEFAULT 0, down_quota_mb INT DEFAULT 0, up_quota_mb INT DEFAULT 0, hours_start INT DEFAULT 0, hours_end INT DEFAULT 24);
CREATE TABLE IF NOT EXISTS pf_connlog (id BIGINT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64), groupname VARCHAR(64), mac VARCHAR(17), ip VARCHAR(45), event VARCHAR(16), bytes_in BIGINT DEFAULT 0, bytes_out BIGINT DEFAULT 0, duration_s INT DEFAULT 0, ts DATETIME, INDEX(username), INDEX(ts));
CREATE TABLE IF NOT EXISTS pf_weblog (id BIGINT AUTO_INCREMENT PRIMARY KEY, ts DATETIME, client_ip VARCHAR(45), username VARCHAR(64), domain VARCHAR(255), INDEX(username), INDEX(ts), INDEX(domain));
CREATE TABLE IF NOT EXISTS pf_commissariats (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(96) UNIQUE, cpn VARCHAR(96) DEFAULT '');
CREATE TABLE IF NOT EXISTS pf_user_site (username VARCHAR(64) PRIMARY KEY, commissariat_id INT, INDEX(commissariat_id));
CREATE TABLE IF NOT EXISTS pf_user_profile (username VARCHAR(64) PRIMARY KEY, nom VARCHAR(96) DEFAULT '', prenom VARCHAR(96) DEFAULT '', service VARCHAR(96) DEFAULT '', INDEX(nom), INDEX(service));
CREATE TABLE IF NOT EXISTS pf_apps (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(96), description TEXT, filename VARCHAR(160), args VARCHAR(255) DEFAULT '', icon VARCHAR(16) DEFAULT '📦', deployed TINYINT(1) NOT NULL DEFAULT 0, added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
INSERT IGNORE INTO pf_groups (groupname) VALUES ('default');
-- Commissariats de l'Essonne (6 circonscriptions de police nationale — éditables depuis la console).
INSERT IGNORE INTO pf_commissariats (name,cpn) VALUES
 ("Évry-Courcouronnes","CPN Évry-Corbeil"),("Corbeil-Essonnes","CPN Évry-Corbeil"),("Ris-Orangis","CPN Évry-Corbeil"),
 ("Vigneux-sur-Seine","CPN Val d'Yerres-Val de Seine"),("Montgeron","CPN Val d'Yerres-Val de Seine"),("Draveil","CPN Val d'Yerres-Val de Seine"),
 ("Juvisy-sur-Orge","CPN Juvisy-sur-Orge"),("Athis-Mons","CPN Juvisy-sur-Orge"),("Savigny-sur-Orge","CPN Juvisy-sur-Orge"),("Viry-Châtillon","CPN Juvisy-sur-Orge"),
 ("Massy","CPN Massy-Palaiseau"),("Palaiseau","CPN Massy-Palaiseau"),("Les Ulis","CPN Massy-Palaiseau"),
 ("Sainte-Geneviève-des-Bois","CPN Sainte-Geneviève-des-Bois"),("Brétigny-sur-Orge","CPN Sainte-Geneviève-des-Bois"),("Arpajon","CPN Sainte-Geneviève-des-Bois"),("Longjumeau","CPN Sainte-Geneviève-des-Bois"),
 ("Étampes","CPN Étampes"),("Dourdan","CPN Étampes");
SQL
# Filtrage DNS : scripts + sudoers + génération initiale
install -m755 "${REPO_DIR}/services/scripts/apply-filter.sh" /usr/local/sbin/proxyfibre-apply-filter
# Catégories thématiques de filtrage (listes YAML, activables depuis l'admin)
[ -f "${REPO_DIR}/services/filter/categories.yaml" ] && install -D -m644 "${REPO_DIR}/services/filter/categories.yaml" /etc/proxyfibre/categories.yaml
install -m755 "${REPO_DIR}/services/scripts/update-adblock.sh" /usr/local/sbin/proxyfibre-update-adblock
cat > /etc/sudoers.d/proxyfibre-filter <<'SUD'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-apply-filter
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-update-adblock enable, /usr/local/sbin/proxyfibre-update-adblock disable
SUD
chmod 440 /etc/sudoers.d/proxyfibre-filter
# Page d'information de blocage (les domaines filtrés résolvent vers la passerelle).
install -d -o www-data -g www-data -m755 /var/www/block
install -m644 "${REPO_DIR}/portal/block.php" /var/www/block/block.php
install -m644 "${REPO_DIR}/services/apache/block.conf" /etc/apache2/sites-available/proxyfibre-block.conf
a2ensite proxyfibre-block >/dev/null 2>&1 || true
/usr/local/sbin/proxyfibre-apply-filter || true

# Pilotage des services depuis la console admin (liste blanche stricte)
install -m755 "${REPO_DIR}/services/scripts/service-ctl.sh" /usr/local/sbin/proxyfibre-service
install -m755 "${REPO_DIR}/services/scripts/apt-ctl.sh"     /usr/local/sbin/proxyfibre-apt
install -m755 "${REPO_DIR}/services/scripts/selfupdate.sh"  /usr/local/sbin/proxyfibre-selfupdate
install -m755 "${REPO_DIR}/services/scripts/update-conf.sh" /usr/local/sbin/proxyfibre-update-conf
# Mesure de la ligne Internet : la passerelle ne peut pas connaître sa capacité autrement.
install -m755 "${REPO_DIR}/services/scripts/speedtest-wan.sh" /usr/local/sbin/proxyfibre-speedtest
# Dépôt Git de Bastion : renseigné depuis la console (Système → Mise à jour de Bastion).
# 600 : le fichier peut contenir un jeton d'accès à un dépôt privé.
[ -f /etc/proxyfibre/update.env ] || printf 'GIT_REPO=""\nGIT_BRANCH="main"\nGIT_TOKEN=""\n' > /etc/proxyfibre/update.env
chmod 600 /etc/proxyfibre/update.env
# rsync : utilisé par la mise à jour pour synchroniser console et portail.
command -v rsync >/dev/null || apt-get -y install rsync >/dev/null 2>&1

# Antivirus : la base de signatures DOIT se mettre à jour seule. Un moteur aux
# signatures figées est pire qu'aucun antivirus — il donne une confiance infondée.
# (Constaté : le service était installé mais « disabled », donc jamais mis à jour.)
systemctl enable --now clamav-freshclam >/dev/null 2>&1 || true
cat > /etc/sudoers.d/proxyfibre-services <<'SUD'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-service start *, /usr/local/sbin/proxyfibre-service stop *, /usr/local/sbin/proxyfibre-service restart *, /usr/local/sbin/proxyfibre-service reload *, /usr/local/sbin/proxyfibre-service logs *
# Mise à jour Debian. Les verbes sont énumérés UN PAR UN, jamais « proxyfibre-apt * » :
# le joker autoriserait aussi « _run » et « _check », qui exécutent apt pour de vrai et
# ne doivent être atteignables que depuis l'unité systemd. Aucun verbe ne prend de nom de
# paquet — sinon la console offrirait « apt install n'importe quoi » en root.
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-apt check, /usr/local/sbin/proxyfibre-apt list, /usr/local/sbin/proxyfibre-apt apply, /usr/local/sbin/proxyfibre-apt state, /usr/local/sbin/proxyfibre-apt log
# Mise à jour de Bastion depuis Git. Même règle : verbes énumérés, « _check » et
# « _apply » (qui écrivent réellement sur le disque) ne sont PAS atteignables par le web.
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-selfupdate state, /usr/local/sbin/proxyfibre-selfupdate check, /usr/local/sbin/proxyfibre-selfupdate apply, /usr/local/sbin/proxyfibre-selfupdate log, /usr/local/sbin/proxyfibre-selfupdate pubkey, /usr/local/sbin/proxyfibre-selfupdate testssh
# Écriture de la configuration du dépôt (URL, branche, jeton) : script dédié, jamais
# un « tee » ou un « sh -c » générique, qui donneraient l'écriture de n'importe quel fichier.
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-update-conf
# Mesure de la ligne. « _run » n'est PAS listé : lui seul sature réellement la liaison.
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-speedtest run, /usr/local/sbin/proxyfibre-speedtest state, /usr/local/sbin/proxyfibre-speedtest log
SUD
chmod 440 /etc/sudoers.d/proxyfibre-services

# Antivirus ClamAV + Active Directory : helpers + sudoers (liste blanche)
install -m755 "${REPO_DIR}/services/scripts/clamav-ctl.sh" /usr/local/sbin/proxyfibre-clamav
install -m755 "${REPO_DIR}/services/scripts/ad-ctl.sh"     /usr/local/sbin/proxyfibre-ad
install -m755 "${REPO_DIR}/services/scripts/gpo-apply.py"  /usr/local/sbin/proxyfibre-gpo-apply
install -m755 "${REPO_DIR}/services/scripts/gpo-apps.py"   /usr/local/sbin/proxyfibre-gpo-apps
install -m755 "${REPO_DIR}/services/scripts/gpo-kms.py"    /usr/local/sbin/proxyfibre-gpo-kms
install -m755 "${REPO_DIR}/services/scripts/gpo-drives.py" /usr/local/sbin/proxyfibre-gpo-drives
# Store d'applications : dossier des installeurs (servi sur 2080) + limites d'upload PHP.
install -d -o www-data -g www-data -m 755 /var/www/html/apps
PHPCONF="$(ls -d /etc/php/*/apache2/conf.d 2>/dev/null | head -1)"
[ -n "$PHPCONF" ] && printf 'upload_max_filesize = 900M\npost_max_size = 910M\nmax_execution_time = 600\nmemory_limit = 256M\n' > "$PHPCONF/99-proxyfibre-uploads.ini"
# Installeur AD (permet une (re)création du domaine depuis la console admin)
[ -f "${REPO_DIR}/provisioning/setup-ad.sh" ] && install -m755 "${REPO_DIR}/provisioning/setup-ad.sh" /usr/local/sbin/proxyfibre-setup-ad
# Échantillonneur de charge (processeur/mémoire) : cron 1/min → historique pf_metrics (24 h)
install -m755 "${REPO_DIR}/services/scripts/metrics-sample.php" /usr/local/sbin/proxyfibre-metrics-sample
echo '* * * * * root php /usr/local/sbin/proxyfibre-metrics-sample >/dev/null 2>&1' > /etc/cron.d/proxyfibre-metrics
install -m755 "${REPO_DIR}/services/scripts/backup-ctl.sh" /usr/local/sbin/proxyfibre-backup
# Cachet électronique des fiches d'habilitation (autorité de signature locale).
install -m755 "${REPO_DIR}/services/scripts/habilitation-ctl.sh" /usr/local/sbin/proxyfibre-habilitation
/usr/local/sbin/proxyfibre-habilitation init >/dev/null 2>&1 || true
# Signature électronique des dossiers de réquisition (CMS/PKCS#7 via l'AC Bastion).
install -m755 "${REPO_DIR}/services/scripts/sign-ctl.sh" /usr/local/sbin/proxyfibre-sign
# Sauvegarde automatique hebdomadaire (timer systemd, activé par défaut).
install -m644 "${REPO_DIR}/services/systemd/proxyfibre-backup.service" /etc/systemd/system/proxyfibre-backup.service
install -m644 "${REPO_DIR}/services/systemd/proxyfibre-backup.timer"   /etc/systemd/system/proxyfibre-backup.timer
systemctl daemon-reload
systemctl enable --now proxyfibre-backup.timer >/dev/null 2>&1 || true
cat > /etc/sudoers.d/proxyfibre-secu <<'SUD'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-clamav update, /usr/local/sbin/proxyfibre-clamav scan *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-ad *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-backup *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-habilitation *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-sign *
SUD
chmod 440 /etc/sudoers.d/proxyfibre-secu
# Rafraîchissement hebdomadaire du bloqueur de publicités (s'il est activé)
echo '0 4 * * 0 root [ "$(mysql -N -B radius -e "SELECT v FROM pf_settings WHERE k=\"adblock_enabled\"" 2>/dev/null)" = "1" ] && /usr/local/sbin/proxyfibre-update-adblock enable' > /etc/cron.d/proxyfibre-adblock
# Quotas/horaires + journalisation : custombinauth (appelé par OpenNDS binauth)
install -m755 "${REPO_DIR}/services/opennds/custombinauth.sh" /usr/lib/opennds/custombinauth.sh
# Échantillons de débit laissés par un essai en ligne de commande : appartenant à root,
# ils empêcheraient le serveur web de les réécrire et le débit resterait figé.
rm -f /dev/shm/pf-net-*.sample 2>/dev/null || true

# Journal systemd : plafonné. Par défaut il grossit jusqu'à 10 % du disque — sur une
# passerelle laissée des mois en service, c'est plusieurs gigaoctets pour rien.
sed -i 's/^#\?SystemMaxUse=.*/SystemMaxUse=500M/' /etc/systemd/journald.conf 2>/dev/null || true
grep -q '^SystemMaxUse=' /etc/systemd/journald.conf || echo 'SystemMaxUse=500M' >> /etc/systemd/journald.conf
grep -q '^SystemMaxFileSize=' /etc/systemd/journald.conf || echo 'SystemMaxFileSize=50M' >> /etc/systemd/journald.conf
systemctl restart systemd-journald >/dev/null 2>&1 || true

# Pas de session graphique sur une passerelle : ~500 Mo de RAM, un flux de mises à jour
# et une surface d'attaque qui n'ont rien à faire sur un équipement réseau exposé.
# On ne DÉSINSTALLE pas (réversible d'une commande) : on ne la démarre plus.
systemctl set-default multi-user.target >/dev/null 2>&1 || true

# Rétention légale : purge quotidienne (365 j)
install -m755 "${REPO_DIR}/services/scripts/purge-logs.sh" /usr/local/sbin/proxyfibre-purge-logs
echo "30 3 * * * root /usr/local/sbin/proxyfibre-purge-logs 365" > /etc/cron.d/proxyfibre-purge
log "Filtrage + quotas + journalisation déployés"

# ── Walled garden : serveurs de MAJ (Windows/Linux) ouverts sans authentification ──
# dnsmasq ajoute les IP résolues des domaines de MAJ à l'ensemble @walledgarden
# d'OpenNDS (autorisé aux clients preauth). Un service compagnon relance dnsmasq
# après OpenNDS (l'ensemble doit exister avant que dnsmasq ne s'y réfère).
install -m644 "${REPO_DIR}/services/dnsmasq/walledgarden.conf" /etc/dnsmasq.d/proxyfibre-walledgarden.conf
install -m755 "${REPO_DIR}/services/scripts/walledgarden-refresh.sh" /usr/local/sbin/proxyfibre-walledgarden-refresh
install -m644 "${REPO_DIR}/services/systemd/proxyfibre-walledgarden.service" /etc/systemd/system/proxyfibre-walledgarden.service
systemctl daemon-reload
systemctl enable proxyfibre-walledgarden.service >/dev/null 2>&1 || true
log "Walled garden (mises à jour Windows/Linux) déployé"

# ── Serveur de temps local (chrony) ──────────────────────────────────────────
if ! command -v chronyd >/dev/null; then apt-get install -y -qq chrony >/dev/null 2>&1 || true; fi
install -D -m644 "${REPO_DIR}/services/chrony/proxyfibre.conf" /etc/chrony/conf.d/proxyfibre.conf 2>/dev/null || true
systemctl restart chrony 2>/dev/null || true
log "Serveur NTP local (chrony) déployé"

# ── Historique de navigation (journalisation DNS → base) ─────────────────────
cat > /etc/dnsmasq.d/proxyfibre-log.conf <<'DNSLOG'
log-queries
log-facility=/var/log/proxyfibre-dns.log
DNSLOG
systemctl restart dnsmasq 2>/dev/null || true
install -m755 "${REPO_DIR}/services/scripts/weblog-ingest.sh" /usr/local/sbin/proxyfibre-weblog-ingest
install -m644 "${REPO_DIR}/services/systemd/proxyfibre-weblog.service" /etc/systemd/system/proxyfibre-weblog.service
systemctl daemon-reload
systemctl enable --now proxyfibre-weblog >/dev/null 2>&1 || true
log "Historique de navigation par utilisateur déployé"

# ── Serveur PXE (installation d'OS par le réseau) ────────────────────────────
if bash "${REPO_DIR}/services/scripts/setup-pxe.sh"; then
  log "Serveur PXE déployé (boot réseau → installation OS)"
else
  log "PXE non déployé (téléchargement netboot indisponible ?) — non bloquant"
fi

# ── Démarrage ────────────────────────────────────────────────────────────────
systemctl restart freeradius
systemctl restart apache2
systemctl restart dnsmasq
# OpenNDS : stop propre + pause avant start (un "restart" trop rapide échoue avec
# "openNDS is already running... Retry later"). Son init prend ~10 s.
systemctl enable opennds >/dev/null 2>&1 || true
systemctl stop opennds 2>/dev/null || true
sleep 3
systemctl start opennds

log "──────────────────────────────────────────────────────────────"
log "Déploiement terminé."
log "  LAN ${LAN_IF} = ${LAN_IP}/${LAN_CIDR}  ·  DHCP ${DHCP_START}-${DHCP_END}"
log "  Portail FAS : http://${LAN_IP}/portal/fas.php"
[ -n "${TEST_USER:-}" ] && [ -n "${TEST_PASS:-}" ] && log "  Utilisateur de test : ${TEST_USER} / ${TEST_PASS}"
log "──────────────────────────────────────────────────────────────"

# Le mot de passe engendré n'est affiché QU'UNE FOIS, au premier déploiement : il n'est
# ensuite lisible que dans /etc/proxyfibre/admin-pass.env, réservé à root.
if [[ "${ADMIN_PASS_GENERE:-0}" = "1" ]]; then
  echo
  echo "  ╔══════════════════════════════════════════════════════════════════╗"
  echo "  ║  MOT DE PASSE DE LA CONSOLE — À NOTER MAINTENANT                 ║"
  echo "  ╠══════════════════════════════════════════════════════════════════╣"
  printf '  ║  Utilisateur : %-49s ║\n' "${ADMIN_USER}"
  printf '  ║  Mot de passe : %-48s ║\n' "${ADMIN_PASS}"
  echo "  ║                                                                  ║"
  echo "  ║  Engendré au hasard : aucun mot de passe par défaut n'existe     ║"
  echo "  ║  dans ce produit. Relisible dans /etc/proxyfibre/admin-pass.env  ║"
  echo "  ║  (accès root). Activez la double authentification dans Profil.   ║"
  echo "  ╚══════════════════════════════════════════════════════════════════╝"
  echo
fi
