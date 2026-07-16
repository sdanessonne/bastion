<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — « En savoir + » : présentation éditoriale du produit. */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';

pf_header('En savoir +', 'apropos.php');
?>
<style>
  .edito{max-width:820px}
  .edito .lead{font-size:1.05rem;color:var(--text);line-height:1.7}
  .edito h3{margin:1.6rem 0 .6rem;font-size:1.05rem;color:var(--accent)}
  .edito p{color:var(--muted);line-height:1.7;margin:.5rem 0}
  .edito ul{color:var(--muted);line-height:1.8}
  .sign{margin-top:2rem;padding:1.2rem 1.4rem;background:linear-gradient(120deg,#1e3a5f,#152238);
    border:1px solid var(--line);border-radius:14px;display:flex;align-items:center;gap:1.1rem}
  .sign img{width:56px;height:56px;flex:none}
  .sign .who{font-weight:600;color:#fff;font-size:1.05rem}
  .sign .who small{display:block;font-weight:400;color:var(--muted);font-size:.85rem;margin-top:.2rem}
</style>

<section class="panel">
  <div class="panel-head"><h2>🏰 Bastion — le contrôleur d'accès de votre réseau</h2></div>
  <div class="edito" style="padding:1.4rem 1.6rem">
    <p class="lead">Bastion est une passerelle de sécurité réseau tout-en-un : elle garde la porte
    d'accès à Internet, authentifie chaque utilisateur, filtre les contenus, journalise dans le respect
    du cadre légal, et pilote l'ensemble depuis une console unique.</p>

    <h3>Une place forte pour votre réseau</h3>
    <p>Comme un bastion protège l'entrée d'une citadelle, le logiciel contrôle qui entre, ce qui circule
    et ce qui est tracé. Rien n'atteint Internet sans être identifié ; rien de sensible n'est laissé
    sans supervision.</p>

    <h3>Ce que Bastion couvre</h3>
    <ul>
      <li><strong>Accès &amp; authentification</strong> — portail captif HTTPS, comptes individuels par matricule.</li>
      <li><strong>Filtrage</strong> — domaines et catégories bloqués, listes importables, bloqueur de publicités.</li>
      <li><strong>Contrôle</strong> — quotas, horaires et débits par groupe d'utilisateurs.</li>
      <li><strong>Traçabilité</strong> — journalisation légale (RGPD), historique de navigation, recherche par agent,
      et <strong>dossiers de réquisition en PDF signé électroniquement</strong>.</li>
      <li><strong>Parc &amp; postes</strong> — annuaire Active Directory intégré, <strong>stratégies de groupe</strong>
      (plus de 100 réglages prêts à déployer : sécurité, navigateurs, fond d'écran, lecteurs réseau…), <strong>store
      d'applications</strong> (déploiement silencieux), activation Windows/Office (KMS).</li>
      <li><strong>Déploiement</strong> — installation d'OS par le réseau (PXE) avec menu configurable.</li>
      <li><strong>Résilience</strong> — antivirus des partages, sauvegarde/restauration complète automatisée.</li>
      <li><strong>Supervision</strong> — état et pilotage des services, et gestion multi-sites depuis un
      serveur central départemental.</li>
    </ul>

    <h3>Pensé pour le terrain</h3>
    <p>Bastion s'installe sur une machine dédiée à deux cartes réseau et se gère intégralement depuis le
    navigateur, sans expertise système : chaque fonction a son onglet, chaque action est immédiate et
    réversible. Il fonctionne en autonomie complète — aucune dépendance à un service externe — ce qui le rend
    adapté à un réseau interne sensible. Un serveur central permet de superviser et piloter l'ensemble des
    passerelles d'un département depuis un point unique.</p>

    <div class="sign">
      <img src="/assets/bastion-icon.svg" alt="Bastion">
      <div class="who">Développé par Mickaël MONESTIER
        <small>Mle : 110.480 — Conception &amp; développement de la solution Bastion</small>
      </div>
    </div>

    <h3>Licence &amp; propriété</h3>
    <p><strong>Copyright © 2026 Mickaël MONESTIER (Mle 110.480) — Tous droits réservés.</strong></p>
    <p>Bastion est mis à disposition <strong>gratuitement du ministère de l'Intérieur</strong> et de ses
    services (notamment la Police nationale) pour leurs besoins internes. L'auteur en conserve
    l'intégralité du droit d'auteur et de la propriété intellectuelle ; toute autre utilisation,
    commercialisation ou redistribution requiert son autorisation écrite préalable.</p>
    <p class="muted small">Les composants tiers intégrés (OpenNDS, FreeRADIUS, dnsmasq, nftables…)
    restent régis par leurs licences respectives (GPL/BSD).</p>
  </div>
</section>
<?php pf_footer(); ?>
