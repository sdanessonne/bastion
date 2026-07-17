# Bastion — Station blanche

Analyse d'une clé USB avant tout usage sur le réseau du service, sur le principe des
stations blanches (type CleanKey) : on insère un support, il est examiné, on sait.

## Principe

**La station constate, elle ne modifie jamais le support.** Une clé peut être une pièce
remise par un tiers, voire un scellé : en effacer un fichier détruirait une preuve. La
décision d'effacer appartient à l'agent, pas au logiciel.

## Ce qu'elle fait

- Détecte l'insertion d'un support USB et lance l'analyse **automatiquement**.
- Analyse avec **Windows Defender**, présent sur tout poste Windows — rien à installer.
- Affiche un verdict lisible à trois mètres : sain, infecté, ou analyse impossible.
- Signale l'**âge des signatures** : périmées, elles rendent tout « aucune menace »
  trompeur.
- Exporte un rapport texte, joignable à un compte rendu.

## Ce qu'elle ne fait pas

- **Un seul moteur.** Les stations du commerce en cumulent plusieurs, car aucun ne détecte
  tout. « Aucune menace » signifie « rien de connu par Defender », pas « inoffensif ».
- **Aucune analyse comportementale** : un code inconnu passera.
- **Elle ne désinfecte pas** — c'est délibéré (voir Principe).

## Construire

Nécessite le SDK .NET 8 ou supérieur.

    dotnet publish -c Release -o publish

Produit un `.exe` **autonome** (~68 Mo) : aucun runtime .NET à installer sur le poste.
Une station blanche est souvent isolée, sans droits d'installation ni accès Internet ;
elle doit démarrer sur un simple copier-coller.

## Éprouver sans interface

    BastionStationBlanche.exe --test <dossier>

Rend l'état du moteur et le résultat de l'analyse sur la sortie standard. Le code de
retour vaut le nombre de menaces. Sert aux essais automatisés.

Pour vérifier la détection, utiliser le fichier de test **EICAR** — une chaîne standard,
inoffensive, reconnue par tous les antivirus. Attention : la protection temps réel de
Windows l'efface en quelques secondes, il faut analyser aussitôt après l'avoir écrit.

## Détection des supports

Un support n'est retenu que s'il est **réellement branché sur le bus USB**
(`Win32_DiskDrive.InterfaceType = 'USB'`, puis chaîne disque → partition → lettre).

`DriveInfo.DriveType` **ne suffit pas**, dans les deux sens :

- beaucoup de clés et de disques USB se déclarent `Fixed`, pas `Removable` : ne garder
  que `Removable` les manquerait ;
- accepter tout `Fixed` sauf le disque système fait passer les **disques internes** pour
  des clés. Constaté en développement : un second NVMe de 1,9 To était proposé à
  l'analyse. Une station qui propose d'analyser 1,9 To de disque interne se bloque des
  heures, et un agent pressé cliquerait.

L'insertion est signalée par `WM_DEVICECHANGE`, avec une relecture différée de 1,5 s : au
moment du message, le volume n'est pas encore monté et n'a pas de lettre. Un sondage de
secours toutes les 4 s couvre les concentrateurs et lecteurs de cartes qui n'émettent
aucun message.

Si WMI est indisponible (service arrêté, poste durci), la station se rabat sur le seul
critère sûr — `Removable` — quitte à manquer les disques USB qui se déclarent `Fixed`.
Manquer un support est gênant ; proposer d'analyser le disque interne de la station
serait pire.

## Points techniques à connaître

- **Le code de retour 2 de MpCmdRun n'est PAS une erreur** : il signifie « menace
  trouvée ». Le traiter comme un échec ferait passer une clé infectée pour un incident
  technique.
- **`-DisableRemediation` est indispensable** : sans lui, Defender supprime ou met en
  quarantaine ce qu'il trouve.
- **La protection temps réel de Windows agit indépendamment** de ce logiciel et peut, elle,
  toucher aux fichiers du support dès l'insertion. Sur une station destinée à examiner des
  pièces, prévoir une exclusion sur les lecteurs amovibles.
- **Un chemin inexistant fait sortir MpCmdRun avec le code 0** — soit « sain ». Le support
  est donc vérifié avant analyse : une station qui déclare saine une clé qu'elle n'a pas
  lue est pire qu'inutile.

## Mode borne (kiosque)

Par défaut la station démarre **en plein écran, sans bordure ni barre de titre**, au-dessus
des autres fenêtres. L'agent insère sa clé, lit le verdict, éteint. Il n'y a rien d'autre à
faire, et rien à fermer par mégarde : Alt+F4 est sans effet.

Sortie de secours pour l'exploitant : **Ctrl+Shift+Q**. Sans elle, une borne en plein écran
ne se quitterait plus qu'en coupant le poste.

`BastionStationBlanche.exe --fenetre` force le mode fenêtré, pour préparer un poste sans
s'enfermer dedans.

> **Ce n'est pas le mode kiosque de Windows.** Une application ne peut pas, à elle seule,
> neutraliser Ctrl+Alt+Suppr, la touche Windows ou le gestionnaire des tâches — aucune
> application ne le peut, et c'est voulu. Pour verrouiller réellement le poste, il faut
> configurer Windows : **Accès attribué (Assigned Access)** ou **Shell Launcher**, en
> désignant cet exécutable comme interface unique de la session. La station coopère avec
> ces mécanismes ; elle ne les remplace pas. Sans eux, un agent déterminé sort de l'écran.

## Réglages — `station.json`

Écrit à côté de l'exécutable au premier lancement, à compléter :

```json
{
  "Passerelle": "https://192.168.182.1:8443",
  "Jeton": "<pf_settings.station_token de la passerelle>",
  "Kiosque": true,
  "BoutonEteindre": true,
  "MajAuto": true,
  "AccepterCertificatInterne": true
}
```

Le jeton se lit sur la passerelle :

```sh
sudo mysql proxyfibre -N -B -e "SELECT v FROM pf_settings WHERE k='station_token';"
```

C'est **`station_token`, jamais `api_token`**. Une station blanche est une machine en libre
accès dans un couloir : si son jeton fuit, il ne doit ouvrir que le dépôt d'un résultat
d'analyse — ni les comptes, ni les journaux. L'API refuse (403) toute autre action à ce
jeton.

Sans `Passerelle` ni `Jeton`, la station fonctionne quand même : elle analyse et affiche,
mais l'écran indique « analyses NON tracées ».

## Mise à jour des signatures

Au démarrage, puis toutes les 4 h (`MajAuto`). Une borne reste allumée des jours ; attendre
un redémarrage la laisserait travailler avec des signatures d'il y a une semaine.

Le bandeau passe à l'ambre au-delà de 2 jours, au rouge au-delà de 7 — parce que des
signatures périmées donnent une **fausse assurance** : la station déclare « sain » ce
qu'elle ne sait plus reconnaître.

> Un code de retour 0 de `MpCmdRun -SignatureUpdate` ne prouve rien : Defender sort en
> succès même sans avoir rien récupéré (poste hors ligne, serveur injoignable). Seule la
> **date des signatures**, relue après coup, fait foi. C'est ce que vérifie le code.

## Remontée vers Bastion

Après chaque analyse, la station dépose le résultat sur la console (`Antivirus` →
`Historique des analyses`, origine « 🔌 Station »).

La remontée part **après** l'affichage du verdict : elle ne doit jamais faire attendre
l'agent. Si la passerelle est injoignable, l'écran le dit en petit, et le verdict reste
valable — c'est lui qui compte.

Une analyse **non aboutie** est enregistrée comme telle (`non aboutie`), et jamais comme
« 0 menace ». Une analyse qui n'a pas fini ne dit rien sur le support.
