# Dépannage

Les pannes ci-dessous ont **toutes** été rencontrées en production. Chacune est décrite par
son symptôme, sa cause réelle, et — le plus important — le **signe** qui permet de la
reconnaître, parce qu'aucune ne s'annonçait d'elle-même.

## Postes

### Ouverture de session très lente, tous les jours

Presque toujours un logiciel du store qui n'arrive pas à s'installer. Le poste retente
celles dont le marqueur manque : un seul installeur défectueux fait donc recommencer tout
le reste à chaque connexion, et sature le réseau au passage.

**Le signe :** dans `C:\ProgramData\Bastion\apps.log`, une passe commencée par
`--- Installation des applications` sans `--- Fin ---` correspondant. Elle n'a pas abouti.

**L'outil :** `\\dc.bastion.pn.int\Commun\Outils\DIAGNOSTIC APPLICATIONS - Double-cliquer ici.cmd`
le dit en trente secondes, et propose d'arrêter un installeur bloqué depuis plus de dix
minutes.

### `gpupdate /force` interminable

Windows exécute les scripts de démarrage de façon **synchrone** et les attend. Un script
qui installe des logiciels peut donc geler `gpupdate` pendant plus d'une heure.

Le lanceur déposé au SYSVOL doit contenir `start "Bastion" /b` — sans quoi le démarrage
attend la fin de l'installation. Ne relancez pas `gpupdate` : **redémarrez le poste**,
c'est plus rapide et ça applique la nouvelle stratégie.

### Fond d'écran et lecteurs réseau absents

Le traitement des stratégies utilisateur n'a pas abouti. Deux causes vues :

- un script de démarrage qui monopolise la machine (voir ci-dessus) ;
- une liaison réseau qui casse pendant la lecture du SYSVOL — typiquement du Wi-Fi en
  limite de portée.

### Arrêt et démarrage très longs

Vérifiez le **démarrage rapide** de Windows :

```powershell
Get-ItemProperty "HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Power" -Name HiberbootEnabled
```

Il doit valoir **1**. À 0, chaque arrêt et chaque démarrage sont complets — plusieurs
dizaines de secondes perdues à chaque fois.

### Une stratégie de groupe ne s'applique pas

Regardez d'abord l'**heure du poste**. Un décalage de plus de cinq minutes fait échouer
Kerberos, donc l'authentification, donc les stratégies — et aucun message ne parle jamais
d'heure. Fréquent sur machine virtuelle, dont l'horloge dérive au démarrage.

### Windows reste « non activé »

Un serveur KMS n'active rien avant d'avoir vu **25 postes** distincts (5 pour Office). En
deçà, rien n'est cassé : le compteur monte à chaque nouvelle machine. L'état réel est dans
*Inventaire des postes*, pas sur le poste.

## Réseau

### Le réseau Wi-Fi disparaît de la liste

`hostapd` peut rester bloqué à l'état `COUNTRY_UPDATE` : il se déclare **actif** et n'émet
aucune balise. Le journal du noyau montre alors `rtw_8822bu: error beacon valid`.

```bash
sudo systemctl stop hostapd && sudo modprobe -r rtw88_8822bu && sleep 4 \
  && sudo modprobe rtw88_8822bu && sleep 6 && sudo systemctl start hostapd
```

**N'automatisez pas cette détection à la légère.** Une surveillance fondée sur le compteur
de paquets émis a été essayée : sur ce pilote les balises n'y sont pas comptées, et le
garde-fou rechargeait le pilote toutes les minutes sur un point d'accès parfaitement sain —
il fabriquait la panne qu'il devait corriger.

### On voit le réseau Wi-Fi mais on ne peut pas s'y connecter

```bash
sudo /usr/sbin/iw dev <interface> station dump | grep -E "signal|bitrate"
```

Un signal autour de **−71 dBm** et un débit tombé à 1 Mb/s : la liaison est en limite de
portée. Les balises portent loin, les réponses d'authentification beaucoup moins — d'où un
réseau visible et inaccessible. Aucun réglage logiciel ne compense cela.

### Un poste est connecté mais n'a aucun accès

```bash
sudo ndsctl json | grep -A6 "<adresse du poste>"
```

Si `clientif` n'est pas l'interface du LAN, le portail a rattaché le client à la mauvaise
interface : il restera `Preauthenticated` quoi qu'il fasse.

### Un site bloqué reste accessible

Le cache DNS du poste : `ipconfig /flushdns`. Si ça persiste, le site est atteint par une
autre adresse que celle bloquée.

## Passerelle

### Après un redémarrage, plus personne ne s'authentifie pendant quelques secondes

FreeRADIUS lit les comptes dans MariaDB et peut partir avant qu'elle soit prête. Il échoue,
puis se relance seul — mais pendant l'intervalle, un agent qui démarre se voit refuser un
mot de passe pourtant valide, et l'erreur est imputée à son compte.

Une dépendance systemd (`After=mariadb.service`) supprime la course.

### La mise à jour dit « Terminé » et un script manque

`selfupdate.sh` installe les scripts d'après **sa propre** liste, celle de la copie en cours
d'exécution. Un script nouvellement ajouté demande donc **deux passages**, et le premier ne
signale rien.

```bash
ls /usr/local/sbin/proxyfibre-<nom>
```

Vérifiez la présence du fichier plutôt que de vous fier au message.

## Console

### Le store refuse un logiciel

C'est voulu. Le fichier reçu est contrôlé avant d'être enregistré : un MSI est un conteneur
OLE, un exécutable commence par `MZ`. Une page web renvoyée à la place d'un installeur est
**refusée** — sans ce contrôle, elle serait déployée sur tout le parc et échouerait en
silence sur chaque poste. Le message dit ce qui a été reçu et depuis quelle adresse.
