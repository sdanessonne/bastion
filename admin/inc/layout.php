<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — en-tête / navigation / pied de page communs. */

require_once __DIR__ . '/navstate.php';

/**
 * Catalogue de la navigation : groupe => fichier => [libellé, icône, synonymes].
 *
 * Sorti de pf_header() pour avoir UNE seule définition : la barre latérale l'affiche,
 * et la recherche globale s'en sert pour trouver une page par son nom ou par un de ses
 * synonymes. Deux copies auraient dérivé — une page ajoutée au menu et introuvable à la
 * recherche est exactement le genre d'écart qui ne se voit pas.
 *
 * @return array<string,array<string,array{0:string,1:string,2?:string}>>
 */
function pf_nav_groups(): array {
    // Navigation groupée par domaine fonctionnel.
    // ── REGROUPEMENT PAR QUESTION POSÉE, ET NON PAR ORDRE D'ÉCRITURE ─────────
    // L'ancien découpage suivait l'histoire du projet : « Santé & sécurité »
    // vivait dans Supervision alors qu'un groupe « Accès & sécurité » existait,
    // « Journalisation » formait un groupe d'UNE entrée — un titre pour rien —
    // et trois groupes portaient 7 entrées quand deux en portaient une ou deux.
    //
    // Les groupes répondent désormais à ce que l'administrateur vient chercher :
    // est-ce que ça tourne · qui a le droit · quels postes · que publie-t-on ·
    // que s'est-il passé.
    //
    // Le troisième élément (optionnel) donne des SYNONYMES pour la recherche :
    // personne ne tape « Parc informatique » quand il cherche un inventaire.
    return [
        'Surveiller' => [
            'index.php'      => ['Tableau de bord', '▚', 'accueil resume'],
            'chercher.php'   => ['Recherche globale', '🔎', 'chercher trouver partout agent poste adresse ip mac domaine'],
            'securite.php'   => ['Santé & sécurité', '🩺', 'alertes anomalies etat'],
            'services.php'   => ['Services', '🧰', 'demarrer arreter redemarrer daemon'],
            'reseau.php'     => ['Trafic réseau', '📡', 'debit bande passante wifi canal'],
            'systeme.php'    => ['Système', '🖥', 'disque memoire cpu mise a jour version'],
            'rapport.php'    => ['Rapport de conformité', '📊', 'audit rgpd bilan pdf hierarchie'],
            'journal.php'    => ['Journalisation', '📄', 'logs historique navigation connexions traces'],
        ],
        'Accès & droits' => [
            'users.php'      => ['Utilisateurs & droits', '👤', 'comptes agents mot de passe roles'],
            'annuaire.php'   => ['Annuaire', '📇', 'trombinoscope photos services'],
            'groups.php'     => ['Groupes & quotas', '⚙', 'debit horaires limitation tunnel vpn'],
            'visiteurs.php'  => ['Accès visiteur', '🎟️', 'invite temporaire ticket'],
            'vpn.php'        => ['VPN', '🔒', 'tunnel wireguard proton ip sortie osint anonymat source ouverte'],
            'lien.php'       => ['Liaison inter-sites', '🔗', 'flotte central commissariats tunnel wireguard concentrateur multi site'],
        ],
        'Protection' => [
            'filter.php'     => ['Filtrage', '⛔', 'blocage sites categories liste noire dns publicite'],
            'antivirus.php'  => ['Antivirus', '🛡️', 'clamav analyse menace station blanche usb'],
            'chiffrement.php' => ['Chiffrement des postes', '🔐', 'bitlocker tpm disque'],
            'quarantaine.php' => ['Quarantaine réseau', '🚫', 'isoler poste couper bloquer'],
            'sauvegarde.php' => ['Sauvegarde', '💾', 'restauration archive cle usb'],
            'fonctions.php'  => ['Fonctions', '🧩', 'activer desactiver antivirus clamav prise de main distance wifi kms module option'],
        ],
        'Postes & réseau' => [
            'ad.php'         => ['Active Directory', '🗄️', 'domaine gpo strategies ou samba'],
            'parc.php'       => ['Parc informatique', '🗃️', 'inventaire machines conformite materiel'],
            'distance.php'   => ['Prise de main à distance', '🖥️', 'depannage assistance controle ecran rustdesk relais consentement'],
            'dhcp.php'       => ['Réservations DHCP', '🔌', 'bail adresse ip mac appareil'],
            'apps.php'       => ['Store d\'applications', '🏪', 'logiciels deploiement installation'],
            'firefox.php'    => ['Firefox', '🦊', 'navigateur mozilla gpo admx accueil doh telemetrie extensions'],
            'pxe.php'        => ['Serveur PXE', '📀', 'boot reseau installation windows ubuntu image'],
        ],
        'Intranet' => [
            'cms.php'        => ['Portail intranet', '🏠', 'pages actualites mediatheque publication'],
            'assistance.php' => ['Demandes d\'assistance', '📨', 'tickets agents demandes support'],
        ],
        'Aide' => [
            'assistant.php'  => ['Assistant de configuration', '🧭', 'demarrage mise en route'],
            'aide.php'       => ['Aide', '❓', 'documentation manuel'],
            'apropos.php'    => ['À propos de Bastion', 'ℹ️', 'version licence auteur'],
        ],
    ];
}

function pf_header(string $title, string $active = ''): void {
    $navGroups = pf_nav_groups();
    $admin = $_SESSION['admin'] ?? '';
    // Mode « embarqué » : une page ouverte dans un onglet (iframe) de journal.php n'affiche
    // ni barre latérale ni en-tête — juste son contenu. pf_footer() garde alors « embed » sur
    // les navigations internes.
    $embed = ($_GET['embed'] ?? '') === '1';
    ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bastion Admin — <?= e($title) ?></title>
  <link rel="icon" href="/assets/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="/assets/bastion-icon.svg">
  <link rel="stylesheet" href="/assets/admin.css">
  <style>
    /* ── BARRE DU HAUT ───────────────────────────────────────────────────────
       Collante et translucide : en faisant défiler une longue page — le journal,
       l'annuaire — on perdait la recherche et le menu utilisateur, et il fallait
       remonter tout en haut pour changer de page. */
    .topbar{position:sticky;top:0;z-index:40;display:flex;align-items:center;gap:1rem;flex-wrap:nowrap;
      padding:.55rem 0;margin-bottom:.4rem;
      background:color-mix(in srgb,var(--bg) 82%,transparent);
      backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);
      border-bottom:1px solid var(--line)}
    /* Repli sans color-mix (navigateurs anciens) : un fond opaque vaut mieux
       qu'un fond transparent qui laisserait le texte de la page passer dessous. */
    @supports not (background:color-mix(in srgb,red 50%,transparent)){.topbar{background:var(--bg)}}
    .tb-left{display:flex;align-items:center;gap:.8rem;min-width:0;flex:0 1 auto}
    .tb-left h1{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

    /* ── Recherche ───────────────────────────────────────────────────────────
       C'est un vrai formulaire pointant sur chercher.php : sans JavaScript, la
       touche Entrée mène tout de même quelque part. La liste déroulante est un
       confort ajouté par-dessus, jamais le seul chemin. */
    .tb-search{position:relative;flex:1 1 260px;max-width:440px;min-width:0}
    .tb-search input{width:100%;padding:.5rem 4.5rem .5rem 2.2rem;font-size:.87rem;border-radius:10px;
      background:var(--panel);border:1px solid var(--line);color:var(--text);outline:none;
      transition:border-color .15s,box-shadow .15s,background .15s}
    .tb-search input:focus{border-color:var(--accent);background:var(--bg);box-shadow:0 0 0 3px rgba(56,189,248,.15)}
    .tb-search input::-webkit-search-cancel-button{filter:invert(.6)}
    .ts-ico{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);pointer-events:none;
      font-size:.85rem;opacity:.65}
    .ts-kbd{position:absolute;right:.55rem;top:50%;transform:translateY(-50%);pointer-events:none;
      background:var(--panel2);color:var(--muted);border:1px solid var(--line);border-radius:5px;
      padding:.1rem .35rem;font-size:.64rem;font-family:ui-monospace,"Cascadia Code",monospace}
    .tb-search input:focus ~ .ts-kbd{opacity:0}
    .ts-pop{position:absolute;left:0;right:0;top:calc(100% + .4rem);background:var(--panel);
      border:1px solid var(--line);border-radius:12px;box-shadow:0 14px 34px rgba(0,0,0,.5);
      padding:.3rem;z-index:60;max-height:60vh;overflow-y:auto}
    .ts-row{display:flex;align-items:center;gap:.6rem;padding:.5rem .6rem;border-radius:8px;
      color:var(--text);text-decoration:none;font-size:.87rem}
    .ts-row:hover,.ts-row.on{background:var(--panel2)}
    /* La ligne surlignée est celle qu'ouvre la touche Entrée : il faut la voir
       avant d'appuyer, sinon on ouvre une page au hasard. */
    .ts-row.on{box-shadow:inset 2px 0 0 var(--accent)}
    .ts-ri{width:1.3rem;text-align:center;flex:none}
    .ts-rt{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .ts-rn{flex:none;font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
    .ts-row.ts-all{border-top:1px solid var(--line);border-radius:0 0 8px 8px;margin-top:.2rem;color:var(--accent)}
    .ts-more{padding:.45rem .7rem;font-size:.74rem;color:var(--muted)}

    /* ── Pastille d'état ─────────────────────────────────────────────────────
       Même source que le voyant du menu : les deux ne peuvent pas diverger. */
    .state-pill{display:inline-flex;align-items:center;gap:.45rem;flex:none;padding:.3rem .7rem .3rem .35rem;
      border-radius:20px;font-size:.76rem;text-decoration:none;color:var(--text);
      background:linear-gradient(90deg,rgba(34,197,94,.16),rgba(34,197,94,.05));border:1px solid rgba(34,197,94,.4)}
    .state-pill:hover{filter:brightness(1.25)}
    .state-pill .sp-ico{width:20px;height:20px;border-radius:50%;display:grid;place-items:center;
      background:var(--ok);color:#052b12;font-size:.7rem;font-weight:700;flex:none}
    .state-pill.warn{background:linear-gradient(90deg,rgba(251,191,36,.16),rgba(251,191,36,.05));border-color:rgba(251,191,36,.45)}
    .state-pill.warn .sp-ico{background:var(--warn);color:#3a2a00}
    .state-pill.danger{background:linear-gradient(90deg,rgba(248,113,113,.16),rgba(248,113,113,.05));border-color:rgba(248,113,113,.45)}
    .state-pill.danger .sp-ico{background:var(--danger);color:#3a0d0d}

    .usermenu{position:relative;flex:none}
    .userbtn{display:flex;align-items:center;gap:.55rem;background:var(--panel2);border:1px solid var(--line);
             color:var(--text);padding:.4rem .7rem .4rem .45rem;border-radius:24px;cursor:pointer;font:inherit}
    .userbtn:hover{border-color:var(--accent)}
    img.uavatar{object-fit:cover;background:var(--accent2)}
    .uavatar{display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:var(--accent2);
             color:#052536;font-weight:700;font-size:.9rem;flex:0 0 auto}
    .uavatar.sm{width:34px;height:34px}
    .uwho{display:flex;flex-direction:column;align-items:flex-start;line-height:1.15;min-width:0}
    .uname{font-size:.85rem;font-weight:500;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    /* Le rôle est affiché en clair : « lecture seule » explique d'avance pourquoi
       un bouton refusera d'enregistrer, au lieu de le laisser découvrir au clic. */
    .urole{font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);
      max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .ucaret{color:var(--muted);font-size:.7rem}
    .usermenu-pop{position:absolute;right:0;top:calc(100% + .5rem);min-width:230px;background:var(--panel);
                  border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 30px rgba(0,0,0,.45);
                  padding:.4rem;display:none;z-index:50}
    .usermenu.open .usermenu-pop{display:block}
    .usermenu-hd{display:flex;align-items:center;gap:.6rem;padding:.6rem .6rem .7rem;border-bottom:1px solid var(--line);margin-bottom:.4rem}
    .usermenu-pop a{display:flex;align-items:center;gap:.6rem;padding:.6rem .7rem;border-radius:8px;color:var(--text);
                    text-decoration:none;font-size:.88rem}
    .usermenu-pop a:hover{background:var(--panel2)}
    .usermenu-pop a.mi-logout{color:var(--danger)}
    .usermenu-pop a.mi-warn{color:#eab308}
    .usermenu-pop a.mi-danger{color:var(--danger)}
    .usermenu-sep{height:1px;background:var(--line);margin:.4rem .3rem}
    .maj-toast{position:fixed;right:1.2rem;bottom:1.2rem;max-width:360px;background:var(--panel);
      border:1px solid var(--line);border-left:3px solid #38bdf8;border-radius:12px;
      box-shadow:0 16px 40px rgba(0,0,0,.5);padding:1rem 1.1rem;z-index:200;animation:majIn .35s cubic-bezier(.16,1,.3,1)}
    @keyframes majIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
    .maj-toast h4{margin:0 0 .35rem;font-size:.95rem;display:flex;align-items:center;gap:.5rem}
    .maj-toast p{margin:0 0 .8rem;font-size:.82rem;color:var(--muted);line-height:1.5}
    .maj-toast .row{display:flex;gap:.5rem}
    @media(max-width:640px){.maj-toast{left:1rem;right:1rem;max-width:none}}
    @media(prefers-reduced-motion:reduce){.maj-toast{animation:none}}
    .usermenu-pop .ico{width:1.2rem;text-align:center}
    /* ── Rétrécissement, dans l'ordre de ce dont on peut se passer ────────────
       Le titre de la page part en premier : le menu latéral indique déjà où l'on
       se trouve. La recherche est ce qu'on garde le plus longtemps — c'est elle
       qui remplace le menu quand l'écran ne peut plus l'afficher. */
    @media (max-width:1100px){.state-pill .sp-txt{display:none}
      .state-pill{padding:.3rem .35rem}}
    @media (max-width:900px){.tb-left h1{display:none}}
    @media (max-width:640px){.uwho{display:none}.ts-kbd{display:none}
      .tb-search input{padding-right:.7rem}}
  </style>
  <style>
    /* ── Barre de chargement ─────────────────────────────────────────────────
       La console rend ses pages côté serveur : entre le clic et l'affichage, il
       ne se passe visuellement RIEN. Sur les pages lentes — l'annuaire, le
       journal — l'administrateur reclique, croyant n'avoir pas cliqué.
       La barre part au clic et ne DISPARAÎT qu'au chargement de la page
       suivante : c'est le navigateur qui la fait disparaître en remplaçant le
       document, et non un minuteur qui prétendrait savoir quand c'est fini. */
    #pf-load{position:fixed;top:0;left:0;height:3px;width:0;z-index:9999;
      background:linear-gradient(90deg,var(--accent2),var(--accent));
      box-shadow:0 0 8px rgba(56,189,248,.6);opacity:0;
      transition:width .25s ease-out,opacity .2s ease}
    #pf-load.on{opacity:1}
    @media (prefers-reduced-motion:reduce){#pf-load{transition:opacity .15s ease}}
  </style>
</head>
<body>
<div id="pf-load" role="presentation"></div>
<?php if ($embed): ?>
  <style>#splash,.sidebar,.topbar,.nav-backdrop{display:none!important}
    .content{margin-left:0!important}.page{padding:1rem 1.2rem!important;max-width:none!important}</style>
<?php endif; ?>
  <div id="splash" class="splash">
    <div class="splash-inner">
      <img class="splash-logo" src="/assets/bastion-icon.svg" alt="Bastion">
      <div class="splash-title">Bastion</div>
      <div class="splash-sub">Console d'administration</div>
      <div class="splash-bar"><span></span></div>
    </div>
  </div>
  <script>if(sessionStorage.getItem('pf_splash')){var s=document.getElementById('splash');if(s)s.style.display='none';}
    try{if(localStorage.getItem('pf_rail')==='1')document.documentElement.classList.add('rail');}catch(e){}</script>
  <div class="nav-backdrop" id="navBackdrop"></div>
  <aside class="sidebar">
    <!-- Le logo est posé dans un écusson plutôt que nu : sur un fond sombre, une icône
         sans support se lit comme une image oubliée là. Le dégradé lui donne un socle. -->
    <div class="brand">
      <span class="brand-shield"><img class="logo" src="/assets/bastion-icon.svg" alt="Bastion"></span>
      <span class="btxt">Bastion<small>Administration</small></span>
    </div>
    <nav>
      <?php
      // Filtrage du menu selon le rôle. « comptes » ne voit que la gestion des comptes/agents.
      // « lecture » et « full » voient tout (la lecture seule est appliquée à l'écriture, pas à
      // la navigation). Défini côté serveur dans inc/auth.php — garde-fou : admin = full.
      $pfRole = $_SESSION['admin_role'] ?? 'full';
      $pfAllow = ($pfRole === 'comptes') ? ['index.php', 'users.php', 'annuaire.php', 'groups.php'] : null;

      // Anomalies à signaler, et pages les plus ouvertes par cet administrateur.
      $pfBadges = nav_badges();
      nav_freq_note($active, (string) $admin);
      $pfFreq = nav_freq_top((string) $admin);

      // Index à plat : sert au bloc « Fréquentes », qui doit retrouver le libellé
      // et l'icône d'une page sans savoir dans quel groupe elle se trouve.
      $pfPlat = [];
      foreach ($navGroups as $gN => $its) { foreach ($its as $ff => $dd) { $pfPlat[$ff] = $dd; } }

      /** Une entrée du menu, avec sa pastille éventuelle. */
      $pfLien = function (string $file, array $d, string $active, array $badges, string $suffixe = '') {
          $b = $badges[$file] ?? null;
          $syn = $d[2] ?? '';
          // « data-r » alimente la recherche : libellé + synonymes, sans accents,
          // pour que « securite » trouve « Santé & sécurité ».
          $r = $d[0] . ' ' . $syn;
          $r = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($r, 'UTF-8')) ?: mb_strtolower($r, 'UTF-8');
          echo '<a href="/' . $file . $suffixe . '" data-r="' . e(strtolower($r)) . '"'
             . ' title="' . e($b ? $d[0] . ' — ' . $b['txt'] : $d[0]) . '"'
             . ' class="' . ($active === $file ? 'active' : '') . '">'
             . '<span class="ico">' . $d[1] . '</span><span class="lbl">' . e($d[0]) . '</span>'
             . ($b ? '<span class="nav-dot ' . $b['lvl'] . '" aria-label="anomalie"></span>' : '')
             . '</a>';
      };
      ?>
      <?php
      // Le champ de recherche a quitté la barre latérale pour la barre du HAUT : il y
      // reste visible quand le menu est replié en rail, et il propose désormais ses
      // résultats en liste déroulante plutôt qu'en filtrant le menu sous les yeux.
      // Les liens ci-dessous restent la SOURCE de cette liste (href, icône, libellé,
      // synonymes de data-r) : une seule définition, donc rien à resynchroniser.
      ?>

      <?php
      // ── FRÉQUENTES ──────────────────────────────────────────────────────────
      // Masquées pendant une recherche : elles feraient doublon avec les résultats
      // et brouilleraient le comptage de ce qui correspond.
      $pfFreqVis = array_values(array_filter($pfFreq,
          fn($p) => isset($pfPlat[$p]) && ($pfAllow === null || in_array($p, $pfAllow, true))));
      if (count($pfFreqVis) >= 2): ?>
        <div class="nav-group-label nav-freq">Fréquentes</div>
        <div class="nav-freq">
          <?php foreach ($pfFreqVis as $ff) { $pfLien($ff, $pfPlat[$ff], $active, $pfBadges); } ?>
        </div>
      <?php endif; ?>

      <?php foreach ($navGroups as $groupName => $items):
          $vis = $pfAllow === null ? $items : array_intersect_key($items, array_flip($pfAllow));
          if (!$vis) { continue; }
      ?>
        <div class="nav-group-label" data-grp="1"><?= e($groupName) ?></div>
        <?php foreach ($vis as $file => $d) { $pfLien($file, $d, $active, $pfBadges); } ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <?php
      // ── ÉTAT DU SYSTÈME, EN BAS DU MENU ────────────────────────────────────
      // Repris de DockPolice, mais branché sur des données RÉELLES : le compte des
      // anomalies que la console connaît déjà (nav_badges), et non une pastille
      // verte écrite en dur. Un voyant qui affiche « opérationnel » quoi qu'il
      // arrive est pire que pas de voyant — il apprend à ne plus le regarder.
      $pfNbDanger = 0; $pfNbWarn = 0;
      foreach ($pfBadges as $b) { if (($b['lvl'] ?? '') === 'danger') { $pfNbDanger++; } else { $pfNbWarn++; } }
      $pfEtat = $pfNbDanger ? 'danger' : ($pfNbWarn ? 'warn' : 'ok');
      $pfEtatTxt = $pfNbDanger
          ? $pfNbDanger . ' panne' . ($pfNbDanger > 1 ? 's' : '')
          : ($pfNbWarn ? $pfNbWarn . ' à surveiller' : 'Système opérationnel');
      ?>
      <a class="sys-state <?= $pfEtat ?>" href="/securite.php"
         title="<?= $pfEtat === 'ok' ? 'Aucune anomalie détectée' : 'Voir le détail sur Santé & sécurité' ?>">
        <span class="sys-dot"></span><span class="sys-txt"><?= e($pfEtatTxt) ?></span>
      </a>
      <button type="button" class="rail-toggle" id="railToggle" title="Réduire / agrandir le menu" aria-label="Réduire ou agrandir le menu">
        <span class="rt-lbl">« Réduire</span><span class="rt-open">»</span>
      </button>
      <!-- Pastille de version : la question « quelle version tourne ici ? » se pose à
           chaque appel d'assistance, et la réponse était enfouie dans « À propos ». -->
      <a class="version-pill" href="/apropos.php" title="Version, licence et auteur de Bastion">
        <span class="vp-tag">v<?= e(BASTION_VERSION) ?></span>
        <span class="vp-link">En savoir +</span>
        <span class="vp-arrow" aria-hidden="true">→</span>
      </a>
      <div class="credit" style="font-size:.68rem;color:var(--muted);opacity:.75;line-height:1.4">
        Bastion — © 2026 Mickaël MONESTIER<br>Mle 110.480 · Tous droits réservés
      </div>
    </div>
  </aside>
  <main class="content">
    <header class="topbar">
      <div class="tb-left">
        <button type="button" class="nav-toggle" id="navToggle" aria-label="Ouvrir le menu">☰</button>
        <h1><?= e($title) ?></h1>
      </div>

      <!-- ── RECHERCHE, DANS LA BARRE DU HAUT ──────────────────────────────────
           Elle vivait dans la barre latérale, donc elle disparaissait dès que le
           menu était replié en rail — au moment précis où retrouver une page est
           le plus utile. Ici elle reste visible en permanence.

           C'est un vrai FORMULAIRE, avec une action : sans JavaScript, la touche
           Entrée mène tout de même à la recherche globale. La liste déroulante
           est un confort ajouté par-dessus, jamais le seul chemin. -->
      <form class="tb-search" id="navForm" method="get" action="/chercher.php" role="search">
        <span class="ts-ico" aria-hidden="true">🔎</span>
        <input type="search" id="navQ" name="q" autocomplete="off"
               placeholder="Rechercher une page, un agent, un poste…"
               aria-label="Rechercher dans la console" aria-expanded="false" aria-controls="navPop">
        <span class="ts-kbd" aria-hidden="true">Ctrl+K</span>
        <div class="ts-pop" id="navPop" role="listbox" hidden></div>
      </form>

      <?php
      // Pastille d'état : même source que le voyant du menu (nav_badges), donc les
      // deux ne peuvent pas se contredire. Elle mène à la page qui explique.
      ?>
      <a class="state-pill <?= $pfEtat ?>" href="/securite.php"
         title="<?= $pfEtat === 'ok' ? 'Aucune anomalie détectée' : 'Voir le détail sur Santé & sécurité' ?>">
        <span class="sp-ico" aria-hidden="true"><?= $pfEtat === 'ok' ? '✓' : '!' ?></span>
        <span class="sp-txt"><?= e($pfEtatTxt) ?></span>
      </a>

      <?php
      // Photo de profil chargée dans la session à la connexion (et à chaque changement).
      // Absente = on retombe sur l'initiale. La version « ?v= » invalide le cache du
      // navigateur dès que la photo change.
      $av = $_SESSION['avatar_v'] ?? null;
      $ini = e(strtoupper(substr($admin, 0, 1)));
      $avImg = $av ? '<img class="uavatar" src="/avatar.php?v=' . e($av) . '" alt="">' : '<span class="uavatar">' . $ini . '</span>';
      $avImgSm = $av ? '<img class="uavatar sm" src="/avatar.php?v=' . e($av) . '" alt="">' : '<span class="uavatar sm">' . $ini . '</span>';
      ?>
      <div class="usermenu" id="usermenu">
        <button type="button" class="userbtn" id="userbtn" aria-haspopup="true" aria-expanded="false">
          <?= $avImg ?>
          <span class="uwho">
            <span class="uname"><?= e($admin) ?></span>
            <span class="urole"><?php
              $r = $_SESSION['admin_role'] ?? 'full';
              echo e($r === 'lecture' ? 'Lecture seule' : ($r === 'comptes' ? 'Gestion des comptes' : 'Administrateur'));
            ?></span>
          </span>
          <span class="ucaret">▾</span>
        </button>
        <div class="usermenu-pop" id="userpop" role="menu">
          <div class="usermenu-hd"><?= $avImgSm ?>
            <div><strong><?= e($admin) ?></strong><br><small class="muted">Administrateur</small></div></div>
          <a href="/profil.php" role="menuitem"><span class="ico">👤</span>Mon profil &amp; sécurité</a>
          <div class="usermenu-sep"></div>
          <a href="/power.php?a=reboot" role="menuitem" class="mi-warn"><span class="ico">🔄</span>Redémarrer le serveur</a>
          <a href="/power.php?a=shutdown" role="menuitem" class="mi-danger"><span class="ico">⏻</span>Arrêter le serveur</a>
          <div class="usermenu-sep"></div>
          <a href="/logout.php" role="menuitem" class="mi-logout"><span class="ico">🚪</span>Se déconnecter</a>
        </div>
      </div>
    </header>
    <?php if (($_SESSION['admin_role'] ?? 'full') === 'lecture'): ?>
      <div class="flash" style="margin:0 0 1rem;background:rgba(234,179,8,.12);color:#eab308;border:1px solid rgba(234,179,8,.3)">👁️ Compte en <strong>lecture seule</strong> — consultation autorisée, modifications désactivées.</div>
    <?php endif; ?>
    <div class="page">
    <?php
}

function pf_footer(): void {
    $embed = ($_GET['embed'] ?? '') === '1';
    ?>
    </div>
  </main>
  <script>
    // ── Chiffres animés, pour TOUTE la console ────────────────────────────────
    // Un élément portant « data-num » compte de 0 jusqu'à sa valeur à l'affichage.
    //
    // Le mécanisme vit ICI et non dans chaque page : le tableau de bord avait le
    // sien, et le recopier ailleurs aurait donné des animations qui divergent —
    // durées différentes, arrondis différents, et un jour l'une qui casse sans
    // qu'on s'en aperçoive sur les autres.
    //
    // Le texte déjà rendu par le serveur sert de MODÈLE : on ne réécrit que les
    // chiffres et on garde ce qu'il y a autour (« 1 145 Mo », « 8 sur 16 »). Une
    // page qui n'aurait pas de JavaScript affiche donc la bonne valeur, tout de
    // suite : l'animation est un ornement, jamais la source de l'information.
    (function () {
      var REDUIT = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
      var cibles = document.querySelectorAll('[data-num]');
      if (!cibles.length) { return; }

      cibles.forEach(function (el) {
        var modele = el.textContent;
        var fin = parseFloat(String(el.dataset.num).replace(',', '.'));
        if (!isFinite(fin)) { return; }
        // Autant de décimales que la valeur d'origine en portait.
        var dec = (String(el.dataset.num).split(/[.,]/)[1] || '').length;
        if (REDUIT) { return; }

        var t0 = null, duree = 700;
        function ecrire(v) {
          var n = v.toLocaleString('fr-FR', { minimumFractionDigits: dec, maximumFractionDigits: dec });
          // On remplace le PREMIER nombre du texte, en gardant unités et suffixes.
          el.textContent = modele.replace(/[0-9][0-9  .,]*/, n);
        }
        function pas(t) {
          if (t0 === null) { t0 = t; }
          var p = Math.min(1, (t - t0) / duree);
          ecrire(fin * (1 - Math.pow(1 - p, 3)));   // ralentit en fin de course
          if (p < 1) { requestAnimationFrame(pas); }
          else { el.textContent = modele; }         // on rétablit le rendu serveur, au caractère près
        }
        ecrire(0);
        requestAnimationFrame(pas);
      });
    })();

    // ── Barre de chargement, en haut de l'écran ───────────────────────────────
    (function () {
      var b = document.getElementById('pf-load');
      if (!b) { return; }
      var t = null, p = 0;

      function demarrer() {
        if (t) { return; }                  // déjà en route : un second clic ne relance pas
        p = 8; b.classList.add('on'); b.style.width = p + '%';
        // On progresse par pas DÉCROISSANTS et l'on plafonne à 92 %. Atteindre 100 %
        // serait mentir : la page n'est pas prête, et une barre pleine qui stagne
        // inquiète davantage qu'une barre qui avance encore.
        t = setInterval(function () {
          p += Math.max(0.4, (92 - p) / 12);
          if (p > 92) { p = 92; }
          b.style.width = p + '%';
        }, 220);
      }
      function arreter() {
        if (t) { clearInterval(t); t = null; }
        b.style.width = '0'; b.classList.remove('on');
      }

      document.addEventListener('click', function (ev) {
        var a = ev.target.closest && ev.target.closest('a');
        if (!a || ev.defaultPrevented) { return; }
        // Ce qui ne provoque PAS de navigation dans cet onglet : nouvel onglet,
        // téléchargement, ancre interne, protocole autre, clic modifié (ctrl/cmd
        // ouvre en arrière-plan), ou bouton du milieu.
        if (a.target === '_blank' || a.hasAttribute('download')) { return; }
        if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || ev.button !== 0) { return; }
        var h = a.getAttribute('href') || '';
        if (h === '' || h.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(h)) { return; }
        if (a.origin && a.origin !== location.origin) { return; }   // site externe
        demarrer();
      }, true);

      document.addEventListener('submit', function (ev) {
        var f = ev.target;
        if (ev.defaultPrevented || (f && f.target === '_blank')) { return; }
        demarrer();
      }, true);

      // Retour par le bouton « Précédent » : le navigateur restaure la page telle
      // qu'elle était, barre comprise — elle resterait figée à 92 % sans ceci.
      window.addEventListener('pageshow', arreter);
      // Navigation annulée (échappement, téléchargement déclenché à la place) :
      // sans cela la barre resterait indéfiniment à l'écran.
      window.addEventListener('focus', function () { setTimeout(arreter, 1200); });
    })();

    // Splashscreen : affiché une fois par session, fondu à la fin du chargement.
    (function(){
      var s=document.getElementById('splash'); if(!s||s.style.display==='none') return;
      function done(){ setTimeout(function(){ s.classList.add('hide'); sessionStorage.setItem('pf_splash','1');
        setTimeout(function(){ if(s.parentNode) s.parentNode.removeChild(s); },650); }, 650); }
      if(document.readyState==='complete') done(); else window.addEventListener('load',done);
    })();
    (function(){
      var m=document.getElementById('usermenu'), b=document.getElementById('userbtn');
      if(!m||!b) return;
      b.addEventListener('click',function(e){
        e.stopPropagation();
        var open=m.classList.toggle('open');
        b.setAttribute('aria-expanded', open?'true':'false');
      });
      document.addEventListener('click',function(e){ if(!m.contains(e.target)) m.classList.remove('open'); });
      document.addEventListener('keydown',function(e){ if(e.key==='Escape') m.classList.remove('open'); });
    })();
    // Menu latéral : repli en rail (desktop, mémorisé) + tiroir (mobile).
    (function(){
      var root=document.documentElement;
      var rt=document.getElementById('railToggle');
      if(rt) rt.addEventListener('click',function(){
        var on=root.classList.toggle('rail');
        try{ localStorage.setItem('pf_rail', on?'1':'0'); }catch(e){}
      });
      var nt=document.getElementById('navToggle'), bd=document.getElementById('navBackdrop');
      function closeNav(){ root.classList.remove('nav-open'); }
      if(nt) nt.addEventListener('click',function(e){ e.stopPropagation(); root.classList.toggle('nav-open'); });
      if(bd) bd.addEventListener('click',closeNav);
      document.addEventListener('keydown',function(e){ if(e.key==='Escape') closeNav(); });
      document.querySelectorAll('.sidebar nav a').forEach(function(a){ a.addEventListener('click',closeNav); });

    // ── RECHERCHE, EN LISTE DÉROULANTE ────────────────────────────────────────
    // 27 destinations : les parcourir de l'oeil est plus lent que d'en taper trois
    // lettres. La recherche porte aussi sur des SYNONYMES (data-r) — « inventaire »
    // doit trouver « Parc informatique », que personne n'appelle ainsi de tête.
    //
    // Elle filtrait auparavant le menu lui-même. Deux défauts : le menu sautait sous
    // les yeux pendant la frappe, et la recherche disparaissait avec lui dès que le
    // menu était replié en rail — au moment précis où retrouver une page sert le
    // plus. Les résultats s'affichent désormais sous le champ, et le menu ne bouge
    // plus.
    //
    // L'INDEX EST LE MENU. On le lit dans le DOM au lieu de le redéclarer en
    // JavaScript : une page ajoutée à la navigation apparaît donc dans la recherche
    // sans qu'on ait à y penser. Deux listes auraient divergé au premier ajout.
    (function () {
      var q = document.getElementById('navQ');
      var pop = document.getElementById('navPop');
      var form = document.getElementById('navForm');
      if (!q || !pop || !form) { return; }

      function plat(v) {
        v = (v || '').toLowerCase();
        if (v.normalize) { v = v.normalize('NFD').replace(/[̀-ͯ]/g, ''); }
        // Les apostrophes sont retirées AUSSI, et pas seulement celles du texte :
        // « data-r » est translittéré côté serveur par iconv, dont le résultat
        // dépend de la bibliothèque C de l'hôte. Debian rend « é » par « e » —
        // d'autres rendent « 'e ». Sur celles-là, « securite » cesserait de
        // trouver « Santé & sécurité », sans message ni trace : la recherche
        // aurait simplement l'air de ne rien connaître. Comme la même mise à plat
        // s'applique à la saisie et à l'index, les retirer ne coûte rien.
        return v.replace(/['’]/g, '');
      }

      // Dédoublonnage par adresse : le bloc « Fréquentes » reprend des liens qui
      // figurent aussi dans leur groupe, et la même page sortirait deux fois.
      var vus = {}, index = [];
      Array.prototype.forEach.call(document.querySelectorAll('.sidebar nav a'), function (a) {
        var h = a.getAttribute('href') || '';
        if (!h || vus[h]) { return; }
        vus[h] = 1;
        var ico = a.querySelector('.ico'), lbl = a.querySelector('.lbl');
        index.push({
          href: h,
          ico: ico ? ico.textContent : '',
          lbl: lbl ? lbl.textContent : a.textContent.trim(),
          r: plat(a.getAttribute('data-r') || a.textContent)
        });
      });

      var sel = -1, lignes = [];

      function fermer() {
        pop.hidden = true;
        while (pop.firstChild) { pop.removeChild(pop.firstChild); }
        lignes = []; sel = -1;
        q.setAttribute('aria-expanded', 'false');
      }

      function marquer(i) {
        if (!lignes.length) { return; }
        // Modulo : arrivé en bas, la flèche revient en haut plutôt que de ne plus
        // rien faire — une liste qui cesse de répondre passe pour cassée.
        sel = (i + lignes.length) % lignes.length;
        lignes.forEach(function (el, k) { el.classList.toggle('on', k === sel); });
        if (lignes[sel].scrollIntoView) { lignes[sel].scrollIntoView({ block: 'nearest' }); }
      }

      // textContent partout, jamais innerHTML : la saisie de l'administrateur est
      // réaffichée dans la liste, et elle ne doit pas pouvoir écrire dans la page.
      function ligne(href, ico, lbl, note, cls) {
        var a = document.createElement('a');
        a.href = href;
        a.className = 'ts-row' + (cls ? ' ' + cls : '');
        a.setAttribute('role', 'option');
        var i = document.createElement('span'); i.className = 'ts-ri'; i.textContent = ico;
        var t = document.createElement('span'); t.className = 'ts-rt'; t.textContent = lbl;
        a.appendChild(i); a.appendChild(t);
        if (note) {
          var n = document.createElement('span'); n.className = 'ts-rn'; n.textContent = note;
          a.appendChild(n);
        }
        pop.appendChild(a); lignes.push(a);
        return a;
      }

      function note(txt) {
        var d = document.createElement('div');
        d.className = 'ts-more'; d.textContent = txt;
        pop.appendChild(d);
      }

      function rendre() {
        var brut = q.value.trim();
        if (brut === '') { fermer(); return; }
        var t = plat(brut);
        while (pop.firstChild) { pop.removeChild(pop.firstChild); }
        lignes = []; sel = -1;

        // Huit pages au plus : au-delà la liste dépasse l'écran et l'on ne choisit
        // plus, on relit. Ce qui est écarté est ANNONCÉ — une liste tronquée en
        // silence se lit comme une liste complète.
        var trouves = index.filter(function (e) { return e.r.indexOf(t) >= 0; });
        trouves.slice(0, 8).forEach(function (e) { ligne(e.href, e.ico, e.lbl, 'Page'); });
        if (trouves.length > 8) { note('+ ' + (trouves.length - 8) + ' autre(s) page(s) — précisez'); }
        if (!trouves.length) { note('Aucune page de la console ne porte ce nom.'); }

        // La recherche globale est TOUJOURS proposée, même quand des pages
        // correspondent : chercher un agent dont le nom ressemble à celui d'une page
        // est courant, et n'offrir le relais qu'en cas d'échec obligerait à vider le
        // champ pour l'obtenir.
        ligne('/chercher.php?q=' + encodeURIComponent(brut), '🔎',
              'Chercher « ' + brut +' » partout', 'Agents, postes, adresses', 'ts-all');

        pop.hidden = false;
        q.setAttribute('aria-expanded', 'true');
        marquer(0);
      }

      q.addEventListener('input', rendre);
      q.addEventListener('focus', function () { if (q.value.trim() !== '') { rendre(); } });

      q.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); marquer(sel + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); marquer(sel - 1); }
        else if (e.key === 'Escape') { q.value = ''; fermer(); q.blur(); }
        else if (e.key === 'Enter') {
          // Une ligne est surlignée : on l'ouvre. Sinon on LAISSE le formulaire
          // partir vers la recherche globale — c'est aussi ce qui se produit quand
          // JavaScript n'a pas pu s'exécuter, et la touche Entrée fait alors
          // toujours quelque chose.
          if (sel >= 0 && lignes[sel]) { e.preventDefault(); location.href = lignes[sel].getAttribute('href'); }
        }
      });

      document.addEventListener('click', function (e) { if (!form.contains(e.target)) { fermer(); } });

      document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
          e.preventDefault();
          q.focus(); q.select();
        }
      });
    })();
    })();
    // Popup « mise à jour disponible ». Une fois par session, hors page Système, en
    // ASYNCHRONE : elle ne retarde jamais l'affichage. On lit l'état déjà rafraîchi par la
    // recherche quotidienne (minuterie) — aucune interrogation réseau n'est déclenchée ici.
    (function(){
      if(location.pathname.indexOf('systeme.php')!==-1) return;
      if(new URLSearchParams(location.search).get('embed')==='1') return;   // pas de toast dans un onglet embarqué
      try{ if(sessionStorage.getItem('pf_maj_shown')) return; }catch(e){}
      fetch('systeme.php?apt=gitstate',{cache:'no-store'})
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(s){
          if(!s || !s.clone) return;
          var retard = parseInt(s.retard,10)||0;
          if(retard < 1) return;
          try{ sessionStorage.setItem('pf_maj_shown','1'); }catch(e){}
          var box=document.createElement('div'); box.className='maj-toast'; box.setAttribute('role','status');
          var h=document.createElement('h4'); h.appendChild(document.createTextNode('🔔 Mise à jour disponible'));
          var p=document.createElement('p');
          // textContent : la version vient de git (hash court) mais on n'injecte jamais de
          // HTML — une valeur d'état ne doit pas pouvoir écrire dans la page.
          p.textContent = 'Une nouvelle version de Bastion est disponible ('
            + retard + (retard>1?' versions':' version') + ' de retard'
            + (s.distant ? ', ' + s.distant : '') + '). Mettez à jour depuis la page Système.';
          var row=document.createElement('div'); row.className='row';
          var voir=document.createElement('a'); voir.className='btn-sm'; voir.href='/systeme.php'; voir.textContent='Voir la mise à jour';
          var tard=document.createElement('button'); tard.className='btn-sm'; tard.type='button'; tard.textContent='Plus tard';
          tard.addEventListener('click',function(){ box.remove(); });
          row.appendChild(voir); row.appendChild(tard);
          box.appendChild(h); box.appendChild(p); box.appendChild(row);
          document.body.appendChild(box);
        })
        .catch(function(){});
    })();
  </script>
<?php if ($embed): ?>
  <script>
  /* Onglet embarqué (iframe de journal.php) : garder « embed=1 » sur les navigations internes
     (formulaires + liens) pour que la barre latérale ne réapparaisse pas dans l'onglet. Les
     téléchargements (export CSV) et les liens externes / _blank sont laissés intacts. */
  (function () {
    document.querySelectorAll('form').forEach(function (f) {
      if (!f.querySelector('input[name=embed]')) {
        var i = document.createElement('input'); i.type = 'hidden'; i.name = 'embed'; i.value = '1'; f.appendChild(i);
      }
    });
    document.querySelectorAll('a[href]').forEach(function (a) {
      var h = a.getAttribute('href');
      if (!h || /^(https?:|mailto:|tel:|#|javascript:)/i.test(h) || a.hasAttribute('download') || a.target === '_blank') return;
      if (h.indexOf('embed=') < 0) { a.setAttribute('href', h + (h.indexOf('?') >= 0 ? '&' : '?') + 'embed=1'); }
    });
  })();
  </script>
<?php endif; ?>
</body>
</html>
    <?php
}

function pf_flash(string $msg, string $type = 'ok'): void {
    echo '<div class="flash ' . e($type) . '">' . e($msg) . '</div>';
}
