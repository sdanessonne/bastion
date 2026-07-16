#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — serveur d'activation KMS (vlmcsd, émulateur KMS open source).
# Permet aux postes du domaine d'activer Windows / Office en volume (GVLK).
# Usage : sudo ./setup-kms.sh
set -euo pipefail
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"

echo "[KMS] Installation du binaire vlmcsd…"
if [ -f "${REPO_DIR}/services/kms/vlmcsd" ]; then
    install -m755 "${REPO_DIR}/services/kms/vlmcsd" /usr/local/sbin/proxyfibre-vlmcsd
else
    echo "[KMS] Binaire absent — compilation depuis les sources…"
    apt-get install -y -qq gcc make git >/dev/null
    tmp="$(mktemp -d)"; git clone --depth 1 https://github.com/Wind4/vlmcsd "$tmp/vlmcsd"
    make -C "$tmp/vlmcsd" >/dev/null
    install -m755 "$tmp/vlmcsd/bin/vlmcsd" /usr/local/sbin/proxyfibre-vlmcsd
    rm -rf "$tmp"
fi

echo "[KMS] Service systemd…"
install -m644 "${REPO_DIR}/services/systemd/proxyfibre-kms.service" /etc/systemd/system/proxyfibre-kms.service
systemctl daemon-reload
systemctl enable --now proxyfibre-kms

echo "[KMS] Ouverture du port 1688 pour les postes (accès routeur)…"
nft list chain ip nds_filter ndsRTR 2>/dev/null | grep -q "dport 1688" || \
    nft insert rule ip nds_filter ndsRTR tcp dport 1688 counter accept 2>/dev/null || true

echo "[KMS] OK. Sur un poste : slmgr /skms <ip-passerelle>:1688 puis slmgr /ato"
