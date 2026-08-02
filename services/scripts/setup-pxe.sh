#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — met en place le serveur PXE (installation d'OS par le réseau).
# - TFTP root /srv/tftp : notre iPXE (undionly.kpxe) + netboot Debian (EFI, kernel, initrd)
# - Boot HTTP : kernel/initrd + script iPXE servis par Apache sur le port 2080
# Nécessite un accès Internet (télécharge le netboot Debian). Idempotent.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"        # services/
REPO_DIR="$(cd "${REPO_DIR}/.." && pwd)"          # racine du repo

# ── Injection du menu dans boot.wim : ISOLEE, car appelee de DEUX endroits ──────
# 1) lors d'une installation complete, a sa place dans le deroule ci-dessous ;
# 2) par « setup-pxe.sh menu », que proxyfibre-selfupdate lance apres chaque mise a
#    jour du depot. Sans ce second appel, une correction du menu poussee sur Git
#    restait lettre morte : boot.wim gardait l'ancienne version et les postes
#    demarraient dessus. Rejouer TOUTE l'installation a chaque mise a jour serait
#    disproportionne et redemarrerait des services en production.
# La fonction est definie ICI, avant l'aiguillage : bash exige qu'une fonction soit
# connue au moment de l'appel.
injecter_menu() {
    WIMB=/var/www/html/boot/win11/boot.wim
    STAMP=/srv/pxe/.startnet-injected
    if [ -f "$WIMB" ] && command -v wimlib-imagex >/dev/null 2>&1; then
      # Empreinte de CE QUI EST INJECTÉ : toute modification du menu ou de winpeshl.ini
      # déclenche la ré-injection. (Un simple drapeau « déjà fait » figeait boot.wim sur
      # une vieille version du script — modifier le menu n'avait alors aucun effet.)
      SUM="$(cat "${REPO_DIR}/services/tftp/startnet.cmd" "${REPO_DIR}/services/tftp/winpeshl.ini" \
             | sha256sum | cut -d' ' -f1)"
      if [ "$SUM" != "$(cat "$STAMP" 2>/dev/null || true)" ]; then
        echo "[PXE] Injection du menu de déploiement dans boot.wim…"
        mkdir -p /srv/pxe/backup
        [ -f /srv/pxe/backup/boot.wim.orig ] || cp "$WIMB" /srv/pxe/backup/boot.wim.orig
        # Sources non vides, VÉRIFIÉ AVANT de toucher à boot.wim : une redirection « > »
        # crée le fichier de sortie même quand la commande qui l'alimente échoue. Un
        # winpeshl.ini vide part sans erreur et le poste démarre alors SANS menu.
        for f in startnet.cmd winpeshl.ini; do
          [ -s "${REPO_DIR}/services/tftp/${f}" ] || \
            { echo "  ABANDON : ${REPO_DIR}/services/tftp/${f} absent ou vide."; exit 1; }
        done
        # Le script vient d'un VRAI fichier du dépôt : surtout PAS d'ici-document, qui écrase
        # les « \\ » du chemin UNC (\\serveur\partage) à travers les couches de quoting.
        # UTF-8 (dépôt) → CP437 (console WinPE) : sans iconv, les cadres semi-graphiques du
        # menu sortiraient en mojibake. CRLF obligatoire (batch).
        sed -e "s|__DNS_IP__|${DNS_IP:-192.168.182.2}|g" -e 's/\r*$/\r/' \
          "${REPO_DIR}/services/tftp/startnet.cmd" | iconv -f UTF-8 -t CP437 > /tmp/pf-startnet.cmd
        sed -e 's/\r*$/\r/' "${REPO_DIR}/services/tftp/winpeshl.ini" > /tmp/pf-winpeshl.ini
        chmod u+w "$WIMB"
        if printf 'delete --force /Windows/System32/startnet.cmd\nadd /tmp/pf-startnet.cmd /Windows/System32/startnet.cmd\ndelete --force /Windows/System32/winpeshl.ini\nadd /tmp/pf-winpeshl.ini /Windows/System32/winpeshl.ini\n' \
             | wimlib-imagex update "$WIMB" 2 >/dev/null 2>&1 \
           && wimlib-imagex info "$WIMB" --boot 2 >/dev/null 2>&1; then
          printf '%s\n' "$SUM" > "$STAMP"
          echo "  menu injecté (index 2)."
        else
          echo "  ATTENTION : injection échouée — boot.wim laissé inchangé."
        fi
        chmod a+rX,u-w "$WIMB"; rm -f /tmp/pf-startnet.cmd /tmp/pf-winpeshl.ini
      fi
    fi
}

if [ "${1:-}" = "menu" ]; then
    injecter_menu
    exit 0
fi
source "${REPO_DIR}/provisioning/config.env"

NETBOOT_URL="${NETBOOT_URL:-https://deb.debian.org/debian/dists/trixie/main/installer-amd64/current/images/netboot/netboot.tar.gz}"

echo "[PXE] Préparation du TFTP root…"
mkdir -p /srv/tftp
if [[ ! -d /srv/tftp/debian-installer ]]; then
  curl -fsSL --max-time 180 "$NETBOOT_URL" -o /tmp/netboot.tar.gz
  tar -xzf /tmp/netboot.tar.gz -C /srv/tftp
  rm -f /tmp/netboot.tar.gz
fi
# Notre iPXE complet (compilé, avec HTTP + PNG + script embarqué + clavier AZERTY).
# C'est ipxe.pxe qui est SERVI (pilotes natifs) : les variantes undionly (.kpxe/.kkpxe)
# dépendent de la pile UNDI de la ROM et leur RÉCEPTION est cassée derrière la ROM iPXE de
# VirtualBox → « DHCP failed ». Voir services/dnsmasq/pxe.conf. Les undionly sont conservés
# en repli (utiles sur du matériel où les pilotes natifs manqueraient).
install -m644 "${REPO_DIR}/services/tftp/ipxe.pxe"       /srv/tftp/ipxe.pxe
install -m644 "${REPO_DIR}/services/tftp/undionly.kkpxe" /srv/tftp/undionly.kkpxe
install -m644 "${REPO_DIR}/services/tftp/undionly.kpxe"  /srv/tftp/undionly.kpxe
chmod -R a+rX /srv/tftp

echo "[PXE] Fichiers de boot HTTP (port 2080)…"
install -d -m755 /var/www/html/boot/debian
cp /srv/tftp/debian-installer/amd64/linux     /var/www/html/boot/debian/linux
cp /srv/tftp/debian-installer/amd64/initrd.gz /var/www/html/boot/debian/initrd.gz
sed "s|__LAN_IP__|${LAN_IP}|g" "${REPO_DIR}/services/tftp/boot.ipxe" > /var/www/html/boot/boot.ipxe
chmod -R a+rX /var/www/html/boot

echo "[PXE] Menu protégé par les identifiants administrateur…"
# Menu validé côté serveur contre les comptes admin (table pf_admins).
# Les identifiants de base sont lus dans /etc/proxyfibre/admin.env (déjà en place).
install -m644 "${REPO_DIR}/portal/menu.php" /var/www/html/boot/menu.php
# Nettoyage : l'ancien mot de passe PXE dédié n'est plus utilisé.
rm -f /etc/proxyfibre/pxe.env
# Fond d'écran du menu : bannière brandée fournie dans le repo (logo Bastion),
# sinon génération basique de secours si ImageMagick est présent.
if [[ -f "${REPO_DIR}/services/tftp/menu-bg.png" ]]; then
  install -m644 "${REPO_DIR}/services/tftp/menu-bg.png" /var/www/html/boot/menu-bg.png
elif command -v convert >/dev/null && [[ ! -f /var/www/html/boot/menu-bg.png ]]; then
  convert -size 1024x768 gradient:'#16305a'-'#0b1120' \
    -gravity North \
    -font DejaVu-Sans-Bold -pointsize 78 -fill '#38bdf8' -annotate +0+72 'BASTION' \
    -font DejaVu-Sans -pointsize 27 -fill '#cbd5e1' -annotate +0+178 'Déploiement réseau — Menu de démarrage' \
    -fill '#28374f' -draw 'rectangle 312,232 712,235' \
    -gravity South \
    -font DejaVu-Sans -pointsize 19 -fill '#7c8db0' -annotate +0+64 'Sélectionnez un système à installer ou démarrer' \
    -font DejaVu-Sans -pointsize 15 -fill '#4a5878' -annotate +0+28 '© Mickaël MONESTIER — Tous droits réservés' \
    -depth 8 -strip PNG24:/var/www/html/boot/menu-bg.png 2>/dev/null || true
fi
chmod -R a+rX /var/www/html/boot

# ── Source d'installation Windows (partage SMB) ──────────────────────────────
# WinPE démarré par PXE ne contient PAS la source d'installation : sans elle, le
# programme d'installation affiche « aucun pilote détecté / pilote de support manquant ».
# On monte l'ISO Windows en lecture seule et on l'expose en partage SMB [Install],
# que WinPE monte avec « net use » avant de lancer setup.exe.
if [ -f /srv/pxe/iso/win11.iso ]; then
  echo "[PXE] Source d'installation Windows (montage ISO + partage SMB)…"
  mkdir -p /srv/pxe/mnt/win11
  mountpoint -q /srv/pxe/mnt/win11 || mount -o loop,ro /srv/pxe/iso/win11.iso /srv/pxe/mnt/win11 2>/dev/null || true
  grep -q "/srv/pxe/mnt/win11" /etc/fstab 2>/dev/null || \
    echo "/srv/pxe/iso/win11.iso /srv/pxe/mnt/win11 udf,iso9660 loop,ro,nofail 0 0" >> /etc/fstab
  # Samba peut ne pas être encore installé : il arrive avec setup-ad.sh, qui n'est
  # pas un prérequis de ce script. Sans ce garde-fou, la redirection « >> » vers un
  # répertoire inexistant faisait mourir setup-pxe.sh EN PLEIN MILIEU, avec pour
  # seule trace « /etc/samba/shares.conf: Aucun fichier ou dossier ». Tout ce qui
  # suit — dont la publication de boot.wim — était silencieusement sauté, et l'on
  # se retrouvait avec une ISO montée mais un PXE incapable de démarrer un poste.
  if [ ! -d /etc/samba ]; then
    echo "[PXE] Samba absent — partage [Install] NON publié."
    echo "      Les postes ne pourront pas atteindre la source Windows tant que"
    echo "      setup-ad.sh n'aura pas installé Samba. Le reste du PXE est en place."
  elif ! grep -q "^\[Install\]" /etc/samba/shares.conf 2>/dev/null; then
    touch /etc/samba/shares.conf
    cat >> /etc/samba/shares.conf <<'SMBEOF'

[Install]
   comment = Source d installation Windows (PXE)
   path = /srv/pxe/mnt/win11
   read only = yes
   browseable = yes
   guest ok = yes
SMBEOF
    smbcontrol all reload-config >/dev/null 2>&1 || true
  fi
fi

# ── Bibliothèque d'images master (déploiement / restauration rapide) ─────────
# [Images]   : LECTURE anonyme  → un poste vierge peut se déployer sans mot de passe
#              (comme le média d'installation : ce n'est pas un secret).
# [ImagesRW] : ÉCRITURE authentifiée → la capture d'une image est une action délibérée
#              d'administrateur ; aucun identifiant n'est stocké dans boot.wim.
mkdir -p /srv/pxe/images && chmod 775 /srv/pxe/images
# Fichiers de réponses (installation automatisée de Windows 11 Pro, contrôles matériel
# contournés). Servis par [Images] en lecture anonyme → ils ne contiennent AUCUN mot de
# passe (la création du compte local reste demandée à la fin).
# Realm réel du domaine, pour la jonction proposée à la 1re ouverture de session (FirstLogonCommands).
_REALM=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
for U in bios uefi; do
  install -m644 "${REPO_DIR}/services/tftp/unattend-${U}.xml" "/srv/pxe/images/unattend-${U}.xml" 2>/dev/null || true
  [ -n "$_REALM" ] && sed -i "s/bastion\.pn\.int/$_REALM/gI" "/srv/pxe/images/unattend-${U}.xml" 2>/dev/null || true
done
touch /etc/samba/shares.conf 2>/dev/null || true
if ! grep -q "^\[Images\]" /etc/samba/shares.conf 2>/dev/null; then
  cat >> /etc/samba/shares.conf <<'SMBEOF'

[Images]
   comment = Bibliotheque d images master (lecture)
   path = /srv/pxe/images
   read only = yes
   browseable = yes
   guest ok = yes

[ImagesRW]
   comment = Bibliotheque d images master (ecriture, authentifie)
   path = /srv/pxe/images
   read only = no
   browseable = no
   guest ok = no
SMBEOF
  smbcontrol all reload-config >/dev/null 2>&1 || true
fi

# ── Déploiement Windows : menu injecté dans boot.wim (INDEX 2) ───────────────
# boot.wim contient 2 images : index 1 « Windows PE » (minimal) et index 2 « Windows Setup ».
# On utilise l'INDEX 2, et voici pourquoi (constaté, pas supposé) :
#  - Setup\CmdLine vaut « winpeshl.exe ». MAIS la ruche de l'index 2 porte SetupType=1 +
#    SystemSetupInProgress=1 + FactoryPreInstallInProgress=1 : winpeshl en déduit qu'une
#    installation est en cours et lance DIRECTEMENT X:\setup.exe — startnet.cmd n'est même
#    pas exécuté (d'où l'écran « Installer le pilote » au lieu du menu).
#    → On pose donc un **winpeshl.ini** : quand il existe, winpeshl lance CE qu'il contient
#      au lieu du comportement par défaut. C'est lui qui donne la main à notre menu.
#  - L'index 1 n'a PAS les paquets WinPE-Setup / ImageBasedSetup : setup.exe lancé depuis le
#    partage y affiche bien sa 1re page mais « Suivant » reste sans effet (moteur 24H2 non
#    initialisable). Aucun binaire ne manque dans System32 : ce sont les composants
#    enregistrés qui manquent → seul l'index 2 permet une installation.
injecter_menu

echo "[PXE] Config dnsmasq…"
sed "s|http://__LAN_IP__|http://${LAN_IP}|g" "${REPO_DIR}/services/dnsmasq/pxe.conf" \
  > /etc/dnsmasq.d/proxyfibre-pxe.conf
# Helper conntrack TFTP : suit le flux de données (port éphémère) pour le laisser
# passer à travers le portail captif. Chargé au boot + immédiatement.
echo "nf_conntrack_tftp" > /etc/modules-load.d/proxyfibre-tftp.conf
echo "options nf_conntrack_tftp ports=69" > /etc/modprobe.d/proxyfibre-tftp.conf
modprobe nf_conntrack_tftp 2>/dev/null || true
systemctl restart dnsmasq

echo "[PXE] Serveur PXE prêt — menu protégé par les identifiants administrateur (compte ${ADMIN_USER:-admin})."
