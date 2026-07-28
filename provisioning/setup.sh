#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# ─────────────────────────────────────────────────────────────────────────────
# Bastion — installation COMPLÈTE en une commande.
# Enchaîne l'installation système (install.sh) puis la configuration (deploy.sh).
# Usage :  sudo bash provisioning/setup.sh
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Lancer en root (sudo)."; exit 1; }
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==== Bastion : installation (1/2) — système ===="
bash "${DIR}/install.sh"

echo "==== Bastion : configuration (2/2) — applicatif ===="
bash "${DIR}/deploy.sh"

source "${DIR}/config.env"
cat <<EOF

════════════════════════════════════════════════════════════════
 Bastion est installé et opérationnel.

   Portail (clients LAN) : http://${LAN_IP}:2080/portal/fas.php
   Compte de test        : ${TEST_USER} / ${TEST_PASS}

   Console d'admin       : https://<ip-management>:8443/  (ou :8080 en HTTP)
   Compte admin          : ${ADMIN_USER} / ${ADMIN_PASS}
                           ⚠ à changer en production !

 Vérification : sudo bash ${DIR}/../scripts/verify.sh
════════════════════════════════════════════════════════════════
EOF
