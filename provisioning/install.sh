#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# ─────────────────────────────────────────────────────────────────────────────
# Bastion — installation SYSTÈME (étape 1/2)
# Installe la stack (paquets), active le routage IP, prépare la base RADIUS.
# Étape 2/2 : provisioning/deploy.sh (configuration applicative).
# Ou tout-en-un : provisioning/setup.sh
# Cible : Debian 12/13. Idempotent.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

log(){ printf '\033[1;32m[Bastion]\033[0m %s\n' "$*"; }
die(){ printf '\033[1;31m[Bastion]\033[0m %s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "Ce script doit être lancé en root (sudo)."
command -v apt-get >/dev/null || die "Ce script cible Debian/Ubuntu (apt introuvable)."

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ -f "${SCRIPT_DIR}/config.env" ]] || die "config.env introuvable."
source "${SCRIPT_DIR}/config.env"

# ── 1. Paquets ───────────────────────────────────────────────────────────────
log "Installation de la stack (opennds, freeradius, mariadb, dnsmasq, apache, php)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
  opennds \
  freeradius freeradius-mysql freeradius-utils \
  mariadb-server \
  dnsmasq \
  apache2 libapache2-mod-php php php-mysql php-yaml php-mbstring php-zip php-gd \
  nftables openssl gettext-base curl ca-certificates \
  qrencode poppler-utils wimtools

# ── 2. Routage IP ────────────────────────────────────────────────────────────
log "Activation du routage IP…"
cat > /etc/sysctl.d/99-proxyfibre.conf <<'EOF'
net.ipv4.ip_forward = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
EOF
sysctl --system >/dev/null

# ── 3. Secrets + base RADIUS ─────────────────────────────────────────────────
mkdir -p /etc/proxyfibre
if [[ ! -f /etc/proxyfibre/secrets.env ]]; then
  gen(){ head -c 32 /dev/urandom | sha256sum | cut -c1-64; }
  umask 077
  printf 'RADIUS_SECRET="%s"\nFAS_KEY="%s"\nDB_PASS="%s"\n' "$(gen)" "$(gen)" "$(gen)" \
    > /etc/proxyfibre/secrets.env
  log "Secrets générés → /etc/proxyfibre/secrets.env"
fi
source /etc/proxyfibre/secrets.env

systemctl enable --now mariadb >/dev/null 2>&1 || true
log "Création de la base ${DB_NAME}…"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
# Schéma FreeRADIUS (radcheck, radusergroup, radacct…)
RLM_SCHEMA="$(ls /etc/freeradius/3.*/mods-config/sql/main/mysql/schema.sql 2>/dev/null | head -1)"
[[ -f "$RLM_SCHEMA" ]] && mysql "${DB_NAME}" < "$RLM_SCHEMA" 2>/dev/null || true

log "──────────────────────────────────────────────────────────────"
log "Stack installée. Étape suivante :  sudo bash ${SCRIPT_DIR}/deploy.sh"
log "──────────────────────────────────────────────────────────────"
