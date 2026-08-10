# Fonctionnalités

Panorama complet de ce que fait Bastion. Le détail d'usage est dans l'aide intégrée de la
console — elle reste accessible quand Internet ne l'est pas.

## Accès et identité

**Portail captif** — aucun accès sans authentification. Comptes nominatifs par matricule à
sept chiffres. Une **date de fin d'accès** peut être programmée pour une mutation ou une fin
de mission : à l'échéance, l'accès Internet et le compte de domaine sont désactivés
automatiquement, et le compte n'est pas supprimé — retirer la date le réactive.

**Bons visiteurs** — identifiants temporaires pour un intervenant extérieur, sans créer de
compte. Ils expirent seuls et disparaissent de la liste. Ils n'ouvrent **aucun** accès au
domaine ni aux dossiers partagés : uniquement Internet, filtré et journalisé comme pour tout
le monde.

**Groupes et quotas** — par groupe : durée de session, débits montant et descendant, quotas
de données, plages horaires. Les nouveaux réglages s'appliquent à la *prochaine* session.

**Annuaire** — trombinoscope avec photo, identité, service, commissariat, droits et présence
en ligne. Recherche instantanée par nom ou par service.

**Badge de service imprimable** — photo, identité, QR code, depuis n'importe quelle fiche.

**Rôles d'administration** — complet, *comptes et agents seulement*, ou *lecture seule*.

**Changement de mot de passe en autonomie** — l'agent le fait depuis le portail, sans passer
par un administrateur. C'est volontaire : personne ne devrait avoir à confier son mot de
passe.

## Postes et domaine

**Contrôleur de domaine intégré** (Samba AD) — ouverture de session Windows, stratégies de
groupe, dossiers partagés avec quotas, déplacement d'objets dans l'arborescence.

**Store d'applications** — catalogue de 90 logiciels courants, déploiement silencieux par
stratégie de démarrage. Les sources sont **résolues au moment du clic** — publications
GitHub, flux SourceForge, index d'éditeur — plutôt que figées sur un numéro de version qui
tombe en panne quelques mois plus tard. Le fichier reçu est contrôlé avant d'être accepté :
un MSI est un conteneur OLE, un exécutable commence par `MZ`, et une page web renvoyée à la
place d'un installeur est refusée.

Désactiver un logiciel ne se contente pas de le retirer du catalogue : il est
**désinstallé** des postes.

**Barre de progression** — pendant une installation, une fenêtre s'affiche dans la session
de l'agent avec le logiciel en cours. Elle ne s'ouvre que s'il y a réellement quelque chose à
installer.

**Inventaire du parc** — matériel, système, édition, état d'activation, navigateur,
logiciels installés, écart d'horloge, conformité.

**Stratégies prêtes à l'emploi** — Firefox et ses extensions imposées, navigateur par
défaut, pavé numérique à l'écran de connexion, bannière juridique, mises à jour automatiques,
chiffrement BitLocker, synchronisation d'horloge, interdiction du pont réseau, page d'accueil
intranet.

**Activation Windows et Office** par serveur KMS local, avec découverte DNS automatique et
bascule Professionnel vers Entreprise.

**Serveur PXE** — installation de Windows 11 ou Ubuntu par le réseau, sans clé USB.

**Prise de main à distance** — relais auto-hébergé. Les deux extrémités se connectent en
**sortant** vers la passerelle : rien n'entre dans le réseau des postes, et l'isolement entre
administration et parc reste entier. Le consentement de l'agent est réglable globalement puis
poste par poste, et verrouillé sur « accord obligatoire » partout où le réglage est absent,
illisible ou injoignable.

**Réservations DHCP** — adresse fixe par adresse MAC, depuis la liste des baux en cours.

## Protection

**Filtrage DNS** — catégories thématiques, domaines un par un ou par import de liste,
bloqueur de publicités rafraîchi chaque semaine, liste blanche pour les faux positifs. Le
filtrage est **incontournable** : les requêtes DNS sortantes sont redirigées vers la
passerelle et le DNS chiffré (DoH/DoT) est bloqué — un poste qui configure « un autre DNS »
reste filtré sans s'en apercevoir.

**Antivirus** — analyse des supports amovibles depuis des stations blanches, chacune
identifiée par son propre jeton. Tableau de bord des analyses, et redistribution des
signatures aux postes.

**Quarantaine réseau** — isoler un poste suspect immédiatement, à distance. Il perd Internet
et l'accès aux autres machines mais garde la passerelle, ce qui permet de l'inventorier et de
le libérer. Mise en quarantaine et levée sont tracées nominativement.

**Chiffrement des postes** — BitLocker piloté par stratégie.

**Sauvegarde chiffrée** — base de données, configuration des services, intranet, paramètres
du domaine. Restauration par dépôt de l'archive.

**Repli sur panne** — si le portail captif tombe, le trafic est coupé plutôt que laissé
passer.

**Posture de sécurité** — revue automatique des points de contrôle de la passerelle.

## Journalisation

**Historique de navigation** par agent et par poste : date, heure, utilisateur, adresse,
domaine. Ce sont les **domaines** qui sont enregistrés, pas le contenu des pages ni les mots
de passe.

**Intégrité** — les journaux sont chaînés et scellés par signature : une ligne modifiée ou
supprimée après coup rompt la chaîne et devient détectable. Sans cela, un journal ne vaudrait
rien devant un magistrat.

**Réquisition judiciaire** — extraction sur une période et un agent donnés, avec la preuve
d'intégrité associée.

**Journal d'audit** — chaque action d'administration : qui, quand, depuis quelle adresse,
quoi. Consultable, jamais modifiable.

**Purge automatique** au terme de la durée de conservation paramétrée.

## Supervision et exploitation

**Santé de la passerelle** — processeur, mémoire, disque, température, état des services,
avec **alertes par courriel**. C'est le seul canal qui fonctionne encore quand la console ne
répond plus.

**Trafic réseau en direct** — qui consomme quoi, maintenant, avec fermeture de session
possible.

**Rapport de conformité PDF** — configuration, comptes, stratégies, état des postes.

**Fonctions** — activer ou couper l'antivirus, la prise de main à distance, le Wi-Fi,
l'activation Windows. L'état est relu sur le système à chaque affichage, jamais mémorisé. Un
état « partielle » est signalé à part : une fonction dont un seul composant tourne a
l'apparence de marcher sans marcher.

**Services** — état et redémarrage de chaque brique.

**Recherche globale** — un seul champ sur dix-huit sources : agents, postes, adresses,
domaines, journaux, documentation.

**Mise à jour tout-en-un** — système Debian et application, depuis un bouton.

## Intranet

Page d'accueil des agents après connexion : **actualités** avec permalien et archive,
**pages d'information** qui restent accessibles même sans Internet, **bandeau d'annonce** à
date d'expiration — il disparaît seul —, **demandes d'assistance**, et l'annuaire.

Chaque publication est tracée au journal d'audit avec son auteur.

## Multi-sites

Raccordement de plusieurs commissariats à un serveur **principal du département** par tunnel
**sortant** : aucun port à ouvrir sur les box des sites. Un site dont l'adresse publique
change se re-raccroche seul, puisque c'est lui qui appelle.

**Cartographie des flux en direct** entre le serveur principal et les sites raccordés.
