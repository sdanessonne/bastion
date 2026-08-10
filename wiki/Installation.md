# Installation

Deux points de départ. Depuis une Debian déjà installée, ou depuis une machine nue.

## Depuis une Debian 12 ou 13 neuve

```bash
curl -fsSL https://raw.githubusercontent.com/sdanessonne/bastion/main/provisioning/bootstrap.sh \
  | sudo BASTION_TOKEN=<jeton> bash
```

Le dépôt est **privé**. Sans authentification, GitHub répond `404` — « n'existe pas », et
non « accès refusé ». Le script teste ce cas et le nomme, plutôt que d'échouer sur un
message qui ferait croire à une faute de frappe dans l'adresse.

| Mode | Variable | Quand l'employer |
|---|---|---|
| Jeton GitHub | `BASTION_TOKEN=ghp_…` | le plus simple ; un droit de lecture suffit |
| Clé SSH | `BASTION_SSH=1` | la machine a déjà une clé autorisée sur le compte |
| Bundle | `BASTION_BUNDLE=/chemin.bundle` | **site sans Internet** — le cas d'un commissariat isolé |

Le jeton n'est pas laissé dans `.git/config` : il y serait lisible et ressortirait dans
`git remote -v`. L'URL distante est réécrite juste après le clonage.

### L'arrêt volontaire

Le script **s'interrompt une fois**, après avoir créé `provisioning/config.env`, et demande
de le renseigner : plan d'adressage, domaine, mots de passe.

Ce n'est pas un défaut. Installer avec les valeurs d'exemple produirait une passerelle en
apparence fonctionnelle, avec des mots de passe connus de quiconque a lu le dépôt — bien
pire qu'une installation interrompue.

Renseignez le fichier, puis relancez **exactement la même commande**.

## Depuis une machine nue — l'image ISO

```bash
sudo bash provisioning/iso/build-iso.sh
```

L'image enchaîne l'installation de Debian **et** celle de Bastion sans intervention, à
partir des fichiers *preseed*.

Deux variantes :

- `preseed.cfg` — disque en clair ;
- `preseed-crypto.cfg` — **disque chiffré**.

Le chiffrement se décide **ici et nulle part ailleurs**. Il ne s'ajoute pas après coup sans
tout réinstaller. Sur une passerelle qui contient les comptes, l'annuaire et les journaux de
navigation, c'est le choix à ne pas manquer — une installation faite à la main, hors image,
laisse le disque en clair par défaut.

L'image pèse environ 9 Go : trop pour une *release* GitHub, il faut la construire ou la
transporter sur clé.

## Réseau attendu

Deux interfaces filaires : une vers la box de l'opérateur, une vers le switch du parc. Le
Wi-Fi est facultatif ; s'il est voulu, la clé USB doit supporter le **mode point d'accès** —
toutes ne le font pas, et celles qui ne le font pas ne le disent pas : elles s'installent
normalement et refusent seulement de créer un réseau.

## Après installation

```bash
sudo /usr/local/sbin/proxyfibre-selftest
```

Zéro échec attendu. Il contrôle les services, les pages de la console, les scripts
privilégiés et l'encodage des scripts destinés aux postes.

La console répond alors sur `https://<adresse de la passerelle>:8443/`.

**Premiers gestes conseillés**, dans cet ordre :

1. changer le mot de passe d'administration et activer la double authentification ;
2. poser une phrase de passe WPA2 si le Wi-Fi est activé ;
3. lancer une sauvegarde, la télécharger, **et la sortir de la machine**.
