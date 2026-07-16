#!/usr/bin/env bash
# Bastion — vérifications post-installation (Phase 1)
# Usage : sudo ./scripts/verify.sh
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/../provisioning/config.env"
[[ -f /etc/proxyfibre/secrets.env ]] && source /etc/proxyfibre/secrets.env

pass=0; fail=0
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; pass=$((pass+1)); }
ko()   { printf '  \033[1;31m✗\033[0m %s\n' "$*"; fail=$((fail+1)); }
check(){ if eval "$2" >/dev/null 2>&1; then ok "$1"; else ko "$1"; fi; }

echo "── Services ─────────────────────────────────────────"
for svc in mariadb freeradius dnsmasq apache2 chilli; do
  check "service $svc actif" "systemctl is-active --quiet $svc"
done

echo "── Réseau ───────────────────────────────────────────"
check "forwarding IP activé"        "[[ \$(sysctl -n net.ipv4.ip_forward) == 1 ]]"
check "interface LAN ${LAN_IF} présente" "ip link show ${LAN_IF}"
check "interface WAN ${WAN_IF} présente" "ip link show ${WAN_IF}"
check "interface tun chilli (tun0)"  "ip link show tun0"
check "NAT masquerade sur ${WAN_IF}" "iptables -t nat -C POSTROUTING -o ${WAN_IF} -j MASQUERADE"

echo "── Portail ──────────────────────────────────────────"
check "page de login HTTP 200"       "curl -fsS -o /dev/null http://${LAN_IP}/index.php"
check "port UAM ${UAM_PORT} en écoute" "ss -lnt | grep -q ':${UAM_PORT}'"

echo "── Authentification RADIUS ──────────────────────────"
if command -v radtest >/dev/null; then
  if radtest "${TEST_USER}" "${TEST_PASS}" 127.0.0.1 0 "${RADIUS_SECRET}" 2>/dev/null | grep -q 'Access-Accept'; then
    ok "radtest ${TEST_USER} → Access-Accept"
  else
    ko "radtest ${TEST_USER} (attendu : Access-Accept)"
  fi
else
  ko "radtest introuvable (paquet freeradius-utils)"
fi

echo "─────────────────────────────────────────────────────"
printf 'Résultat : \033[1;32m%d OK\033[0m / \033[1;31m%d échec(s)\033[0m\n' "$pass" "$fail"
[[ $fail -eq 0 ]]
