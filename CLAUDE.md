# Bastion — règles de contribution

## L'aide fait partie de la fonctionnalité

Toute modification visible par un exploitant ou un agent s'accompagne de la mise à
jour de `admin/aide.php`, **dans le même commit**. Ce n'est pas une étape de
finition à faire plus tard : une fonction que la documentation ignore n'existe pas
pour celui qui doit s'en servir.

Ce qui déclenche une mise à jour de l'aide :

- une nouvelle page de console, ou une page qui change de rôle ;
- un nouveau réglage, surtout s'il a des conséquences juridiques ou de sécurité ;
- un changement de comportement visible côté poste (installation, ouverture de
  session, message affiché à l'agent) ;
- une panne diagnostiquée dont la cause n'était pas devinable — elle devient une
  entrée de FAQ, parce qu'elle se reproduira ailleurs.

Où écrire :

- la **section** du domaine concerné, pour « à quoi ça sert et comment on s'en sert » ;
- le groupe **« Questions fréquentes »**, pour « ça ne marche pas, pourquoi ».

Deux exigences de fond, héritées de tout ce qui a mal tourné sur ce projet :

1. **Dire le pourquoi, pas seulement le comment.** Une réponse qui n'explique pas
   la cause ne sert qu'une fois. « Le KMS n'active rien avant 25 postes » vaut mieux
   que « patientez ».
2. **Nommer ce qui échoue en silence.** Les pannes coûteuses de ce projet ne
   s'annonçaient pas : un port rejeté par le portail captif, une stratégie jamais
   appliquée, une page web enregistrée comme installeur. Si un dispositif peut
   paraître fonctionner sans l'être, l'aide doit dire à quoi le reconnaître.

Avant de committer : `python scripts/verifier-aide.py`. Une balise non fermée ne
casse pas PHP — elle avale silencieusement les sections suivantes à l'affichage.

## Vérifier avant d'affirmer

Ce dépôt possède des harnais de vérification ; ils existent parce que chacun a
rattrapé une panne réelle. Les lancer fait partie du travail :

- `scripts/verifier-gpo-ps1.ps1` — les scripts de stratégie s'analysent sous
  PowerShell 5.1 (une virgule de trop rendait le script inanalysable avant même
  que sa propre journalisation ne démarre) ;
- `scripts/verifier-catalogue-apps.php` — chaque entrée du store rend un vrai
  installeur, pas une page web ;
- `scripts/verifier-aide.py` — le HTML de l'aide est équilibré.

## Déploiement

`selfupdate.sh` installe les scripts d'après **sa propre** liste, celle de la copie
en cours d'exécution. Un script nouvellement ajouté à cette liste demande donc
**deux passages**, et le premier annonce « Terminé » sans rien signaler du manque.
Vérifier avec `ls /usr/local/sbin/proxyfibre-<nom>` plutôt que de se fier au
message.
