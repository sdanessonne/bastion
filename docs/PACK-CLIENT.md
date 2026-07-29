# Bastion — pack d'installation clé en main

Ce document décrit ce que l'on remet au client, comment le préparer, et ce qui se
passe quand il démarre le serveur. C'est la procédure d'installation ; le manuel
d'utilisation est [`documentation-utilisateur.md`](documentation-utilisateur.md).

### Ce qu'il faut, côté serveur

| | |
|---|---|
| **Deux cartes réseau** | l'une vers Internet (WAN), l'autre vers le réseau interne (LAN) — c'est structurel, Bastion est une passerelle |
| Processeur 64 bits | 2 cœurs suffisent |
| 4 Go de mémoire | 8 Go si le contrôleur de domaine sert plus de 50 postes |
| 250 Go de disque | le disque est **entièrement effacé et chiffré** |
| Un accès Internet | l'installation télécharge ses paquets Debian |

---

## Ce que contient le pack

| Support | Contenu | Taille |
|---|---|---|
| **Clé unique — amorçable** | `bastion-installation.iso` : Debian, le code Bastion, et les médias de déploiement trouvés à la fabrication | 0,9 à 25 Go |

Le fabricant embarque dans l'image **tout ce qu'il trouve** : le code de Bastion
(pris dans le dépôt local, pas cloné depuis Internet) et, s'ils sont présents,
`win11.iso`, `master.wim` et `ubuntu.iso`. Une image complète pèse environ 9 Go et
tient sur une clé de 16 Go.

> Le code est embarqué et **non cloné**. Le dépôt est privé : un serveur qui vient
> de s'installer n'a aucune authentification pour y accéder. La première
> installation réelle l'a montré — le service de déploiement a échoué en une
> seconde, et le serveur a démarré sur un écran nu.

Sans média Windows à la fabrication, l'image reste utilisable : le serveur
s'installe normalement, et l'on ajoute les sources plus tard (voir plus bas).

---

## Préparer les supports

### Fabriquer l'image

Sur une machine disposant du dépôt :

```bash
cd provisioning/iso
sudo ./build-iso.sh
```

Trois questions, posées **une seule fois** :

- mot de passe du compte d'administration `proxyfibre` du serveur ;
- phrase secrète du disque chiffré (Entrée = identique au mot de passe) ;
- dépôt Git (Entrée = valeur par défaut).

Les réponses sont conservées dans `iso-secrets.env` (permissions 600, **hors dépôt
Git**) : les fabrications suivantes ne redemandent rien. L'image sort dans le même
dossier.

### Écrire la clé USB

Il faut une clé de **16 Go minimum** pour une image complète. Tout son contenu sera
effacé.

L'image est **hybride** : elle contient déjà sa propre table de partitions. Elle doit
donc être recopiée **octet par octet**, et surtout pas « décompressée » ou copiée
fichier par fichier — une clé préparée de cette façon ne démarre pas.

**Sous Windows — Rufus**

1. Sélectionner la clé, puis `bastion-installation.iso`.
2. Cliquer sur **DÉMARRER**.
3. Rufus détecte l'image hybride et propose deux modes : choisir
   **« Écrire en mode Image DD »**, *pas* le mode ISO.

Le mode ISO reconstruit une clé amorçable à sa façon et perd la partition EFI :
l'image démarre alors en BIOS mais pas en UEFI. Le mode DD copie à l'identique.

**Sous Linux ou macOS**

Vérifiez le nom du périphérique avant de valider — `dd` n'a pas de garde-fou et
écrase ce que vous lui désignez.

```bash
lsblk                                    # identifier la clé : sdb, sdc…
sudo dd if=bastion-installation.iso of=/dev/sdX bs=4M status=progress conv=fsync
```

Sur macOS, le périphérique s'appelle `/dev/rdiskN` et il faut d'abord le démonter
avec `diskutil unmountDisk /dev/diskN`.

> **L'image contient le mot de passe et la phrase secrète du disque, en clair.**
> C'est le prix d'une installation sans aucune saisie : la machine doit bien lire
> ces valeurs quelque part. Conservez-la comme une clé — coffre ou armoire forte,
> jamais un partage ouvert.

### Les sources de déploiement

Le fabricant embarque ce qu'il trouve dans `/srv/pxe/iso` et `/srv/pxe/images`.
Pour désigner d'autres fichiers, renseignez `MEDIAS` :

```bash
sudo MEDIAS="/mnt/sources/*.iso /mnt/sources/master.wim" ./build-iso.sh
```

Les noms attendus :

```
win11.iso      source d'installation Windows 11    (option [1] du menu PXE)
master.wim     image master, si vous en avez déjà  (option [2])
ubuntu.iso     amorçage Ubuntu en direct           (facultatif)
```

Les noms comptent : ce sont ceux que le serveur cherche.

---

## Installer

1. Brancher la clé sur le serveur.
2. Démarrer dessus (amorçage BIOS ou UEFI, les deux fonctionnent).
3. Attendre.

Il n'y a **aucune question**, aucun écran à valider. Le serveur s'installe, chiffre
son disque, redémarre, se configure, met en place les sources de déploiement, puis
s'arrête sur son écran de connexion.

Comptez 30 à 45 minutes selon le matériel et le débit Internet — l'installation
télécharge ses paquets Debian.

### Ce qui se passe, dans l'ordre

| | |
|---|---|
| 1 | Debian s'installe sur un **disque chiffré** (LUKS) |
| 2 | Le code Bastion et les médias sont recopiés du support vers le disque, **pendant** l'installation — le support pourrait avoir été retiré ensuite |
| 3 | Premier démarrage : `/opt/bastion-init/run.sh` prend le relais |
| 4 | `deploy.sh` installe et configure portail captif, RADIUS, contrôleur de domaine, DNS, DHCP, console, PXE |
| 5 | Le service d'installation se désactive : il ne tournera plus |

Le journal complet reste dans `/var/log/bastion-install.log`.

---

## Après l'installation

**Le mot de passe de la console est affiché une seule fois**, à la fin de
l'installation, dans un encadré. Il est engendré au hasard — ce produit n'a aucun
mot de passe par défaut. On le relit ensuite dans `/etc/proxyfibre/admin-pass.env`,
accessible au seul compte `root`.

Première connexion : `https://<adresse-du-serveur>:8443`

Trois choses à faire tout de suite :

1. **Activer la double authentification** sur le compte d'administration (Profil).
2. **Approuver le certificat** de l'autorité Bastion sur les postes d'administration
   (Sécurité → « Approuver le certificat de la console »).
3. **Adapter l'avertissement légal** de la page de connexion (Sécurité →
   « Avertissement de la page de connexion ») : le texte fourni est une base
   courante, pas un modèle officiel. Faites-le valider.

---

## Si les sources de déploiement manquent

Rien n'est perdu : le serveur fonctionne, seul le déploiement Windows est
indisponible. Branchez une clé ordinaire contenant les fichiers ci-dessus, et
lancez :

```bash
sudo proxyfibre-import-media auto
```

Il cherche les fichiers sur tous les supports amovibles branchés, les copie et
actualise le serveur PXE. La commande est sans effet si les fichiers sont déjà là :
on peut la relancer sans risque.

Pour un dossier précis plutôt qu'une recherche automatique :

```bash
sudo proxyfibre-import-media /media/monsupport
```

---

## Si l'installation semble avoir échoué

Le serveur démarre mais rien ne répond sur `:8443` ? L'écran de connexion le dit :
un déploiement raté y affiche un encadré `BASTION : le deploiement a ECHOUE`.

Le détail est toujours au même endroit :

```bash
sudo cat /var/log/bastion-install.log
```

Chaque étape y est datée, depuis la lecture du support jusqu'à la fin de
`deploy.sh`. Deux lignes à chercher en premier :

- `[copier.sh] AUCUN support de médias trouvé` — le support n'a pas été lu pendant
  l'installation. Le serveur est utilisable, mais vide : relancez le déploiement à
  la main depuis le dépôt.
- `[init] ECHEC : deploy.sh a echoue` — l'installation des services a buté. Le
  message d'erreur exact suit immédiatement dans le journal.

Pour reprendre le déploiement après correction :

```bash
sudo systemctl start bastion-init.service
```

---

## Vérifier que tout est en place

```bash
sudo proxyfibre-selftest full
```

Il contrôle les pages de la console, les services, les scripts déployés, l'encodage
des scripts destinés aux postes et l'intégrité du catalogue de stratégies. Il doit
finir sur **0 échec**.
