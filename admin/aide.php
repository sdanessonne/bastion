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
      <p>Partages depuis un poste : <code>\\\\bastion.pn.int\\Commun</code>. Les dossiers partagés se créent, se
      renomment en lecture seule/écriture, se rendent visibles ou se retirent depuis l\'onglet « Partages ».</p>'],

    ['gpo', '📋', 'Stratégies de groupe (GPO)', '
      <p>Le <strong>catalogue de stratégies</strong> déploie en un clic (sur tout le domaine) plus de 100 réglages
      prêts à l\'emploi : sécurité &amp; durcissement, confidentialité, verrouillage de l\'interface, Windows Update,
      navigateurs Edge / Chrome / Firefox, Office, etc.</p>
      <ul>
        <li><strong>Fond d\'écran des postes</strong> : téléversez une image, elle s\'impose à l\'ouverture de session
        (un aperçu s\'affiche dans la console).</li>
        <li><strong>Lecteurs réseau</strong> : montez automatiquement des partages (ex. <code>Z: → \\\\bastion.pn.int\\Commun</code>).</li>
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
      message, liens rapides) ; « Pages » et « Actualités » sont un mini-CMS (Markdown léger, aperçu en direct) visible
      par les utilisateurs après connexion ; « Médiathèque » stocke les images (ré-encodées à l\'import pour la sécurité).
      L\'<strong>Assistance</strong> (demandes des agents) reste une entrée séparée.</p>'],
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
      <strong>sauvegarde automatique hebdomadaire</strong> est active par défaut.</p>'],

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
