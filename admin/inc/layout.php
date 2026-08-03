<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — en-tête / navigation / pied de page communs. */

function pf_header(string $title, string $active = ''): void {
    // Navigation groupée par domaine fonctionnel.
    $navGroups = [
        'Supervision' => [
            'index.php'    => ['Tableau de bord', '▚'],
            'reseau.php'   => ['Trafic réseau', '📡'],
            'securite.php' => ['Santé & sécurité', '🩺'],
            'rapport.php'  => ['Rapport de conformité', '📊'],
            'services.php' => ['Services', '🧰'],
            'sauvegarde.php' => ['Sauvegarde', '💾'],
            'systeme.php'  => ['Système', '🖥'],
        ],
        'Accès & sécurité' => [
            'users.php'     => ['Utilisateurs & droits', '👤'],
            'annuaire.php'  => ['Annuaire', '📇'],
            'groups.php'    => ['Groupes & quotas', '⚙'],
            'filter.php'    => ['Filtrage', '⛔'],
            'antivirus.php' => ['Antivirus', '🛡️'],
            'chiffrement.php' => ['Chiffrement des postes', '🔐'],
            'visiteurs.php' => ['Accès visiteur', '🎟️'],
        ],
        'Réseau & postes' => [
            'ad.php'   => ['Active Directory', '🗄️'],
            'parc.php' => ['Parc informatique', '🗃️'],
            'dhcp.php' => ['Réservations DHCP', '🔌'],
            'quarantaine.php' => ['Quarantaine réseau', '🚫'],
            'vpn.php'  => ['Sortie par tunnel', '🔒'],
            'apps.php' => ['Store d\'applications', '🏪'],
            'pxe.php'  => ['Serveur PXE', '📀'],
        ],
        'Intranet' => [
            'cms.php'        => ['Portail intranet', '🏠'],
            'assistance.php' => ['Assistance', '📨'],
        ],
        'Journalisation' => [
            'journal.php' => ['Journalisation', '📄'],
        ],
        'Aide' => [
            'assistant.php' => ['Assistant de configuration', '🧭'],
            'aide.php'    => ['Aide', '❓'],
            'apropos.php' => ['En savoir +', 'ℹ️'],
        ],
    ];
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
    .topbar{display:flex;align-items:center;justify-content:space-between;gap:1rem}
    .usermenu{position:relative}
    .userbtn{display:flex;align-items:center;gap:.55rem;background:var(--panel2);border:1px solid var(--line);
             color:var(--text);padding:.4rem .7rem .4rem .45rem;border-radius:24px;cursor:pointer;font:inherit}
    .userbtn:hover{border-color:var(--accent)}
    img.uavatar{object-fit:cover;background:var(--accent2)}
    .uavatar{display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:var(--accent2);
             color:#052536;font-weight:700;font-size:.9rem;flex:0 0 auto}
    .uavatar.sm{width:34px;height:34px}
    .uname{font-size:.88rem;font-weight:500;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
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
    @media (max-width:640px){.uname{display:none}}
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
    <div class="brand"><img class="logo" src="/assets/bastion-icon.svg" alt="Bastion"><span class="btxt">Bastion<br><small>Administration</small></span></div>
    <nav>
      <?php
      // Filtrage du menu selon le rôle. « comptes » ne voit que la gestion des comptes/agents.
      // « lecture » et « full » voient tout (la lecture seule est appliquée à l'écriture, pas à
      // la navigation). Défini côté serveur dans inc/auth.php — garde-fou : admin = full.
      $pfRole = $_SESSION['admin_role'] ?? 'full';
      $pfAllow = ($pfRole === 'comptes') ? ['index.php', 'users.php', 'annuaire.php', 'groups.php'] : null;
      foreach ($navGroups as $groupName => $items):
          $vis = $pfAllow === null ? $items : array_intersect_key($items, array_flip($pfAllow));
          if (!$vis) { continue; }
      ?>
        <div class="nav-group-label"><?= e($groupName) ?></div>
        <?php foreach ($vis as $file => [$label, $icon]): ?>
          <a href="/<?= $file ?>" title="<?= e($label) ?>" class="<?= $active === $file ? 'active' : '' ?>">
            <span class="ico"><?= $icon ?></span><span class="lbl"><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <button type="button" class="rail-toggle" id="railToggle" title="Réduire / agrandir le menu" aria-label="Réduire ou agrandir le menu">
        <span class="rt-lbl">« Réduire</span><span class="rt-open">»</span>
      </button>
      <div class="credit" style="font-size:.68rem;color:var(--muted);opacity:.75;line-height:1.4">
        Bastion — © 2026 Mickaël MONESTIER<br>Mle 110.480 · Tous droits réservés
      </div>
    </div>
  </aside>
  <main class="content">
    <header class="topbar">
      <div style="display:flex;align-items:center;gap:.8rem;min-width:0">
        <button type="button" class="nav-toggle" id="navToggle" aria-label="Ouvrir le menu">☰</button>
        <h1><?= e($title) ?></h1>
      </div>
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
          <span class="uname"><?= e($admin) ?></span>
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
