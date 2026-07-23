# Chiffrement du disque de la passerelle (LUKS + déverrouillage TPM)

## Pourquoi

Le disque de la passerelle contient les secrets les plus sensibles du commissariat :
l'annuaire Active Directory (**empreintes de mots de passe**, **clés de récupération
BitLocker**), la base (journaux, identifiants), les configurations et la clé de chiffrement
des sauvegardes. Si le disque virtuel (VDI/VMDK) est **copié, volé ou mis au rebut** sans
chiffrement, tout fuite.

Le **chiffrement intégral du disque (LUKS)** protège ces données **au repos**. Couplé au
**TPM**, le disque se déverrouille tout seul au démarrage sur *sa* machine (reboot autonome
préservé), mais reste **verrouillé si le disque est déplacé** ailleurs.

> ⚠️ Le chiffrement du disque se pose **à l'installation**. On ne convertit pas un système
> déjà en service sans le réinstaller. Ce document concerne donc surtout les **nouvelles**
> passerelles ; la migration d'une passerelle existante passe par sauvegarde + réinstallation.

---

## A. Nouvelle passerelle (recommandé)

### 1. Installer Debian avec un LVM chiffré
Dans l'installeur Debian, au partitionnement, choisir :
**« Assisté – utiliser un disque entier avec LVM chiffré »**, et définir une **phrase
secrète** LUKS forte (à conserver hors de la machine — c'est le secours ultime).

Poser ensuite la pile Bastion normalement :
```bash
sudo bash provisioning/deploy.sh
```

### 2. Ajouter le déverrouillage automatique par le TPM
Activer un **TPM 2.0** sur la VM (VirtualBox : VM éteinte → *Configuration → Système →
Activer le TPM 2.0*), puis :
```bash
sudo bash provisioning/setup-luks-tpm.sh
```
Le script installe `clevis`, lie le volume LUKS au TPM (PCR 7 par défaut), et régénère
l'initramfs. Il **refuse de tourner** si le disque n'est pas déjà chiffré ou s'il n'y a pas
de TPM — aucune modification hasardeuse.

Résultat : au démarrage, le disque s'ouvre **sans saisie** via le TPM (même hors réseau) ;
déplacé sur une autre machine, il reste **verrouillé**. La phrase secrète LUKS reste un
secours si le TPM est réinitialisé.

---

## B. Passerelle existante (migration)

Chiffrer un root déjà en service = réinstallation. La voie sûre réutilise la sauvegarde
chiffrée et le runbook de restauration :

1. **Sauvegarder** (Console → Sauvegarde) et **télécharger l'archive chiffrée** hors machine ;
   noter la **phrase secrète de chiffrement des sauvegardes**.
2. **Réinstaller** Debian avec **LVM chiffré** (§ A.1).
3. **Restaurer** base + config + AD depuis la sauvegarde — voir
   [RESTAURATION-RUNBOOK.md](RESTAURATION-RUNBOOK.md).
4. **Ajouter le TPM** (§ A.2).

---

## Vérifications

```bash
lsblk -o NAME,FSTYPE,MOUNTPOINT      # une couche « crypto_LUKS » sous la racine
sudo clevis luks list -d <device>    # doit lister une liaison « tpm2 »
```
Puis **redémarrer** : le système doit démarrer sans demander de phrase.

## Choix du PCR (compromis)

Le script lie par défaut le **PCR 7** (état du Secure Boot) : robuste aux mises à jour de
noyau (mesurées sur d'autres PCR), tout en détectant une désactivation du Secure Boot. Le
verrouillage « si le disque est déplacé » vient du **scellement au TPM physique de la
machine** (un autre TPM ne peut pas desceller) — le PCR 7 seul suffit déjà à cela.

Pour durcir, on peut lier plusieurs PCR : `sudo bash provisioning/setup-luks-tpm.sh 0,7`
(le PCR 0 couvre le firmware, mais impose un **re-scellement** après chaque mise à jour
BIOS). **Ne jamais inclure le PCR 9** — il est modifié par `update-initramfs` et casserait
le déverrouillage (le script le refuse).

## Limites (honnêteté)

- **PCR 7 seul est une protection *modérée*** : elle vise le **vol/déplacement du disque**
  (le cas réel pour une appliance dérobée), pas un attaquant local sophistiqué — un noyau
  signé malveillant laisserait le PCR 7 inchangé, et clevis n'utilise pas de session TPM
  chiffrée par défaut (risque théorique d'écoute du bus). Pour un poste réellement exposé,
  compléter par le mot de passe au démarrage (mode passphrase).
- **Conserver impérativement la phrase secrète LUKS d'origine** hors de la machine : c'est
  le seul secours si l'état mesuré change (mise à jour firmware/Secure Boot/bootloader) et
  qu'un **re-scellement** devient nécessaire (`clevis luks unbind` puis `bind`).
