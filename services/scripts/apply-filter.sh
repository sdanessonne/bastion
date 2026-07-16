#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — régénère la liste de blocage DNS depuis la base et recharge dnsmasq.
# Déployé en /usr/local/sbin/proxyfibre-apply-filter, exécuté en root (via sudo).
set -euo pipefail
OUT="/etc/dnsmasq.d/proxyfibre-blocklist.conf"
TMP="$(mktemp)"
# Les domaines bloqués résolvent vers la passerelle (et non 0.0.0.0) afin de servir la
# page d'information de blocage Bastion (vhost sur 80/443).
GW_IP="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -m1 '^192\.168\.182\.' || echo 192.168.182.1)"

{
  echo "# Bastion — domaines bloqués (GÉNÉRÉ automatiquement — ne pas éditer)"
  echo "# Résolution vers ${GW_IP} → page d'information de blocage."
  # Accès root : authentification MariaDB par socket unix (pas de mot de passe).
  mysql -N -B radius -e "SELECT domain FROM pf_blocklist ORDER BY domain;" 2>/dev/null \
  | while read -r d; do
      [ -n "$d" ] || continue
      printf 'address=/%s/%s\n' "$d" "$GW_IP"
    done
} > "$TMP"

install -m644 "$TMP" "$OUT"
rm -f "$TMP"
# restart (et non reload) : dnsmasq ne relit les directives address= qu'au redémarrage.
systemctl restart dnsmasq
