#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# proxyfibre-anomaly — détection d'anomalies réseau / Active Directory.
# Trois détecteurs, comparés à une base de référence stockée en base :
#   1. nouvel appareil sur le LAN (baux dnsmasq vs MAC connues) ;
#   2. changement des membres des groupes d'administration AD ;
#   3. GPO ajoutée / supprimée / version changée « hors console ».
# Les anomalies sont écrites dans « pf_anomaly » (non acquittées) : la console les affiche
# et « sys_alerts() » les remonte dans le canal d'alerte existant (courriel + tableau de bord).
#
# « Apprendre en silence d'abord » : au PREMIER scan, l'état courant devient la référence
# SANS alerte ; seules les nouveautés APPARUES ENSUITE sont signalées.
#
# Usage : proxyfibre-anomaly scan | status
set -u
DB=radius
ST=/usr/bin/samba-tool
action="${1:-scan}"

# Fichier de baux dnsmasq (emplacements Debian usuels).
LEASES=/var/lib/misc/dnsmasq.leases
[ -f "$LEASES" ] || LEASES=/var/lib/dnsmasq/dnsmasq.leases

msql() { mysql -N "$DB" "$@" 2>/dev/null; }
esc()  { printf '%s' "$1" | sed "s/'/''/g"; }

ensure_tables() {
  mysql "$DB" 2>/dev/null <<'SQL'
CREATE TABLE IF NOT EXISTS pf_anomaly (
  id INT AUTO_INCREMENT PRIMARY KEY, ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  type VARCHAR(16) NOT NULL, severity VARCHAR(8) NOT NULL DEFAULT 'warn',
  detail VARCHAR(255) NOT NULL, sig CHAR(40) NOT NULL UNIQUE,
  acknowledged TINYINT(1) NOT NULL DEFAULT 0, ack_by VARCHAR(64) NULL, ack_at DATETIME NULL);
CREATE TABLE IF NOT EXISTS pf_anomaly_state (
  k VARCHAR(48) PRIMARY KEY, v MEDIUMTEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);
SQL
}

# --raw : SANS ça, le client mysql échappe les retours à la ligne d'une valeur multi-lignes
# en « \n » LITTÉRAL — la référence reviendrait sur une seule ligne et TOUT paraîtrait changé.
state_get() { mysql -N --raw "$DB" 2>/dev/null -e "SELECT v FROM pf_anomaly_state WHERE k='$(esc "$1")'"; }
state_set() { mysql "$DB" 2>/dev/null -e "INSERT INTO pf_anomaly_state (k,v) VALUES ('$(esc "$1")','$(esc "$2")') ON DUPLICATE KEY UPDATE v=VALUES(v)"; }
# Enregistre une anomalie (idempotent via sig : une même anomalie n'est pas répétée).
record() { # type severity detail
  sig=$(printf '%s|%s' "$1" "$3" | sha1sum | cut -d' ' -f1)
  mysql "$DB" 2>/dev/null -e "INSERT IGNORE INTO pf_anomaly (type,severity,detail,sig) VALUES ('$(esc "$1")','$(esc "$2")','$(esc "$3")','$sig')"
}
# Différence ensembliste : lignes de $1 absentes de $2 (compatibles dash, sans <()).
minus() {
  bf=$(mktemp); printf '%s\n' "$2" > "$bf"
  printf '%s\n' "$1" | grep -vxF -f "$bf" 2>/dev/null | grep .
  rm -f "$bf"
}

detect_lan() {
  [ -f "$LEASES" ] || return 0
  cur=$(awk '{print tolower($2)}' "$LEASES" 2>/dev/null | grep -E '^([0-9a-f]{2}:){5}[0-9a-f]{2}$' | sort -u)
  res=$(msql -e "SELECT LOWER(mac) FROM pf_dhcp" | grep .)          # réservations = toujours connues
  known=$(printf '%s\n%s\n' "$(state_get lan_macs)" "$res" | sort -u | grep .)
  if [ -z "$(state_get lan_init)" ]; then
    state_set lan_macs "$(printf '%s\n%s\n' "$cur" "$res" | sort -u | grep .)"
    state_set lan_init 1
    return 0
  fi
  minus "$cur" "$known" | while IFS= read -r m; do
    [ -n "$m" ] || continue
    ip=$(awk -v M="$m" 'tolower($2)==M{print $3; exit}' "$LEASES")
    host=$(awk -v M="$m" 'tolower($2)==M{print $4; exit}' "$LEASES")
    record lan warn "Nouvel appareil sur le réseau : $m ($ip ${host:-nom inconnu})"
  done
  state_set lan_macs "$(printf '%s\n%s\n' "$known" "$cur" | sort -u | grep .)"
}

detect_admins() {
  cur=$(for g in "Domain Admins" "Administrators" "Enterprise Admins" "Schema Admins"; do
          "$ST" group listmembers "$g" 2>/dev/null; done | sort -u | grep .)
  [ -n "$cur" ] || return 0                                          # AD injoignable : on ne touche à rien
  prev=$(state_get ad_admins)
  if [ -z "$(state_get ad_init)" ]; then
    state_set ad_admins "$cur"; state_set ad_init 1; return 0
  fi
  minus "$cur" "$prev" | while IFS= read -r u; do [ -n "$u" ] && record admin danger "Compte AJOUTÉ aux administrateurs AD : $u"; done
  minus "$prev" "$cur" | while IFS= read -r u; do [ -n "$u" ] && record admin danger "Compte RETIRÉ des administrateurs AD : $u"; done
  state_set ad_admins "$cur"
}

detect_gpo() {
  cur=$("$ST" gpo listall 2>/dev/null | awk '/^GPO/{g=$3} /^version/{print g" "$NF}' | sort)
  [ -n "$cur" ] || return 0
  prev=$(state_get gpos)
  if [ -z "$(state_get gpo_init)" ]; then
    state_set gpos "$cur"; state_set gpo_init 1; return 0
  fi
  # Suppression du bruit : si la console a fait une action GPO récemment, les changements
  # sont ATTENDUS — on met à jour la référence sans alerter.
  # Toute action de la page Active Directory est journalisée « ad.<verbe> ». On teste donc le
  # PRÉFIXE et non une liste de verbes : cette liste devait être rallongée à chaque nouvelle
  # fonctionnalité de la console, et l'oubli produisait une fausse alerte « hors console »
  # (constaté deux fois : déploiement des lecteurs, puis écran de connexion et filtres WMI).
  console=$(msql -e "SELECT COUNT(*) FROM pf_audit WHERE action LIKE 'ad.%' AND ts >= DATE_SUB(NOW(), INTERVAL 25 MINUTE)")
  console=${console:-0}
  if [ "$console" -eq 0 ] 2>/dev/null; then
    # GUID seuls, pour repérer ajouts / suppressions.
    cg=$(printf '%s\n' "$cur"  | awk '{print $1}' | sort)
    pg=$(printf '%s\n' "$prev" | awk '{print $1}' | sort)
    minus "$cg" "$pg" | while IFS= read -r g; do [ -n "$g" ] && record gpo danger "GPO AJOUTÉE hors console : $g"; done
    minus "$pg" "$cg" | while IFS= read -r g; do [ -n "$g" ] && record gpo danger "GPO SUPPRIMÉE hors console : $g"; done
    # Changements de version (GUID présent des deux côtés, version différente).
    pf=$(mktemp); printf '%s\n' "$prev" > "$pf"
    printf '%s\n' "$cur" | while IFS= read -r line; do
      [ -n "$line" ] || continue
      g=$(printf '%s' "$line" | awk '{print $1}'); v=$(printf '%s' "$line" | awk '{print $2}')
      ov=$(awk -v G="$g" '$1==G{print $2; exit}' "$pf")
      [ -n "$ov" ] && [ "$ov" != "$v" ] && record gpo warn "GPO modifiée hors console : $g (version $ov → $v)"
    done
    rm -f "$pf"
  fi
  state_set gpos "$cur"
}

case "$action" in
  scan)
    ensure_tables
    detect_lan
    detect_admins
    detect_gpo
    state_set last_scan "$(date '+%Y-%m-%d %H:%M:%S')"
    n=$(msql -e "SELECT COUNT(*) FROM pf_anomaly WHERE acknowledged=0"); n=${n:-0}
    echo "scan termine ; anomalies non acquittees : $n"
    ;;
  status)
    ensure_tables
    echo "last_scan=$(state_get last_scan)"
    echo "non_acquittees=$(msql -e 'SELECT COUNT(*) FROM pf_anomaly WHERE acknowledged=0')"
    ;;
  *) echo "usage: proxyfibre-anomaly scan|status" >&2; exit 2 ;;
esac
