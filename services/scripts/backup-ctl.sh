#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — sauvegarde / restauration de la configuration et des données.
# Appelé par la console admin via sudo. Sauvegardes dans /srv/pxe/backups.
#   create              → crée une archive horodatée (progression -> fichier d'état), affiche son nom
#   list                → nom<TAB>taille<TAB>date par sauvegarde
#   restore <fichier>   → restaure (base + config + médias) [DESTRUCTIF]
#   delete  <fichier>   → supprime une sauvegarde
#   path    <fichier>   → chemin absolu (pour le téléchargement)
#   start   <create|restore> [f] → lance l'opération en arrière-plan (progression via 'status')
#   status              → état courant : state / op / pct / step / result
#   prune   [n]         → ne conserver que les n plus récentes (défaut 8)
#   auto    <status|enable|disable|run> → sauvegarde automatique hebdomadaire (timer systemd)
set -eu

DIR=/srv/pxe/backups
[ -d /srv/pxe ] || DIR=/var/backups/bastion
mkdir -p "$DIR"
STATUS=/dev/shm/proxyfibre-backup.status

action="${1:-}"
arg="${2:-}"
# Sécurité : n'accepter que des noms de sauvegarde Bastion.
safe() { case "$(basename "$1")" in bastion-*.tar.gz) return 0 ;; *) echo "fichier refuse" >&2; exit 2 ;; esac; }

# Écrit l'état de progression (lu par la console via 'status').
setstatus() { # state op pct step [result]
  {
    echo "state=$1"; echo "op=$2"; echo "pct=$3"; echo "step=$4"; echo "result=${5:-}"
  } > "$STATUS" 2>/dev/null || true
  chmod 644 "$STATUS" 2>/dev/null || true
}

do_create() {
  ts=$(date +%Y%m%d-%H%M%S)
  setstatus running create 5 "Préparation…"
  stg=$(mktemp -d)
  # 1) Base de données (root = auth socket, pas de mot de passe).
  setstatus running create 20 "Base de données"
  mysqldump --single-transaction radius > "$stg/db.sql" 2>/dev/null || mysqldump radius > "$stg/db.sql" 2>/dev/null || true
  # 2) Configuration.
  setstatus running create 40 "Configuration"
  tar czf "$stg/config.tar.gz" -C / \
    etc/proxyfibre etc/dnsmasq.conf etc/dnsmasq.d etc/config/opennds \
    etc/samba/smb.conf etc/samba/shares.conf 2>/dev/null || true
  # 3) Médias de l'intranet (CMS).
  setstatus running create 55 "Médias de l'intranet"
  tar czf "$stg/uploads.tar.gz" -C /var/www/html/portal/intranet uploads 2>/dev/null || true
  # 4) Sauvegarde du domaine Active Directory (si présent).
  if systemctl is-active --quiet samba-ad-dc 2>/dev/null; then
    setstatus running create 75 "Domaine Active Directory"
    mkdir -p "$stg/ad"
    samba-tool domain backup offline --targetdir="$stg/ad" >/dev/null 2>&1 || true
  fi
  # 5) Manifeste + archive finale.
  {
    echo "Bastion — sauvegarde"
    echo "date: $ts"
    echo "hote: $(hostname)"
    echo "realm: $(testparm -s --parameter-name=realm 2>/dev/null || echo -)"
  } > "$stg/manifest.txt"
  setstatus running create 90 "Compression de l'archive"
  out="$DIR/bastion-$ts.tar.gz"
  tar czf "$out" -C "$stg" .
  chmod 640 "$out"; chgrp www-data "$out" 2>/dev/null || true
  rm -rf "$stg"
  setstatus done create 100 "Terminé" "bastion-$ts.tar.gz"
  echo "bastion-$ts.tar.gz"
}

do_restore() {
  safe "$1"; f="$DIR/$(basename "$1")"
  [ -f "$f" ] || { setstatus error restore 0 "Introuvable" "introuvable"; echo "introuvable" >&2; exit 2; }
  setstatus running restore 10 "Extraction de l'archive"
  stg=$(mktemp -d); tar xzf "$f" -C "$stg"
  setstatus running restore 40 "Base de données"
  [ -f "$stg/db.sql" ]         && mysql radius < "$stg/db.sql" 2>/dev/null || true
  setstatus running restore 60 "Configuration"
  [ -f "$stg/config.tar.gz" ]  && tar xzf "$stg/config.tar.gz" -C / 2>/dev/null || true
  setstatus running restore 80 "Médias de l'intranet"
  [ -f "$stg/uploads.tar.gz" ] && tar xzf "$stg/uploads.tar.gz" -C /var/www/html/portal/intranet 2>/dev/null || true
  chown -R www-data:www-data /var/www/html/portal/intranet/uploads 2>/dev/null || true
  setstatus running restore 92 "Redémarrage des services"
  systemctl reload apache2 2>/dev/null || true
  systemctl restart dnsmasq 2>/dev/null || true
  rm -rf "$stg"
  setstatus done restore 100 "Terminé" "restaure"
  echo "restaure (base + config + medias). AD non restaure automatiquement (voir stg/ad)."
}

case "$action" in
  list)
    for f in "$DIR"/bastion-*.tar.gz; do
      [ -f "$f" ] || continue
      printf '%s\t%s\t%s\n' "$(basename "$f")" "$(stat -c%s "$f")" "$(stat -c%y "$f" | cut -d. -f1)"
    done ;;

  create)  do_create ;;
  restore) do_restore "$arg" ;;

  start)
    # Lance l'opération en arrière-plan ; la console suit via 'status'.
    op="$arg"; name="${3:-}"
    cur=$(sed -n 's/^state=//p' "$STATUS" 2>/dev/null || echo idle)
    [ "$cur" = "running" ] && { echo "occupe" >&2; exit 3; }
    case "$op" in
      create)  setstatus running create 1 "Démarrage…"; setsid "$0" create  >/dev/null 2>&1 & ;;
      restore) safe "$name"; setstatus running restore 1 "Démarrage…"; setsid "$0" restore "$name" >/dev/null 2>&1 & ;;
      *) echo "op invalide" >&2; exit 2 ;;
    esac
    echo "started" ;;

  status)
    if [ -f "$STATUS" ]; then cat "$STATUS"; else echo "state=idle"; fi ;;

  prune)
    keep="${arg:-8}"
    n=0
    for f in $(ls -1t "$DIR"/bastion-*.tar.gz 2>/dev/null); do
      n=$((n+1))
      [ "$n" -le "$keep" ] || rm -f "$f"
    done
    echo "conserve $keep" ;;

  delete) safe "$arg"; rm -f "$DIR/$(basename "$arg")"; echo "supprime" ;;
  path)   safe "$arg"; echo "$DIR/$(basename "$arg")" ;;

  auto)
    case "$arg" in
      enable)  systemctl enable --now proxyfibre-backup.timer >/dev/null 2>&1 && echo "active" || echo "echec" ;;
      disable) systemctl disable --now proxyfibre-backup.timer >/dev/null 2>&1 && echo "desactive" || echo "echec" ;;
      run)     do_create >/dev/null; "$0" prune 8 >/dev/null; echo "ok" ;;
      status)
        en=$(systemctl is-enabled proxyfibre-backup.timer 2>/dev/null || true); [ -n "$en" ] || en=disabled
        ac=$(systemctl is-active  proxyfibre-backup.timer 2>/dev/null || true); [ -n "$ac" ] || ac=inactive
        nxt=$(systemctl show proxyfibre-backup.timer -p NextElapseUSecRealtime --value 2>/dev/null || true)
        # Normaliser en date lisible si la valeur est numérique (microsecondes epoch).
        case "$nxt" in
          ''|*[!0-9]*) : ;;
          *) nxt=$(date -d "@$((nxt/1000000))" "+%d/%m/%Y à %Hh%M" 2>/dev/null || echo "$nxt") ;;
        esac
        printf 'enabled=%s\nactive=%s\nnext=%s\n' "$en" "$ac" "$nxt" ;;
      *) echo "usage: auto status|enable|disable|run" >&2; exit 2 ;;
    esac ;;

  *) echo "usage: create | list | restore <f> | delete <f> | path <f> | start <op> [f] | status | prune [n] | auto <...>" >&2; exit 2 ;;
esac
