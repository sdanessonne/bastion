#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — antivirus ClamAV (serveur + analyse des dossiers partagés).
# Usage : sudo ./setup-antivirus.sh
set -euo pipefail
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
export DEBIAN_FRONTEND=noninteractive

echo "[AV] Installation ClamAV…"
apt-get update -qq
apt-get install -y clamav clamav-daemon clamav-freshclam >/dev/null

echo "[AV] Première mise à jour de la base virale…"
systemctl stop clamav-freshclam 2>/dev/null || true
freshclam --stdout || true
systemctl enable --now clamav-freshclam

echo "[AV] Démarrage du moteur temps réel…"
systemctl enable --now clamav-daemon || true

echo "[AV] Helper + analyse quotidienne planifiée…"
install -m755 "${REPO_DIR}/services/scripts/clamav-ctl.sh" /usr/local/sbin/proxyfibre-clamav
mkdir -p /srv/partage/commun /srv/partage/fonctionnaires
# Analyse quotidienne des dossiers partagés + espace web (journalisée).
cat > /etc/cron.d/proxyfibre-clamav <<'CRON'
30 2 * * * root /usr/local/sbin/proxyfibre-clamav scan /srv/partage >> /var/log/proxyfibre-clamav.log 2>&1
CRON

echo "[AV] Terminé. Analyse : console admin → onglet Antivirus."
