# Bastion — Station blanche

Analyse d'une clé USB avant tout usage sur le réseau du service, sur le principe des
stations blanches (type CleanKey) : on insère un support, il est examiné, on sait.

## Principe

**La station constate, elle ne modifie jamais le support.** Une clé peut être une pièce
remise par un tiers, voire un scellé : en effacer un fichier détruirait une preuve. La
décision d'effacer appartient à l'agent, pas au logiciel.

## Ce qu'elle fait

- Détecte l'insertion d'un support et lance l'analyse **automatiquement**.
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
