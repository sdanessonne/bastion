<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — Aide / documentation intégrée (regroupée par domaine, à jour). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';

$ver = defined('BASTION_VERSION') ? BASTION_VERSION : '';

// Aide regroupée par domaine : 'Groupe' => [ [ancre, icône, titre, contenu HTML], … ].
$GROUPS = [
  'Prise en main' => [
    ['demarrage', '🚀', 'Prise en main', '
      <p>Bastion est le contrôleur d\'accès de votre réseau : il authentifie les utilisateurs, filtre, journalise,
      gère les postes (Active Directory, GPO, applications) et la sécurité, depuis cette console unique.</p>
      <ul>
        <li><strong>Console d\'administration</strong> : <code>https://&lt;passerelle&gt;:8443</code> (ou <code>https://bastion.pn.int:8443</code>).</li>
        <li><strong>Portail utilisateur</strong> : les postes sont redirigés automatiquement à la connexion au réseau.</li>
        <li>Le menu de gauche regroupe les fonctions par domaine (Supervision, Accès &amp; sécurité, Réseau &amp; postes,
        Intranet, Journalisation). Les changements sont <strong>immédiats</strong>.</li>
        <li>Menu du haut à droite : votre profil &amp; sécurité (photo, mot de passe, double authentification),
        redémarrer / arrêter le serveur, se déconnecter.</li>
      </ul>
      <p class="tip">Une <strong>mise à jour tout-en-un</strong> (système Debian + application Bastion) est disponible
      dans <em>Système</em> : un seul bouton vérifie et installe les deux.</p>'],
  ],

  'Accès & sécurité' => [
    ['utilisateurs', '👤', 'Utilisateurs, droits &amp; rôles', '
      <p><strong>Utilisateurs &amp; droits</strong> : un seul écran pour tout le cycle de vie d\'un agent —
      <strong>accès Internet</strong> (portail), <strong>compte de domaine</strong> (AD), identité (nom, prénom,
      service), <strong>photo</strong>, <strong>commissariat</strong> d\'appartenance, et opérations en <strong>masse</strong>
      (import CSV, actions groupées).</p>
      <ul>
        <li><strong>Date de fin d\'accès</strong> : programmez la désactivation d\'un compte (fin de mission, mutation).
        À l\'échéance, l\'accès Internet et le compte de domaine sont désactivés automatiquement — le compte n\'est pas
        supprimé (retirer la date le réactive).</li>
        <li><strong>Droits de gestion</strong> : administrateur de la console et/ou du domaine. Pour un administrateur
        console, un <strong>niveau d\'accès</strong> : <em>complet</em>, <em>comptes &amp; agents seulement</em>, ou
        <em>lecture seule</em> (consultation sans modification).</li>
      </ul>
      <p><strong>Groupes &amp; quotas</strong> : par groupe, la durée de session, les débits, les quotas de données et
      les plages horaires.</p>
      <p class="tip">Identifiant imposé : matricule à 7 chiffres (ex. <code>0110480</code>) ; administrateur
      <code>admin-0110480</code>. Le compte <code>admin</code> intégré garde toujours l\'accès complet. Les nouveaux
      quotas s\'appliquent à la <em>prochaine</em> connexion.</p>'],

    ['annuaire', '📇', 'Annuaire, photos &amp; badges', '
      <p><strong>Annuaire</strong> : trombinoscope visuel des fonctionnaires — photo, identité, service, commissariat,
      droits et présence en ligne, avec recherche instantanée. La photo se règle dans la fiche du compte
      (« Utilisateurs &amp; droits »).</p>
      <p><strong>Badge</strong> : depuis une fiche de l\'annuaire, générez un badge de service <strong>imprimable</strong>
      (photo, identité, QR code) — bouton « Imprimer » puis, au besoin, « Enregistrer au format PDF ».</p>'],

    ['filtrage', '⛔', 'Filtrage &amp; publicités', '
      <p><strong>Filtrage</strong> : bloquez des domaines (un par un ou par import de liste) et des <strong>catégories
      thématiques</strong> (adulte, jeux d\'argent, réseaux sociaux, streaming, malveillant). Activez le
      <strong>bloqueur de publicités</strong> (liste communautaire, mise à jour hebdomadaire).</p>
      <p>Le blocage est appliqué au niveau DNS : il fonctionne quel que soit le site (HTTP comme HTTPS) et prend effet
      immédiatement pour tous les clients.</p>'],

    ['visiteurs', '🎫', 'Accès visiteur (bons temporaires)', '
      <p><strong>Accès visiteur</strong> : délivrez un <strong>bon</strong> — un identifiant et un mot de passe à
      usage temporaire — à une personne de passage (intervenant, stagiaire, réunion), sans lui créer de compte.</p>
      <ul>
        <li>Choisissez la <strong>durée de validité</strong> et le nombre de bons à générer ; imprimez ou recopiez
        les identifiants pour les remettre au visiteur.</li>
        <li>Le visiteur se connecte par le <strong>portail</strong> comme un agent, et reste soumis au
        <strong>filtrage</strong> et à la <strong>journalisation</strong>.</li>
        <li>À l\'échéance, le bon cesse de fonctionner et disparaît automatiquement de la liste.</li>
      </ul>
      <p class="tip">Un bon n\'ouvre <strong>aucun</strong> accès au domaine ni aux dossiers partagés : il ne
      donne que l\'accès Internet encadré. Pour un accès durable, créez un compte d\'agent.</p>'],

    ['antivirus', '🛡️', 'Antivirus &amp; stations blanches', '
      <p><strong>Antivirus</strong> (ClamAV) : état du moteur, mise à jour de la base virale, analyse à la demande des
      dossiers partagés et de l\'espace web, historique. Une analyse complète est aussi <strong>planifiée chaque
      nuit</strong>. Les fichiers déposés par les clients dans les partages sont analysés.</p>
      <p><strong>Stations blanches</strong> (analyse de clés USB) : elles déposent leurs résultats ici et récupèrent
      leur base virale sur la passerelle, sans Internet. Chaque poste reçoit son propre <strong>jeton</strong> :
      vous voyez lequel se sert (et quand) et pouvez en <strong>révoquer un seul</strong> — poste volé ou remplacé —
      sans reconfigurer les autres. Un bilan des analyses (30 j) est affiché.</p>'],
  ],

  'Réseau &amp; postes' => [
    ['ad', '🗄️', 'Active Directory', '
      <p><strong>Active Directory</strong> (domaine <code>BASTION.PN.INT</code> par défaut), présenté en
      <strong>onglets</strong> : vue d\'ensemble, comptes &amp; groupes, postes, partages &amp; lecteurs, stratégies.
      Les ordinateurs indiquent leur <strong>système</strong>, leur <strong>dernière ouverture de session</strong> et
      le dernier agent connecté ; un poste inactif depuis plus de 30 jours est signalé.</p>
      <p><strong>Joindre un poste au domaine</strong> :</p>
      <ol>
        <li>Régler le DNS du poste sur <code>192.168.182.2</code>.</li>
        <li>Système → « Ce PC » → Domaine → <code>bastion.pn.int</code>.</li>
        <li>Identifiants <code>Administrator</code> / mot de passe du domaine, puis redémarrer.</li>
      </ol>
      <p>Depuis un poste, un dossier partagé s\'ouvre par le <strong>nom du serveur</strong> :
      <code>\\\\dc.bastion.pn.int\\Commun</code>. Le <em>nom de domaine</em> (<code>\\\\bastion.pn.int\\…</code>)
      ne convient pas pour un dossier ordinaire : Windows répond « Élément introuvable ».</p>'],

    ['partages', '📁', 'Dossiers partagés : droits &amp; quota', '
      <p>Un <strong>dossier partagé</strong> est un espace commun sur la passerelle, ouvert depuis un poste par
      <code>\\\\dc.bastion.pn.int\\NomDuDossier</code>. Les fichiers déposés sont analysés par l\'antivirus.</p>
      <p><strong>Droits d\'accès</strong> (colonne « Droits d\'accès » → « Modifier les droits ») — deux régimes :</p>
      <ul>
        <li><strong>Tous les agents du domaine</strong>, en <em>lecture-écriture</em> (dépôt, modification,
        suppression) ou en <em>lecture seule</em> (consultation uniquement) ;</li>
        <li><strong>Seulement les groupes désignés</strong> : pour chaque groupe, <em>Aucun accès</em>,
        <em>Lecture seule</em> ou <em>Lecture-écriture</em>. Les agents qui ne sont dans aucun de ces groupes
        n\'ouvrent plus le dossier du tout.</li>
      </ul>
      <p>Les <strong>administrateurs du domaine</strong> conservent toujours l\'accès : un dossier ne peut pas
      devenir inadministrable. <strong>Retirer un droit ne supprime aucun fichier</strong> — les documents déjà
      déposés restent en place et redeviennent accessibles si le droit est rendu.</p>
      <p><strong>Quota</strong> : une limite en Mo par dossier (0 = illimité). Les postes voient l\'espace plafonné
      et l\'écriture est refusée une fois plein ; <strong>aucun fichier n\'est supprimé</strong>.</p>
      <p class="tip">Un agent que vous venez d\'ajouter à un groupe travaille encore avec ses anciens droits : ils
      sont figés à l\'ouverture de session. Il doit <strong>fermer sa session Windows et la rouvrir</strong>.</p>'],

    ['lecteurs', '💽', 'Lecteurs réseau', '
      <p>Un <strong>lecteur réseau</strong> connecte automatiquement un dossier partagé à une lettre (Z:, Y:…) à
      l\'ouverture de session des agents. Ce n\'est qu\'un <strong>raccourci</strong> : il indique où se trouve le
      dossier, il n\'accorde aucun droit — ceux-ci se règlent sur le dossier partagé lui-même.</p>
      <ul>
        <li><strong>Chemin réseau</strong> : toujours par le <strong>nom du serveur</strong>
        (<code>\\\\dc.bastion.pn.int\\Commun</code>). La liste déroulante propose les dossiers existants.</li>
        <li><strong>Pour qui ?</strong> : « Tous les agents », ou un <strong>groupe</strong> précis — le lecteur
        n\'apparaît alors que chez ses membres.</li>
        <li>Après toute modification : « <strong>🚀 Déployer sur les postes</strong> ».</li>
      </ul>
      <p class="tip">Les lecteurs se connectent <strong>à l\'ouverture de session</strong> : après un déploiement,
      l\'agent doit <strong>fermer sa session et la rouvrir</strong>. Un <code>gpupdate</code> seul ne suffit pas.
      Un compte administrateur du domaine ne voit pas les lecteurs connectés : testez avec un compte d\'agent.</p>'],

    ['chiffrement', '🔒', 'Chiffrement des postes', '
      <p><strong>Chiffrement des postes</strong> : vue d\'ensemble des postes du domaine et de l\'état de
      chiffrement de leur disque, avec les <strong>clés de récupération</strong> conservées dans l\'annuaire.
      En cas de disque bloqué ou de carte mère changée, la clé se retrouve ici (export possible).</p>
      <ul>
        <li><strong>Postes Windows</strong> : le chiffrement s\'active par la stratégie « Chiffrement BitLocker » ;
        la clé de récupération est déposée automatiquement dans l\'annuaire.</li>
        <li><strong>Postes Linux</strong> : l\'entrée PXE « <strong>Debian — installation chiffrée (LUKS)</strong> »
        chiffre le disque dès l\'installation. La phrase secrète est <strong>saisie sur le poste</strong> pendant
        l\'installation : elle n\'est jamais préenregistrée sur le serveur.</li>
      </ul>
      <p class="tip">Un poste chiffré protège les données <strong>quand il est éteint</strong> (vol, perte,
      mise au rebut). Il ne remplace ni la session verrouillée, ni les droits sur les dossiers partagés.</p>'],

    ['gpo', '📋', 'Stratégies de groupe (GPO)', '
      <p>Le <strong>catalogue de stratégies</strong> déploie en un clic (sur tout le domaine) plus de 100 réglages
      prêts à l\'emploi : sécurité &amp; durcissement, confidentialité, verrouillage de l\'interface, Windows Update,
      navigateurs Edge / Chrome / Firefox, Office, etc.</p>
      <ul>
        <li><strong>Fond d\'écran des postes</strong> : téléversez une image, elle s\'impose à l\'ouverture de session
        (un aperçu s\'affiche dans la console).</li>
        <li><strong>Lecteurs réseau</strong> : connectez automatiquement des dossiers partagés
        (ex. <code>Z: → \\\\dc.bastion.pn.int\\Commun</code>) — voir la rubrique « Lecteurs réseau ».</li>
        <li>Chaque GPO déployée peut être <strong>désactivée</strong> (déliée du domaine, réversible) ou
        <strong>désinstallée</strong>. Son état réel (active / désactivée) est indiqué.</li>
      </ul>
      <p class="tip">Sur le poste : <code>gpupdate /force</code> puis redémarrage / réouverture de session. Déployez
      « <strong>Attendre le réseau à l\'ouverture de session</strong> » pour que fond d\'écran et lecteurs apparaissent
      dès la 1<sup>re</sup> connexion. L\'heure du poste doit être synchronisée (GPO « Synchronisation de l\'heure »),
      sinon Kerberos et donc les GPO échouent.</p>'],

    ['apps', '🏪', 'Store d\'applications', '
      <p><strong>Store d\'applications</strong> : déployez des logiciels sur tous les postes du domaine. Un catalogue
      d\'applications courantes se récupère en un clic depuis la source officielle ; vous pouvez ajouter votre propre
      installeur (.msi/.exe). « Appliquer sur les postes » : une GPO les installe en silence au démarrage. Testez
      d\'abord sur un poste pilote.</p>'],

    ['kms', '🔑', 'Activation Windows / Office', '
      <p>Activez le <strong>service KMS</strong> depuis l\'onglet Active Directory : les postes Windows et Office non
      activés s\'activent automatiquement contre la passerelle (clé générique selon l\'édition), via une GPO et
      l\'auto-découverte DNS. Les postes déjà activés (OEM/numérique) ne sont pas touchés.</p>'],

    ['dhcp', '🔌', 'Réservations DHCP', '
      <p><strong>Réservations DHCP</strong> : attribuez toujours la même adresse IP à un appareil (repéré par son
      adresse MAC) — imprimantes, serveurs, bornes. Le champ propose les appareils actuellement connectés ; l\'appareil
      prend l\'IP réservée à son prochain renouvellement de bail.</p>'],

    ['quarantaine', '🚫', 'Quarantaine réseau', '
      <p><strong>Quarantaine réseau</strong> : en cas d\'incident, isolez un poste — son accès Internet et tout son
      trafic routé par la passerelle sont coupés immédiatement, sans toucher au portail. La quarantaine se lève d\'un
      clic (bouton par poste ou « Tout lever »). La liste des postes connectés permet d\'isoler en un clic.</p>
      <p class="tip">Limite : la passerelle route, elle ne fait pas de pont — le trafic entre deux postes du même
      sous-réseau ne passe pas par elle et n\'est donc pas filtrable ici. La passerelle elle-même ne peut pas être isolée.</p>'],

    ['pxe', '📀', 'Serveur PXE (installation d\'OS)', '
      <p><strong>Serveur PXE</strong> : installez un système (Debian, Ubuntu, Windows) sur un poste par le réseau.
      Paramétrez le menu (titre, délai, entrées, protection), prévisualisez-le, changez la bannière. Sur le poste :
      démarrer en <strong>amorçage réseau (PXE)</strong>. Menu protégé par les identifiants administrateur, clavier
      en <strong>AZERTY</strong>.</p>'],
  ],

  'Journalisation' => [
    ['navigation', '🌐', 'Navigation, journaux &amp; recherche', '
      <p>La <strong>Journalisation</strong> réunit ses outils en onglets. <strong>Navigation</strong> : historique des
      sites par utilisateur, statistiques, export CSV. <strong>Journaux légaux</strong> : traçabilité des connexions
      (RGPD), filtrable et exportable. <strong>Recherche agent</strong> : fiche complète d\'un agent (identité, comptes,
      postes de connexion, navigation). Purge automatique après un an.</p>'],

    ['audit', '🕵️', 'Journal d\'audit des administrateurs', '
      <p><strong>Audit console</strong> : trace <em>qui</em> (quel administrateur) a fait <em>quoi</em> et <em>quand</em>
      dans la console — création/suppression de comptes, modification de GPO, révocation de jetons, changement du mot
      de passe système, mises à jour. Aucun secret n\'est enregistré, seulement l\'action et sa cible. Filtres par
      administrateur, action, période, et export CSV.</p>'],

    ['requisition', '⚖️', 'Réquisition judiciaire', '
      <p><strong>Réquisition</strong> : en cas de réquisition judiciaire ou administrative, extrayez toute la
      traçabilité détenue sur une cible — <strong>agent, IP, MAC, domaine ou période</strong>.</p>
      <ul>
        <li>Renseignez le cadre légal (n° de réquisition, autorité requérante, cadre juridique, requérant).</li>
        <li>Un <strong>dossier visuel</strong> s\'affiche (identités, sessions, navigation).</li>
        <li>« Télécharger le dossier signé » produit une archive avec le <strong>PDF signé électroniquement</strong>
        (intégrité et origine vérifiables) + la procédure de vérification.</li>
      </ul>
      <p class="tip">Chaque extraction est elle-même journalisée. La signature s\'appuie sur l\'autorité de certification
      interne ; le destinataire vérifie l\'archive avec OpenSSL (voir <code>VERIFICATION.txt</code>).</p>'],
  ],

  'Intranet' => [
    ['intranet', '🏠', 'Portail intranet &amp; contenu', '
      <p><strong>Portail intranet</strong> (onglets) : « Accueil » personnalise la page d\'accueil interne (titre,
      message, liens rapides) ; « Pages » et « Actualités » se rédigent dans un <strong>éditeur visuel</strong> —
      on met en forme directement (gras, titres, listes, liens, images), sans aucun code à connaître, et l\'aperçu
      est permanent. « Médiathèque » stocke les images (ré-encodées à l\'import pour la sécurité) : le bouton
      « Insérer une image » y puise directement. Les pages sont visibles par les agents après connexion.
      L\'<strong>Assistance</strong> (demandes des agents) reste une entrée séparée.</p>
      <p class="tip">Le contenu collé depuis un traitement de texte est <strong>nettoyé automatiquement</strong> :
      seule la mise en forme sûre est conservée. C\'est volontaire — cela protège le portail.</p>'],
  ],

  'Supervision &amp; exploitation' => [
    ['sante', '💓', 'Santé, rapport &amp; tableau de bord', '
      <p><strong>Système</strong> affiche la <strong>santé de la passerelle</strong> (processeur, mémoire, disque, durée
      de service) avec alerte si le disque se remplit, ainsi que l\'état de toutes les fonctions et les deux mises à jour
      (système + Bastion) réunies.</p>
      <p><strong>Rapport de conformité</strong> (Supervision) : bilan périodique imprimable / PDF — comptes, activité
      réseau, antivirus, GPO, actions d\'audit, dernière sauvegarde, rétention légale — à remettre à la hiérarchie.</p>'],

    ['sauvegarde', '💾', 'Sauvegarde &amp; restauration', '
      <p><strong>Sauvegarde</strong> : créez une archive complète (base, configuration, médias intranet,
      <strong>sauvegarde du domaine AD</strong>), téléchargez-la, ou restaurez une sauvegarde antérieure. Une
      <strong>sauvegarde automatique hebdomadaire</strong> est active par défaut.</p>
      <p><strong>Sauvegarde hors-site</strong> : une archive qui ne quitte jamais la passerelle disparaît avec
      elle. La dernière archive, <strong>déjà chiffrée</strong>, peut être recopiée automatiquement vers un
      <strong>dossier partagé</strong> du réseau (NAS, seconde passerelle). Renseignez l\'hôte, le partage, un
      compte et le sous-dossier, puis « <strong>Tester la connexion</strong> » avant d\'activer l\'envoi
      automatique. Rien ne part sur Internet : la copie <strong>reste sur votre réseau</strong>.</p>
      <p class="tip">Conservez la <strong>phrase secrète ailleurs que sur le support</strong> de sauvegarde :
      sans elle, l\'archive chiffrée est inexploitable — y compris par vous.</p>'],

    ['trafic', '📡', 'Trafic réseau en direct', '
      <p><strong>Trafic réseau</strong> : ce qui passe par la passerelle <strong>en temps réel</strong> — débit
      montant et descendant vers Internet, et <strong>classement des postes</strong> qui consomment le plus.
      La page se rafraîchit toute seule.</p>
      <p>C\'est l\'écran à ouvrir quand « Internet rame » : il montre en quelques secondes si la ligne est saturée
      et, le cas échéant, par quel poste. Un poste peut être <strong>déconnecté</strong> d\'un clic depuis la liste
      des clients (il devra se reconnecter au portail).</p>'],

    ['temps', '🕒', 'Serveur de temps (heure)', '
      <p>L\'heure est <strong>critique</strong> : au-delà de 5 minutes d\'écart entre un poste et le serveur,
      l\'authentification du domaine est refusée — stratégies, applications et lecteurs réseau cessent de
      s\'appliquer, et l\'horodatage des journaux perd sa valeur probante.</p>
      <ul>
        <li>La passerelle se synchronise sur un <strong>serveur de temps</strong> paramétrable (<em>Système</em>),
        et sert elle-même de référence horaire à tous les postes du réseau.</li>
        <li>Le panneau indique la source utilisée, l\'écart mesuré et la dernière synchronisation ; un bouton
        force une resynchronisation immédiate.</li>
        <li>Côté postes, déployez la stratégie « <strong>Recaler l\'heure au démarrage</strong> ».</li>
      </ul>
      <p class="tip">Les postes en <strong>machine virtuelle</strong> décalent souvent leur horloge au démarrage.
      Dans ce cas, l\'outil <code>Install-BastionTimeGuard.cmd</code> (dossier « Commun ») corrige le poste
      durablement — voir « Outils à lancer sur un poste ».</p>'],

    ['services', '🧰', 'Services', '
      <p><strong>Services</strong> : état de tous les services (portail, base, DNS, web, domaine, antivirus, KMS…), avec
      démarrage / arrêt / redémarrage, consultation du <strong>journal</strong> de chaque service, et actualisation
      automatique.</p>'],

    ['central', '🏢', 'Serveur central (multi-sites)', '
      <p>Le <strong>Bastion Central</strong> (machine dédiée, <code>https://&lt;central&gt;:9443</code>) supervise et
      pilote toutes les passerelles d\'un département depuis un point unique : vue d\'ensemble, détail par site, et
      <strong>actions groupées</strong> (pousser un blocage, créer un compte, piloter un service sur plusieurs sites).
      Chaque passerelle est ajoutée avec son URL admin et son jeton d\'API.</p>'],
  ],

  'Dépannage' => [
    ['depannage', '🔧', 'Dépannage', '
      <ul>
        <li><strong>Le portail ne demande plus la connexion (accès ouvert)</strong> : le service <em>Portail captif</em>
        est probablement en échec — le redémarrer depuis Services. Ne jamais poser d\'adresse IP supplémentaire sans
        étiquette (alias) sur l\'interface du LAN.</li>
        <li><strong>Un client ne voit pas le portail</strong> : vérifier qu\'il a une adresse IP (DHCP) et que le
        service <em>Portail captif</em> est actif.</li>
        <li><strong>Avertissement de certificat</strong> : normal en interne ; déployez la GPO « Certificat racine »
        pour le supprimer sur les postes du domaine.</li>
        <li><strong>Fond d\'écran / lecteur réseau absent</strong> : <code>gpupdate /force</code> + redémarrage ;
        déployez « Attendre le réseau à l\'ouverture de session ». Vérifier que l\'heure du poste est synchronisée.</li>
        <li><strong>Lecteurs réseau invisibles pour un Domain Admin</strong> : tester avec un utilisateur du domaine
        <em>non-administrateur</em> (le jeton UAC d\'un admin masque les lecteurs mappés).</li>
        <li><strong>Quota / horaire non appliqué</strong> : effet à la prochaine connexion, pas sur la session en cours.</li>
        <li><strong>Poste non joint au domaine</strong> : vérifier que son DNS pointe sur <code>192.168.182.2</code>.</li>
      </ul>'],

    ['dep-lecteurs', '💽', 'Les lecteurs réseau ne remontent pas', '
      <p><strong>Cause la plus fréquente :</strong> les lecteurs sont connectés par un <strong>script d\'ouverture
      de session</strong>. Ils ne se montent donc qu\'<em>au moment où l\'agent ouvre sa session Windows</em>.</p>
      <p><strong>Geste à faire, dans l\'ordre :</strong></p>
      <ul>
        <li>Sur le poste : <strong>fermer la session</strong> Windows puis la <strong>rouvrir</strong>. Verrouiller
        et déverrouiller l\'écran ne suffit pas ; redémarrer le poste convient aussi.</li>
        <li>Dans la console (<em>Active Directory</em> → « Partages &amp; lecteurs ») : le <strong>chemin
        réseau</strong> doit désigner le <strong>nom du serveur</strong>, par exemple
        <code>\\\\dc.bastion.pn.int\\Commun</code>.</li>
        <li>Colonne « <strong>Pour qui ?</strong> » : si le lecteur est réservé à un groupe, l\'agent doit être
        membre de ce groupe — et avoir rouvert sa session depuis son ajout.</li>
        <li>Le dossier partagé visé doit accorder un accès à ce groupe : colonne « Droits d\'accès ».</li>
        <li>Après toute modification : « <strong>🚀 Déployer sur les postes</strong> », puis réouverture de session
        par l\'agent.</li>
      </ul>
      <p class="tip">Un compte <strong>administrateur du domaine</strong> ne voit pas les lecteurs montés : faites
      le test avec un compte d\'agent ordinaire. Déployez « Attendre le réseau à l\'ouverture de session » pour
      fiabiliser la toute première connexion d\'un poste.</p>'],

    ['dep-partage-droits', '🔐', 'Le dossier partagé s\'ouvre, mais impossible d\'écrire', '
      <p><strong>Symptôme :</strong> le dossier s\'ouvre mais l\'enregistrement est refusé — ou bien le fichier
      déposé par un agent n\'est pas modifiable par ses collègues.</p>
      <ul>
        <li><strong>Droits du partage</strong> : <em>Active Directory</em> → « Partages &amp; lecteurs » → colonne
        « <strong>Droits d\'accès</strong> » → « <strong>Modifier les droits</strong> ». Choisir « Tous les agents
        du domaine » <em>en lecture-écriture</em>, ou « Seulement les groupes désignés » puis, pour chaque groupe :
        <em>Aucun accès</em>, <em>Lecture seule</em> ou <em>Lecture-écriture</em>.</li>
        <li><strong>Permissions du dossier</strong> : si l\'accès reste refusé, ou si les collègues ne peuvent pas
        modifier un fichier déjà déposé, cliquer « <strong>🔧 Réparer l\'accès aux dossiers partagés</strong> ».
        L\'opération réapplique les permissions de base aux dossiers et à leur contenu ; elle ne touche pas aux
        droits par groupe et ne supprime aucun fichier.</li>
        <li><strong>Quota atteint</strong> : si la jauge de la colonne « Quota » est pleine, la lecture fonctionne
        mais l\'écriture est refusée. Relever la valeur en Mo (0 = illimité) ou faire faire le ménage.</li>
        <li><strong>« Élément introuvable »</strong> à l\'ouverture : le chemin utilise le nom de domaine.
        Utiliser <code>\\\\dc.bastion.pn.int\\Commun</code> (nom du serveur).</li>
      </ul>
      <p class="tip">Un agent que vous venez d\'ajouter à un groupe travaille encore avec ses anciens droits : ses
      groupes sont figés à l\'ouverture de session. Il doit se déconnecter puis se reconnecter à Windows.</p>'],

    ['dep-scripts-demarrage', '⚙️', 'Rien ne s\'applique sur les postes ?', '
      <p>Une seule règle explique la majorité des cas :</p>
      <ul>
        <li><strong>Applications, activation Windows, chiffrement, recalage de l\'heure</strong> = scripts de
        <strong>démarrage</strong> → il faut <strong>redémarrer le poste</strong>. Un <code>gpupdate</code> ne les
        lance jamais, fermer et rouvrir la session non plus.</li>
        <li><strong>Fond d\'écran, lecteurs réseau</strong> = <strong>ouverture de session</strong> → l\'agent ferme
        sa session et la rouvre.</li>
      </ul>
      <p><strong>Geste à faire :</strong> redémarrer le poste et laisser le démarrage aller à son terme —
      l\'installation est silencieuse et peut durer plusieurs minutes. Pour ne pas attendre, lancer
      <code>Install-BastionApps.cmd</code> en administrateur sur le poste.</p>
      <p class="tip">Si le problème revient à chaque démarrage, la cause est l\'heure du poste : voir la rubrique
      suivante. Relancer un outil une seconde fois est sans risque : une application déjà installée est
      ignorée.</p>'],

    ['dep-gptini', '🕒', '« Windows a tenté en vain de lire gpt.ini »', '
      <p>Ce message — comme « la stratégie de l\'ordinateur n\'a pas pu être mise à jour » — signifie que le poste
      <strong>n\'a pas pu lire les stratégies</strong> sur le contrôleur de domaine. Deux causes, à traiter dans cet
      ordre :</p>
      <ul>
        <li><strong>L\'heure du poste est fausse.</strong> Au-delà de 5 minutes d\'écart avec le serveur,
        l\'authentification est refusée et plus rien ne s\'applique : ni stratégies, ni applications, ni lecteurs
        réseau. C\'est le cas classique des postes en <strong>machine virtuelle</strong>, dont l\'horloge se décale
        au démarrage. Geste : passer <code>Install-BastionTimeGuard.cmd</code> sur le poste, en administrateur, puis
        redémarrer. Sur une machine virtuelle, vérifier aussi l\'horloge de l\'ordinateur hôte.</li>
        <li><strong>Les permissions des stratégies sont désynchronisées</strong> côté serveur (fréquent après la
        création d\'une stratégie). Geste : <em>Active Directory</em> → onglet « Stratégies (GPO) » →
        « <strong>🩺 Diagnostic des stratégies</strong> », puis « <strong>🔧 Réparer les permissions SYSVOL</strong> »,
        et redémarrer le poste.</li>
      </ul>
      <p class="tip">Le recalage de l\'heure par stratégie est lui-même un script de démarrage : sur un poste dont
      l\'horloge est déjà trop décalée, il ne peut pas s\'exécuter — c\'est un cercle vicieux.
      <code>Install-BastionTimeGuard.cmd</code>, installé localement et indépendant du domaine, est là pour le
      rompre.</p>'],

    ['outils-poste', '🧰', 'Outils à lancer sur un poste', '
      <p>Trois outils sont mis à disposition dans le dossier partagé <strong>« Commun »</strong>
      (<code>\\\\dc.bastion.pn.int\\Commun</code>). Ils se lancent <strong>en tant qu\'administrateur</strong>
      sur le poste concerné, et peuvent être relancés sans risque.</p>
      <ul>
        <li><code>Install-BastionTimeGuard.cmd</code> — <strong>corrige l\'heure du poste</strong> et la maintient
        à chaque démarrage. À passer sur tout poste dont l\'horloge se décale (machines virtuelles).</li>
        <li><code>Install-BastionApps.cmd</code> — <strong>installe tout de suite</strong> les applications du
        catalogue, sans attendre un redémarrage.</li>
        <li><code>Bastion-Diag.ps1</code> — <strong>rapport de diagnostic</strong> (à ouvrir dans Windows
        PowerShell en administrateur). Il ne modifie rien et produit <code>C:\\bastion-diag.txt</code> ainsi que
        <code>C:\\bastion-gpresult.html</code> : joignez ces deux fichiers à une demande d\'assistance.</li>
      </ul>
      <p class="tip">Ces outils sont utiles quand un poste résiste alors que la console, elle, est correctement
      configurée : ils agissent localement, sans dépendre du domaine.</p>'],
  ],
];

pf_header('Aide', 'aide.php');
?>
<style>
  .aide-hero{background:linear-gradient(130deg,#152238,#1e3a5f);border:1px solid var(--line);border-radius:16px;
    padding:1.5rem 1.7rem;margin-bottom:1.4rem;display:flex;align-items:center;gap:1.2rem;flex-wrap:wrap}
  .aide-hero img{width:52px;height:52px;flex:none}
  .aide-hero h1{margin:0;font-size:1.5rem;color:#fff}
  .aide-hero p{margin:.25rem 0 0;color:#9fb3d1;font-size:.9rem;max-width:60ch}
  .aide-hero .chip{margin-left:auto;display:flex;gap:.5rem;flex-wrap:wrap}
  .aide-hero .ver{background:rgba(255,255,255,.1);color:#cbd5e1;border-radius:20px;padding:.3rem .8rem;font-size:.78rem;font-family:ui-monospace,monospace}
  .aide{display:grid;grid-template-columns:230px 1fr;gap:1.5rem;align-items:start}
  .aide .toc{position:sticky;top:1rem;background:var(--card,#1e293b);border:1px solid var(--line);border-radius:12px;padding:.8rem;max-height:calc(100vh - 2rem);overflow:auto;scrollbar-width:thin}
  .aide .toc .grp{font-size:.64rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);opacity:.6;font-weight:700;padding:.7rem .5rem .2rem}
  .aide .toc .grp:first-child{padding-top:.2rem}
  .aide .toc a{display:block;padding:.3rem .5rem;border-radius:8px;color:var(--muted);text-decoration:none;font-size:.84rem}
  .aide .toc a:hover{background:var(--bg);color:var(--text)}
  .aide .doc .grp-title{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700;margin:1.2rem .3rem .5rem}
  .aide .doc .grp-title:first-child{margin-top:0}
  .aide .doc h3{display:flex;align-items:center;gap:.5rem;margin:0 0 .6rem;font-size:1.1rem}
  .aide .doc section{background:var(--card,#1e293b);border:1px solid var(--line);border-radius:14px;padding:1.2rem 1.4rem;margin-bottom:1rem;scroll-margin-top:1rem}
  .aide .doc p,.aide .doc li{color:var(--muted);line-height:1.7}
  .aide .doc strong{color:var(--text)}
  .aide .doc code{background:var(--bg);padding:.1rem .35rem;border-radius:5px;font-size:.85em}
  .aide .doc .tip{background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.3);color:#bae6fd;padding:.6rem .8rem;border-radius:10px;margin:.6rem 0 0}
  .aide input.search{width:100%;padding:.6rem .7rem;margin-bottom:.7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px;font-size:.9rem}
  .aide-foot{color:var(--muted);font-size:.82rem;margin-top:1.4rem;border-top:1px solid var(--line);padding-top:.9rem}
  @media(max-width:820px){.aide{grid-template-columns:1fr}.aide .toc{position:static;max-height:none}}
  @media print{.sidebar,.topbar,.aide .toc,.aide-hero .chip,.nav-backdrop{display:none!important}
    .content{margin:0!important}.aide{grid-template-columns:1fr}body{background:#fff!important}
    .aide .doc section{break-inside:avoid;border-color:#ccc}}
</style>
<div class="aide-hero">
  <img src="/assets/bastion-icon.svg" alt="">
  <div>
    <h1>Aide &amp; documentation</h1>
    <p>Guide d'utilisation de la console d'administration Bastion. Cliquez une rubrique dans le sommaire, ou
    recherchez un mot-clé.</p>
  </div>
  <div class="chip">
    <?php if ($ver !== ''): ?><span class="ver">version <?= e($ver) ?></span><?php endif; ?>
    <button type="button" class="btn-sm" onclick="window.print()">🖨️ Imprimer</button>
  </div>
</div>
<div class="aide">
  <nav class="toc">
    <input class="search" type="search" placeholder="Rechercher dans l'aide…" oninput="aideFilter(this.value)">
    <?php foreach ($GROUPS as $grpName => $items): ?>
      <div class="grp"><?= $grpName ?></div>
      <?php foreach ($items as [$id, $ic, $t]): ?>
        <a href="#<?= $id ?>"><?= $ic ?> <?= $t ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="doc">
    <?php foreach ($GROUPS as $grpName => $items): ?>
      <div class="grp-title" data-grp="1"><?= $grpName ?></div>
      <?php foreach ($items as [$id, $ic, $t, $body]): ?>
        <section id="<?= $id ?>" data-t="<?= e(strtolower(strip_tags($t))) ?>">
          <h3><span><?= $ic ?></span> <?= $t ?></h3>
          <?= $body ?>
        </section>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <div class="aide-foot">Bastion — contrôleur d'accès au réseau. Développé par <strong>Mickaël MONESTIER</strong>
    (Mle 110.480). Voir aussi l'onglet « En savoir + ».</div>
  </div>
</div>
<script>
function aideFilter(q){
  q=(q||"").toLowerCase().trim();
  var groups={};
  document.querySelectorAll(".aide .doc section").forEach(function(s){
    var hit = !q || s.getAttribute("data-t").indexOf(q)>=0 || s.textContent.toLowerCase().indexOf(q)>=0;
    s.style.display = hit ? "" : "none";
  });
  // Masquer un titre de groupe si toutes ses sections sont cachées.
  document.querySelectorAll(".aide .doc .grp-title").forEach(function(g){
    var any=false, n=g.nextElementSibling;
    while(n && n.tagName==="SECTION"){ if(n.style.display!==""){}else{any=true;} n=n.nextElementSibling; }
    g.style.display = any ? "" : "none";
  });
}
</script>
<?php pf_footer(); ?>
