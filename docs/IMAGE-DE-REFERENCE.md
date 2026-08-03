# Déployer Bastion par image de référence

Méthode pour installer plusieurs commissariats rapidement : on prépare **une
machine gabarit**, on la généralise, on prend son image, et chaque copie se donne
sa propre identité au premier démarrage.

Compter **5 minutes par serveur** au déploiement, contre 20 à 30 avec l'ISO.

---

## Pourquoi on ne clone pas un serveur en service

C'est la question qui revient, et la réponse tient en trois points concrets.

**L'annuaire Active Directory porte une identité de réplication** — identifiant
d'invocation, compteurs de mise à jour. Deux serveurs issus du même clone se
déclarent le même contrôleur de domaine. Samba ne le supporte pas, et la
corruption n'apparaît pas au démarrage : elle apparaît des semaines plus tard,
sur des ouvertures de session qui échouent sans explication.

**Tout secret présent dans l'image est partagé par tous les exemplaires.** Mot de
passe du relais de messagerie, clé privée WireGuard, certificats, jetons d'API,
clés SSH autorisées. La compromission d'un seul commissariat les ouvre tous.

**Les journaux de navigation, l'annuaire nominatif et le journal d'audit sont des
données personnelles.** Les recopier dans un autre commissariat est une
divulgation.

D'où le gabarit généralisé : il ne contient **ni secret, ni identité, ni
domaine, ni donnée**.

---

## 1. Préparer le gabarit

Sur une machine **dédiée**, jamais mise en service :

```bash
sudo /home/bastion/proxyFibre/provisioning/install.sh
sudo /home/bastion/proxyFibre/provisioning/deploy.sh
```

Vérifiez que tout fonctionne, puis **ne créez aucun compte, ne promouvez pas le
domaine, ne configurez pas le relais de messagerie** : ces éléments doivent
naître sur chaque serveur, pas dans le moule.

## 2. Généraliser

```bash
sudo proxyfibre-sysprep --confirmer
```

Le script arrête les services, efface les secrets, la base, l'annuaire, les
journaux, les clés d'hôte SSH, l'identité machine et **les clés SSH autorisées**
— c'est le plus facile à oublier, et une clé laissée là ouvre tous les serveurs
déployés ensuite.

**Éteignez sans redémarrer.** Un redémarrage relancerait la personnalisation et
gâcherait le gabarit.

## 3. Prendre l'image

Depuis un système de secours (clé USB Debian live), disque gabarit non monté :

```bash
sudo dd if=/dev/sda conv=sparse bs=4M status=progress | gzip -1 > bastion-modele.img.gz
```

`conv=sparse` et `gzip` ramènent une image de 30 Go à environ 2 Go, l'espace
vide ne se compressant pratiquement pas.

## 4. Déployer sur un nouveau serveur

```bash
gunzip -c bastion-modele.img.gz | sudo dd of=/dev/sda bs=4M status=progress
sync
```

Le disque cible doit être **au moins aussi grand** que celui du gabarit. S'il est
plus grand, la partition est étendue automatiquement au premier démarrage.

## 5. Premier démarrage

Deux façons de fournir les valeurs propres au site.

**À la main** — le technicien est devant la machine. Trois questions
s'affichent sur la console : nom d'hôte, domaine, adresse LAN.

**En série** — déposez `/boot/bastion-site.env` avant le premier démarrage :

```sh
NOM_HOTE="bastion-cml91"
DOMAINE="bastion.pn.int"
LAN_IP_S="192.168.182.1"
```

Le serveur régénère alors son identité, ses clés, ses secrets, étend sa
partition, déploie Bastion, **vérifie que le portail répond**, puis affiche le
mot de passe de la console — relevez-le, il n'est montré qu'une fois (il reste
dans `/etc/proxyfibre/admin-pass.env`, en 600 root).

---

## Si la personnalisation échoue

Elle s'arrête et le dit. Le marqueur `/var/lib/bastion/firstboot-done` **n'est
pas posé** : l'opération reste rejouable une fois la cause corrigée.

```bash
sudo systemctl start bastion-firstboot
```

Journal complet : `/var/log/bastion-firstboot.log`.

C'est délibéré : le pire résultat n'est pas l'échec, c'est un serveur qui
démarre, présente une console, et se révèle à moitié configuré le jour où il
compte.

---

## Ce qui reste à faire sur chaque serveur

Le gabarit ne peut pas les porter — ils sont propres à chaque site :

- le relais de messagerie pour les alertes (*Services → Surveillance et alertes*) ;
- la configuration VPN, si le site en utilise une ;
- les comptes des agents et les groupes ;
- le média Windows pour le PXE, à importer depuis la console.
