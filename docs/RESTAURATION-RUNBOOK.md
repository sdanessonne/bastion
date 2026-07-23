# Restauration de la passerelle Bastion — runbook *testé*

Ce document décrit **comment reconstruire** une passerelle Bastion à partir d'une
sauvegarde chiffrée, et **comment prouver** qu'une sauvegarde est réellement récupérable.

> Une sauvegarde jamais restaurée n'est pas une sauvegarde : c'est un espoir.

---

## 0. Le réflexe régulier — tester la sauvegarde (non destructif)

Console → **Sauvegarde → 🧪 Tester la restauration** (ou en ligne de commande :
`sudo proxyfibre-backup verify`).

Cet **exercice** déchiffre la dernière sauvegarde, restaure la **base** dans une base
jetable et le **domaine AD** dans un répertoire jetable, compte les objets, puis nettoie
tout. Il ne touche à rien en production. Il répond à la seule question qui compte :
« cette sauvegarde se restaure-t-elle vraiment ? »

**Résultat attendu :** `base=ok (N tables) · ad=ok (M comptes)`. À lancer régulièrement.

---

## 1. Reconstruction complète (passerelle perdue)

### Prérequis
- Le fichier `bastion-AAAAMMJJ-HHMMSS.tar.gz.gpg` (**téléchargé hors de la passerelle**).
- La **phrase secrète** de chiffrement (dans un coffre / gestionnaire de mots de passe —
  **jamais** sur la passerelle : sinon elle disparaît avec elle).
- Une VM Debian (même version) avec le **même nom d'hôte**, la **même IP LAN**
  (`192.168.182.1`) et le **même realm** (`bastion.pn.int`) que l'originale.

### Étape 1 — Reposer la pile de base
Cloner le dépôt et lancer l'installeur, qui réinstalle Samba AD, MariaDB, Apache/PHP,
OpenNDS, FreeRADIUS, dnsmasq, etc. :

```bash
git clone <dépôt> proxyFibre && cd proxyFibre
sudo bash provisioning/deploy.sh
```

### Étape 2 — Déchiffrer et extraire la sauvegarde

```bash
gpg --batch --pinentry-mode loopback --passphrase '<PHRASE SECRÈTE>' \
    -o /tmp/bastion.tar.gz -d bastion-AAAAMMJJ-HHMMSS.tar.gz.gpg
mkdir -p /tmp/rst && tar xzf /tmp/bastion.tar.gz -C /tmp/rst
ls /tmp/rst          # db.sql  config.tar.gz  uploads.tar.gz  ad/  manifest.txt
```

### Étape 3 — Restaurer base + configuration + médias
Le plus simple : déposer l'archive dans `/srv/pxe/backups`, puis **Console → Sauvegarde →
Restaurer**, ou en ligne de commande :

```bash
sudo cp bastion-AAAAMMJJ-HHMMSS.tar.gz.gpg /srv/pxe/backups/
sudo proxyfibre-backup restore bastion-AAAAMMJJ-HHMMSS.tar.gz.gpg
```

Cela recharge la **base** (comptes, filtrage, journaux, réglages…), la **configuration**
et les **médias** de l'intranet. Le domaine AD se restaure à part (étape 4).

### Étape 4 — Restaurer le domaine Active Directory

```bash
sudo systemctl stop samba-ad-dc
sudo mv /var/lib/samba /var/lib/samba.old
sudo samba-tool domain backup restore \
    --backup-file=/tmp/rst/ad/samba-backup-*.tar.bz2 \
    --newservername=DC --targetdir=/var/lib/samba
sudo systemctl start samba-ad-dc
```

> `--newservername=DC` doit correspondre au **nom du contrôleur** (celui de l'hôte).
> Vérifier ensuite : `sudo samba-tool domain info 127.0.0.1`.

### Étape 5 — Vérifier la remise en service

```bash
sudo proxyfibre-selftest full
```

Attendu : services actifs, pages console en 200/302 (pas de 500), comptes AD présents,
GPO en place. Contrôler enfin l'ouverture de session d'un poste et l'accès Internet filtré.

---

## 2. Exercice de restauration réalisé (preuve)

`sudo proxyfibre-backup verify` sur la sauvegarde chiffrée courante (2026-07-23) :

```
chiffree=oui  manifest=ok  base=ok (39 tables)  ad=ok (5 comptes)
```

→ code de sortie **0**, durée **~27 s**. La base **et** l'Active Directory se restaurent
correctement dans des emplacements jetables : **la sauvegarde est récupérable**. ✅

Objectif de reconstruction complète : **repartir en ~30 min** sur une VM déjà provisionnée.
