#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Bastion — installation Debian *sans intervention* dans la VM VirtualBox.
# S'appuie sur « VBoxManage unattended » (VirtualBox >= 7) : génère le fichier de
# préconfiguration (preseed), l'injecte dans l'installateur et démarre la VM.
#
# À l'issue, la VM redémarre sur un Debian installé, avec openssh-server + sudo +
# git prêts, pour y déployer Bastion (provisioning/install.sh).
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

VM_NAME="${VM_NAME:-Bastion-gw}"
ISO_PATH="${ISO_PATH:-C:\\Users\\micka\\Downloads\\debian-13.5.0-amd64-netinst.iso}"

# Compte créé dans la VM (à changer en production).
VM_USER="${VM_USER:-proxyfibre}"
VM_PASS="${VM_PASS:-ProxyFibre#2026}"
VM_HOST="${VM_HOST:-proxyfibre.lan}"

VBOX=""
for p in "/c/Program Files/Oracle/VirtualBox/VBoxManage.exe" \
         "/c/Program Files (x86)/Oracle/VirtualBox/VBoxManage.exe"; do
  [[ -f "$p" ]] && VBOX="$p" && break
done
[[ -n "$VBOX" ]] || { echo "VBoxManage introuvable." >&2; exit 1; }

echo "Configuration de l'installation sans intervention pour '${VM_NAME}'…"
"$VBOX" unattended install "$VM_NAME" \
  --iso="$ISO_PATH" \
  --user="$VM_USER" \
  --password="$VM_PASS" \
  --full-user-name="Bastion Admin" \
  --hostname="$VM_HOST" \
  --locale=fr_FR \
  --country=FR \
  --time-zone=Europe/Paris \
  --language=fr_FR \
  --post-install-command="apt-get update; apt-get install -y openssh-server sudo git; usermod -aG sudo ${VM_USER}" \
  --start-vm=gui

echo "──────────────────────────────────────────────────────────────"
echo "Installation lancée. La VM va installer Debian toute seule (~10-15 min)."
echo "  Compte : ${VM_USER} / ${VM_PASS}   (hostname ${VM_HOST})"
echo "Suivez la progression dans la fenêtre VirtualBox."
echo "Quand elle a redémarré et affiche l'invite de login, prévenez-moi :"
echo "je déploierai Bastion dessus."
echo "──────────────────────────────────────────────────────────────"
