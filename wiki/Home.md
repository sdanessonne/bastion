# Bastion — wiki

Contrôleur d'accès réseau pour un parc qui n'est pas raccordé au réseau de
l'administration. Ce wiki est le manuel d'**exploitation** : ce qu'on fait au
quotidien, et ce qu'on fait quand ça ne marche pas.

La présentation du produit est dans le [README](https://github.com/sdanessonne/bastion).
L'aide détaillée, elle, vit **dans la console** — page *Aide* — parce qu'elle doit rester
accessible quand Internet ne l'est pas.

## Pages

- **[Installation](Installation)** — depuis une Debian neuve ou une machine nue
- **[Dépannage](Depannage)** — les pannes rencontrées, leur cause et leur signe
- **[Exploitation](Exploitation)** — les gestes réguliers

## Les trois choses à savoir avant tout

**Une modification de la console est immédiate ; une stratégie de groupe ne l'est pas.**
Activer un logiciel dans le store ne déploie rien tant qu'on n'a pas pressé *Appliquer sur
les postes*. C'est l'oubli le plus fréquent, et il coûte une demi-heure de recherche.

**Un service « actif » ne prouve rien.** Un point d'accès Wi-Fi peut se déclarer monté sans
émettre une seule balise ; un installeur peut se déclarer publié sans que rien n'arrive.
Vérifiez toujours l'effet, jamais l'intention — c'est le principe qui a guidé toute la
conception.

**Le disque est le point de rupture.** Il se remplit par trois côtés à la fois — images
d'installation, installeurs du store, journaux. Un disque plein arrête la base de données,
donc l'authentification de tout le monde, d'un seul coup. Surveillez-le sur la page *Santé*,
et vérifiez que les alertes par courriel partent réellement : c'est le seul canal qui
fonctionne encore quand la console ne répond plus.

## Vérifier l'état de la passerelle

```bash
sudo /usr/local/sbin/proxyfibre-selftest
```

Il contrôle les services, les pages, les scripts privilégiés et les encodages. Zéro échec
attendu. C'est le contrôle le plus fiable du projet : il a rattrapé des régressions dans
les heures qui ont suivi leur introduction.
