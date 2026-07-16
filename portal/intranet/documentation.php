<?php
/** Bastion — Documentation utilisateur (guide de l'accès Internet et des services). */
require_once __DIR__ . '/_common.php';
$me = intranet_user();
intranet_head('Documentation', 'documentation');
?>
<h1>Documentation</h1>
<div class="card">
  <h2 style="margin-top:0;font-size:1.05rem;color:var(--accent)">Accès à Internet</h2>
  <ul>
    <li>Connectez-vous au réseau, puis saisissez vos identifiants sur le portail qui s'affiche.</li>
    <li>Une fois connecté, votre accès reste ouvert pour la durée de votre session.</li>
    <li>Retrouvez à tout moment votre consommation dans <strong>« Mon compte »</strong>.</li>
  </ul>
</div>
<div class="card">
  <h2 style="margin-top:0;font-size:1.05rem;color:var(--accent)">Quotas &amp; horaires</h2>
  <ul>
    <li>Selon votre profil, une durée de session, un débit et un volume de données peuvent s'appliquer.</li>
    <li>À l'épuisement du quota ou hors des plages autorisées, l'accès se ferme automatiquement.</li>
    <li>Les compteurs se réinitialisent à la prochaine session.</li>
  </ul>
</div>
<div class="card">
  <h2 style="margin-top:0;font-size:1.05rem;color:var(--accent)">Dossiers partagés</h2>
  <ul>
    <li>Depuis un poste du domaine : <code>\\192.168.182.2\Commun</code> (espace commun) ou votre partage dédié.</li>
    <li>Les fichiers déposés sont automatiquement analysés par l'antivirus.</li>
  </ul>
</div>
<div class="card">
  <h2 style="margin-top:0;font-size:1.05rem;color:var(--accent)">Bon usage</h2>
  <ul>
    <li>Certains sites (publicités, contenus indésirables) sont filtrés pour la sécurité de tous.</li>
    <li>Votre navigation peut être journalisée conformément à la réglementation en vigueur.</li>
    <li>En cas de difficulté, utilisez <strong>« Assistance informatique »</strong>.</li>
  </ul>
</div>
<?php intranet_foot();
