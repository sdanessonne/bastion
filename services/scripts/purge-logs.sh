#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Purge des journaux au-delà de la durée légale de conservation.
#
# ── CE N'EST PAS UNE OPTION ─────────────────────────────────────────────────
# Le droit français (art. L.34-1 CPCE, décret n° 2021-1362) impose de conserver les
# données de connexion un an — et de les EFFACER ensuite. Conserver au-delà n'est pas
# une précaution, c'est une infraction. Le RGPD dit la même chose : limitation de la
# durée de conservation.
#
# ── CE QUE LA VERSION PRÉCÉDENTE OUBLIAIT ───────────────────────────────────
# Elle ne purgeait que pf_connlog. L'historique de navigation (pf_weblog — la donnée
# la plus sensible : qui a consulté quoi) et les sessions (radacct) étaient conservés
# INDÉFINIMENT. C'est précisément ce qu'une réquisition ou un contrôle CNIL examine.
#
# ── L'ARTICULATION AVEC LE SCELLEMENT ───────────────────────────────────────
# Chaque journée écoulée est scellée : empreinte SHA-256 des lignes, chaînée à la
# veille et signée. Effacer les lignes d'un jour scellé rend son empreinte
# INVÉRIFIABLE — et la console l'afficherait comme une ALTÉRATION, alors qu'il s'agit
# d'une purge légale. On marque donc le scellé (purged_at). La chaîne reste
# vérifiable : le scellé, le chaînage et la signature sont des valeurs STOCKÉES, que
# la purge ne touche pas. Seule la comparaison « les données correspondent-elles à
# l'empreinte » devient sans objet — les données ont légalement disparu.
#
# Usage : purge-logs.sh [jours]   (défaut : réglage log_retention_days, sinon 365)
set -euo pipefail

DB="mysql -N -B radius"

# Durée de conservation : argument, réglage de la console, ou défaut légal.
RETENTION="${1:-}"
if [ -z "$RETENTION" ]; then
    RETENTION="$($DB -e "SELECT v FROM pf_settings WHERE k='log_retention_days' LIMIT 1" 2>/dev/null || true)"
fi
case "$RETENTION" in ''|*[!0-9]*) RETENTION=365 ;; esac
# Garde-fous : un réglage aberrant — 0, ou une faute de frappe — effacerait TOUT.
[ "$RETENTION" -lt 30 ]   && { echo "REFUS: conservation de ${RETENTION} j — 30 j minimum."; exit 1; }
[ "$RETENTION" -gt 1825 ] && { echo "REFUS: conservation de ${RETENTION} j — au-delà de 5 ans, aucune base légale."; exit 1; }

LIMITE="$($DB -e "SELECT DATE(NOW() - INTERVAL ${RETENTION} DAY)")"
echo "Purge des journaux antérieurs au ${LIMITE} (conservation : ${RETENTION} jours)"

purge() {
    local t="$1" c="$2" cond="${3:-}" n
    n="$($DB -e "SELECT COUNT(*) FROM ${t} WHERE ${c} < (NOW() - INTERVAL ${RETENTION} DAY) ${cond};" 2>/dev/null || echo 0)"
    if [ "${n:-0}" -gt 0 ]; then
        $DB -e "DELETE FROM ${t} WHERE ${c} < (NOW() - INTERVAL ${RETENTION} DAY) ${cond};"
        printf '  %-14s %8s ligne(s) effacée(s)\n' "$t" "$n"
    else
        printf '  %-14s %8s\n' "$t" "à jour"
    fi
}

purge pf_weblog   ts                                    # navigation : le plus sensible
purge pf_connlog  ts                                    # connexions au portail
purge radpostauth authdate                              # tentatives d'authentification
# Sessions RADIUS : on ne purge QUE les sessions TERMINÉES. Une session encore ouverte
# depuis plus d'un an est une anomalie à examiner, pas à effacer discrètement.
purge radacct     acctstoptime "AND acctstoptime IS NOT NULL"

# Métriques système : aucune donnée personnelle, mais garder cinq ans de courbes de
# charge n'a aucun intérêt. 90 jours couvrent largement un diagnostic.
$DB -e "DELETE FROM pf_metrics WHERE ts < UNIX_TIMESTAMP(NOW() - INTERVAL 90 DAY);" 2>/dev/null || true

# ── Scellés des journées purgées ────────────────────────────────────────────
# Sans ce marquage, la console annoncerait « ALTÉRATION DÉTECTÉE » sur des journées
# légalement effacées : un faux positif qui décrédibiliserait tout le dispositif
# d'intégrité, y compris dans un dossier de réquisition.
$DB -e "ALTER TABLE pf_log_seal ADD COLUMN IF NOT EXISTS purged_at DATETIME NULL;" 2>/dev/null || true
m="$($DB -e "SELECT COUNT(*) FROM pf_log_seal WHERE purged_at IS NULL AND day < '${LIMITE}';" 2>/dev/null || echo 0)"
if [ "${m:-0}" -gt 0 ]; then
    $DB -e "UPDATE pf_log_seal SET purged_at = NOW() WHERE purged_at IS NULL AND day < '${LIMITE}';" 2>/dev/null || true
    echo "  ${m} scellé(s) marqué(s) « données purgées » — leur chaîne reste vérifiable."
fi

# Une purge légale doit pouvoir être prouvée : on en garde trace.
logger -t bastion-purge "purge effectuee : conservation ${RETENTION} j, anterieur au ${LIMITE}"
echo "Terminé."
