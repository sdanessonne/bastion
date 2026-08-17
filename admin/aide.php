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
        <li>Le <strong>menu</strong> s\'ouvre par le bouton <strong>☰</strong>, en haut à gauche — sur grand écran
        comme sur téléphone. Il se retire ensuite pour rendre toute la largeur au contenu, ce qui compte sur les
        pages denses : inventaire du parc, journaux, annuaire. Un clic à côté, la touche <kbd>Échap</kbd>, ou le
        choix d\'une page le referment. Les fonctions y sont groupées par domaine (Surveiller, Accès &amp; droits,
        Protection, Postes &amp; réseau, Intranet, Aide). Les changements sont <strong>immédiats</strong>.</li>
        <li>La <strong>barre du haut</strong> reste visible quand vous faites défiler une longue page —
        journal, annuaire, inventaire — pour ne pas avoir à remonter afin de changer de page. Elle porte, de
        gauche à droite : le bouton de menu, le titre de la page, la <strong>recherche</strong>, un
        <strong>voyant d\'état</strong> et votre compte. Sur écran étroit, ces éléments s\'effacent dans cet
        ordre : le titre d\'abord (le menu indique déjà où l\'on est), la recherche en dernier.</li>
        <li>La <strong>recherche</strong> (<kbd>Ctrl</kbd>+<kbd>K</kbd>) propose ses résultats dans une liste
        sous le champ. Elle connaît des <strong>synonymes</strong> : « inventaire » trouve <em>Parc
        informatique</em>, que personne n\'appelle ainsi de tête. Les flèches parcourent la liste,
        <kbd>Entrée</kbd> ouvre la ligne surlignée. La dernière ligne, <em>Chercher partout</em>, mène à la
        recherche globale — agents, postes, adresses IP ou MAC — et elle est <strong>toujours</strong>
        proposée, même quand des pages correspondent.</li>
        <li>Le <strong>voyant d\'état</strong>, à droite de la recherche, affiche « Système opérationnel » ou le
        nombre d\'anomalies. Il partage sa source avec les pastilles du menu : les deux ne peuvent pas se
        contredire. Un clic mène à <em>Santé &amp; sécurité</em>, qui en donne le détail.</li>
        <li>Menu du haut à droite : votre profil &amp; sécurité (photo, mot de passe, double authentification),
        redémarrer / arrêter le serveur, se déconnecter. Votre <strong>rôle</strong> y est écrit sous votre nom —
        « lecture seule » explique d\'avance pourquoi un bouton refusera d\'enregistrer, au lieu de le laisser
        découvrir au clic.</li>
        <li>En bas du menu : le même <strong>voyant d\'état</strong>, et la <strong>version</strong> de Bastion.
        La question « quelle version tourne ici ? » se pose à chaque appel d\'assistance, et la réponse était
        jusqu\'ici enfouie dans « À propos ».</li>
      </ul>
      <p class="tip">Une <strong>mise à jour tout-en-un</strong> (système Debian + application Bastion) est disponible
      dans <em>Système</em> : un seul bouton vérifie et installe les deux.</p>'],
    ['materiel', '🧰', 'Prérequis matériels', '      <p>Bastion tient sur une machine modeste, mais deux ressources décident du résultat : la <strong>mémoire</strong>
      et surtout le <strong>disque</strong>. Les valeurs ci-dessous ne sont pas des estimations de brochure : elles
      sont mesurées sur une passerelle en service.</p>

      <table style="max-width:720px">
        <thead><tr><th></th><th>Minimum</th><th>Recommandé</th></tr></thead>
        <tbody>
          <tr><th>Processeur</th><td>2 cœurs x86-64</td><td>4 cœurs</td></tr>
          <tr><th>Mémoire</th><td>4 Go</td><td>8 Go</td></tr>
          <tr><th>Disque</th><td>120 Go</td><td>250 Go (SSD)</td></tr>
          <tr><th>Réseau</th><td colspan="2">Deux interfaces filaires : une vers la box, une vers le parc</td></tr>
        </tbody>
      </table>

      <p><strong>Ce qui consomme la mémoire</strong>, relevé sur une passerelle de production équipée de 4 Go :</p>
      <ul>
        <li><strong>Antivirus (ClamAV) : ~1,1 Go</strong> à lui seul — il charge toute sa base de signatures en
        mémoire. C\'est de loin le premier poste. Sur une machine à 4 Go, ne l\'activez qu\'en connaissance de
        cause ; sur 8 Go il ne pose aucun problème.</li>
        <li>Serveur web (console + portail) : ~800 Mo.</li>
        <li>Contrôleur de domaine : ~320 Mo. Base de données : ~170 Mo. DNS/DHCP : ~70 Mo.</li>
      </ul>
      <p>Au repos, sans image d\'installation en cours, l\'ensemble occupe environ <strong>2 Go</strong>. Un
      processeur d\'entrée de gamme suffit : une charge moyenne de 1,5 sur 4 cœurs a été relevée en fonctionnement
      normal. Le processeur n\'est pas le facteur limitant.</p>

      <p><strong>Le disque est le vrai piège.</strong> Il se remplit lentement, par trois côtés à la fois, et rien
      ne s\'en alarme avant la panne :</p>
      <ul>
        <li>une <strong>image d\'installation Windows</strong> pèse à elle seule <strong>7 à 8 Go</strong> ;</li>
        <li>les <strong>installeurs du store</strong> s\'accumulent — plusieurs centaines de mégaoctets dès une
        dizaine de logiciels, et rien ne les supprime tout seuls ;</li>
        <li>les <strong>journaux</strong> grossissent tous les jours, d\'autant plus que le parc est actif.</li>
      </ul>
      <p class="tip">Un disque plein arrête la base de données, et donc l\'authentification de <strong>tout le
      monde</strong>, d\'un seul coup. C\'est la panne la plus fréquente de ce type d\'installation, et la plus
      brutale. Surveillez l\'espace libre sur la page <em>Santé</em>, et vérifiez que les alertes par courriel
      partent réellement — c\'est le seul canal qui fonctionne encore quand la console, elle, ne répond plus.</p>

      <p><strong>Réseau.</strong> Il faut deux liens : un vers la box de l\'opérateur, un vers le switch du parc.
      Le Wi-Fi est facultatif ; si vous en voulez un, la clé USB doit supporter le <strong>mode point d\'accès</strong>
      — toutes ne le font pas, et celles qui ne le font pas ne le disent pas : elles s\'installent normalement et
      refusent seulement de créer un réseau.</p>

      <p class="tip">Le <strong>chiffrement du disque</strong> n\'est pas un prérequis technique mais une exigence
      de fond : la passerelle contient les comptes, les journaux de navigation et l\'annuaire. Il se décide à
      <em>l\'installation</em> et ne peut pas être ajouté après coup sans tout réinstaller. Une installation faite
      à la main, hors de l\'image fournie, laisse le disque en clair par défaut.</p>'],
  ],

  'Questions fréquentes' => [
    ['faq-postes', '🖥️', 'FAQ — Les postes', '      <p><strong>Un logiciel activé dans le store n\'arrive pas sur les postes.</strong> Trois causes, dans l\'ordre de
      fréquence. Le bouton <strong>« Appliquer sur les postes »</strong> n\'a pas été pressé — activer un logiciel ne
      déploie rien tant que la stratégie n\'est pas réécrite. Le poste n\'a pas redémarré ni rouvert de session depuis.
      Ou les <strong>arguments d\'installation silencieuse</strong> sont faux, et l\'installeur attend une réponse dans
      une fenêtre que personne ne voit. Vérifiez le journal d\'installation sur le poste :
      <code>C:\\ProgramData\\Bastion\\</code>.</p>
      <p><strong>Comment savoir où en est une installation ?</strong> Le fichier
      <code>C:\\ProgramData\\Bastion\\apps-progress.json</code> sur le poste indique le logiciel en cours, l\'étape et
      l\'horodatage. C\'est ce fichier que lit la fenêtre de progression affichée à l\'agent.</p>
      <p><strong>La fenêtre de progression ne s\'affiche jamais.</strong> C\'est le comportement normal quand il n\'y a
      rien à installer : elle ne s\'ouvre pas pour annoncer qu\'elle n\'a rien à dire. Elle ne s\'affiche que s\'il reste
      réellement des logiciels à poser.</p>
      <p><strong>La photo de l\'agent n\'apparaît pas à l\'ouverture de session.</strong> À la <em>première</em> connexion
      sur une machine, le profil Windows n\'existe pas encore quand la tâche passe : la photo apparaît à la connexion
      suivante. Si elle manque toujours, vérifiez qu\'une photo est bien déposée dans la fiche du compte.</p>
      <p><strong>Une stratégie de groupe ne s\'applique pas.</strong> Regardez d\'abord l\'<strong>heure du poste</strong>.
      Un décalage de plus de cinq minutes fait échouer Kerberos, donc l\'authentification, donc les stratégies —
      et le message d\'erreur affiché ne parle jamais d\'heure. C\'est fréquent sur machine virtuelle, dont l\'horloge
      dérive au démarrage. Ensuite seulement : <code>gpupdate /force</code> puis <code>gpresult /r</code>.</p>
      <p><strong>Edge est toujours là après le déploiement du retrait.</strong> Le journal du poste,
      <code>C:\\Windows\\Temp\\bastion-edge.log</code>, donne la réponse à la ligne près. Trois causes, dans l\'ordre.
      <em>« ABANDON : aucun autre navigateur installé »</em> — le script a refusé d\'agir pour ne pas couper l\'accès au
      portail captif ; déployez Firefox ou Chrome par le store, puis redémarrez le poste.
      <em>« ECHEC VERIFIE »</em> avec un GeoId autre que 84 — Microsoft n\'ouvre la désinstallation d\'Edge que dans
      l\'Espace économique européen, et la région du poste est ailleurs (fréquent sur une machine réinstallée depuis une
      image étrangère). <em>« programme de désinstallation introuvable »</em> — Edge était en cours de mise à jour ; le
      script réessaiera au prochain démarrage.</p>
      <p><strong>Edge est revenu tout seul quelques jours après.</strong> Le blocage de réinstallation a été levé : soit
      la stratégie a été déliée du domaine, soit elle n\'a jamais atteint le poste. Sans ce blocage, Windows Update
      repose Edge de lui-même, et rien ne le signale.</p>
      <p><strong>Depuis le retrait d\'Edge, Office ou Teams ne démarrent plus.</strong> Regardez la dernière ligne du
      journal : si elle dit <em>« WebView2 introuvable »</em>, c\'est la cause. WebView2 est un produit distinct dont ces
      logiciels dépendent ; la stratégie l\'autorise explicitement, mais un poste dont la stratégie a été appliquée
      partiellement peut l\'avoir perdu. Réinstallez le <em>Microsoft Edge WebView2 Runtime</em> sur le poste.</p>
      <p><strong>Windows reste « non activé ».</strong> Un serveur KMS n\'active rien avant d\'avoir vu 25 postes
      distincts (5 pour Office). En deçà du seuil, rien n\'est cassé : le compteur monte à chaque nouvelle machine.
      L\'état réel de chaque poste est dans « Inventaire des postes ».</p>
      <p><strong>Un poste n\'apparaît pas dans la liste des postes joignables.</strong> Il doit d\'abord s\'être
      <em>inventorié</em> — la console refuse d\'enregistrer un identifiant pour une machine qu\'elle ne connaît pas,
      sinon n\'importe quel nom inventé peuplerait la liste. Vérifiez qu\'il figure dans « Parc informatique », puis
      qu\'il a redémarré depuis le déploiement de la stratégie.</p>
      <p><strong>Le poste est dans la liste mais sans identifiant.</strong> Le client est installé mais ne s\'est pas
      encore annoncé. Le journal <code>C:\\ProgramData\\Bastion\\distance.log</code> sur le poste dit à quelle étape
      ça s\'est arrêté : installation, configuration, redémarrage du service ou remontée de l\'identifiant.</p>
      <p><strong>La connexion échoue alors que l\'identifiant est bon.</strong> Vérifiez les deux voyants en haut de
      la page « Prise de main à distance ». Si « Postes autorisés à joindre le relais » est au rouge, le portail
      captif bloque : relancez <code>setup-distance.sh install</code> sur la passerelle.</p>
      <p><strong>L\'arrêt et le démarrage du poste sont très longs.</strong> Regardez d\'abord le
      <strong>démarrage rapide</strong> de Windows. La stratégie du pavé numérique le désactivait, parce qu\'il
      restaure l\'état du clavier de la session précédente et faisait ignorer le réglage un démarrage sur deux.
      Le calcul était mauvais : cela imposait à chaque poste un arrêt et un démarrage complets, tous les jours,
      pour un confort sur l\'écran de connexion. Il est rétabli. Vérifiez sur un poste que
      <code>HKLM\\SYSTEM\\CurrentControlSet\\Control\\Session Manager\\Power\\HiberbootEnabled</code> vaut
      <strong>1</strong> ; s\'il vaut encore 0, la stratégie n\'a pas été rejouée depuis la correction.</p>
      <p>Ensuite, comptez les <strong>scripts de démarrage</strong>. Windows les exécute l\'un après l\'autre et
      <em>attend</em> chacun avant d\'ouvrir la session. Cinq stratégies en portent un ; celle des applications
      est la plus lourde, puisqu\'elle télécharge et installe.</p>

      <p><strong>L\'ouverture de session est lente, tous les jours.</strong> Presque toujours la même cause : une
      application du store qui n\'arrive pas à s\'installer. Le poste retente celles dont le marqueur manque, donc
      un seul installeur défectueux fait recommencer tout le reste à chaque fois. Ouvrez
      <code>C:\\ProgramData\\Bastion\\apps.log</code> : la ligne <code>ABANDONNE après 3 échecs</code> nomme la
      coupable. Le plus souvent son fichier n\'est pas un installeur — une page web enregistrée comme
      <code>.exe</code> par une source qui ne publiait pas de binaire. Récupérez-la de nouveau depuis le store,
      ou désactivez-la.</p>

      <p><strong>gpupdate prend un temps interminable.</strong> Chaque stratégie liée au domaine est traitée à
      chaque passage. Au-delà d\'une vingtaine, cela se sent, et les stratégies portant un script pèsent bien plus
      que les autres. Avant de chercher un problème réseau, comptez-les : c\'est souvent une accumulation, pas une
      panne.</p>'],
    ['faq-acces', '🔑', 'FAQ — Comptes et accès', '      <p><strong>Un agent ne peut pas se connecter alors que son mot de passe est bon.</strong> Vérifiez dans l\'ordre :
      le compte n\'a-t-il pas une <strong>date de fin d\'accès</strong> dépassée (il est alors désactivé, pas supprimé) ;
      le service d\'authentification est-il actif (page Services) ; le poste est-il bien sur le réseau servi par la
      passerelle.</p>
      <p><strong>Un agent doit changer son mot de passe.</strong> Il le fait lui-même depuis le portail, sans passer par
      un administrateur. C\'est volontaire : personne ne devrait avoir à confier son mot de passe à quelqu\'un.</p>
      <p><strong>Donner un accès à un intervenant extérieur.</strong> Un <strong>bon visiteur</strong> — identifiant et
      mot de passe temporaires, sans création de compte. Il expire seul. Il n\'ouvre <em>aucun</em> accès au domaine ni
      aux dossiers partagés : uniquement Internet, filtré et journalisé comme pour tout le monde.</p>
      <p><strong>J\'ai modifié un quota, l\'agent a toujours l\'ancien.</strong> Les quotas et les droits de groupe
      s\'appliquent à la <strong>prochaine session</strong>. Fermez sa session en cours depuis la page des sessions pour
      ne pas attendre.</p>
      <p><strong>Quelle différence entre le compte d\'accès et le compte de domaine ?</strong> Le premier ouvre
      Internet par le portail ; le second ouvre la session Windows et les dossiers partagés. Un agent a généralement
      les deux, gérés depuis la même fiche — mais ils peuvent exister l\'un sans l\'autre.</p>
      <p><strong>Un agent a retrouvé un bureau vide et ses documents envolés après une modification de sa fiche.</strong>
      Son <strong>compte de domaine</strong> a été décoché puis recréé. Décocher ne suspend pas le compte : il est
      <em>effacé</em> de l\'annuaire, et le compte recréé porte un identifiant de sécurité (SID) neuf. Windows n\'y
      reconnaît pas le même utilisateur et lui ouvre un profil neuf ; l\'ancien profil est toujours sur le disque du
      poste, sous <code>C:\\Utilisateurs\\</code> avec un suffixe, mais il faut y recopier les documents à la main.
      La console demande maintenant une confirmation explicite avant d\'en arriver là. Pour une absence temporaire,
      utilisez la <strong>date de fin d\'accès</strong>, qui désactive sans détruire.</p>
      <p><strong>Une suppression groupée a emporté plus de comptes que ceux affichés.</strong> Les lignes masquées par
      un filtre restaient sélectionnées : on filtrait un commissariat, on cochait « tout sélectionner », et la
      sélection contenait encore les comptes d\'un filtre précédent — que rien ne montrait, ni avant ni après.
      Désormais une ligne qui sort de l\'écran sort de la sélection, et la confirmation énumère les matricules.
      Pour retrouver ce qui a été supprimé, le <strong>journal d\'audit</strong> conserve chaque action groupée avec
      son nombre de comptes ; les comptes eux-mêmes se restaurent depuis une <strong>sauvegarde</strong>.</p>
      <p><strong>« Identifiant invalide » sur un ancien compte que je n\'ai pas renommé.</strong> Corrigé : le format
      matricule ne s\'impose qu\'à la <em>création</em>. Les comptes antérieurs à cette règle (<code>dupont.jean</code>)
      étaient devenus impossibles à modifier — ni date de fin, ni commissariat, ni photo — alors que le champ
      identifiant est en lecture seule en édition : le message désignait une valeur que personne ne pouvait corriger.</p>
      <p><strong>« 12 mots de passe réinitialisés », mais des agents n\'ont pas le nouveau.</strong> Le comptage
      incluait les comptes qui n\'ont <em>ni</em> accès Internet <em>ni</em> compte de domaine : il n\'y avait rien à
      changer chez eux. Le bandeau distingue maintenant les comptes traités des comptes écartés, avec le motif.</p>
      <p><strong>Un administrateur a fait une modification inexpliquée.</strong> Le <strong>journal d\'audit</strong>
      dit qui, quand, depuis quelle adresse et quoi. Commencez toujours par là : la cause est presque toujours une
      action humaine récente, pas une panne. Les <em>actions groupées</em> y figurent aussi désormais — elles n\'y
      laissaient aucune trace, et une suppression de cinquante comptes passait donc inaperçue.</p>
      <p><strong>« Retirer du domaine » échoue avec « <code>non-leaf node … it has N children</code> ».</strong>
      C\'est un poste chiffré par <strong>BitLocker</strong> : ses clés de récupération sont séquestrées dans
      l\'annuaire, rattachées sous l\'objet du poste, et l\'ancien code refusait de supprimer un objet qui a des
      enfants. La suppression fonctionnait donc sur les postes non chiffrés et échouait sur les autres, avec ce
      message obscur. Corrigé : le poste et ses clés sont retirés ensemble, et la confirmation prévient combien de
      clés seront détruites. Si la machine reste chiffrée quelque part, notez la clé avant de retirer le poste :
      une fois l\'objet supprimé, la clé est perdue.</p>'],
    ['faq-reseau', '🌐', 'FAQ — Réseau, filtrage et lenteurs', '      <p><strong>J\'ai bloqué un site, il reste accessible.</strong> Le poste garde les adresses DNS en mémoire quelques
      minutes. <code>ipconfig /flushdns</code> sur le poste tranche immédiatement. Si ça persiste au-delà, le site est
      probablement atteint par une autre adresse que celle bloquée.</p>
      <p><strong>Un agent peut-il contourner le filtrage en changeant son DNS ?</strong> Non. Les requêtes DNS sortantes
      sont redirigées vers la passerelle et le DNS chiffré (DoH/DoT) est bloqué. Un poste qui configure « un autre
      DNS » reste filtré, sans s\'en apercevoir.</p>
      <p><strong>Un site légitime est bloqué à tort.</strong> Ajoutez-le à la liste blanche plutôt que de désactiver
      toute la catégorie — sinon vous rouvrez bien plus large que le besoin.</p>
      <p><strong>« Internet rame ».</strong> Ouvrez le <strong>trafic en direct</strong> : vous voyez en quelques
      secondes si la ligne sature et par qui. Regardez <em>vers quels domaines</em> avant d\'intervenir : une mise à
      jour Windows sur dix postes ressemble à un abus et n\'en est pas un.</p>
      <p><strong>Une machine ne reçoit pas d\'adresse IP.</strong> Si elle n\'apparaît dans aucun bail DHCP, le problème
      est physique (câble, port, VLAN), pas dans la configuration. Attention aussi : le Wi-Fi et l\'Ethernet d\'un
      portable ont <strong>deux adresses MAC différentes</strong> — une réservation ne vaut que pour l\'une des deux.</p>
      <p><strong>Puis-je ouvrir la console d\'administration depuis un poste du service ?</strong> Non, et c\'est
      délibéré : la console n\'est pas jointe depuis le réseau des postes. Cela évite qu\'un poste compromis puisse
      atteindre l\'administration de la passerelle.</p>'],
    ['faq-journaux', '⚖️', 'FAQ — Journaux et réquisitions', '      <p><strong>Un agent n\'apparaît pas dans les journaux.</strong> Vérifiez qu\'il s\'est authentifié au portail : un
      poste non authentifié n\'a pas d\'accès, donc rien à journaliser. L\'absence de trace n\'est pas la preuve d\'une
      absence d\'activité.</p>
      <p><strong>Que contiennent exactement les journaux ?</strong> Date et heure, utilisateur, adresse IP, et le
      <strong>domaine</strong> consulté. Pas le contenu des pages, pas les identifiants saisis, pas les mots de passe.
      La journalisation répond à une obligation légale ; ce n\'est pas un outil de lecture du contenu.</p>
      <p><strong>Comment répondre à une réquisition judiciaire ?</strong> La page dédiée produit un extrait sur une
      période et un agent donnés, avec la <strong>preuve d\'intégrité</strong> associée. Les journaux sont chaînés et
      scellés par signature : une ligne modifiée après coup rompt la chaîne et devient détectable — c\'est ce qui donne
      sa valeur à l\'extrait.</p>
      <p><strong>Combien de temps garde-t-on les journaux ?</strong> La durée est paramétrable, et la purge est
      automatique au-delà. Conserver plus longtemps que la durée légale n\'est pas une précaution : c\'est une faute.</p>
      <p><strong>Peut-on effacer l\'historique d\'un agent à sa demande ?</strong> Non. Ces journaux répondent à une
      obligation de conservation ; ils ne sont pas à la disposition de la personne concernée ni de
      l\'administrateur.</p>'],
    ['faq-exploitation', '🛠️', 'FAQ — Exploitation quotidienne', '      <p><strong>Comment mettre Bastion à jour ?</strong> Page <em>Système</em> : un seul bouton vérifie et installe le
      système Debian et l\'application. Faites une <strong>sauvegarde avant</strong>, systématiquement.</p>
      <p><strong>Une fonction ne se comporte pas comme décrit ici.</strong> Vérifiez d\'abord la version installée en bas
      de cette page. Une aide en avance sur le serveur décrit des choses qui n\'y sont pas encore.</p>
      <p><strong>La console ne répond plus.</strong> Regardez si les <strong>alertes par courriel</strong> sont arrivées :
      c\'est le seul canal qui fonctionne encore quand l\'interface est tombée. Cause la plus fréquente : le
      <strong>disque plein</strong>, qui arrête la base de données et donc l\'authentification de tout le monde d\'un
      coup.</p>
      <p><strong>Faut-il redémarrer le serveur après un changement ?</strong> Presque jamais. Les modifications de la
      console sont immédiates. Si un service semble figé, redémarrez <strong>ce service seul</strong> depuis la page
      Services — tout relancer d\'un bloc fait perdre l\'information la plus utile : lequel était en cause.</p>
      <p><strong>À quoi sert la sauvegarde si tout est sur la passerelle ?</strong> Justement à ça. Une sauvegarde qui
      reste sur le disque qu\'elle protège ne sert à rien le jour où ce disque lâche : téléchargez l\'archive et
      sortez-la de la machine. Et conservez son secret ailleurs — chiffrée sans son secret, elle est illisible.</p>
      <p><strong>Comment savoir si une sauvegarde est bonne ?</strong> En la restaurant. Une sauvegarde jamais
      restaurée est une hypothèse, pas une garantie ; faites l\'essai une fois hors urgence, c\'est le seul moment où
      découvrir un problème ne coûte rien.</p>'],
  ],

  'Accès & sécurité' => [
    ['utilisateurs', '👤', 'Utilisateurs, droits &amp; rôles', '
      <p><strong>Utilisateurs &amp; droits</strong> : un seul écran pour tout le cycle de vie d\'un agent —
      <strong>accès Internet</strong> (portail), <strong>compte de domaine</strong> (AD), identité (nom, prénom,
      service), <strong>photo</strong>, <strong>commissariat</strong> d\'appartenance, et opérations en <strong>masse</strong>
      (import CSV, actions groupées).</p>

      <p class="tip"><strong>Décocher un accès, c\'est le supprimer — pas le suspendre.</strong> Décocher
      « Compte domaine » <em>efface</em> le compte de l\'annuaire. Recréé plus tard, il porte un identifiant de
      sécurité (SID) neuf : Windows lui ouvre alors un <strong>profil neuf</strong> sur les postes — bureau vide,
      documents de l\'ancien profil hors de portée de l\'agent. La console affiche désormais, à l\'endroit même où
      la case vient d\'être décochée, un <strong>bandeau rouge portant une case de confirmation</strong> ; sans
      cette case, l\'enregistrement est refusé et <em>rien</em> n\'est modifié. Pour une absence temporaire, posez
      plutôt une <strong>date de fin d\'accès</strong> : elle désactive sans détruire.</p>

      <p><strong>Le groupe du portail</strong> (quotas, horaires, sortie par tunnel) se choisit dans une
      <strong>liste déroulante</strong> : on ne peut désigner qu\'un groupe qui existe. Le champ était libre, et
      une faute de frappe rattachait l\'agent à un groupe <em>inexistant</em> — il perdait alors la politique
      attendue sans que rien ne le signale. Si un compte porte un groupe supprimé depuis, la liste l\'affiche
      suivi de « <em>groupe introuvable</em> » plutôt que de le remplacer en silence. L\'action groupée
      « Changer le groupe » propose les mêmes noms et <strong>refuse en bloc</strong> un groupe inconnu : aucun
      compte n\'est touché, plutôt que cinquante reclassés dans un groupe fantôme.</p>
      <ul>
        <li><strong>Date de fin d\'accès</strong> : programmez la désactivation d\'un compte (fin de mission, mutation).
        À l\'échéance, l\'accès Internet et le compte de domaine sont désactivés automatiquement — le compte n\'est pas
        supprimé (retirer la date le réactive).</li>
        <li><strong>Droits de gestion</strong> : administrateur de la console et/ou du domaine. Pour un administrateur
        console, un <strong>niveau d\'accès</strong> : <em>complet</em>, <em>comptes &amp; agents seulement</em>, ou
        <em>lecture seule</em> (consultation sans modification).</li>
        <li><strong>Mot de passe</strong> : pour un compte de domaine ou un administrateur de console, au moins
        8 caractères avec une majuscule et un chiffre. La fiche est <strong>refusée en entier</strong> si la règle
        n\'est pas tenue : auparavant l\'accès Internet était créé et le compte de domaine non, laissant un agent
        qui navigue mais n\'ouvre pas sa session. La stratégie du domaine peut exiger davantage.</li>
        <li><strong>Sélection et filtres</strong> : filtrer une ligne la <em>retire</em> de la sélection, et
        « tout sélectionner » ne coche que ce qui est affiché. La confirmation de suppression groupée
        <strong>énumère les matricules</strong> au lieu d\'annoncer un nombre.</li>
        <li><strong>Comptes ignorés</strong> : une action groupée annonce combien de comptes elle a écartés
        <em>et pourquoi</em> (pas de compte de domaine, ni accès Internet ni domaine, commissariat inconnu…).
        Toutes les actions groupées sont inscrites au <strong>journal d\'audit</strong>.</li>
        <li><strong>Export CSV</strong> (bouton ⬇️) : télécharge tous les comptes — identifiant, groupe, domaine,
        commissariat, identité — aux mêmes colonnes que l\'import, pour un état du parc ou un contrôle. Les
        <strong>mots de passe n\'y figurent jamais</strong> : un fichier de comptes qui promène les mots de passe en
        clair est une fuite. Ce fichier reste <em>ré-importable tel quel</em> — l\'import met à jour un compte existant
        sans mot de passe (il n\'en recrée pas les comptes disparus, faute de secret).</li>
        <li><strong>Trier et filtrer</strong> : les colonnes Identifiant, Commissariat et État se trient d\'un clic sur
        leur titre. Les filtres (recherche, commissariat, type d\'accès) <strong>survivent à un enregistrement</strong> :
        après avoir modifié un compte, on retrouve la liste là où on l\'avait laissée, pas remise à zéro.</li>
      </ul>
      <p><strong>Groupes &amp; quotas</strong> : par groupe, la durée de session, les débits, les quotas de données et
      les plages horaires.</p>
      <p class="tip">Identifiant imposé <em>à la création</em> : matricule à 7 chiffres (ex. <code>0110480</code>) ;
      administrateur <code>admin-0110480</code>. Les comptes plus anciens, créés avant cette règle, restent
      modifiables — le format ne s\'applique qu\'aux nouveaux. Le compte <code>admin</code> intégré garde toujours
      l\'accès complet. Les nouveaux quotas s\'appliquent à la <em>prochaine</em> connexion.</p>
      <p class="tip">Vous ne pouvez ni supprimer votre propre compte, ni vous retirer votre droit d\'administration :
      la console refuse. Cette perte-là ne se voit qu\'à la déconnexion suivante, quand il n\'y a plus de quoi
      revenir en arrière. Passez par un autre administrateur.</p>'],

    ['annuaire', '📇', 'Annuaire, photos &amp; badges', '      <p>L\'<strong>annuaire</strong> est le trombinoscope du service : photo, nom, prénom, service, commissariat, droits
      et présence en ligne, avec une recherche instantanée par nom ou par service. Il sert autant à mettre un visage
      sur un nom qu\'à vérifier d\'un coup d\'œil qui possède quels droits.</p>
      <ul>
        <li><strong>La photo</strong> se dépose dans la fiche du compte (« Utilisateurs &amp; droits »). Elle alimente
        trois choses à la fois : l\'annuaire, le badge, et l\'image du compte Windows sur l\'écran d\'ouverture de session.</li>
        <li><strong>Sur les postes</strong>, la photo est posée à l\'ouverture de session par une tâche dédiée. Un agent
        qui se connecte pour la <em>première</em> fois sur une machine n\'a pas encore de profil : sa photo apparaît à la
        connexion suivante, pas à celle-là.</li>
        <li><strong>Badge de service imprimable</strong> : depuis une fiche, bouton « Imprimer » — photo, identité et
        QR code. Le navigateur permet ensuite « Enregistrer au format PDF » si vous voulez le garder.</li>
      </ul>
      <p class="tip">Cadrez la photo <strong>serrée sur le visage</strong> et en format carré. Windows la recadre
      lui-même pour l\'écran d\'ouverture de session, et une photo en pied y devient un point minuscule.</p>'],

    ['filtrage', '⛔', 'Filtrage &amp; publicités', '      <p>Le filtrage s\'applique <strong>au niveau DNS</strong> : quand un poste demande l\'adresse d\'un site interdit, la
      passerelle ne la lui donne pas. Conséquence pratique : ça marche en HTTP comme en HTTPS, pour tous les
      navigateurs et toutes les applications, sans rien installer sur les postes.</p>
      <ul>
        <li><strong>Catégories</strong> : adulte, jeux d\'argent, réseaux sociaux, streaming, sites malveillants. Une
        case à cocher par catégorie, effet immédiat pour tout le monde.</li>
        <li><strong>Domaines</strong> : ajout un par un, ou import d\'une liste. Bloquer <code>exemple.fr</code> bloque
        aussi ses sous-domaines.</li>
        <li><strong>Bloqueur de publicités</strong> : liste communautaire, rafraîchie chaque semaine. Il allège les
        pages et ferme une voie d\'infection courante — les régies publicitaires servent régulièrement de vecteur.</li>
        <li><strong>Exceptions</strong> : un site légitime pris à tort dans une catégorie se débloque en l\'ajoutant à la
        liste blanche, sans désactiver toute la catégorie.</li>
      </ul>
      <p class="tip">Le filtrage est <strong>incontournable</strong> : les requêtes DNS sortantes sont redirigées vers la
      passerelle, et le DNS chiffré (DoH/DoT) est bloqué. Un poste qui configure « un autre DNS » dans ses paramètres
      reste filtré — sans cela, contourner Bastion demanderait trente secondes.</p>
      <p class="tip">Un site bloqué qui reste accessible pendant quelques minutes, c\'est le <strong>cache DNS du
      poste</strong>, pas une règle inopérante. <code>ipconfig /flushdns</code> sur le poste, ou attendez l\'expiration.</p>'],

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

    ['antivirus', '🛡️', 'Antivirus &amp; stations blanches', '      <p>Deux dispositifs distincts, souvent confondus.</p>
      <p><strong>1. Analyse des supports amovibles (« station blanche »)</strong> — une clé USB ou un disque externe
      apporté de l\'extérieur est analysé avant d\'être branché sur un poste du service. C\'est la voie d\'infection la
      plus banale d\'un réseau fermé : le support voyage là où le réseau ne va pas.</p>
      <ul>
        <li>Chaque station d\'analyse possède son propre <strong>jeton</strong>, délivré depuis cette page. Il identifie
        la station qui remonte un résultat — sans lui, n\'importe quoi pourrait déclarer une analyse.</li>
        <li>Le <strong>tableau de bord</strong> liste les analyses : date, station, support, verdict, fichiers trouvés.
        C\'est la trace à produire si un incident remonte plus tard.</li>
      </ul>
      <p><strong>2. Base de signatures</strong> — la passerelle télécharge et redistribue les mises à jour de signatures
      aux stations, qui n\'ont donc pas besoin d\'accès Internet direct.</p>
      <p class="tip">Une base de signatures périmée donne une analyse qui <strong>réussit sans rien voir</strong> — le
      pire des résultats, parce qu\'il rassure à tort. La date de dernière mise à jour est affichée sur cette page :
      c\'est elle qu\'il faut regarder, pas le nombre d\'analyses réussies.</p>'],
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
      ne convient pas pour un dossier ordinaire : Windows répond « Élément introuvable ».</p>
      <p><strong>Retirer un poste du domaine</strong> : cliquez sur le poste, puis « Retirer du domaine ». Cela
      efface son objet de l\'annuaire — à faire quand un poste part au rebut ou va être réinstallé. Retirer un poste
      <em>depuis le poste lui-même</em> (Windows) ne le supprime pas de l\'annuaire : il y resterait affiché
      indéfiniment. Le <strong>contrôleur de domaine</strong> (le serveur) ne figure pas dans cette liste : ce n\'est
      pas un poste du parc mais la passerelle elle-même, et sa version Samba se lit dans <em>Système</em>.</p>
      <p class="tip">Si le poste est <strong>chiffré par BitLocker</strong>, ses <strong>clés de récupération</strong>
      sont séquestrées dans l\'annuaire, <em>sous</em> l\'objet du poste. Les retirer du domaine les efface aussi, et
      elles deviennent irrécupérables : la confirmation le rappelle et indique combien il y en a. Notez-les avant si
      la machine reste chiffrée et pourrait un jour réclamer sa clé. Auparavant, la suppression d\'un tel poste
      <strong>échouait</strong> avec un message « <code>non-leaf node</code> » incompréhensible — corrigé : le poste
      et ses clés partent ensemble.</p>'],

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
        (un aperçu s\'affiche dans la console). L\'image est <strong>recopiée sur le disque du poste</strong> au
        démarrage, et la stratégie pointe sur cette copie locale.
        <br><span class="muted">Auparavant elle était lue depuis le réseau à chaque ouverture de session : si le
        partage n\'était pas joignable à cet instant — réseau pas encore monté, Wi-Fi lent, passerelle qui
        redémarre, portable sorti du commissariat — le bureau restait <strong>noir, sans message</strong>. La copie
        n\'est refaite que si l\'image a changé, et la précédente est conservée tant que la nouvelle n\'est pas
        complète. Journal du poste : <code>C:\\ProgramData\\Bastion\\wallpaper.log</code>.</span></li>
        <li><strong>Lecteurs réseau</strong> : connectez automatiquement des dossiers partagés
        (ex. <code>Z: → \\\\dc.bastion.pn.int\\Commun</code>) — voir la rubrique « Lecteurs réseau ».</li>
        <li>Chaque GPO déployée peut être <strong>désactivée</strong> (déliée du domaine, réversible) ou
        <strong>désinstallée</strong>. Son état réel (active / désactivée) est indiqué.</li>
        <li><strong>Le déploiement ET la désinstallation affichent une jauge de progression.</strong> La suppression
        d\'une stratégie efface un objet dans l\'annuaire <em>et</em> son dossier dans le SYSVOL : cela prend quelques
        secondes, pendant lesquelles le bouton semblait sans effet — on recliquait. La barre suit maintenant le travail
        réel et, surtout, <strong>affiche l\'échec</strong> (stratégie protégée, erreur du domaine) au lieu de laisser
        croire à une suppression qui n\'a pas eu lieu.</li>
      </ul>
      <p class="tip">Sur le poste : <code>gpupdate /force</code> puis redémarrage / réouverture de session. Déployez
      « <strong>Attendre le réseau à l\'ouverture de session</strong> » pour que fond d\'écran et lecteurs apparaissent
      dès la 1<sup>re</sup> connexion. L\'heure du poste doit être synchronisée (GPO « Synchronisation de l\'heure »),
      sinon Kerberos et donc les GPO échouent.</p>'],

    ['edge', '🧹', 'Retirer Microsoft Edge des postes', '
      <p>Bouton <strong>« Retirer Microsoft Edge »</strong>, onglet <em>Active Directory</em>. Il déploie une stratégie
      de démarrage qui <strong>désinstalle Edge</strong> sur chaque poste du domaine, retire ses raccourcis, et
      <strong>bloque sa réinstallation</strong>.</p>

      <p><strong>Pourquoi ce n\'est pas une simple case du catalogue.</strong> Aucune clé de registre ne désinstalle un
      logiciel. Le catalogue sait très bien <em>brider</em> Edge — mot de passe, InPrivate, synchronisation — mais le
      retirer suppose d\'exécuter son propre programme de désinstallation sur le poste, sous l\'identité SYSTÈME. C\'est
      un script de démarrage, et rien d\'autre.</p>

      <p><strong>Déployez un autre navigateur AVANT.</strong> Ce n\'est pas un conseil de confort : sans navigateur, le
      poste ne peut plus ouvrir le portail captif, donc ne s\'authentifie plus, donc perd <em>tout</em> accès réseau.
      Le script vérifie la présence de Firefox ou de Chrome et <strong>abandonne en le disant</strong> si aucun n\'est
      installé — mais le parc se retrouve alors dans un état mixte, difficile à lire. Passez par le store, activez
      Firefox ou Chrome, pressez « Appliquer sur les postes », <em>puis</em> déployez ce retrait.</p>

      <p><strong>Le blocage de réinstallation n\'est pas optionnel.</strong> Sans lui, Windows Update repose Edge en
      quelques jours et le parc revient à son état initial — la stratégie restant affichée comme « déployée ».
      C\'est exactement le genre de panne qui ne s\'annonce pas : tout indique que la fonction marche, et le terrain dit
      le contraire.</p>

      <p><strong>WebView2 est préservé</strong>, et explicitement autorisé dans la règle de blocage. C\'est un produit
      distinct, qui partage le moteur d\'Edge, et dont dépendent Office, Teams et une partie des logiciels du store.
      Sans cette exception nommée, la règle générale « ne rien installer » l\'emporterait et casserait ces applications
      des mois plus tard, sans lien visible avec cette stratégie.</p>

      <p><strong>Ce qui se défait, et ce qui ne se défait pas.</strong> Délier la stratégie lève le blocage : Edge
      revient alors de lui-même sur les postes. La désinstallation, en revanche, ne se rejoue pas à l\'envers — les
      postes déjà traités devront être réapprovisionnés à la main si vous changez d\'avis.</p>

      <p class="tip">Le résultat se lit sur le poste dans <code>C:\\Windows\\Temp\\bastion-edge.log</code>. Il se termine
      par <code>RETIRE ET VERIFIE</code>, ou par la raison exacte de l\'échec. Le script ne se fie pas au code de sortie
      du programme de Microsoft, qui rend la main sur un « succès » dans des cas où il n\'a rien retiré : il vérifie la
      disparition de <code>msedge.exe</code>.</p>'],

    ['apps', '🏪', 'Store d\'applications', '      <p>Le <strong>store d\'applications</strong> installe des logiciels sur les postes du domaine, en silence, sans
      passer sur chaque machine. L\'installeur est hébergé sur la passerelle ; une stratégie de démarrage
      (« Bastion — Applications ») le télécharge et l\'exécute au démarrage du poste et à chaque ouverture de session.</p>
      <p><strong>Mettre un logiciel à disposition</strong></p>
      <ul>
        <li><strong>Depuis le catalogue</strong> (90 logiciels courants) : cliquez sur « Récupérer ». La passerelle
        demande la <em>version du jour</em> à l\'éditeur — le catalogue ne fige aucun numéro de version, sinon les
        liens tomberaient en panne dès la publication suivante.</li>
        <li><strong>Un logiciel absent du catalogue</strong> : « Ajouter une application », déposez le <code>.msi</code>
        ou le <code>.exe</code>, et donnez les <strong>arguments d\'installation silencieuse</strong>. C\'est le point le
        plus délicat : un MSI veut généralement <code>/qn</code>, un exécutable <code>/S</code>, <code>/silent</code> ou
        <code>/VERYSILENT</code> selon son fabricant d\'installeur. Un mauvais argument fait ouvrir une fenêtre que
        personne ne verra jamais, et l\'installation reste bloquée là, indéfiniment.</li>
        <li><strong>Activez</strong> le logiciel, puis <strong>« Appliquer sur les postes »</strong>. Rien ne part tant
        que ce bouton n\'a pas été pressé : c\'est lui qui réécrit la stratégie.</li>
        <li><strong>Désactiver</strong> un logiciel ne le retire pas des postes où il est déjà posé — il le
        <strong>désinstalle</strong> à la prochaine application de la stratégie.</li>
      </ul>
      <p><strong>Quand un logiciel ajouté arrive-t-il sur les postes ?</strong> Au <strong>démarrage</strong> du
      poste, et désormais aussi à la <strong>prochaine ouverture de session</strong> — mais seulement si la liste
      déployée a changé depuis le dernier passage. La stratégie porte une empreinte de ce qui est déployé ; le
      poste retient celle qu\'il a appliquée, et compare. Si rien n\'a bougé, il ressort en quelques millisecondes
      sans rien télécharger.<br>
      <span class="muted">Ce détour n\'est pas de la coquetterie : un rejeu <em>inconditionnel</em> avait été
      essayé, puis retiré le 6 août 2026 parce qu\'il rendait les sessions très lentes — une seule application en
      échec faisait retélécharger toutes les autres à chaque connexion. Conséquence assumée du réglage actuel :
      une application qui échoue est retentée au prochain <em>démarrage</em>, pas à chaque session.</span></p>

      <p><strong>Ce que voit l\'agent</strong> — pendant une installation, une fenêtre s\'affiche dans sa session avec le
      nom du logiciel en cours et une barre de progression. Elle ne s\'affiche <em>que</em> s\'il y a réellement quelque
      chose à installer, et se ferme seule à la fin. Sur un poste déjà à jour : rien. C\'est normal.</p>
      <p><strong>Entrées « à déposer à la main »</strong> — une quinzaine de logiciels du catalogue n\'ont pas de bouton :
      leur éditeur ne publie aucun fichier récupérable automatiquement (page de téléchargement dynamique, archive ZIP,
      paquet MSIX, lien signé valable deux heures). La raison est écrite sur la carte. Ce ne sont pas des liens à
      rafraîchir : téléchargez l\'installeur depuis le site de l\'éditeur et ajoutez-le manuellement.</p>
      <p class="tip">Si un téléchargement échoue, le message dit <strong>ce qui s\'est passé</strong> : le code HTTP,
      l\'adresse exacte, ou la nature de ce qui a été reçu. Une page web renvoyée à la place d\'un installeur est
      <strong>refusée</strong>, jamais enregistrée — sans ce contrôle, elle serait déployée sur tout le parc et
      échouerait en silence sur chaque poste.</p>'],

    ['dock', '🚀', 'Bastion Dock — barre de lancement des postes', '
      <p>Barre d\'icônes flottante sur le bureau de l\'agent, pour lancer ses applications sans les
      chercher. Elle se déploie par le <strong>store d\'applications</strong>, comme les autres logiciels :
      activez <em>Bastion Dock</em>, puis pressez <strong>« Appliquer sur les postes »</strong>.</p>

      <p><strong>Ce qu\'elle fait, et ce qu\'elle ne fait pas.</strong> C\'est un <em>lanceur</em> : elle
      affiche des icônes et démarre des programmes. Elle ne joint aucun serveur, n\'ouvre aucun port et ne
      remonte rien. Ce n\'est pas un oubli, c\'est le choix de conception : l\'inventaire du parc, la prise
      de main à distance et les demandes d\'assistance ont déjà leur mécanisme dans Bastion. Un second
      chemin ferait remonter <strong>deux inventaires qui divergeraient</strong>, et ouvrirait un
      <strong>second canal de prise de main</strong> à côté de celui dont le consentement par groupe a été
      construit exprès.</p>

      <p><strong>L\'icône « Demande d\'assistance ».</strong> Elle ouvre la page d\'assistance de
      l\'intranet dans le navigateur de l\'agent. Ce n\'est <em>pas</em> un second système de tickets :
      la demande arrive exactement au même endroit que celles déposées depuis l\'intranet, dans
      <strong>Demandes d\'assistance</strong> de la console. Un formulaire propre au dock aurait créé un
      second endroit où chercher, et des agents persuadés d\'avoir signalé une panne que personne n\'aurait
      vue.<br>
      L\'adresse vise le portail sur <code>2080</code>, qui redirige vers <code>2443</code> en HTTPS —
      les deux sont laissés passer par le portail captif <strong>avant authentification</strong>. Un poste
      qui n\'a pas encore ouvert de session réseau peut donc quand même signaler sa panne, ce qui est
      précisément le moment où il en a besoin. L\'adresse se règle dans <code>apps.json</code> si le plan
      d\'adressage du site diffère.<br>
      <span class="muted">Si la page ne s\'ouvre pas, le dock <strong>le dit</strong> et affiche l\'adresse,
      au lieu de ne rien faire : l\'agent peut la saisir à la main ou la donner au support.</span></p>

      <p><strong>Le poids compte.</strong> Le paquet fait <strong>198 Mo</strong> — il embarque son propre
      moteur d\'exécution. Chaque poste le télécharge une fois depuis la passerelle au démarrage. Sur un
      poste relié en Wi-Fi à débit faible, comptez plusieurs dizaines de minutes ; l\'installation reprendra
      au démarrage suivant si elle n\'aboutit pas.</p>

      <p><strong>Où vivent ses réglages.</strong> L\'agent peut ajouter une application, déplacer la barre
      ou changer la taille des icônes : tout est écrit dans <code>apps.json</code>, à côté de l\'exécutable,
      sous <code>C:\\ProgramData\\</code>. C\'est la raison pour laquelle l\'installation ne se fait pas dans
      <em>Program Files</em>, où un compte ordinaire ne pourrait pas écrire — la barre semblerait alors
      oublier tous ses réglages à chaque fermeture.</p>

      <p class="tip">La barre ne s\'affiche qu\'après une <strong>ouverture de session</strong> : elle
      démarre avec la session de l\'agent, pas avec la machine. Sur un poste qui vient d\'installer le
      paquet, elle apparaîtra à la connexion suivante.</p>'],

    ['kms', '🔑', 'Activation Windows / Office', '      <p><strong>Activation Windows et Office</strong> par serveur KMS hébergé sur la passerelle : les postes du domaine
      s\'activent tout seuls, sans clé à saisir sur chaque machine et sans accès aux serveurs de Microsoft.</p>
      <ul>
        <li><strong>Découverte automatique</strong> : la passerelle publie un enregistrement DNS
        <code>_vlmcs._tcp</code> dans le domaine. Un poste fraîchement joint trouve le serveur seul, sans configuration.</li>
        <li><strong>Bascule Pro → Entreprise</strong> : un poste livré en édition Professionnel peut être basculé en
        édition Entreprise par stratégie. La clé employée est une <em>GVLK</em> publique de Microsoft — ce n\'est pas
        une licence, c\'est l\'identifiant qui dit au poste de s\'adresser au KMS.</li>
        <li><strong>Vérifier</strong> : la page « Inventaire des postes » affiche l\'état d\'activation et l\'édition de
        chaque machine. C\'est là qu\'il faut regarder, pas sur le poste.</li>
      </ul>
      <p class="tip">Un KMS n\'active rien tant qu\'il n\'a pas atteint son <strong>seuil</strong> : 25 postes Windows
      distincts (5 pour Office). En deçà, les postes répondent « non activé » sans que rien ne soit cassé — le compteur
      monte à chaque nouvelle machine et l\'activation se débloque d\'elle-même. Sur un petit parc, ce seuil n\'est
      jamais atteint : il faut alors des licences MAK, saisies poste par poste.</p>'],

    ['dhcp', '🔌', 'Réservations DHCP', '      <p><strong>Réservation DHCP</strong> : donner toujours la même adresse IP à un matériel, en l\'associant à son
      adresse MAC. Utile pour une imprimante, un serveur, un poste que l\'on veut retrouver à la même place, ou une
      machine citée dans une règle de filtrage.</p>
      <ul>
        <li>La liste des <strong>baux en cours</strong> montre ce qui est connecté maintenant, avec l\'adresse MAC et le
        nom annoncé par la machine : le plus simple est de réserver depuis cette liste plutôt que de recopier une MAC à
        la main.</li>
        <li>L\'adresse réservée doit rester <strong>dans le réseau du LAN</strong>. La prendre hors de la plage
        distribuée évite qu\'elle soit attribuée à quelqu\'un d\'autre entre-temps.</li>
        <li>La réservation prend effet au <strong>renouvellement du bail</strong>, pas immédiatement : redémarrez la
        machine, ou débranchez et rebranchez son câble, pour ne pas attendre.</li>
      </ul>
      <p class="tip">Une machine qui n\'apparaît dans aucun bail n\'a pas reçu d\'adresse : le problème est en amont
      (câble, port, VLAN), pas dans la réservation. Et attention aux ordinateurs portables : leur Wi-Fi et leur
      Ethernet ont <strong>deux adresses MAC différentes</strong> — une réservation ne vaut que pour l\'une des deux.</p>'],

    ['quarantaine', '🚫', 'Quarantaine réseau', '      <p>La <strong>quarantaine</strong> coupe un poste du réseau sans se déplacer et sans le débrancher : à utiliser
      quand une machine est suspectée d\'être compromise, ou quand une analyse remonte quelque chose.</p>
      <ul>
        <li>Le poste isolé <strong>perd Internet et l\'accès aux autres machines</strong>, y compris aux dossiers
        partagés. Il garde l\'accès à la passerelle, ce qui permet de continuer à l\'inventorier et de le sortir de
        quarantaine à distance.</li>
        <li>L\'isolement est <strong>immédiat</strong> et n\'attend pas un redémarrage.</li>
        <li>La mise en quarantaine et la levée sont <strong>tracées au journal d\'audit</strong>, avec l\'administrateur
        qui les a décidées. Couper le réseau d\'un collègue est une décision qui doit rester nominative.</li>
      </ul>
      <p class="tip">Prévenez l\'utilisateur du poste. Vu de sa place, une quarantaine est indiscernable d\'une panne
      réseau : sans un mot, il appellera l\'assistance, redémarrera, changera de câble, et vous perdrez une heure à
      diagnostiquer ce que vous avez déclenché vous-même.</p>'],
    ['distance', '🖥️', 'Prise de main à distance', '      <p>Prendre la main sur l\'écran d\'un poste du domaine pour dépanner un agent, sans se déplacer.</p>

      <p><strong>Pourquoi ça passe par un relais.</strong> Les postes sont sur un réseau
      (<code>192.168.182.0/24</code>) qu\'aucune route ne relie au réseau d\'administration : c\'est cette coupure qui
      empêche un poste compromis d\'atteindre la console. Une prise de main « directe » — bureau à distance,
      assistance Windows — obligerait à percer cet isolement. Ici, le poste <em>et</em> l\'administrateur se
      connectent en <strong>sortant</strong> vers un relais hébergé sur la passerelle : rien n\'entre dans le réseau
      des postes. C\'est aussi ce qui permettra de dépanner un poste d\'un autre commissariat par le tunnel.</p>

      <p><strong>Préparer votre poste d\'administration</strong> (une seule fois)</p>
      <ul>
        <li>Installez le client <strong>RustDesk</strong> sur votre machine.</li>
        <li>Dans <em>Paramètres → Réseau</em>, renseignez le <strong>serveur</strong> et la <strong>clé</strong>
        affichés en haut de la page « Prise de main à distance » de la console. Sans la clé, le client se
        connecterait au serveur public de l\'éditeur au lieu du vôtre — et le dépannage sortirait du service.</li>
      </ul>

      <p><strong>Dépanner un poste</strong></p>
      <ul>
        <li>Le tableau de la console donne, pour chaque poste, son <strong>identifiant</strong>. C\'est le seul lien
        entre un nom de machine et le numéro à composer : sans lui il faudrait aller lire l\'écran du poste, ce que
        la prise de main est censée éviter.</li>
        <li>Saisissez cet identifiant dans votre client, connectez-vous.</li>
        <li>Renseignez le <strong>motif</strong> et cliquez « Déclarer » : l\'intervention est inscrite au journal
        d\'audit à votre nom. La prise de main a lieu dans le client, hors du navigateur — sans cette déclaration,
        la console ne saurait pas dire qui est intervenu sur quel poste.</li>
      </ul>

      <p><strong>Consentement de l\'agent</strong> — deux régimes, réglables globalement puis poste par poste.
      En <strong>accord obligatoire</strong> (le défaut), une fenêtre demande à l\'agent d\'accepter et une bannière
      reste visible pendant toute l\'intervention. En <strong>sans accord</strong>, la main se prend directement.</p>
      <p class="tip">Le second régime n\'a de sens que sur un poste <em>sans utilisateur</em> : borne, poste
      technique, salle libre-service. Sur le poste d\'un agent, prendre la main sans son accord ni information
      préalable expose le service — les constatations faites ainsi sont contestables, et la démarche doit figurer
      au registre RGPD. Le défaut reste « accord » partout où le réglage est absent, illisible ou injoignable :
      une panne ne doit jamais ouvrir un poste par accident.</p>

      <p class="tip">Deux voyants en haut de la page, et il faut les <strong>deux</strong> au vert. « Relais en
      service » dit que les processus tournent. « Postes autorisés à joindre le relais » dit que le portail captif
      les laisse passer — sans quoi les services tourneraient, les ports écouteraient, et pas un poste ne
      s\'enregistrerait jamais.</p>
      <p><strong>Le relais est annoncé sur l\'adresse publique du service.</strong> C\'est ce qui permet de dépanner
      un poste depuis l\'extérieur, et de raccorder d\'autres commissariats plus tard. L\'adresse exacte et la clé
      sont affichées en haut de la page « Prise de main à distance » — elles ne sont écrites nulle part dans le
      code, justement pour ne pas se retrouver publiées.</p>
      <ul>
        <li><strong>La box doit rediriger les ports</strong> vers la passerelle : <code>TCP 21115</code> à
        <code>21119</code> et <code>UDP 21116</code>. Sans cette redirection, le relais écoute mais personne ne
        l\'atteint du dehors — et rien sur la passerelle ne peut le signaler, puisque de son point de vue tout
        fonctionne.</li>
        <li><strong>Ce qui protège le relais, c\'est la clé</strong>, exigée de tout client. Le voyant « Clé exigée
        des clients » doit être au vert. Sans elle, un service joignable depuis Internet serait un relais ouvert :
        n\'importe qui pourrait s\'y enregistrer et s\'en servir.</li>
        <li><strong>La clé se traite comme un secret de service.</strong> Elle est publique au sens
        cryptographique, mais c\'est elle qui décide qui entre : la diffuser revient à ouvrir le relais.</li>
      </ul>
      <p class="tip">Le trafic reste chiffré de bout en bout entre les deux postes : le relais achemine sans
      pouvoir lire. Mais il voit qui parle à qui, et il est désormais exposé — c\'est un service de plus à
      surveiller sur la page Santé.</p>'],

    ['pxe', '📀', 'Serveur PXE (installation d\'OS)', '      <p>Le <strong>serveur PXE</strong> installe un système d\'exploitation par le réseau : le poste démarre sur sa
      carte réseau, un menu apparaît, et l\'installation part sans clé USB ni DVD. Pratique pour remettre à neuf une
      machine, ou en préparer plusieurs de suite.</p>
      <ul>
        <li><strong>Déposez les images</strong> (Windows 11, Ubuntu) depuis cette page ; elles apparaissent ensuite au
        menu de démarrage des postes.</li>
        <li><strong>Côté poste</strong> : activez le démarrage réseau dans le BIOS/UEFI, et prenez l\'entrée réseau au
        menu de démarrage (souvent <code>F12</code>, parfois <code>F9</code> ou <code>Échap</code> selon le fabricant).</li>
        <li>Le poste doit être sur le <strong>réseau du LAN</strong>, celui que la passerelle sert : le PXE s\'appuie sur
        le DHCP, il ne traverse pas un routeur.</li>
        <li>Une image Windows occupe <strong>plusieurs gigaoctets</strong>. Surveillez l\'espace disque de la passerelle
        avant d\'en déposer une seconde.</li>
      </ul>
      <p class="tip">Le démarrage réseau échoue le plus souvent pour trois raisons, dans cet ordre : le
      <strong>démarrage sécurisé</strong> (Secure Boot) est actif, le poste est branché sur le <strong>mauvais
      port</strong>, ou l\'image est incomplète. Le menu qui n\'apparaît pas du tout désigne les deux premières ; le menu
      qui apparaît puis échoue désigne la troisième.</p>'],
  ],

  'Flotte multi-sites' => [
    ['lien', '🔗', 'Liaison inter-sites', '
      <p>Chaque commissariat a <strong>son</strong> serveur Bastion, derrière la box de son opérateur.
      La <em>Liaison inter-sites</em> les relie pour qu\'une console unique voie tout le département.</p>
      <p><strong>Deux rôles, à déclarer sur chaque serveur :</strong></p>
      <ul>
        <li><strong>Rattaché</strong> — le cas courant. Le serveur compose un tunnel <em>sortant</em> vers son
        principal. <strong>Rien à ouvrir sur sa box</strong>, et une adresse qui change n\'y fait rien.</li>
        <li><strong>Principal du département</strong> — un seul serveur. Il écoute, porte l\'adresse
        <code>10.90.0.1</code> et connaît la clé publique de chaque site. C\'est le <strong>seul</strong> de la
        flotte à avoir besoin d\'un point de contact joignable de l\'extérieur : un port UDP redirigé sur sa box.</li>
      </ul>
      <p><strong>Mise en service, dans cet ordre :</strong></p>
      <ol>
        <li>Sur le principal : déclarer le rôle, créer la clé, choisir le port, démarrer. Relever sa clé publique.</li>
        <li>Sur chaque commissariat : déclarer le rôle « rattaché », créer la clé, relever sa clé publique.</li>
        <li>Retour sur le principal : rattacher chaque site (nom, clé publique, adresse <code>10.90.0.11</code>,
        <code>.12</code>…). La <strong>carte</strong> en haut de page montre alors qui répond et qui ne répond pas.</li>
        <li>Sur chaque commissariat : saisir la clé du principal et son point de contact, puis <em>Connecter</em>.</li>
      </ol>
      <p class="tip"><strong>Ce qui passe dans ce tunnel :</strong> uniquement le réseau de gestion
      <code>10.90.0.0/24</code>. La navigation des agents ne l\'emprunte pas et la route par défaut n\'est pas
      touchée — une panne du principal ne coupe pas Internet au commissariat.</p>
      <p><strong>Un site « jamais vu » n\'est pas un site rattaché.</strong> La carte et la table le distinguent :
      déclarer une clé ne prouve rien. Trois causes, par ordre de fréquence — point de contact erroné côté site,
      clé mal recopiée, sortie UDP bloquée par son opérateur.</p>
      <p><strong>Si l\'adresse publique du principal change</strong> (fréquent derrière une box), les tunnels
      restent « montés » et plus rien ne passe. Une veille le détecte et <strong>prévient par courriel</strong>.
      Si le point de contact est un <em>nom</em> et non une adresse, chaque site le re-résout tout seul.
      Une adresse fixe demandée à l\'opérateur supprime le problème.</p>
      <p class="tip">Ces serveurs équipent les postes <strong>hors réseau interministériel</strong> : la liaison emprunte donc les accès opérateur des commissariats, et le tunnel remplace ici le réseau privé. Un seul point est exposé dans le département — le principal, sur son port UDP — et chaque site possède sa propre clé, révocable seule.</p>'],
  ],

  'Journalisation' => [
    ['navigation', '🌐', 'Navigation, journaux &amp; recherche', '      <p>L\'<strong>historique de navigation</strong> conserve, par agent et par poste, les sites consultés : date et
      heure, domaine, utilisateur, adresse IP. C\'est ce qui permet de répondre à « qui a consulté quoi, et quand ».</p>
      <ul>
        <li><strong>Recherche</strong> par agent, par domaine ou par période ; les <strong>statistiques</strong> montrent
        les domaines les plus visités et le volume par utilisateur.</li>
        <li>Ce sont les <strong>domaines</strong> qui sont enregistrés, pas le contenu des pages ni les mots de passe :
        la journalisation répond à une obligation légale, elle n\'est pas un outil de surveillance du contenu.</li>
        <li>Les journaux sont <strong>chaînés et scellés</strong> par signature : une ligne modifiée ou supprimée après
        coup rompt la chaîne et devient détectable. Sans cela, un journal ne vaudrait rien devant un magistrat.</li>
        <li>La <strong>durée de conservation</strong> est paramétrable ; au-delà, les entrées sont purgées
        automatiquement. Conserver plus longtemps que nécessaire est une faute, pas une précaution.</li>
      </ul>
      <p class="tip">Un agent absent des journaux n\'a pas forcément « rien fait » : vérifiez d\'abord qu\'il s\'est
      authentifié au portail. Un poste non authentifié n\'a pas d\'accès — donc rien à journaliser.</p>'],

    ['audit', '🕵️', 'Journal d\'audit des administrateurs', '      <p>Le <strong>journal d\'audit</strong> enregistre ce que font les <em>administrateurs</em> dans cette console — à
      ne pas confondre avec l\'historique de navigation, qui concerne les agents.</p>
      <ul>
        <li>Chaque entrée porte <strong>qui</strong>, <strong>quand</strong>, <strong>depuis quelle adresse</strong>,
        <strong>quoi</strong> : création ou suppression de compte, changement de droits, publication d\'une actualité,
        mise en quarantaine, modification du filtrage, déploiement d\'une stratégie.</li>
        <li>Il répond à deux questions qui reviennent toujours après coup : « qui a changé ça », et « depuis
        quand ». Sans lui, un droit accordé par erreur est intraçable.</li>
        <li>Il est <strong>consultable, pas modifiable</strong> — y compris par un administrateur complet.</li>
      </ul>
      <p class="tip">Quand un comportement inattendu apparaît (un agent qui perd un accès, un site qui se bloque tout
      seul), commencez par ce journal plutôt que par les fichiers de configuration : neuf fois sur dix, la cause est
      une action humaine récente et elle est écrite ici.</p>'],

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
    ['intranet', '🏠', 'Portail intranet &amp; contenu', '      <p>L\'<strong>intranet</strong> est la page d\'accueil des agents après connexion au portail : actualités du
      service, pages d\'information, liens utiles, annuaire, assistance.</p>
      <ul>
        <li><strong>Actualités</strong> : chaque publication a un <strong>permalien</strong> et rejoint une
        <strong>archive</strong> consultable — une note de service reste retrouvable des mois plus tard.</li>
        <li><strong>Pages</strong> : consignes, procédures, numéros utiles. Elles vivent dans Bastion, donc elles
        restent accessibles même quand l\'accès extérieur est coupé.</li>
        <li><strong>Bandeau d\'annonce</strong> : un message en haut de toutes les pages, avec une <strong>date
        d\'expiration</strong> — il disparaît seul, ce qui évite les avis de fête nationale encore affichés en octobre.</li>
        <li><strong>Recherche</strong> : un seul champ qui interroge actualités, pages, annuaire et documentation.</li>
        <li>Chaque publication est <strong>tracée au journal d\'audit</strong>, avec son auteur.</li>
      </ul>
      <p class="tip">Une page modifiée qui semble inchangée côté agent, c\'est le cache du navigateur : un
      <strong>Ctrl+F5</strong> tranche la question en une seconde.</p>'],
  ],

  'Supervision &amp; exploitation' => [
    ['sante', '💓', 'Santé, rapport &amp; tableau de bord', '      <p>La page <strong>Santé</strong> montre l\'état de la passerelle en direct : processeur, mémoire, disque,
      température, durée de fonctionnement, et l\'état de chaque service essentiel.</p>
      <ul>
        <li><strong>Alertes par courriel</strong> : au franchissement d\'un seuil (disque presque plein, service arrêté),
        un message part. C\'est le seul canal qui fonctionne encore quand la console, elle, ne répond plus — une alerte
        qui ne s\'affiche que dans l\'interface ne sert à rien le jour où l\'interface tombe.</li>
        <li><strong>Le disque est la panne la plus courante</strong> : journaux, images d\'installation et installeurs du
        store grossissent sans bruit. Un disque plein arrête la base de données, et donc l\'authentification de tout le
        monde d\'un coup.</li>
        <li><strong>Rapport de conformité</strong> : un PDF récapitulant configuration, comptes, stratégies appliquées
        et état des postes — à produire lors d\'un contrôle, plutôt que de faire des copies d\'écran.</li>
        <li><strong>Tableau de bord</strong> : la vue d\'ensemble d\'ouverture — sessions actives, alertes, postes non
        conformes.</li>
      </ul>
      <p class="tip">Vérifiez que les alertes <strong>partent réellement</strong> : envoyez un message d\'essai depuis la
      configuration du courriel. Une alerte silencieuse par erreur de configuration est pire que pas d\'alerte du tout,
      parce qu\'on croit être couvert.</p>'],

    ['sauvegarde', '💾', 'Sauvegarde &amp; restauration', '      <p><strong>Sauvegarde</strong> : une archive <strong>chiffrée</strong> contenant la base de données (comptes,
      groupes, quotas, journaux), la configuration des services, le contenu de l\'intranet et les paramètres du domaine.</p>
      <ul>
        <li><strong>Lancez-la à la main</strong> avant toute intervention lourde : mise à jour, changement de
        configuration réseau, manipulation de l\'annuaire.</li>
        <li><strong>Téléchargez l\'archive et sortez-la de la machine.</strong> Une sauvegarde qui dort sur le disque
        qu\'elle est censée protéger ne sert à rien le jour où ce disque lâche.</li>
        <li><strong>Restauration</strong> : déposez l\'archive et confirmez. L\'opération <strong>écrase</strong> l\'état
        actuel — comptes, journaux, réglages. Ce n\'est pas une fusion.</li>
        <li>L\'archive étant chiffrée, elle est <strong>inutilisable sans son secret</strong>. Conservez-le ailleurs que
        sur la passerelle, sinon les deux disparaîtront ensemble.</li>
      </ul>
      <p class="tip">Une sauvegarde jamais restaurée est une <strong>hypothèse</strong>, pas une garantie. Essayez la
      restauration une fois, hors production, avant d\'en avoir besoin en urgence — c\'est le seul moment où découvrir
      un problème ne coûte rien.</p>'],
    ['fonctions', '🧩', 'Fonctions optionnelles', '      <p>Tout ce que fait Bastion n\'est pas indispensable partout. Cette page permet de couper ce dont vous
      n\'avez pas l\'usage — et surtout de le rallumer sans ligne de commande.</p>

      <p><strong>Ce qui n\'y figure pas.</strong> Le portail captif, le DNS/DHCP, la base de données et le
      contrôleur de domaine n\'apparaissent pas ici : les couper n\'est pas un réglage, c\'est une panne. Pour un
      redémarrage ponctuel de ceux-là, voyez la page <em>Services</em>.</p>

      <p><strong>Chaque fonction annonce trois choses</strong> : ce qu\'elle fait, ce qui cesse de marcher si vous
      la coupez, et ce que ça libère. C\'est la seule information qui permette de décider — un interrupteur sans
      conséquence affichée n\'aide personne.</p>
      <ul>
        <li><strong>Antivirus</strong> — le premier consommateur de mémoire de la passerelle, et de loin : il
        charge toute sa base de signatures en RAM. Le couper libère près d\'un gigaoctet, mesuré. En échange, les
        stations d\'analyse ne rendent plus de verdict, et les clés USB apportées de l\'extérieur entrent sans
        contrôle.</li>
        <li><strong>Prise de main à distance</strong> — consommation négligeable, mais le relais est joignable
        depuis Internet. Le couper referme cette porte quand personne n\'en a besoin.</li>
        <li><strong>Activation Windows / Office</strong> — attention au décalage : les postes déjà activés le
        restent environ 180 jours avant de repasser en « non activé ». La panne apparaîtra des mois après la
        décision, et plus personne ne fera le lien.</li>
        <li><strong>Point d\'accès Wi-Fi</strong> — le réseau sans fil disparaît ; les appareils sans port réseau
        perdent tout accès.</li>
        <li><strong>Historique de navigation</strong> — listé, mais <strong>verrouillé</strong> : il répond à une
        obligation légale de conservation, et c\'est une des raisons d\'être de la passerelle. Il ne se coupe pas
        d\'un clic depuis une page web.</li>
      </ul>

      <p class="tip">L\'état affiché est relu sur le système à chaque ouverture de la page, jamais mémorisé dans
      un réglage. Un indicateur qui annonce « activé » pendant que le service est mort ferait chercher la panne
      ailleurs — il coûterait plus cher que pas d\'indicateur du tout.</p>

      <p class="tip">Guettez l\'état <strong>« Partielle »</strong>. Deux fonctions reposent sur deux composants :
      si un seul tourne, tout a l\'apparence de marcher et rien ne marche. C\'est le cas le plus déroutant, donc
      celui qui est signalé le plus fort.</p>

      <p>Activer ou couper une fonction est <strong>immédiat et survit au redémarrage</strong>, et chaque
      basculement part au journal d\'audit avec le nom de celui qui l\'a décidé.</p>'],

    ['trafic', '📡', 'Trafic réseau en direct', '      <p><strong>Trafic réseau en direct</strong> : qui consomme quoi, maintenant. Chaque poste actif y apparaît avec
      son débit montant et descendant, son utilisateur et son volume de session.</p>
      <ul>
        <li>Sert surtout à répondre à « Internet rame » : en quelques secondes on voit si la ligne est saturée, et
        par qui — téléchargement, sauvegarde en ligne, mise à jour massive.</li>
        <li>Les <strong>limites de débit par groupe</strong> se règlent dans « Groupes &amp; quotas » : plutôt que de
        couper quelqu\'un, on plafonne une catégorie d\'usage.</li>
        <li>Une session peut être <strong>fermée</strong> depuis la page des sessions ; l\'agent devra se réidentifier au
        portail.</li>
      </ul>
      <p class="tip">Un débit élevé n\'est pas un abus. Avant d\'intervenir, regardez <em>vers quels domaines</em> il va :
      une mise à jour Windows sur dix postes ressemble beaucoup à un téléchargement massif, et ce n\'est pas la même
      conversation à avoir.</p>'],

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

    ['services', '🧰', 'Services', '      <p>La page <strong>Services</strong> donne l\'état de chaque brique de la passerelle et permet de la redémarrer
      sans ligne de commande.</p>
      <ul>
        <li><strong>Portail captif</strong> — redirige les postes non authentifiés et ouvre l\'accès après connexion.
        Arrêté, plus personne n\'obtient d\'accès.</li>
        <li><strong>Authentification</strong> — vérifie les identifiants. Arrêtée, les connexions sont refusées alors
        que les mots de passe sont bons.</li>
        <li><strong>DNS / DHCP</strong> — distribue les adresses et applique le filtrage. Arrêté, les postes ne
        reçoivent plus d\'adresse et ne résolvent plus rien : la panne la plus visible de toutes.</li>
        <li><strong>Base de données</strong> — comptes, journaux, paramètres. Tout en dépend.</li>
        <li><strong>Contrôleur de domaine</strong> — ouverture de session Windows, stratégies, dossiers partagés.</li>
        <li><strong>Serveur web</strong> — cette console et le portail.</li>
        <li><strong>Prise de main à distance</strong> — <strong>deux</strong> unités, et il faut les deux :
        l\'<em>annuaire</em>, où les postes s\'enregistrent, et le <em>relais</em>, qui achemine le flux. Si seul
        l\'annuaire tourne, un poste s\'affiche comme joignable et la connexion échoue sans explication.</li>
      </ul>
      <p class="tip">Redémarrez un service <strong>un par un</strong>, en vérifiant après chacun. Tout relancer d\'un
      bloc fait perdre l\'information la plus utile : lequel était réellement en cause.</p>'],

    ['central', '🏢', 'Serveur central (multi-sites)', '      <p>Le <strong>serveur central</strong> est le point de rendez-vous d\'une flotte de plusieurs commissariats. Chaque
      site ouvre vers lui un tunnel <em>sortant</em>, ce qui évite d\'avoir à ouvrir un port sur la box de chaque
      commissariat — et donc d\'exposer chaque site depuis Internet.</p>
      <ul>
        <li>Déclarez ce serveur comme <strong>principal du département</strong> dans « Liaison inter-sites » ; les
        autres se déclarent comme <strong>site</strong> et pointent vers lui.</li>
        <li>Le principal voit l\'état de chaque site, la date du dernier contact et les flux en direct sur la
        <strong>cartographie</strong>.</li>
        <li>Un site dont l\'adresse publique change (box redémarrée, bail renouvelé) se <strong>re-raccroche seul</strong> :
        c\'est lui qui appelle, donc son changement d\'adresse n\'a pas d\'importance.</li>
      </ul>
      <p class="tip">La liaison sert à la <strong>supervision</strong> et à la distribution. Elle ne fusionne pas les
      annuaires : chaque commissariat garde son domaine et ses comptes. Voir « Liaison inter-sites » pour le détail
      du raccordement.</p>'],
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
  /* Les tableaux de l'aide se stylent ICI : la classe « .tbl » des autres pages n'est
     pas chargée sur celle-ci, et un tableau non stylé passe pour un défaut d'affichage. */
  .aide .doc table{width:100%;border-collapse:collapse;margin:.7rem 0 1rem;font-size:.9rem}
  .aide .doc table th,.aide .doc table td{border:1px solid var(--line);padding:.45rem .7rem;text-align:left}
  .aide .doc table thead th{background:var(--bg);color:var(--text);font-size:.8rem;text-transform:uppercase;letter-spacing:.04em}
  .aide .doc table tbody th{color:var(--text);font-weight:600;white-space:nowrap}
  .aide .doc table td{color:var(--muted)}
  @media(max-width:600px){.aide .doc table{display:block;overflow-x:auto}}
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
  // Le sommaire suit le filtre. Sans cela il continuait de proposer des liens vers
  // des sections masquees : on cliquait, et rien ne se passait.
  document.querySelectorAll(".aide .toc a[href^='#']").forEach(function(a){
    var s = document.getElementById(a.getAttribute("href").slice(1));
    a.style.display = (s && s.style.display === "none") ? "none" : "";
  });
  document.querySelectorAll(".aide .toc .grp").forEach(function(g){
    var any=false, n=g.nextElementSibling;
    while(n && n.tagName==="A"){ if(n.style.display!=="none"){any=true;} n=n.nextElementSibling; }
    g.style.display = any ? "" : "none";
  });
}
</script>
<?php pf_footer(); ?>
