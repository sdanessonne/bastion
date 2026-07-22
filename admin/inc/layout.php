<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — en-tête / navigation / pied de page communs. */

function pf_header(string $title, string $active = ''): void {
    // Navigation groupée par domaine fonctionnel.
    $navGroups = [
        'Supervision' => [
            'index.php'    => ['Tableau de bord', '▚'],
            'services.php' => ['Services', '🧰'],
            'sauvegarde.php' => ['Sauvegarde', '💾'],
            'systeme.php'  => ['Système', '🖥'],
        ],
        'Accès & sécurité' => [
            'users.php'     => ['Utilisateurs & droits', '👤'],
            'groups.php'    => ['Groupes & quotas', '⚙'],
            'filter.php'    => ['Filtrage', '⛔'],
            'antivirus.php' => ['Antivirus', '🛡️'],
        ],
        'Réseau & postes' => [
            'ad.php'   => ['Active Directory', '🗄️'],
            'apps.php' => ['Store d\'applications', '🏪'],
            'pxe.php'  => ['Serveur PXE', '📀'],
        ],
        'Intranet' => [
            'intranet.php'   => ['Portail intranet', '🏠'],
            'cms.php'        => ['Pages & actualités', '📝'],
            'assistance.php' => ['Assistance', '📨'],
        ],
        'Journalisation' => [
            'recherche.php'   => ['Recherche agent', '🔎'],
            'weblog.php'      => ['Navigation', '🌐'],
            'logs.php'        => ['Journaux légaux', '📄'],
            'requisition.php' => ['Réquisition', '⚖️'],
        ],
        'Aide' => [
            'aide.php'    => ['Aide', '❓'],
            'apropos.php' => ['En savoir +', 'ℹ️'],
        ],
    ];
    $admin = $_SESSION['admin'] ?? '';
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
</head>
<body>
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
      <?php foreach ($navGroups as $groupName => $items): ?>
        <div class="nav-group-label"><?= e($groupName) ?></div>
        <?php foreach ($items as $file => [$label, $icon]): ?>
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
    <div class="page">
    <?php
}

function pf_footer(): void {
    ?>
    </div>
  </main>
  <script>
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
</body>
</html>
    <?php
}

function pf_flash(string $msg, string $type = 'ok'): void {
    echo '<div class="flash ' . e($type) . '">' . e($msg) . '</div>';
}
