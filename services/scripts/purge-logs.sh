#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — purge des journaux de connexion au-delà de la durée légale de rétention.
# La loi française (art. L.34-1 CPCE / R.10-13) impose une conservation d'un an.
# Usage : proxyfibre-purge-logs [jours]   (défaut : 365)
set -euo pipefail
RETENTION_DAYS="${1:-365}"
mysql -N -B radius -e "DELETE FROM pf_connlog WHERE ts < (NOW() - INTERVAL ${RETENTION_DAYS} DAY);"
