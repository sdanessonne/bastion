#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — sauvegarde / restauration de la configuration et des données.
# Appelé par la console admin via sudo. Sauvegardes dans /srv/pxe/backups.
#   create              → crée une archive horodatée (progression -> fichier d'état), affiche son nom
#   list                → nom<TAB>taille<TAB>date par sauvegarde
#   restore <fichier>   → restaure (base + config + médias) [DESTRUCTIF]
#   verify  [fichier]   → EXERCICE de restauration non destructif (base + AD vers du jetable)
#   usb     <list|export <device>> → copie de la dernière sauvegarde vers une clé USB amovible
#   delete  <fichier>   → supprime une sauvegarde
#   path    <fichier>   → chemin absolu (pour le téléchargement)
#   start   <create|restore> [f] → lance l'opération en arrière-plan (progression via 'status')
#   status              → état courant : state / op / pct / step / result
#   prune   [n]         → ne conserver que les n plus récentes (défaut 8)
#   auto    <status|enable|disable|run> → sauvegarde automatique hebdomadaire (timer systemd)
#   key     <status|gen|show> → phrase secrète de chiffrement AES-256 (gpg) des archives
# Les archives contiennent l'AD (empreintes + clés BitLocker) : chiffrées si une clé existe.
set -eu

DIR=/srv/pxe/backups
[ -d /srv/pxe ] || DIR=/var/backups/bastion
mkdir -p "$DIR"
STATUS=/dev/shm/proxyfibre-backup.status
KEYFILE=/etc/proxyfibre/backup.key   # phrase secrète (600 root) ; absente = sauvegardes en clair

action="${1:-}"
arg="${2:-}"
have_key() { [ -s "$KEYFILE" ]; }
# Sécurité : n'accepter que des noms de sauvegarde Bastion (clair ou chiffré .gpg).
safe() { case "$(basename "$1")" in bastion-*.tar.gz|bastion-*.tar.gz.gpg) return 0 ;; *) echo "fichier refuse" >&2; exit 2 ;; esac; }
# Vrai si $1 est une partition sur un disque AMOVIBLE (clé/disque USB) — barrière stricte
# pour l'export USB : on n'écrit JAMAIS vers un disque système (removable=0).
is_removable_part() {
  _t=$(lsblk -rno TYPE "$1" 2>/dev/null | head -1)
  _pk=$(lsblk -rno PKNAME "$1" 2>/dev/null | head -1)
  [ "$_t" = "part" ] && [ -n "$_pk" ] && [ "$(cat "/sys/block/$_pk/removable" 2>/dev/null)" = "1" ]
}

# Un DISQUE amovible entier, sans table de partition exploitable. C'est le cas d'une
# clé neuve ou d'une clé qu'on vient d'effacer — précisément celle qu'on veut
# formater. La première version ne listait que des PARTITIONS : ces clés-là
# n'apparaissaient nulle part, et la fonction « formater » était inutile au moment
# où elle servait le plus.
# Le contrôle de taille n'est pas superflu : un lecteur de cartes vide se présente
# comme un disque amovible de 0 octet.
is_removable_disk() {
  _n=$(basename "$1")
  _t=$(lsblk -rno TYPE "$1" 2>/dev/null | head -1)
  [ "$_t" = "disk" ] || return 1
  [ "$(cat "/sys/block/$_n/removable" 2>/dev/null)" = "1" ] || return 1
  [ "$(cat "/sys/block/$_n/size" 2>/dev/null || echo 0)" -gt 0 ] || return 1
  # Jamais le disque qui porte la racine, même s'il se déclare amovible.
  _rootpk=$(lsblk -rno PKNAME "$(findmnt -no SOURCE / 2>/dev/null)" 2>/dev/null | head -1)
  [ "$_n" != "$_rootpk" ]
}

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
  raw="$DIR/bastion-$ts.tar.gz"
  tar czf "$raw" -C "$stg" .
  rm -rf "$stg"
  # Chiffrement (si une phrase secrète est configurée) : l'archive contient l'AD
  # — empreintes de mots de passe ET clés de récupération BitLocker — et la base ;
  # elle doit être protégée au repos et surtout hors de la passerelle.
  if have_key; then
    setstatus running create 96 "Chiffrement (AES-256)"
    if gpg --batch --yes --quiet --pinentry-mode loopback \
           --passphrase-file "$KEYFILE" --cipher-algo AES256 \
           -o "$raw.gpg" --symmetric "$raw" 2>/dev/null; then
      rm -f "$raw"; name="bastion-$ts.tar.gz.gpg"
    else
      name="bastion-$ts.tar.gz"   # échec du chiffrement → garder l'archive plutôt que rien
    fi
  else
    name="bastion-$ts.tar.gz"
  fi
  out="$DIR/$name"
  chmod 640 "$out"; chgrp www-data "$out" 2>/dev/null || true
  setstatus done create 100 "Terminé" "$name"
  echo "$name"
}

do_restore() {
  safe "$1"; f="$DIR/$(basename "$1")"
  [ -f "$f" ] || { setstatus error restore 0 "Introuvable" "introuvable"; echo "introuvable" >&2; exit 2; }
  stg=$(mktemp -d)
  case "$f" in
    *.gpg)
      have_key || { setstatus error restore 0 "Clé de chiffrement absente" "cle_absente"; echo "cle de chiffrement absente" >&2; rm -rf "$stg"; exit 4; }
      setstatus running restore 5 "Déchiffrement"
      if ! gpg --batch --yes --quiet --pinentry-mode loopback --passphrase-file "$KEYFILE" \
               -o "$stg/archive.tar.gz" --decrypt "$f" 2>/dev/null; then
        setstatus error restore 0 "Déchiffrement impossible (mauvaise phrase ?)" "dechiffrement"; echo "dechiffrement impossible" >&2; rm -rf "$stg"; exit 4
      fi
      setstatus running restore 10 "Extraction de l'archive"
      tar xzf "$stg/archive.tar.gz" -C "$stg"; rm -f "$stg/archive.tar.gz"
      ;;
    *)
      setstatus running restore 10 "Extraction de l'archive"
      tar xzf "$f" -C "$stg"
      ;;
  esac
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

do_verify() {
  # EXERCICE DE RESTAURATION non destructif : déchiffre l'archive, restaure la base dans
  # une base JETABLE et l'AD dans un répertoire JETABLE, compte les objets, puis nettoie.
  # Prouve que la sauvegarde est réellement RESTAURABLE (pas seulement présente et lisible).
  if [ -n "${1:-}" ]; then safe "$1"; f="$DIR/$(basename "$1")"
  else f=$(ls -1t "$DIR"/bastion-*.tar.gz "$DIR"/bastion-*.tar.gz.gpg 2>/dev/null | head -1); fi
  [ -f "$f" ] || { setstatus error verify 0 "Aucune sauvegarde" "aucune"; echo "aucune sauvegarde" >&2; exit 2; }
  setstatus running verify 8 "Préparation ($(basename "$f"))"
  stg=$(mktemp -d); enc="non"
  case "$f" in
    *.gpg)
      enc="oui"
      have_key || { setstatus error verify 0 "Clé de chiffrement absente" "cle_absente"; echo "cle absente" >&2; rm -rf "$stg"; exit 4; }
      setstatus running verify 15 "Déchiffrement"
      gpg --batch --yes --quiet --pinentry-mode loopback --passphrase-file "$KEYFILE" -o "$stg/a.tar.gz" --decrypt "$f" 2>/dev/null \
        || { setstatus error verify 0 "Déchiffrement impossible (mauvaise phrase ?)" "dechiffrement"; echo "dechiffrement KO" >&2; rm -rf "$stg"; exit 4; }
      tar xzf "$stg/a.tar.gz" -C "$stg" 2>/dev/null; rm -f "$stg/a.tar.gz" ;;
    *) tar xzf "$f" -C "$stg" 2>/dev/null ;;
  esac
  [ -f "$stg/manifest.txt" ] && rman="ok" || rman="absent"
  # 1) BASE : restaurer dans une base jetable, compter les tables.
  setstatus running verify 40 "Test de restauration de la base"
  rdb="absent"
  if [ -f "$stg/db.sql" ]; then
    rdb="ECHEC"
    mysql -e "DROP DATABASE IF EXISTS radius_verify; CREATE DATABASE radius_verify" 2>/dev/null
    if mysql radius_verify < "$stg/db.sql" 2>/dev/null; then
      nt=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='radius_verify'" 2>/dev/null)
      rdb="ok (${nt:-0} tables)"
    fi
    mysql -e "DROP DATABASE IF EXISTS radius_verify" 2>/dev/null
  fi
  # 2) AD : restaurer la sauvegarde du domaine dans un répertoire jetable, compter les comptes.
  setstatus running verify 70 "Test de restauration de l'Active Directory"
  rad="absent"
  adf=$(ls -1 "$stg"/ad/*.tar.bz2 2>/dev/null | head -1)
  if [ -n "$adf" ]; then
    rad="ECHEC"; tdir=$(mktemp -d)
    if samba-tool domain backup restore --backup-file="$adf" --newservername=VERIFYDC --targetdir="$tdir" >/dev/null 2>&1; then
      nu=$(samba-tool user list -H "$tdir/private/sam.ldb" 2>/dev/null | grep -c .)
      rad="ok (${nu:-0} comptes)"
    fi
    rm -rf "$tdir"
  fi
  rm -rf "$stg"
  res="chiffree=$enc manifest=$rman base=$rdb ad=$rad"
  setstatus done verify 100 "Vérification terminée" "$res"
  echo "$res"
  case "$rdb::$rad" in ok*::ok*) : ;; *) exit 1 ;; esac
}

case "$action" in
  list)
    for f in "$DIR"/bastion-*.tar.gz "$DIR"/bastion-*.tar.gz.gpg; do
      [ -f "$f" ] || continue
      printf '%s\t%s\t%s\n' "$(basename "$f")" "$(stat -c%s "$f")" "$(stat -c%y "$f" | cut -d. -f1)"
    done ;;

  create)  do_create ;;
  restore) do_restore "$arg" ;;
  verify)  do_verify "$arg" ;;

  start)
    # Lance l'opération en arrière-plan ; la console suit via 'status'.
    op="$arg"; name="${3:-}"
    cur=$(sed -n 's/^state=//p' "$STATUS" 2>/dev/null || echo idle)
    [ "$cur" = "running" ] && { echo "occupe" >&2; exit 3; }
    case "$op" in
      create)  setstatus running create 1 "Démarrage…"; setsid "$0" create  >/dev/null 2>&1 & ;;
      restore) safe "$name"; setstatus running restore 1 "Démarrage…"; setsid "$0" restore "$name" >/dev/null 2>&1 & ;;
      verify)  setstatus running verify 1 "Démarrage…"; setsid "$0" verify "$name" >/dev/null 2>&1 & ;;
      *) echo "op invalide" >&2; exit 2 ;;
    esac
    echo "started" ;;

  status)
    if [ -f "$STATUS" ]; then cat "$STATUS"; else echo "state=idle"; fi ;;

  prune)
    keep="${arg:-8}"
    n=0
    for f in $(ls -1t "$DIR"/bastion-*.tar.gz "$DIR"/bastion-*.tar.gz.gpg 2>/dev/null); do
      n=$((n+1))
      [ "$n" -le "$keep" ] || rm -f "$f"
    done
    echo "conserve $keep" ;;

  usb)
    # Export de la dernière sauvegarde vers une clé/disque USB AMOVIBLE (copie hors-machine
    # souveraine, sans réseau). On refuse toute cible non amovible (disque système).
    case "$arg" in
      list)
        # La console affiche une jauge : il lui faut l'OCCUPATION, pas seulement la
        # taille. Or « lsblk » ne connaît l'espace utilisé que pour un système de
        # fichiers MONTÉ. Une clé fraîchement branchée ne l'est pas forcément — on la
        # monte donc en LECTURE SEULE le temps de lire, puis on la démonte. Afficher
        # « 32 Go » sans dire combien il en reste ne renseigne sur rien : c'est
        # justement quand la clé est pleine que la copie échoue.
        lsblk -rno PATH,TYPE,FSTYPE,PKNAME 2>/dev/null | while read -r p t fst pk; do
          [ "$t" = "part" ] && [ -n "$fst" ] && [ -n "$pk" ] || continue
          [ "$(cat "/sys/block/$pk/removable" 2>/dev/null)" = "1" ] || continue
          lbl=$(lsblk -no LABEL "$p" 2>/dev/null | sed 's/[^[:print:]]//g')
          sz=$(lsblk -no SIZE "$p" 2>/dev/null | tr -d ' ')
          mp=$(findmnt -no TARGET "$p" 2>/dev/null | head -1)
          tot=0; use=0
          if [ -n "$mp" ]; then
            set -- $(df -B1 --output=size,used "$mp" 2>/dev/null | tail -1)
            tot=${1:-0}; use=${2:-0}
          else
            _tmp=$(mktemp -d)
            if mount -o ro,nosuid,nodev,noexec "$p" "$_tmp" 2>/dev/null; then
              set -- $(df -B1 --output=size,used "$_tmp" 2>/dev/null | tail -1)
              tot=${1:-0}; use=${2:-0}
              umount "$_tmp" 2>/dev/null
            fi
            rmdir "$_tmp" 2>/dev/null
          fi
          printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$p" "${lbl:-sans nom}" "$fst" "$sz" "$mp" "$tot" "$use"
        done
        # Puis les disques amovibles SANS partition exploitable : clés neuves ou
        # effacées. Elles n'ont ni étiquette ni système de fichiers — on les annonce
        # telles quelles, « à préparer », pour qu'elles soient au moins formatables.
        lsblk -rno PATH,TYPE 2>/dev/null | while read -r d t; do
          [ "$t" = "disk" ] || continue
          is_removable_disk "$d" || continue
          # A-t-il déjà une partition avec un système de fichiers ? Alors il est
          # couvert par la boucle précédente, et le lister deux fois n'aiderait pas.
          if lsblk -rno TYPE,FSTYPE "$d" 2>/dev/null | awk '$1=="part" && $2!=""{f=1} END{exit !f}'; then
            continue
          fi
          sz=$(lsblk -dno SIZE "$d" 2>/dev/null | tr -d ' ')
          printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$d" "clé non préparée" "aucun" "$sz" "" "0" "0"
        done ;;
      export)
        dev="${3:-}"
        [ -n "$dev" ] || { echo "usage: usb export <device>" >&2; exit 2; }
        is_removable_part "$dev" || { echo "REFUS: $dev n'est pas une partition USB amovible" >&2; exit 2; }
        bk=$(ls -1t "$DIR"/bastion-*.tar.gz.gpg "$DIR"/bastion-*.tar.gz 2>/dev/null | head -1)
        [ -f "$bk" ] || { echo "aucune sauvegarde a exporter" >&2; exit 2; }
        mp=$(findmnt -no TARGET "$dev" 2>/dev/null | head -1); here=0
        if [ -z "$mp" ]; then
          mp=$(mktemp -d)
          mount -o rw,nosuid,nodev,noexec "$dev" "$mp" 2>/dev/null || { rmdir "$mp" 2>/dev/null; echo "montage impossible ($dev)" >&2; exit 1; }
          here=1
        fi
        dst="$mp/Bastion-sauvegardes"; mkdir -p "$dst" 2>/dev/null
        if cp -f "$bk" "$dst/" 2>/dev/null; then sync; msg="exporte: $(basename "$bk") vers $dev"; rc=0
        else msg="ECHEC de la copie vers $dev"; rc=1; fi
        [ "$here" = 1 ] && { umount "$mp" 2>/dev/null; rmdir "$mp" 2>/dev/null; }
        echo "$msg"; exit "$rc" ;;
      format)
        # ── EFFACEMENT TOTAL D'UNE CLÉ ────────────────────────────────────────
        # La seule commande de ce dépôt qui détruit des données à la demande d'une
        # page web. Les garde-fous ne sont donc pas négociables, et ils sont ici —
        # pas dans le formulaire : une validation qui n'existe qu'en PHP protège
        # l'utilisateur distrait, jamais le système.
        dev="${3:-}"; fs="${4:-exfat}"
        [ -n "$dev" ] || { echo "usage: usb format <device> [exfat|ntfs|fat32]" >&2; exit 2; }
        # Deux cas : une partition existante, ou un DISQUE entier sans table de
        # partition — la clé neuve. Dans le second on prépare le support, sinon la
        # fonction serait inutile là où elle sert le plus.
        entier=0
        if is_removable_part "$dev"; then
            entier=0
        elif is_removable_disk "$dev"; then
            entier=1
        else
            echo "REFUS: $dev n'est ni une partition ni un disque USB amovible" >&2; exit 2
        fi
        # Une partition amovible peut malgré tout porter le système : un serveur
        # démarré sur clé USB, ou la clé d'installation encore branchée. On refuse
        # tout ce qui est monté sur un chemin vital, et le support d'amorçage.
        _mp=$(findmnt -no TARGET "$dev" 2>/dev/null | head -1)
        case "$_mp" in
          /|/boot|/boot/*|/usr|/usr/*|/var|/var/*|/home|/home/*|/etc|/etc/*|/srv|/srv/*)
            echo "REFUS: $dev est monte sur $_mp — partition systeme" >&2; exit 2 ;;
        esac
        _root=$(findmnt -no SOURCE / 2>/dev/null | head -1)
        [ "$dev" = "$_root" ] && { echo "REFUS: $dev porte le systeme de fichiers racine" >&2; exit 2; }

        case "$fs" in
          exfat) mk="mkfs.exfat"; opt="-n BASTION" ;;
          ntfs)  mk="mkfs.ntfs";  opt="-f -L BASTION" ;;
          fat32) mk="mkfs.vfat";  opt="-F 32 -n BASTION" ;;
          *) echo "REFUS: systeme de fichiers inconnu ($fs)" >&2; exit 2 ;;
        esac
        command -v "$mk" >/dev/null 2>&1 || {
            echo "ECHEC: $mk absent. Installer exfatprogs (exfat), ntfs-3g (ntfs) ou dosfstools (fat32)." >&2
            exit 1; }

        # exFAT et NTFS sont préférables à FAT32 : celui-ci plafonne les fichiers à
        # 4 Go, et une sauvegarde chiffrée les dépasse vite. La console propose donc
        # exFAT par défaut.
        [ -n "$_mp" ] && { umount "$dev" 2>/dev/null || { echo "ECHEC: $dev est occupe (demonter d'abord)" >&2; exit 1; }; }

        cible="$dev"
        if [ "$entier" = 1 ]; then
            # Clé neuve : on lui pose une table de partition et UNE partition unique
            # occupant tout le support. Table MS-DOS et non GPT : c'est ce que tous les
            # Windows savent lire sans discuter, et une clé de sauvegarde a vocation à
            # être relue sur n'importe quel poste, y compris ancien.
            command -v sfdisk >/dev/null 2>&1 || { echo "ECHEC: sfdisk absent (paquet util-linux)" >&2; exit 1; }
            for _p in $(lsblk -rno PATH,TYPE "$dev" 2>/dev/null | awk '$2=="part"{print $1}'); do
                umount "$_p" 2>/dev/null || true
            done
            wipefs -a "$dev" >/dev/null 2>&1 || true
            printf 'label: dos\n,,07\n' | sfdisk "$dev" >/dev/null 2>&1 || {
                echo "ECHEC: table de partition impossible sur $dev" >&2; exit 1; }
            partprobe "$dev" >/dev/null 2>&1 || true
            # Le noyau met un instant à publier la nouvelle partition : sans cette
            # attente, mkfs échouerait sur un fichier de périphérique inexistant.
            _n=0
            while [ $_n -lt 10 ]; do
                cible=$(lsblk -rno PATH,TYPE "$dev" 2>/dev/null | awk '$2=="part"{print $1; exit}')
                [ -b "$cible" ] && break
                sleep 1; _n=$((_n + 1))
            done
            [ -b "$cible" ] || { echo "ECHEC: partition non apparue sur $dev" >&2; exit 1; }
        fi

        if $mk $opt "$cible" >/dev/null 2>&1; then
            sync
            echo "formate: $cible en $fs (etiquette BASTION)"
            exit 0
        else
            echo "ECHEC du formatage de $cible en $fs" >&2
            exit 1
        fi ;;
      *) echo "usage: usb list | export <device> | format <device> [fs]" >&2; exit 2 ;;
    esac ;;

  offsite)
    # Copie HORS-SITE de la dernière sauvegarde (déjà chiffrée) vers un partage réseau SMB
    # — NAS ou 2e passerelle — via smbclient (aucun montage). Reste ON-PREM : rien dans le cloud.
    # Une panne matérielle de la passerelle ne fait alors plus tout perdre.
    OENV=/etc/proxyfibre/offsite.env
    STATE=/etc/proxyfibre/offsite.state
    oget() { sed -n "s/^$1=//p" "$OENV" 2>/dev/null | head -1; }
    case "$arg" in
      set)
        h="${3:-}"; shr="${4:-}"; us="${5:-}"; pw="${6:-}"; sub="${7:-Bastion}"
        [ -n "$h" ] && [ -n "$shr" ] && [ -n "$us" ] || { echo "usage: offsite set <hote> <partage> <user> <pass> [sous-dossier]" >&2; exit 2; }
        mkdir -p /etc/proxyfibre; umask 077
        { printf 'OFFSITE_HOST=%s\n'   "$h"
          printf 'OFFSITE_SHARE=%s\n'  "$shr"
          printf 'OFFSITE_USER=%s\n'   "$us"
          printf 'OFFSITE_PASS=%s\n'   "$pw"
          printf 'OFFSITE_SUBDIR=%s\n' "$sub"
          printf 'OFFSITE_AUTO=1\n'; } > "$OENV"
        chmod 600 "$OENV"; chown root:root "$OENV" 2>/dev/null || true
        echo "enregistre" ;;
      off)  rm -f "$OENV" "$STATE"; echo "desactive" ;;
      auto)
        [ -f "$OENV" ] || { echo "aucune destination" >&2; exit 2; }
        case "${3:-}" in
          on)  sed -i 's/^OFFSITE_AUTO=.*/OFFSITE_AUTO=1/' "$OENV"; grep -q '^OFFSITE_AUTO=' "$OENV" || echo 'OFFSITE_AUTO=1' >> "$OENV"; echo "on" ;;
          off) sed -i 's/^OFFSITE_AUTO=.*/OFFSITE_AUTO=0/' "$OENV"; grep -q '^OFFSITE_AUTO=' "$OENV" || echo 'OFFSITE_AUTO=0' >> "$OENV"; echo "off" ;;
          *) echo "usage: offsite auto on|off" >&2; exit 2 ;;
        esac ;;
      status)
        if [ -f "$OENV" ]; then
          echo "configured=yes"
          echo "host=$(oget OFFSITE_HOST)"
          echo "share=$(oget OFFSITE_SHARE)"
          echo "user=$(oget OFFSITE_USER)"
          echo "subdir=$(oget OFFSITE_SUBDIR)"
          echo "auto=$(oget OFFSITE_AUTO)"
          [ -f "$STATE" ] && cat "$STATE"
        else
          echo "configured=no"
        fi ;;
      test|push)
        [ -f "$OENV" ] || { echo "aucune destination configuree" >&2; exit 2; }
        H=$(oget OFFSITE_HOST); SH=$(oget OFFSITE_SHARE); U=$(oget OFFSITE_USER)
        P=$(oget OFFSITE_PASS); SUB=$(oget OFFSITE_SUBDIR); [ -n "$SUB" ] || SUB=Bastion
        # Fichier d'authentification temporaire : le mot de passe n'apparaît PAS dans argv/ps,
        # et un « % » ou une espace dans le mot de passe ne casse plus l'appel.
        af=$(mktemp); chmod 600 "$af"
        printf 'username = %s\npassword = %s\n' "$U" "$P" > "$af"
        if [ "$arg" = "test" ]; then
          if smbclient "//$H/$SH" -A "$af" -c 'ls' >/dev/null 2>&1; then rc=0; msg="ok: partage joignable"; else rc=1; msg="ECHEC: partage injoignable ou identifiants invalides"; fi
          rm -f "$af"; [ "$rc" = 0 ] && echo "$msg" || { echo "$msg" >&2; exit 1; }
        else
          bk=$(ls -1t "$DIR"/bastion-*.tar.gz.gpg "$DIR"/bastion-*.tar.gz 2>/dev/null | head -1)
          [ -f "$bk" ] || { rm -f "$af"; echo "aucune sauvegarde a envoyer" >&2; exit 2; }
          smbclient "//$H/$SH" -A "$af" -c "mkdir \"$SUB\"" >/dev/null 2>&1 || true
          when=$(date '+%Y-%m-%d %H:%M:%S')
          if smbclient "//$H/$SH" -A "$af" -c "cd \"$SUB\"; put \"$bk\" \"$(basename "$bk")\"" >/dev/null 2>&1; then
            printf 'last_status=ok\nlast_file=%s\nlast_at=%s\n' "$(basename "$bk")" "$when" > "$STATE"; chmod 600 "$STATE"
            rm -f "$af"; echo "envoye: $(basename "$bk") vers //$H/$SH/$SUB"
          else
            printf 'last_status=fail\nlast_at=%s\n' "$when" > "$STATE"; chmod 600 "$STATE"
            rm -f "$af"; echo "ECHEC de l'envoi vers //$H/$SH" >&2; exit 1
          fi
        fi ;;
      *) echo "usage: offsite set|off|auto <on|off>|status|test|push" >&2; exit 2 ;;
    esac ;;

  key)
    # Phrase secrète de chiffrement des sauvegardes (AES-256 via gpg).
    case "$arg" in
      status)  if have_key; then echo "key=yes"; echo "algo=AES256"; else echo "key=no"; fi ;;
      gen)
        # Génère une phrase aléatoire (si absente) et l'affiche UNE fois pour archivage hors-machine.
        if have_key && [ "${3:-}" != "force" ]; then echo "existe"; exit 0; fi
        mkdir -p /etc/proxyfibre
        pass=$(openssl rand -base64 30 | tr -d '\n/+=' | cut -c1-32)
        [ -n "$pass" ] || { echo "echec" >&2; exit 1; }
        printf '%s' "$pass" > "$KEYFILE"
        chmod 600 "$KEYFILE"; chown root:root "$KEYFILE" 2>/dev/null || true
        echo "$pass" ;;
      show)    have_key && cat "$KEYFILE" || { echo "aucune" >&2; exit 2; } ;;
      *) echo "usage: key status|gen [force]|show" >&2; exit 2 ;;
    esac ;;

  delete) safe "$arg"; rm -f "$DIR/$(basename "$arg")"; echo "supprime" ;;
  path)   safe "$arg"; echo "$DIR/$(basename "$arg")" ;;

  auto)
    case "$arg" in
      enable)  systemctl enable --now proxyfibre-backup.timer >/dev/null 2>&1 && echo "active" || echo "echec" ;;
      disable) systemctl disable --now proxyfibre-backup.timer >/dev/null 2>&1 && echo "desactive" || echo "echec" ;;
      run)     do_create >/dev/null; "$0" prune 8 >/dev/null
               # Poussée hors-site auto après la sauvegarde planifiée (si activée).
               [ "$(sed -n 's/^OFFSITE_AUTO=//p' /etc/proxyfibre/offsite.env 2>/dev/null)" = "1" ] && "$0" offsite push >/dev/null 2>&1 || true
               echo "ok" ;;
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

  *) echo "usage: create | list | restore <f> | delete <f> | path <f> | start <op> [f] | status | prune [n] | auto <...> | key <status|gen|show>" >&2; exit 2 ;;
esac
