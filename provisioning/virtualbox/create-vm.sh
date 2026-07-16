#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Bastion — création de la VM passerelle sous VirtualBox (Windows/Git Bash)
# Non destructif : ne recrée pas la VM si elle existe déjà.
#
# Topologie :
#   NIC1 = WAN  → NAT           (accès Internet via l'hôte)  → eth0
#   NIC2 = LAN  → intnet        (réseau des clients)         → eth1
#
# Une 2e VM « client » peut être branchée sur le même réseau interne (LAN_NET)
# pour tester le portail captif.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

# --- Paramètres (surchargeables par variables d'environnement) ---
VM_NAME="${VM_NAME:-Bastion-gw}"
VM_RAM_MB="${VM_RAM_MB:-2048}"
VM_CPUS="${VM_CPUS:-2}"
VM_DISK_MB="${VM_DISK_MB:-20000}"
LAN_NET="${LAN_NET:-proxyfibre-lan}"     # nom du réseau interne VirtualBox (côté LAN)
ISO_PATH="${ISO_PATH:-$HOME/Downloads/debian-13.5.0-amd64-netinst.iso}"

# --- Localiser VBoxManage ---
VBOX=""
for p in "/c/Program Files/Oracle/VirtualBox/VBoxManage.exe" \
         "/c/Program Files (x86)/Oracle/VirtualBox/VBoxManage.exe"; do
  [[ -f "$p" ]] && VBOX="$p" && break
done
[[ -n "$VBOX" ]] || { echo "VBoxManage introuvable — VirtualBox est-il installé ?" >&2; exit 1; }
[[ -f "$ISO_PATH" ]] || { echo "ISO introuvable : $ISO_PATH" >&2; exit 1; }

# --- Idempotence ---
if "$VBOX" list vms | grep -q "\"${VM_NAME}\""; then
  echo "La VM '${VM_NAME}' existe déjà — rien à faire."
  exit 0
fi

echo "Création de la VM '${VM_NAME}'…"
"$VBOX" createvm --name "$VM_NAME" --ostype "Debian_64" --register

# CPU / RAM / affichage
"$VBOX" modifyvm "$VM_NAME" \
  --memory "$VM_RAM_MB" --cpus "$VM_CPUS" --vram 16 \
  --graphicscontroller vmsvga --ioapic on --rtcuseutc on

# --- Cartes réseau ---
# NIC1 = WAN (NAT) : la VM obtient Internet via l'hôte.
"$VBOX" modifyvm "$VM_NAME" --nic1 nat --nictype1 virtio
# NIC2 = LAN (réseau interne) : les clients à authentifier s'y branchent.
"$VBOX" modifyvm "$VM_NAME" --nic2 intnet --intnet2 "$LAN_NET" --nictype2 virtio --cableconnected2 on

# --- Stockage : disque SATA + lecteur DVD avec l'ISO ---
# Dossier machine par défaut de VirtualBox (chemin Windows, ex. C:\Users\...\VirtualBox VMs).
DEF_FOLDER="$("$VBOX" list systemproperties | sed -n 's/^Default machine folder:[[:space:]]*//p' | sed 's/[[:space:]]*$//')"
# VBoxManage.exe attend un chemin Windows (antislashs) — on le construit tel quel.
DISK_PATH="${DEF_FOLDER}\\${VM_NAME}\\${VM_NAME}.vdi"
"$VBOX" createmedium disk --filename "$DISK_PATH" --size "$VM_DISK_MB" --format VDI

"$VBOX" storagectl "$VM_NAME" --name "SATA" --add sata --controller IntelAhci --portcount 2 --bootable on
"$VBOX" storageattach "$VM_NAME" --storagectl "SATA" --port 0 --device 0 --type hdd --medium "$DISK_PATH"
"$VBOX" storageattach "$VM_NAME" --storagectl "SATA" --port 1 --device 0 --type dvddrive --medium "$ISO_PATH"

# Démarrer sur le DVD (installation), puis disque.
"$VBOX" modifyvm "$VM_NAME" --boot1 dvd --boot2 disk --boot3 none --boot4 none

echo "──────────────────────────────────────────────────────────────"
echo "VM '${VM_NAME}' créée."
echo "  RAM ${VM_RAM_MB} Mo · ${VM_CPUS} vCPU · disque ${VM_DISK_MB} Mo"
echo "  NIC1 WAN=NAT (eth0) · NIC2 LAN=intnet '${LAN_NET}' (eth1)"
echo "  ISO : ${ISO_PATH}"
echo "Démarrez-la avec :"
echo "  \"$VBOX\" startvm \"${VM_NAME}\""
echo "──────────────────────────────────────────────────────────────"
