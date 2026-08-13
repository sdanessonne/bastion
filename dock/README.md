# Dock des postes — lanceur d'applications

> **État : dégraissé, compile, pas encore déployé.** Le choix a été fait le 2026-08-14 :
> ce dock est un **lanceur**, pas une seconde console poste. Les huit services qui
> faisaient double emploi avec Bastion ont été retirés — voir « Ce qui a été retiré ».
> Il reste à rebaptiser le produit, fabriquer le MSI et le publier au store.

## Ce qui a été retiré, et ce qu'il en reste

| | Avant | Après |
|---|---|---|
| Fichiers C# | 66 | **13** |
| Lignes | ~14 800 | **1 483** |
| Points d'entrée serveur | 15 | **0** |
| Appels réseau | télémétrie, écran, commandes, tickets, bandeaux, mises à jour | **aucun** |

Le dock affiche des icônes et démarre des programmes. Il ne joint aucun serveur,
n'ouvre aucun port, ne remonte rien. La seule référence réseau restante est
`AddressFamily.InterNetwork`, pour lire l'adresse IP du poste et l'afficher.

**Trois retraits méritent d'être connus.** La *mise à jour automatique* : un logiciel qui
se remplace tout seul contourne le store — la console afficherait une version, les postes
en auraient une autre, et rien ne le signalerait. Le *serveur HTTP local sur le port 43782*
du lecteur de carte agent : un port en écoute sur chaque poste que plus personne
n'interroge est une surface d'attaque offerte pour rien. La *synchronisation des
préférences* : elle n'écrivait rien localement, donc sans backoffice tout réglage de
l'agent serait perdu à la fermeture, sans message — le dock donnerait l'impression
d'oublier. Il écrit désormais dans son `apps.json`.

---

# Origine du code


Barre d'icônes flottante affichée sur le bureau des postes du domaine. Code repris du
projet **DockPolice** (`pincile/DockLite`) le 2026-08-13, pour être adapté à Bastion.

**En l'état, ce code n'est relié à rien.** Il n'est ni compilé, ni déployé, ni référencé
par la console. Rien n'a changé pour l'exploitant ni pour l'agent.

## Ce qui a été importé

| | |
|---|---|
| `DockLite/` | Le dock lui-même — WPF, .NET 8, 53 fichiers C# (nom d'assemblage : `DockPolice`) |
| `DockPolice.Agent/` | Service Windows : télémétrie, exécution de commandes, gestion de session |
| `installer/` | Fabrication du MSI (WiX) |
| `tools/`, `publish.ps1` | Scripts de publication et de signature |

Environ 14 800 lignes de C#.

## Ce qui a été volontairement laissé dehors

**La clé privée de signature de code** (`tools/DockPolice-CodeSign.pfx`). Une clé privée
n'a rien à faire dans un dépôt : elle permettrait à quiconque y a accès de signer du code
au nom du projet.

**Les fichiers de configuration porteurs de secrets.** `apps.json` et `agent.json`
contenaient, en clair :

- une **clé d'API** ;
- une **chaîne de connexion MySQL en compte `root`, sans mot de passe**.

Ces fichiers sont installés **sur chaque poste**, donc lisibles par n'importe quel agent
connecté. Sur un parc de police, c'est un problème sérieux — et il ne s'annoncerait pas :
le dock fonctionnerait normalement.

`agent.json` figure ici sous forme de **gabarit à champs vides**, parce que le projet Agent
le référence et que son absence casserait la compilation. Ne jamais y écrire de vraie
valeur : un secret poussé une seule fois reste dans l'historique Git même après
suppression, et doit alors être considéré comme compromis et changé. Les règles
correspondantes sont dans le `.gitignore` à la racine.

## Ce que le code attend d'un serveur

Le dock parle à un backoffice PHP par quinze points d'entrée, avec une clé d'API :

```
agent-poll.php           agent-result.php          attachments.php
dock-config-default.php  dock-config-get.php       download.php
habilitation-catalog.php habilitation-create.php   habilitation-list.php
machine-live.php         machine-snapshot.php      system-action-poll.php
system-action-result.php upload.php                vault-extension-deploy.php
```

`ApiBaseUrl` pointe sur `http://localhost/dockpolice` et `CommissariatCode` est vide :
tel quel, sur un poste, le dock ne joindrait **aucun** serveur. Les tickets et les
notifications resteraient muets, sans message d'erreur.

## Le vrai travail d'adaptation

Ce n'est pas un changement de logo. La plupart des services du dock **font déjà double
emploi** avec des fonctions de Bastion, qui ont leur propre source de vérité :

| Service du dock | Ce que Bastion fait déjà |
|---|---|
| `MachineReporter`, `MachineSnapshot`, `MachineLive` | Inventaire du parc (`gpo-inventory.py` → `parc.php`) |
| `RemoteCommandService`, `ScreenCapture`, `ScreenStreamService`, `RemoteInputService` | Prise de main à distance par relais RustDesk (`distance.php`) |
| `TicketService`, `OfflineTicketQueue` | Demandes d'assistance (`assistance.php`) |
| `AntivirusInfo`, `TrellixInfo` | ClamAV et station blanche (`antivirus.php`) |
| `ActiveDirectoryService`, `LapsService`, `HabilitationService` | Annuaire et stratégies (`ad.php`, `users.php`) |
| `BroadcastService` | Bandeaux d'annonce (`cms.php`) |
| `AppDiscovery` | Store d'applications (`apps.php`) |
| `UpdateChecker`, `UpdateCheckerService` | Mise à jour (`systeme.php`) |

Garder les deux mécanismes ferait remonter **deux inventaires qui divergeront**, et
ouvrirait un **second canal de prise de main** à côté de celui dont le consentement par
groupe a été construit exprès. C'est une décision à prendre service par service, pas une
option de configuration.

La question à trancher avant d'écrire une ligne : le dock est-il un **lanceur
d'applications** (on garde le dock, on jette les services et on branche le peu qui reste
sur l'API de la console), ou une **seconde console poste** (on assume deux chemins) ?

## Compiler

```powershell
dotnet publish DockLite\DockLite.csproj -c Release -r win-x64 --self-contained
```

L'exécutable pèse ~163 Mo (autonome, .NET embarqué). Un MSI se fabrique ensuite depuis
`installer/`.
