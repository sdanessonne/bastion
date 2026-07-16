<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — Aide / documentation intégrée. */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';

// Sections d'aide : [ancre, icône, titre, contenu HTML].
$H = [
  ['demarrage', '🚀', 'Prise en main', '
    <p>Bastion est le contrôleur d\'accès de votre réseau : il authentifie les utilisateurs, filtre,
    journalise, gère les postes (Active Directory, GPO, applications) et la sécurité, depuis cette console.</p>
    <ul>
      <li><strong>Console admin</strong> : <code>https://&lt;passerelle&gt;:8443</code> (ou <code>https://bastion.pn.int:8443</code>).</li>
      <li><strong>Portail utilisateur</strong> : les clients sont redirigés automatiquement à la connexion.</li>
      <li>Le menu de gauche regroupe les fonctions par domaine (Supervision, Accès &amp; sécurité, Réseau &amp; postes,
      Intranet, Journalisation). Les changements sont <strong>immédiats</strong>.</li>
    </ul>'],

  ['utilisateurs', '👤', 'Utilisateurs & droits', '
    <p><strong>Onglet Utilisateurs &amp; droits</strong> : un seul écran pour créer un agent avec, au choix,
    l\'<strong>accès Internet</strong> (portail), un <strong>compte de domaine</strong> (AD), et les droits
    <strong>administrateur console</strong> ou <strong>administrateur du domaine</strong>. Vous y gérez aussi
    l\'identité (nom, prénom, service), le <strong>commissariat</strong> d\'appartenance, et les opérations en
    <strong>masse</strong> (import CSV, actions groupées).</p>
    <p><strong>Onglet Groupes &amp; quotas</strong> : par groupe, la durée de session, les débits, les quotas de
    données et les plages horaires.</p>
    <p class="tip">Format d\'identifiant imposé : matricule à 7 chiffres (ex. <code>0110480</code>) ; administrateur
    <code>admin-0110480</code>. Les nouveaux quotas s\'appliquent à la <em>prochaine</em> connexion.</p>'],

  ['filtrage', '⛔', 'Filtrage & publicités', '
    <p><strong>Onglet Filtrage</strong> : bloquez des domaines (un par un ou par import de liste) et des
    <strong>catégories thématiques</strong> (adulte, jeux d\'argent, réseaux sociaux, streaming, malveillant).
    Activez le <strong>bloqueur de publicités</strong> (liste communautaire, mise à jour hebdomadaire).</p>
    <p>Le blocage est appliqué au niveau DNS : il fonctionne quel que soit le site (HTTP comme HTTPS) et prend
    effet immédiatement pour tous les clients.</p>'],

  ['navigation', '🌐', 'Navigation & journaux', '
    <p><strong>Onglet Navigation</strong> : historique des sites consultés par utilisateur, avec statistiques et
    export CSV. <strong>Onglet Journaux</strong> : traçabilité légale des connexions (RGPD), filtrable et
    exportable. <strong>Onglet Recherche agent</strong> : fiche complète d\'un agent (identité, comptes, postes de
    connexion, navigation). Purge automatique après un an.</p>'],

  ['requisition', '⚖️', 'Réquisition judiciaire', '
    <p><strong>Onglet Réquisition</strong> (groupe Journalisation) : en cas de réquisition judiciaire ou
    administrative, extrayez toute la traçabilité détenue sur une cible — <strong>agent, adresse IP, adresse MAC,
    domaine, ou période</strong> — sur une plage de dates.</p>
    <ul>
      <li>Renseignez le cadre légal (n° de réquisition, autorité requérante, cadre juridique, requérant).</li>
      <li>Un <strong>dossier visuel</strong> s\'affiche (identités, sessions, navigation).</li>
      <li>Le bouton « Télécharger le dossier signé » produit une archive avec le <strong>PDF signé
      électroniquement</strong> (intégrité et origine vérifiables) + la procédure de vérification.</li>
    </ul>
    <p class="tip">Chaque extraction est elle-même journalisée. La signature s\'appuie sur l\'autorité de
    certification interne de Bastion ; le destinataire vérifie l\'archive avec OpenSSL (voir <code>VERIFICATION.txt</code>).</p>'],

  ['ad', '🗄️', 'Active Directory', '
    <p><strong>Onglet Active Directory</strong> : gérez le domaine (par défaut <code>BASTION.PN.INT</code>) —
    ordinateurs (description, dernier utilisateur), <strong>groupes</strong> (vos groupes métier séparés des
    groupes système Windows), unités d\'organisation, <strong>GPO</strong> (voir section dédiée), <strong>dossiers
    partagés</strong>, <strong>lecteurs réseau</strong>, <strong>fond d\'écran</strong> et le nom de domaine.
    Les comptes (fonctionnaires) se gèrent dans « Utilisateurs &amp; droits ».</p>
    <p><strong>Joindre un poste au domaine</strong> :</p>
    <ol>
      <li>Régler le DNS du poste sur <code>192.168.182.2</code>.</li>
      <li>Système → « Ce PC » → Renommer/Domaine → domaine <code>bastion.pn.int</code>.</li>
      <li>Identifiants : <code>Administrator</code> / mot de passe du domaine, puis redémarrer.</li>
    </ol>
    <p>Accès à un partage depuis un poste : <code>\\\\bastion.pn.int\\Commun</code>. Les fichiers déposés sont
    analysés par l\'antivirus.</p>'],

  ['gpo', '📋', 'Stratégies de groupe (GPO)', '
    <p>Dans l\'onglet <strong>Active Directory</strong>, le <strong>catalogue de stratégies</strong> déploie en
    un clic (sur tout le domaine) plus de 100 réglages prêts à l\'emploi, classés par thème : sécurité &amp;
    durcissement, confidentialité, verrouillage de l\'interface, Windows Update, navigateurs
    <strong>Edge / Chrome / Firefox</strong>, Office, etc.</p>
    <ul>
      <li><strong>Fond d\'écran des postes</strong> : téléversez une image, elle s\'impose à l\'ouverture de session.</li>
      <li><strong>Lecteurs réseau</strong> : montez automatiquement des partages (ex. <code>Z: → \\\\bastion.pn.int\\Commun</code>).</li>
      <li>Chaque GPO déployée est listée avec sa <strong>portée</strong> (ordinateur/utilisateur) et sa description.</li>
    </ul>
    <p class="tip">Sur le poste, appliquez avec <code>gpupdate /force</code> puis un redémarrage / une réouverture
    de session. Déployez la stratégie « <strong>Attendre le réseau à l\'ouverture de session</strong> » pour que le
    fond d\'écran et les lecteurs apparaissent dès la 1<sup>re</sup> connexion.</p>'],

  ['apps', '🏪', 'Store d\'applications', '
    <p><strong>Onglet Store d\'applications</strong> : déployez des logiciels sur tous les postes du domaine.
    Un <strong>catalogue</strong> de 90 applications courantes (navigateurs, bureautique, multimédia, sécurité,
    outils…) se récupère en un clic depuis la source officielle ; vous pouvez aussi ajouter votre propre
    installeur (.msi/.exe).</p>
    <p>Activez les applications voulues puis « <strong>Appliquer sur les postes</strong> » : une GPO les installe
    en silence au démarrage, sans intervention. Testez d\'abord sur un poste pilote.</p>'],

  ['kms', '🔑', 'Activation Windows / Office', '
    <p>Depuis l\'onglet <strong>Active Directory</strong>, activez le <strong>service KMS</strong> : les postes
    Windows et Office non activés s\'activent automatiquement contre la passerelle (clé générique selon l\'édition),
    via une GPO et l\'auto-découverte DNS. Les postes déjà activés (OEM/numérique) ne sont pas touchés.</p>'],

  ['pxe', '📀', 'Serveur PXE (installation d\'OS)', '
    <p><strong>Onglet Serveur PXE</strong> : installez un système (Debian, Ubuntu, Windows) sur un poste par le
    réseau. Paramétrez le menu (titre, délai, entrées, protection), prévisualisez-le et changez la bannière.</p>
    <ul>
      <li>Sur le poste : démarrer en <strong>amorçage réseau (PXE)</strong>.</li>
      <li>Menu protégé par les <strong>identifiants administrateur</strong>.</li>
      <li>Clavier du menu en <strong>AZERTY</strong>.</li>
    </ul>'],

  ['antivirus', '🛡️', 'Antivirus', '
    <p><strong>Onglet Antivirus</strong> (ClamAV) : état du moteur, mise à jour de la base virale, analyse à la
    demande des dossiers partagés et de l\'espace web, historique des analyses. Une analyse complète est aussi
    <strong>planifiée chaque nuit</strong>.</p>'],

  ['sauvegarde', '💾', 'Sauvegarde & restauration', '
    <p><strong>Onglet Sauvegarde</strong> : créez une archive complète (base de données, configuration, médias
    intranet, <strong>sauvegarde du domaine AD</strong>), téléchargez-la, ou restaurez une sauvegarde antérieure.
    Une <strong>sauvegarde automatique hebdomadaire</strong> est active par défaut (les plus récentes sont
    conservées).</p>'],

  ['services', '🧰', 'Services', '
    <p><strong>Onglet Services</strong> : état de tous les services (portail, base, DNS, web, domaine, antivirus,
    KMS…), avec démarrage / arrêt / redémarrage, consultation du <strong>journal</strong> de chaque service, et
    actualisation automatique.</p>'],

  ['central', '🏢', 'Serveur central (multi-sites)', '
    <p>Le <strong>Bastion Central</strong> (machine dédiée, <code>https://&lt;central&gt;:9443</code>) supervise et
    pilote toutes les passerelles d\'un département depuis un point unique : vue d\'ensemble, détail par site, et
    <strong>actions groupées</strong> (pousser un blocage, créer un compte / un fonctionnaire AD, piloter un
    service sur plusieurs sites à la fois).</p>
    <p>Chaque passerelle est ajoutée avec son URL admin et son jeton d\'API (onglet « Sites / passerelles »).</p>'],

  ['intranet', '🏠', 'Intranet', '
    <p><strong>Onglet Portail intranet</strong> : personnalisez la page d\'accueil interne (titre, message, liens).
    <strong>Onglet Contenu</strong> : un mini-CMS pour publier des pages et des actualités (Markdown léger) visibles
    par les utilisateurs après connexion.</p>'],

  ['depannage', '🔧', 'Dépannage', '
    <ul>
      <li><strong>Le portail ne demande plus la connexion (accès Internet ouvert)</strong> : le service
      <em>Portail captif</em> est probablement en échec — le redémarrer depuis l\'onglet Services. Ne jamais poser
      d\'adresse IP supplémentaire sans étiquette (alias) sur l\'interface du LAN.</li>
      <li><strong>Un client ne voit pas le portail</strong> : vérifier qu\'il a bien une adresse IP (DHCP) et que
      le service <em>Portail captif</em> est actif.</li>
      <li><strong>Avertissement de certificat</strong> : normal en interne (certificat interne) ; déployez la GPO
      « Certificat racine » pour le supprimer sur les postes du domaine.</li>
      <li><strong>Fond d\'écran / lecteur réseau absent</strong> : <code>gpupdate /force</code> + redémarrage ;
      déployez la GPO « Attendre le réseau à l\'ouverture de session ».</li>
      <li><strong>Quota/horaire non appliqué</strong> : effet à la prochaine connexion, pas sur la session en cours.</li>
      <li><strong>Poste non joint au domaine</strong> : vérifier que son DNS pointe sur <code>192.168.182.2</code>.</li>
    </ul>'],
];

pf_header('Aide', 'aide.php');
?>
<style>
  .aide{display:grid;grid-template-columns:210px 1fr;gap:1.5rem;align-items:start}
  .aide .toc{position:sticky;top:1rem;background:var(--card,#1e293b);border:1px solid var(--line);border-radius:12px;padding:.8rem}
  .aide .toc a{display:block;padding:.35rem .5rem;border-radius:8px;color:var(--muted);text-decoration:none;font-size:.86rem}
  .aide .toc a:hover{background:var(--bg);color:var(--text)}
  .aide .doc h3{display:flex;align-items:center;gap:.5rem;margin:0 0 .6rem;font-size:1.1rem}
  .aide .doc section{background:var(--card,#1e293b);border:1px solid var(--line);border-radius:14px;padding:1.2rem 1.4rem;margin-bottom:1.1rem;scroll-margin-top:1rem}
  .aide .doc p,.aide .doc li{color:var(--muted);line-height:1.7}
  .aide .doc strong{color:var(--text)}
  .aide .doc code{background:var(--bg);padding:.1rem .35rem;border-radius:5px;font-size:.85em}
  .aide .doc .tip{background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.3);color:#bae6fd;padding:.6rem .8rem;border-radius:10px}
  .aide input.search{width:100%;padding:.6rem .7rem;margin-bottom:.7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px;font-size:.9rem}
  @media(max-width:760px){.aide{grid-template-columns:1fr}.aide .toc{position:static}}
</style>
<div class="aide">
  <nav class="toc">
    <input class="search" type="search" placeholder="Rechercher…" oninput="aideFilter(this.value)">
    <?php foreach ($H as [$id, $ic, $t]): ?>
      <a href="#<?= $id ?>"><?= $ic ?> <?= e($t) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="doc">
    <?php foreach ($H as [$id, $ic, $t, $body]): ?>
      <section id="<?= $id ?>" data-t="<?= e(strtolower($t)) ?>">
        <h3><span><?= $ic ?></span> <?= e($t) ?></h3>
        <?= $body ?>
      </section>
    <?php endforeach; ?>
    <p class="muted small">Bastion — développé par Mickaël MONESTIER (Mle 110.480). Voir l\'onglet « En savoir + ».</p>
  </div>
</div>
<script>
function aideFilter(q){
  q=(q||"").toLowerCase();
  document.querySelectorAll(".aide .doc section").forEach(function(s){
    var hit = s.getAttribute("data-t").indexOf(q)>=0 || s.textContent.toLowerCase().indexOf(q)>=0;
    s.style.display = hit ? "" : "none";
  });
}
</script>
<?php pf_footer(); ?>
