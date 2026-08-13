# Tutoriel — installer Bastion de zéro

De la machine vide au premier poste raccordé au domaine. Comptez **deux heures**, dont une
d'attente pendant les installations.

---

## Étape 0 — Ce qu'il faut avant de commencer

**La machine.** 4 Go de mémoire au minimum, 8 recommandés. **250 Go de disque** — c'est le
point de rupture le plus fréquent, un disque plein arrête la base de données donc
l'authentification de tout le monde. Un processeur d'entrée de gamme à quatre cœurs suffit
largement.

**Deux interfaces réseau filaires.** Une vers la box de l'opérateur, une vers le switch du
parc. Repérez physiquement laquelle est laquelle : c'est la première chose qu'on confond, et
brancher le réseau du service sur le mauvais port suffit à lancer un serveur DHCP pirate.

**Le Wi-Fi est facultatif.** Si vous en voulez un, la clé USB doit supporter le **mode point
d'accès**. Toutes ne le font pas, et celles qui ne le font pas ne le disent pas : elles
s'installent normalement et refusent seulement de créer un réseau. Vérifiez le chipset avant
d'acheter — MediaTek `mt7612u` ou `mt7921u`, Atheros `ath9k_htc`.

**Un accès au dépôt.** Le dépôt est privé : il vous faut un jeton GitHub en lecture, une clé
SSH autorisée, ou un fichier *bundle* si le site n'a pas Internet.

**Une décision à prendre maintenant, pas après :** le disque sera-t-il **chiffré** ? Cela se
décide à l'installation et ne s'ajoute pas après coup sans tout refaire. Sur une machine qui
contiendra les comptes, l'annuaire et les journaux de navigation, la question mérite deux
minutes de réflexion.

---

## Étape 1 — Installer Debian

Debian **12 ou 13**, installation standard, en anglais ou en français peu importe.

- Chiffrez le disque si vous avez répondu oui plus haut (LVM chiffré dans l'installeur).
- Ne cochez **aucun** environnement de bureau — la machine n'a pas d'écran à faire tourner.
- Cochez **serveur SSH** et **utilitaires usuels du système**.
- Créez un compte administrateur ordinaire, avec `sudo`.

Au redémarrage, notez l'adresse IP obtenue côté box :

```bash
ip -4 -br a
```

**Raccourci :** l'image ISO fournie par le projet enchaîne Debian et Bastion sans
intervention. Elle se construit avec `provisioning/iso/build-iso.sh`, pèse 9 Go, et évite
les étapes 1 à 3.

---

## Étape 2 — Lancer l'installation de Bastion

```bash
curl -fsSL https://raw.githubusercontent.com/sdanessonne/bastion/main/provisioning/bootstrap.sh \
  | sudo BASTION_TOKEN=ghp_votrejeton bash
```

Selon votre mode d'accès, remplacez `BASTION_TOKEN=…` par `BASTION_SSH=1` ou
`BASTION_BUNDLE=/media/usb/code.bundle`.

**Le script va s'arrêter.** C'est prévu : il crée `/opt/bastion/provisioning/config.env` et
vous demande de le renseigner. Installer avec les valeurs d'exemple donnerait une passerelle
en apparence fonctionnelle, avec des mots de passe que tout lecteur du dépôt connaît.

---

## Étape 3 — Renseigner la configuration

```bash
sudo nano /opt/bastion/provisioning/config.env
```

Ce qui compte :

| Réglage | À quoi faire attention |
|---|---|
| Interfaces WAN et LAN | les noms réels (`ip -br a`), pas ceux de l'exemple |
| Plan d'adressage du LAN | évitez `192.168.1.0/24`, trop courant : un agent en télétravail aurait le même chez lui |
| Nom de domaine | un domaine **interne** (`.int`, `.lan`), jamais un domaine public réel |
| Mots de passe | administration, base de données, annuaire — **tous différents** |

Puis relancez **exactement la même commande** qu'à l'étape 2. Cette fois elle ira au bout :
paquets, services, base, contrôleur de domaine, console. Comptez vingt à quarante minutes.

---

## Étape 4 — Vérifier avant d'aller plus loin

```bash
sudo /usr/local/sbin/proxyfibre-selftest
```

**Zéro échec attendu.** Il contrôle les services, les pages de la console, les scripts
privilégiés et l'encodage des scripts destinés aux postes. S'il signale quelque chose,
réglez-le maintenant : tout ce qui suit s'appuie dessus.

La console répond alors sur `https://<adresse de la passerelle>:8443/`. Le certificat est
auto-signé, votre navigateur vous préviendra — c'est normal.

---

## Étape 5 — Les trois premiers gestes dans la console

Dans cet ordre, avant tout le reste.

**Changez le mot de passe d'administration** et activez la **double authentification** —
menu du compte, en haut à droite.

**Posez une phrase de passe WPA2** si le Wi-Fi est activé. Un réseau sans fil ouvert dans un
commissariat, c'est la porte d'entrée la plus simple qui soit.

**Lancez une sauvegarde**, téléchargez-la, et **sortez-la de la machine**. Une sauvegarde qui
dort sur le disque qu'elle protège ne sert à rien le jour où ce disque lâche. Conservez son
secret de chiffrement ailleurs.

---

## Étape 6 — Créer les comptes

**Utilisateurs & droits** → *Nouvel utilisateur*.

L'identifiant est le **matricule à sept chiffres**. Cochez ce dont l'agent a besoin : accès
Internet par le portail, compte de domaine pour ouvrir une session Windows, ou les deux.

Un administrateur prend un compte séparé, préfixé `admin-`. On ne travaille pas au quotidien
avec un compte qui peut tout faire.

**Groupes & quotas** définit ensuite la durée de session, les débits et les plages horaires.
Les nouveaux réglages s'appliquent à la *prochaine* connexion de l'agent, pas à celle en
cours.

---

## Étape 7 — Raccorder un poste au domaine

Sur le poste Windows, en administrateur :

1. DNS : l'adresse de la passerelle **côté annuaire** — celle en `.2` du plan d'adressage,
   pas celle du portail. C'est l'erreur la plus fréquente et elle donne un message
   incompréhensible.
2. *Paramètres → Système → Domaine* : indiquez le nom de domaine, puis les identifiants
   d'administration du domaine.
3. Redémarrez.

**Si la jonction échoue, regardez l'heure du poste avant tout le reste.** Un décalage de plus
de cinq minutes fait échouer Kerberos, donc l'authentification — et aucun message ne parle
jamais d'heure. C'est fréquent sur machine virtuelle.

Les partages se rejoignent par le **nom du serveur** (`\\dc.<domaine>\Commun`), jamais par le
nom de domaine seul : ce dernier rend « Élément introuvable ».

---

## Étape 8 — Déployer sur le parc

**Filtrage** — cochez les catégories à bloquer. L'effet est immédiat, pour tout le monde.

**Store d'applications** — récupérez les logiciels voulus, activez-les, puis pressez
**Appliquer sur les postes**. Sans ce bouton, rien ne part : c'est l'oubli le plus fréquent
et il coûte une demi-heure de recherche.

**Active Directory** — déployez les stratégies utiles : lecteurs réseau, bannière juridique,
activation Windows, chiffrement BitLocker.

Sur les postes, ensuite : `gpupdate /force` puis un redémarrage.

---

## Étape 9 — Ce qu'il reste à faire régulièrement

Une **sauvegarde** avant chaque intervention lourde, téléchargée et sortie de la machine.

Un coup d'œil à la page **Santé** : le disque surtout, qui se remplit par trois côtés à la
fois — images d'installation, installeurs du store, journaux.

Et vérifiez une fois que les **alertes par courriel** partent réellement, par un envoi
d'essai. C'est le seul canal qui fonctionne encore quand la console, elle, ne répond plus.

---

## Le réflexe qui vaut pour tout

**Vérifiez l'effet, jamais l'intention.** Un service peut se déclarer `active` sans rien
faire. Un script peut annoncer « publié » sans que rien n'arrive. Le message de succès ne
prouve rien ; seul le résultat compte.

Et méfiez-vous de vos propres outils de mesure : sur ce projet, trois vérifications
successives ont donné de faux diagnostics — une commande absente du `PATH`, un filtre qui ne
lisait pas les attributs binaires, un compteur qui ne comptait pas ce qu'on croyait. Avant de
corriger, vérifiez la vérification.

---

En cas de problème, la page **[Dépannage](Depannage)** liste les pannes rencontrées en
production, avec pour chacune le **signe** qui permet de la reconnaître.
