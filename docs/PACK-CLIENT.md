# Bastion — pack d'installation clé en main

Ce document décrit ce que l'on remet au client, et ce qui se passe quand il démarre
le serveur. Il tient volontairement sur deux pages : c'est la procédure, pas le
manuel — celui-ci est [`documentation-utilisateur.md`](documentation-utilisateur.md).

---

## Ce que contient le pack

| Support | Contenu | Taille |
|---|---|---|
| **Clé A — amorçable** | `bastion-installation.iso` | ~940 Mo |
| **Clé B — données** *(fournie par le service)* | `win11.iso`, éventuellement `master.wim` et `ubuntu.iso` | 8 à 25 Go |

### Pourquoi deux supports, et pas un seul

La clé A est écrite en mode brut (`dd`) : elle occupe le support entier, on ne peut
rien y ajouter. Surtout, **la source d'installation Windows n'a pas à être
redistribuée** : elle pèse 7,9 Go à elle seule, et le service dispose de son propre
média sous licence en volume. Bastion ne la fournit pas, il l'utilise.

La clé B est une clé **ordinaire**, formatée en exFAT ou NTFS. Elle peut être
préparée par le client lui-même.

---

## Préparer les supports

### Clé A — le serveur

Sur une machine disposant du dépôt :

```bash
cd provisioning/iso
sudo ./build-iso.sh
```

Trois questions, posées **une seule fois** :

- mot de passe du compte `BASTION` du serveur ;
- phrase secrète du disque chiffré (Entrée = identique au mot de passe) ;
- dépôt Git (Entrée = valeur par défaut).

Les réponses sont conservées dans `iso-secrets.env` (permissions 600, **hors dépôt
Git**) : les fabrications suivantes ne redemandent rien. L'image sort dans le même
dossier.

```bash
sudo dd if=bastion-installation.iso of=/dev/sdX bs=4M status=progress conv=fsync
```

> **L'image contient le mot de passe et la phrase secrète du disque, en clair.**
> C'est le prix d'une installation sans aucune saisie : la machine doit bien lire
> ces valeurs quelque part. Conservez-la comme une clé — coffre ou armoire forte,
> jamais un partage ouvert.

### Clé B — les sources de déploiement

Une clé USB ordinaire, avec ces fichiers **à la racine** ou dans un dossier `bastion` :

```
win11.iso      source d'installation Windows 11    (option [1] du menu PXE)
master.wim     image master, si vous en avez déjà  (option [2])
ubuntu.iso     amorçage Ubuntu en direct           (facultatif)
```

Les noms comptent : ce sont ceux que le serveur cherche.

---

## Installer

1. Brancher la **clé A** et la **clé B** sur le serveur.
2. Démarrer dessus (amorçage BIOS ou UEFI, les deux fonctionnent).
3. Attendre.

Il n'y a **aucune question**, aucun écran à valider. Le serveur s'installe, chiffre
son disque, redémarre, se configure, importe les sources de la clé B, puis s'arrête
sur son écran de connexion.

Comptez 30 à 45 minutes selon le matériel et le débit Internet — l'installation
télécharge ses paquets Debian.

### Ce qui se passe, dans l'ordre

| | |
|---|---|
| 1 | Debian s'installe sur un **disque chiffré** (LUKS) |
| 2 | Premier démarrage : le dépôt Bastion est récupéré |
| 3 | `deploy.sh` installe et configure portail captif, RADIUS, contrôleur de domaine, DNS, DHCP, console, PXE |
| 4 | Les fichiers de la clé B sont importés et pris en compte |
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

## Si la clé B a été oubliée

Rien n'est perdu : le serveur fonctionne, seul le déploiement Windows est
indisponible. Branchez la clé et lancez :

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

## Vérifier que tout est en place

```bash
sudo proxyfibre-selftest full
```

Il contrôle les pages de la console, les services, les scripts déployés, l'encodage
des scripts destinés aux postes et l'intégrité du catalogue de stratégies. Il doit
finir sur **0 échec**.
