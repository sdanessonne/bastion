#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# ─────────────────────────────────────────────────────────────────────────────
# Bastion — déverrouillage TPM du disque chiffré (clevis + TPM2).
#
# À lancer sur une passerelle dont le disque est DÉJÀ chiffré (LUKS), typiquement
# posé à l'installation de Debian (« LVM chiffré »). Ce script AJOUTE le
# déverrouillage automatique par le TPM local : le disque s'ouvre tout seul au
# démarrage (reboot autonome, sans saisir de phrase), MAIS reste VERROUILLÉ si le
# disque est déplacé sur une autre machine (TPM différent) — protection au vol.
#
# Il NE chiffre PAS un système déjà installé en clair : chiffrer un root en service
# reviendrait à une réinstallation. Sur un système non chiffré, il REFUSE de tourner
# (voir docs/CHIFFREMENT-DISQUE-LUKS.md pour la marche à suivre).
#
# Usage : sudo ./setup-luks-tpm.sh [PCR]        (PCR TPM à lier, défaut 7 = Secure Boot)
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "À lancer en root : sudo ./setup-luks-tpm.sh" >&2; exit 1; }
PCR="${1:-7}"
# Le PCR 9 est modifié par « update-initramfs » : l'inclure casserait le déverrouillage au
# démarrage suivant. On le refuse explicitement.
case ",$PCR," in
  *",9,"*) echo "REFUS : ne pas lier au PCR 9 (modifié par update-initramfs → déverrouillage cassé)." >&2; exit 4 ;;
esac

echo "== Bastion — déverrouillage TPM du disque chiffré =="

# 1) GARDE-FOU : le root doit DÉJÀ être chiffré. Sinon on refuse (aucune modification).
if [ ! -s /etc/crypttab ]; then
  cat >&2 <<'MSG'
REFUS : aucun volume chiffré déclaré (/etc/crypttab vide).

Ce script N'ENCHIFFRE PAS un système déjà installé en clair. Pour un disque chiffré :
  • Nouvelle passerelle : réinstaller Debian en choisissant « Utiliser un disque entier
    avec LVM chiffré », puis relancer ce script pour ajouter le déverrouillage TPM.
  • Passerelle existante : migrer via sauvegarde chiffrée + réinstallation chiffrée +
    restauration — voir docs/CHIFFREMENT-DISQUE-LUKS.md et docs/RESTAURATION-RUNBOOK.md.
MSG
  exit 2
fi

# Résoudre le device LUKS depuis /etc/crypttab (2ᵉ champ ; gère « UUID=… »).
src=$(awk 'NF && $1 !~ /^#/ {print $2; exit}' /etc/crypttab)
case "$src" in
  UUID=*) LUKSDEV="/dev/disk/by-uuid/${src#UUID=}" ;;
  *)      LUKSDEV="$src" ;;
esac
[ -e "$LUKSDEV" ] || { echo "REFUS : device LUKS introuvable ($src)." >&2; exit 2; }
cryptsetup isLuks "$LUKSDEV" 2>/dev/null || { echo "REFUS : $LUKSDEV n'est pas un volume LUKS." >&2; exit 2; }
echo "Volume LUKS détecté : $LUKSDEV"

# 2) Un TPM 2.0 est nécessaire pour le déverrouillage local.
if [ ! -e /dev/tpm0 ] && [ ! -e /dev/tpmrm0 ]; then
  cat >&2 <<'MSG'
REFUS : aucun TPM détecté (/dev/tpm*).
Activez un TPM 2.0 sur la machine. VirtualBox : VM éteinte → Configuration → Système
→ cocher « Activer le TPM » (version 2.0), puis relancer ce script.
MSG
  exit 3
fi

# 3) Paquets clevis (déverrouillage LUKS par TPM, intégré à l'initramfs Debian).
echo "Installation de clevis + tpm2-tools…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y --no-install-recommends \
  cryptsetup clevis clevis-luks clevis-tpm2 clevis-initramfs tpm2-tools >/dev/null

# 4) Lier le volume au TPM (idempotent : on ne re-lie pas si déjà fait).
if clevis luks list -d "$LUKSDEV" 2>/dev/null | grep -q tpm2; then
  echo "Déjà lié au TPM (clevis) — rien à refaire."
else
  echo
  echo ">>> Saisissez la PHRASE SECRÈTE LUKS ACTUELLE à l'invite (pour ajouter le TPM) :"
  clevis luks bind -d "$LUKSDEV" tpm2 "{\"pcr_bank\":\"sha256\",\"pcr_ids\":\"${PCR}\"}"
fi

# 5) Régénérer l'initramfs pour embarquer le hook de déverrouillage clevis.
echo "Mise à jour de l'initramfs…"
update-initramfs -u -k all

cat <<'MSG'

✅ Terminé.
   • Au prochain démarrage, le disque se déverrouille TOUT SEUL via le TPM (aucune saisie),
     y compris hors réseau — le reboot autonome de la passerelle est préservé.
   • Si le disque est déplacé sur une AUTRE machine, il reste VERROUILLÉ (TPM différent).
   • La PHRASE SECRÈTE LUKS d'origine reste un secours indispensable : conservez-la
     hors de la machine. Vérifier : « clevis luks list -d <device> ».
MSG
