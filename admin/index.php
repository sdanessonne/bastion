<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — tableau de bord : résumé + sessions en direct. */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/adcache.php';
require_once __DIR__ . '/inc/bienvenue.php';

// ── Action : déconnecter un client ───────────────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deauth') {
    csrf_check();
    $mac = (string) ($_POST['mac'] ?? '');
    if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $mac)) {
        shell_exec('sudo /usr/bin/ndsctl deauth ' . escapeshellarg($mac) . ' 2>/dev/null');
        nds_clients_flush();   // l'etat des clients vient de changer
        $flash = ['Client déconnecté.', 'ok'];
    }
    header('Location: /index.php?msg=deauth'); exit;
}

// ── Mesure de la ligne Internet ──────────────────────────────────────────────
$stFlash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'speedtest') {
    csrf_check();
    $r = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-speedtest run 2>&1'));
    $stFlash = str_starts_with($r, 'ERREUR') ? [$r, 'err']
        : ($r === 'deja-en-cours' ? ['Une mesure est déjà en cours.', 'warn']
           : ['Mesure lancée — la ligne va être saturée une vingtaine de secondes.', 'ok']);
}
$wan = json_decode(pf_cmd_cache('speedtest', 120, 'sudo /usr/local/sbin/proxyfibre-speedtest state 2>/dev/null'), true) ?: [];

$clients = nds_clients();
$authCount = 0; $totalDown = 0;
foreach ($clients as $c) {
    if (($c['state'] ?? '') === 'Authenticated') { $authCount++; }
    $totalDown += (int) ($c['download_this_session'] ?? 0);
}
$userCount = (int) pf_db()->query('SELECT COUNT(DISTINCT username) FROM radcheck')->fetchColumn();

// ── Ressources système (fonctions dans inc/config.php) ───────────────────────
$cpu      = sys_cpu_pct();
$mem      = sys_mem();
$diskSys  = sys_disk('/');
$diskData = sys_disk('/srv/pxe');
$cores    = max(1, (int) trim((string) shell_exec('nproc 2>/dev/null')));
$uptime   = trim((string) shell_exec('uptime -p 2>/dev/null'));

// ── Santé des services (un seul appel systemctl pour tous) ───────────────────
$SVC_VUE = [
    'opennds'           => 'Portail captif',
    'freeradius'        => 'Authentification',
    'mariadb'           => 'Base de données',
    'dnsmasq'           => 'DHCP / DNS',
    'samba-ad-dc'       => 'Active Directory',
    'proxyfibre-weblog' => 'Journal navigation',
    'apache2'           => 'Serveur web',
];
$svcState = sys_units_active(array_keys($SVC_VUE));
$alerts   = sys_alerts();

pf_header('Tableau de bord', 'index.php');
if (isset($_GET['msg'])) { pf_flash('Client déconnecté.', 'ok'); }
// Accueil de connexion : rendu UNE FOIS, puis consommé. Il n'apparaît donc pas en
// revenant au tableau de bord au cours de la même session.
echo bienvenue_afficher();
?>
<style>
  .alerts{display:grid;gap:.6rem;margin-bottom:1.2rem}
  .alert{display:flex;align-items:flex-start;gap:.75rem;padding:.85rem 1rem;border-radius:10px;
         border:1px solid;font-size:.94rem;line-height:1.45}
  .alert.danger{background:rgba(248,113,113,.10);border-color:rgba(248,113,113,.45)}
  .alert.warn  {background:rgba(234,179,8,.10); border-color:rgba(234,179,8,.42)}
  .alert-ico{font-size:1.1rem;line-height:1.3}
  .alert-txt{flex:1}
  .alert .btn-sm{flex:none;text-decoration:none}
  .svc-strip{display:flex;flex-wrap:wrap;gap:.5rem;padding:1rem 1.3rem}
  .svc-chip{display:inline-flex;align-items:center;gap:.45rem;padding:.4rem .75rem;border-radius:999px;
            background:var(--bg);border:1px solid var(--line);font-size:.86rem;text-decoration:none;color:inherit}
  .svc-chip:hover{border-color:#38bdf8}
  .dot{width:8px;height:8px;border-radius:50%;flex:none}
  .dot.ok{background:#4ade80;box-shadow:0 0 0 3px rgba(74,222,128,.16)}
  .dot.ko{background:#f87171;box-shadow:0 0 0 3px rgba(248,113,113,.16)}
  .dot.warn{background:#eab308;box-shadow:0 0 0 3px rgba(234,179,8,.16)}
  a.kpi{text-decoration:none;color:inherit;display:block;transition:border-color .15s,transform .15s}
  a.kpi:hover{border-color:#38bdf8;transform:translateY(-2px)}
  /* Chiffres à CHASSE FIXE pendant le défilement : sans cela, chaque glyphe a sa propre
     largeur et le compteur tremble à chaque image. tabular-nums fige la largeur des
     chiffres sans changer de police. */
  .kpi-val[data-kpi]{font-variant-numeric:tabular-nums;font-feature-settings:"tnum" 1}
  /* Éclat bref sur une valeur qui vient de changer en direct. */
  @keyframes kpiPulse{0%{color:#38bdf8;text-shadow:0 0 12px rgba(56,189,248,.55)}100%{color:#fff;text-shadow:none}}
  .kpi-val.kpi-pulse{animation:kpiPulse .9s ease-out}
  @media(prefers-reduced-motion:reduce){.kpi-val.kpi-pulse{animation:none}}
</style>

<!-- Anomalies d'abord : c'est ce qui doit sauter aux yeux en arrivant. -->
<section class="alerts" id="alerts">
<?php foreach ($alerts as $a): ?>
  <div class="alert <?= e($a['lvl']) ?>">
    <span class="alert-ico"><?= $a['lvl'] === 'danger' ? '⛔' : '⚠️' ?></span>
    <span class="alert-txt"><?= e($a['txt']) ?></span>
    <a class="btn-sm" href="<?= e($a['url']) ?>"><?= e($a['act']) ?></a>
  </div>
<?php endforeach; ?>
</section>

<!-- data-raw = valeur NUMÉRIQUE brute : le texte affiché est déjà mis en forme par le
     serveur (donc lisible même si le script échoue), mais l'animation a besoin du nombre
     pour interpoler. « down » est en octets : c'est le JS qui le met en forme. -->
<section class="cards">
  <a class="kpi" href="users.php"><div class="kpi-val" data-kpi="int" data-raw="<?= $userCount ?>"><?= $userCount ?></div><div class="kpi-lbl">Comptes</div></a>
  <a class="kpi" href="sessions.php"><div class="kpi-val" id="kpiAuth" data-kpi="int" data-raw="<?= $authCount ?>"><?= $authCount ?></div><div class="kpi-lbl">Connectés maintenant</div></a>
  <a class="kpi" href="sessions.php"><div class="kpi-val" id="kpiSeen" data-kpi="int" data-raw="<?= count($clients) ?>"><?= count($clients) ?></div><div class="kpi-lbl">Clients suivis</div></a>
  <a class="kpi" href="weblog.php"><div class="kpi-val" id="kpiDown" data-kpi="bytes" data-raw="<?= (int) $totalDown ?>"><?= fmtBytes($totalDown) ?></div><div class="kpi-lbl">Données (session)</div></a>
</section>

<section class="panel">
  <div class="panel-head"><h2>🛡️ État des services</h2>
    <a class="muted small" href="services.php" style="text-decoration:none">Tout gérer →</a></div>
  <div class="svc-strip" id="svcStrip">
    <?php foreach ($SVC_VUE as $unit => $nom):
      $st  = $svcState[$unit] ?? 'inconnu';
      $cls = $st === 'active' ? 'ok' : ($st === 'activating' ? 'warn' : 'ko');
      $lib = ['active' => 'actif', 'activating' => 'démarrage…', 'failed' => 'en échec'][$st] ?? 'arrêté';
    ?>
      <a class="svc-chip" href="services.php" data-unit="<?= e($unit) ?>" title="<?= e($unit) ?> — <?= e($lib) ?>">
        <span class="dot <?= $cls ?>"></span><?= e($nom) ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php
$barcol = fn(int $p) => $p < 70 ? '#4ade80' : ($p < 90 ? '#eab308' : '#f87171');
$resBlock = function (string $key, string $icon, string $label, int $pct, string $detail) use ($barcol) {
    ?>
    <div class="res" data-res="<?= $key ?>">
      <div class="res-top"><span><?= $icon ?> <?= e($label) ?></span><strong class="res-pct"><?= $pct ?> %</strong></div>
      <div class="bar"><div class="fill" style="width:<?= $pct ?>%;background:<?= $barcol($pct) ?>"></div></div>
      <div class="muted small res-detail" style="margin-top:.35rem"><?= $detail ?></div>
    </div>
    <?php
};
?>
<style>
  .res-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:1.2rem;padding:1.3rem}
  .res-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.55rem}
  .res-top span{font-weight:500} .res-top strong{font-size:1.35rem}
  .bar{height:9px;background:var(--bg);border-radius:6px;overflow:hidden}
  .bar .fill{height:100%;border-radius:6px;transition:width .5s ease}
</style>
<section class="panel">
  <div class="panel-head"><h2>🖥️ Ressources de la passerelle</h2>
    <span class="muted small"><?= e($uptime ?: '') ?></span></div>
  <div class="res-grid">
    <?php $resBlock('cpu', '🧠', 'Processeur', $cpu, $cores . ' cœur(s)'); ?>
    <?php $resBlock('mem', '💾', 'Mémoire (RAM)', $mem['pct'], fmtBytes($mem['used']) . ' / ' . fmtBytes($mem['total'])); ?>
    <?php if ($diskSys): $resBlock('disksys', '🗄️', 'Disque système', $diskSys['pct'], fmtBytes($diskSys['used']) . ' / ' . fmtBytes($diskSys['total']) . ' · ' . fmtBytes($diskSys['free']) . ' libres'); endif; ?>
    <?php if ($diskData): $resBlock('diskdata', '📀', 'Disque données (PXE)', $diskData['pct'], fmtBytes($diskData['used']) . ' / ' . fmtBytes($diskData['total']) . ' · ' . fmtBytes($diskData['free']) . ' libres'); endif; ?>
  </div>
  <div style="padding:0 1.3rem 1rem">
    <div class="muted small" style="margin-bottom:.35rem">Historique (session en cours) —
      <span style="color:#38bdf8">■</span> Processeur &nbsp; <span style="color:#4ade80">■</span> Mémoire</div>
    <canvas id="histChart" style="width:100%;height:64px;display:block"></canvas>
    <span class="muted small" id="resTs">en direct · mise à jour toutes les 5 s</span>
  </div>
</section>

<?php
// Débit WAN. Premier calcul côté serveur pour que la page ne s'ouvre pas sur deux zéros ;
// le sondage prend ensuite le relais.
$net0 = sys_net_rate();
?>
<style>
  .net-head{display:flex;gap:2.4rem;align-items:baseline;flex-wrap:wrap;padding:1.2rem 1.3rem .8rem}
  .net-val{font-size:1.75rem;font-weight:700;line-height:1;font-variant-numeric:tabular-nums;color:#fff}
  .net-lbl{color:var(--muted);font-size:.8rem;margin-top:.25rem}
  .net-fl{font-size:1.1rem;margin-right:.3rem}
</style>
<?php
$wDown = (int) ($wan['down'] ?? 0);
$wUp   = (int) ($wan['up'] ?? 0);
$wAt   = (int) ($wan['date'] ?? 0);
$wEnc  = !empty($wan['en_cours']);
// Part de la ligne occupée. N'a de sens QUE si la ligne a été mesurée : sans mesure,
// afficher un pourcentage supposerait une capacité qu'on ne connaît pas.
$pctDown = ($wDown > 0) ? min(100, (int) round(100 * $net0['down'] / $wDown)) : -1;
$pctUp   = ($wUp   > 0) ? min(100, (int) round(100 * $net0['up']   / $wUp))   : -1;
?>
<section class="panel">
  <div class="panel-head"><h2>📶 Débit Internet</h2>
    <span class="muted small">interface <?= e($net0['if']) ?> · en direct</span>
  </div>
  <?php if ($stFlash): ?>
    <div style="padding:0 1.3rem"><div class="flash <?= e($stFlash[1]) ?>"><?= e($stFlash[0]) ?></div></div>
  <?php endif; ?>
  <div class="net-head">
    <div>
      <div class="net-val"><span class="net-fl" style="color:#38bdf8">▼</span><span id="netDown"><?= e(fmtBytes($net0['down'])) ?>/s</span></div>
      <div class="net-lbl">Descendant — ce que les postes téléchargent
        <span id="netPctD"><?= $pctDown >= 0 ? '· <strong>' . $pctDown . ' %</strong> de la ligne' : '' ?></span></div>
    </div>
    <div>
      <div class="net-val"><span class="net-fl" style="color:#a78bfa">▲</span><span id="netUp"><?= e(fmtBytes($net0['up'])) ?>/s</span></div>
      <div class="net-lbl">Montant — ce qui part vers Internet
        <span id="netPctU"><?= $pctUp >= 0 ? '· <strong>' . $pctUp . ' %</strong> de la ligne' : '' ?></span></div>
    </div>
    <div style="margin-left:auto;text-align:right">
      <div class="muted small" id="netPeak">crête : —</div>
      <div class="muted small" id="netAvg">moyenne : —</div>
    </div>
  </div>

  <!-- Capacité de la ligne. La passerelle ne peut PAS la deviner : /sys/.../speed donne
       la vitesse du lien Ethernet vers la box (une fibre et un ADSL y affichent la même
       valeur), et la box n'expose rien de standard. Seule une mesure réelle répond. -->
  <div style="padding:0 1.3rem 1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
    <div class="muted small" style="line-height:1.7;flex:1;min-width:16rem">
      <?php if ($wAt > 0): ?>
        <strong>Capacité mesurée de la ligne</strong> —
        ▼ <strong style="color:#fff"><?= e(fmtBytes($wDown)) ?>/s</strong>
        (<?= number_format($wDown * 8 / 1e6, 1, ',', ' ') ?> Mbit/s)
        &nbsp;·&nbsp; ▲ <strong style="color:#fff"><?= e(fmtBytes($wUp)) ?>/s</strong>
        (<?= number_format($wUp * 8 / 1e6, 1, ',', ' ') ?> Mbit/s)
        <br>mesurée le <?= e(date('d/m/Y à H:i', $wAt)) ?>
        <?php if (!empty($wan['erreur'])): ?><br><span style="color:#eab308">⚠️ <?= e($wan['erreur']) ?></span><?php endif; ?>
      <?php else: ?>
        <strong>Capacité de la ligne : inconnue.</strong> La passerelle ne peut pas la deviner —
        le lien Ethernet vers la box affiche la même vitesse qu'il s'agisse d'une fibre ou d'un ADSL.
        Lancez une mesure pour situer le débit ci-dessus par rapport à ce que la ligne encaisse.
      <?php endif; ?>
    </div>
    <form method="post" style="margin:0" onsubmit="return confirm('Mesurer la ligne ?\n\nLe test SATURE délibérément la ligne pendant une vingtaine de secondes, dans les deux sens.\nLes postes connectés en pâtiront le temps du test.\n\nÀ éviter aux heures de service.')">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="speedtest">
      <button class="btn-sm" id="stBtn" <?= $wEnc ? 'disabled' : '' ?>>
        <?= $wEnc ? 'Mesure en cours…' : ($wAt > 0 ? '↻ Remesurer la ligne' : 'Mesurer la ligne') ?>
      </button>
    </form>
  </div>
  <div style="padding:0 1.3rem 1.2rem">
    <canvas id="netChart" style="width:100%;height:96px;display:block"></canvas>
    <span class="muted small">5 dernières minutes · <span style="color:#38bdf8">■</span> descendant
      &nbsp;<span style="color:#a78bfa">■</span> montant</span>
  </div>
</section>
<script>
(function(){
  function col(p){return p<70?'#4ade80':(p<90?'#eab308':'#f87171');}

  // ══════════════ Compteurs animés ══════════════
  var REDUIT = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Reproduit fmtBytes() de inc/config.php À L'IDENTIQUE (unités françaises, virgule
  // décimale, espace pour les milliers). Tout écart ferait sauter le chiffre au
  // chargement, quand le JS reprend la valeur déjà rendue par le serveur.
  function fmtOctets(n){
    var u=['o','Ko','Mo','Go','To'], i=0; n=+n||0;
    while(n>=1024 && i<u.length-1){ n/=1024; i++; }
    var s=n.toFixed(i?1:0).split('.');
    s[0]=s[0].replace(/\B(?=(\d{3})+(?!\d))/g,' ');
    return s.join(',')+' '+u[i];
  }
  function fmtEntier(n){
    return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g,' ');
  }
  function fmtDe(el){ return el.dataset.kpi==='bytes' ? fmtOctets : fmtEntier; }

  function animeVers(el, to, flash){
    if(!el) return;
    var from = parseFloat(el.dataset.raw||'0')||0;
    to = +to||0;
    var fmt = fmtDe(el);
    el.dataset.raw = to;
    if(from===to){ el.textContent = fmt(to); return; }
    if(REDUIT){ el.textContent = fmt(to); if(flash) pulse(el); return; }
    // Durée fixe : un compteur qui passe de 0 à 3 doit prendre le même temps qu'un
    // autre qui passe de 0 à 12 000, sinon les cartes ne s'achèvent pas ensemble.
    var t0=null, DUR=750;
    function pas(t){
      if(t0===null) t0=t;
      var p=Math.min(1,(t-t0)/DUR);
      var e=1-Math.pow(1-p,3);              // easeOutCubic : démarre vite, se pose doucement
      el.textContent = fmt(from+(to-from)*e);
      if(p<1) requestAnimationFrame(pas); else if(flash) pulse(el);
    }
    requestAnimationFrame(pas);
  }
  // Bref éclat quand une valeur change EN DIRECT : signale ce qui a bougé sans
  // obliger à comparer. Volontairement absent au chargement (tout « change » alors).
  function pulse(el){
    if(REDUIT) return;
    el.classList.remove('kpi-pulse'); void el.offsetWidth; el.classList.add('kpi-pulse');
  }

  // Au chargement : on repart de 0 vers la valeur rendue par le serveur.
  function demarrer(){
    document.querySelectorAll('.kpi-val[data-kpi]').forEach(function(el){
      var to = parseFloat(el.dataset.raw||'0')||0;
      el.dataset.raw = 0;
      animeVers(el, to, false);
    });
  }
  function upd(k,pct,detail){
    var el=document.querySelector('.res[data-res="'+k+'"]'); if(!el)return;
    var v=el.querySelector('.res-pct'); if(v)v.textContent=pct+' %';
    var f=el.querySelector('.fill'); if(f){f.style.width=pct+'%';f.style.background=col(pct);}
    if(detail){var d=el.querySelector('.res-detail'); if(d)d.textContent=detail;}
  }
  var histCPU=[], histRAM=[], MAXN=60;
  function drawHist(){
    var c=document.getElementById('histChart'); if(!c)return;
    var w=c.clientWidth||600, h=64; if(c.width!==w)c.width=w; c.height=h;
    var ctx=c.getContext('2d'); ctx.clearRect(0,0,w,h);
    ctx.strokeStyle='rgba(148,163,184,.15)'; ctx.lineWidth=1;
    [0.25,0.5,0.75].forEach(function(g){ctx.beginPath();ctx.moveTo(0,h*g);ctx.lineTo(w,h*g);ctx.stroke();});
    function line(arr,color){
      if(arr.length<2)return; ctx.beginPath(); ctx.strokeStyle=color; ctx.lineWidth=2; ctx.lineJoin='round';
      var n=arr.length;
      for(var i=0;i<n;i++){var x=w*(i/(n-1)); var y=h-(Math.max(0,Math.min(100,arr[i]))/100)*h; i?ctx.lineTo(x,y):ctx.moveTo(x,y);}
      ctx.stroke();
    }
    line(histRAM,'#4ade80'); line(histCPU,'#38bdf8');
  }
  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function updServices(sv){
    if(!sv)return;
    document.querySelectorAll('#svcStrip .svc-chip').forEach(function(chip){
      var st=sv[chip.dataset.unit]; if(!st)return;
      var cls=(st==='active')?'ok':((st==='activating')?'warn':'ko');
      var lib=({active:'actif',activating:'démarrage…',failed:'en échec'})[st]||'arrêté';
      var dot=chip.querySelector('.dot'); if(dot)dot.className='dot '+cls;
      chip.title=chip.dataset.unit+' — '+lib;
    });
  }
  function updAlerts(list){
    var box=document.getElementById('alerts'); if(!box||!list)return;
    // Ne reconstruire que si le contenu a changé : sinon on reflow la page toutes les 5 s.
    var sig=JSON.stringify(list); if(box.dataset.sig===sig)return; box.dataset.sig=sig;
    box.innerHTML=list.map(function(a){
      return '<div class="alert '+(a.lvl==='danger'?'danger':'warn')+'">'
           + '<span class="alert-ico">'+(a.lvl==='danger'?'⛔':'⚠️')+'</span>'
           + '<span class="alert-txt">'+esc(a.txt)+'</span>'
           + '<a class="btn-sm" href="'+esc(a.url)+'">'+esc(a.act)+'</a></div>';
    }).join('');
  }
  async function tick(){
    try{
      var r=await fetch('/metrics.php',{cache:'no-store'}); if(!r.ok)return;
      var d=await r.json();
      upd('cpu',d.cpu.pct,d.cpu.detail); upd('mem',d.mem.pct,d.mem.detail);
      if(d.disksys)upd('disksys',d.disksys.pct,d.disksys.detail);
      if(d.diskdata)upd('diskdata',d.diskdata.pct,d.diskdata.detail);
      updServices(d.services); updAlerts(d.alerts);
      if(d.kpi){
        animeVers(document.getElementById('kpiAuth'), d.kpi.auth, true);
        animeVers(document.getElementById('kpiSeen'), d.kpi.seen, true);
        animeVers(document.getElementById('kpiDown'), d.kpi.down, true);
      }
      if(d.net){ majNet(d.net.down|0, d.net.up|0); majPart(d.net.capD|0, d.net.capU|0, d.net.test); }
    }catch(e){}
  }
  async function loadHistory(){
    try{ var r=await fetch('/metrics.php?history=180',{cache:'no-store'}); if(!r.ok)return;
      var d=await r.json(); if(!d.history)return;
      histCPU=d.history.map(function(x){return x[1];}); histRAM=d.history.map(function(x){return x[2];});
      drawHist();
    }catch(e){}
  }
  async function tickSessions(){
    try{ var r=await fetch('/sessions.php',{cache:'no-store'}); if(!r.ok)return;
      var tb=document.getElementById('sessBody'); if(tb)tb.innerHTML=await r.text(); }catch(e){}
  }
  // ══════════════ Débit Internet en direct ══════════════
  // Anneau de 60 points : à un sondage toutes les 5 s, cela fait 5 minutes — assez pour
  // voir une pointe passer, assez court pour rester lisible.
  var netD=[], netU=[], NET_N=60;
  function drawNet(){
    var c=document.getElementById('netChart'); if(!c)return;
    var w=c.clientWidth||600, h=96;
    // Écrans à forte densité : sans devicePixelRatio, le tracé est flou.
    var dpr=window.devicePixelRatio||1;
    if(c.width!==Math.round(w*dpr)){ c.width=Math.round(w*dpr); c.height=Math.round(h*dpr); }
    var x=c.getContext('2d'); x.setTransform(dpr,0,0,dpr,0,0); x.clearRect(0,0,w,h);

    // ÉCHELLE : un débit n'a pas de maximum naturel (contrairement au processeur, borné
    // à 100 %). On s'ajuste donc sur la plus forte valeur VISIBLE, avec un plancher de
    // 64 ko/s — sans lui, un réseau au repos afficherait le moindre paquet ARP comme un
    // pic plein écran, ce qui serait alarmant et faux.
    var max=65536;
    for(var i=0;i<netD.length;i++){ if(netD[i]>max)max=netD[i]; if(netU[i]>max)max=netU[i]; }
    max*=1.15;   // respiration : la courbe ne colle pas au bord haut

    x.strokeStyle='rgba(148,163,184,.15)'; x.lineWidth=1;
    [0.25,0.5,0.75].forEach(function(g){ x.beginPath(); x.moveTo(0,h*g); x.lineTo(w,h*g); x.stroke(); });
    x.fillStyle='rgba(148,163,184,.55)'; x.font='10px system-ui,sans-serif'; x.textAlign='right';
    x.fillText(fmtOctets(max)+'/s', w-2, 10);

    function trace(arr,col,fill){
      if(arr.length<2)return;
      var n=arr.length, pas=w/(NET_N-1), x0=w-(n-1)*pas;   // ancré à DROITE : le présent est à droite
      x.beginPath();
      for(var i=0;i<n;i++){ var px=x0+i*pas, py=h-Math.min(1,arr[i]/max)*h*0.92;
        i?x.lineTo(px,py):x.moveTo(px,py); }
      x.strokeStyle=col; x.lineWidth=2; x.lineJoin='round'; x.stroke();
      x.lineTo(x0+(n-1)*pas,h); x.lineTo(x0,h); x.closePath();
      x.fillStyle=fill; x.fill();
    }
    trace(netU,'#a78bfa','rgba(167,139,250,.10)');
    trace(netD,'#38bdf8','rgba(56,189,248,.12)');
  }
  // Part de la ligne occupée. capD/capU valent 0 tant qu'aucune mesure n'a eu lieu :
  // on n'affiche alors RIEN plutôt qu'un pourcentage d'une capacité inconnue.
  var stVu=false;
  function majPart(capD,capU,enTest){
    var d=netD.length?netD[netD.length-1]:0, u=netU.length?netU[netU.length-1]:0;
    var eD=document.getElementById('netPctD'), eU=document.getElementById('netPctU');
    if(eD) eD.innerHTML = capD>0 ? '· <strong>'+Math.min(100,Math.round(100*d/capD))+' %</strong> de la ligne' : '';
    if(eU) eU.innerHTML = capU>0 ? '· <strong>'+Math.min(100,Math.round(100*u/capU))+' %</strong> de la ligne' : '';
    var b=document.getElementById('stBtn');
    if(b) b.disabled = !!enTest;
    // La mesure vient de finir : on recharge pour afficher la nouvelle capacité et sa date.
    if(enTest) stVu=true; else if(stVu){ stVu=false; location.reload(); }
  }
  function majNet(d,u){
    netD.push(d); netU.push(u);
    while(netD.length>NET_N){ netD.shift(); netU.shift(); }
    var eD=document.getElementById('netDown'), eU=document.getElementById('netUp');
    if(eD) eD.textContent=fmtOctets(d)+'/s';
    if(eU) eU.textContent=fmtOctets(u)+'/s';
    var crete=0, som=0;
    for(var i=0;i<netD.length;i++){ if(netD[i]>crete)crete=netD[i]; som+=netD[i]; }
    var p=document.getElementById('netPeak'), a=document.getElementById('netAvg');
    if(p) p.textContent='crête : '+fmtOctets(crete)+'/s';
    if(a) a.textContent='moyenne : '+fmtOctets(Math.round(som/Math.max(1,netD.length)))+'/s';
    drawNet();
  }

  window.addEventListener('resize',function(){ drawHist(); drawNet(); });
  demarrer();
  loadHistory();
  setInterval(tick,5000);
  setInterval(loadHistory,60000);
  setInterval(tickSessions,15000);
})();
</script>

<section class="panel">
  <div class="panel-head"><h2>Sessions en direct</h2><span class="muted small">actualisé automatiquement</span></div>
  <div class="table-wrap">
  <table class="grid-table">
    <thead><tr>
      <th>Utilisateur</th><th>État</th><th>IP</th><th>MAC</th>
      <th>Durée</th><th>↓ / ↑</th><th></th>
    </tr></thead>
    <tbody id="sessBody">
    <?php if (!$clients): ?>
      <tr><td colspan="7" class="muted center">Aucun client connecté.</td></tr>
    <?php else: foreach ($clients as $mac => $c):
      $user = 'inconnu';
      if (!empty($c['custom']) && ($d = base64_decode($c['custom'], true)) && preg_match('/user=([^,]+)/', $d, $mm)) { $user = $mm[1]; }
      $auth = ($c['state'] ?? '') === 'Authenticated';
      $dur  = time() - (int) ($c['session_start'] ?? time());
    ?>
      <tr>
        <td><strong><?= e($user) ?></strong></td>
        <td><span class="badge <?= $auth ? 'on' : 'off' ?>"><?= $auth ? 'Connecté' : 'En attente' ?></span></td>
        <td class="mono"><?= e($c['ip'] ?? '') ?></td>
        <td class="mono"><?= e($mac) ?></td>
        <td><?= $auth ? fmtDuration($dur) : '—' ?></td>
        <td><?= fmtBytes($c['download_this_session'] ?? 0) ?> / <?= fmtBytes($c['upload_this_session'] ?? 0) ?></td>
        <td>
          <?php if ($auth): ?>
          <form method="post" onsubmit="return confirm('Déconnecter <?= e($user) ?> ?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="deauth">
            <input type="hidden" name="mac" value="<?= e($mac) ?>">
            <button class="btn-sm btn-danger">Déconnecter</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php pf_footer(); ?>
