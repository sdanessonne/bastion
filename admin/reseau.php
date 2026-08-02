<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Supervision réseau en temps réel.
 *
 * Complète le tableau de bord (qui liste QUI est connecté + totaux cumulés) par la
 * dimension DÉBIT INSTANTANÉ : courbe glissante du débit WAN + « top talkers » (débit
 * live par poste/agent). Le débit par client n'existe nulle part côté serveur (le noyau
 * et OpenNDS ne publient que des compteurs cumulés) : la page fournit les compteurs +
 * un horodatage, et le NAVIGATEUR calcule les débits par différence entre deux sondages.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';

// ── Endpoint JSON (?data=1) : débit WAN + compteurs cumulés par client + horodatage ──
if (isset($_GET['data'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    session_write_close();   // ndsctl est lent (~1,7 s) : libérer le verrou de session

    $net    = sys_net_rate();
    $wanCap = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-speedtest state 2>/dev/null'), true) ?: [];

    $clients = [];
    foreach (nds_clients() as $mac => $c) {
        $user = '';
        if (!empty($c['custom']) && ($d = base64_decode($c['custom'], true)) && preg_match('/user=([^,]+)/', $d, $mm)) {
            $user = $mm[1];
        }
        $clients[] = [
            'mac'   => (string) $mac,
            'ip'    => (string) ($c['ip'] ?? ''),
            'user'  => $user,
            'auth'  => (($c['state'] ?? '') === 'Authenticated'),
            'dl'    => (int) ($c['download_this_session'] ?? 0),
            'ul'    => (int) ($c['upload_this_session'] ?? 0),
            'start' => (int) ($c['session_start'] ?? 0),
        ];
    }
    echo json_encode([
        't'       => microtime(true),
        'net'     => ['down' => $net['down'], 'up' => $net['up'], 'if' => $net['if'],
                      'capD' => (int) ($wanCap['down'] ?? 0), 'capU' => (int) ($wanCap['up'] ?? 0)],
        'clients' => $clients,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * ── Rôle physique des ports ──────────────────────────────────────────────────
 * Rien, nulle part, ne disait quel port du boîtier portait le WAN et lequel
 * portait le LAN. Lors du câblage du premier serveur, le réseau du service a été
 * branché sur le port LAN : son profil s'est activé seul, dnsmasq a démarré, et un
 * serveur DHCP a écouté quelques minutes sur un réseau de production. Aucun bail
 * n'est parti, mais personne n'aurait pu s'en apercevoir depuis la console.
 * Ces trois lignes coûtent peu et rendent l'erreur visible avant qu'elle nuise.
 */
function pf_ports_reseau(): array {
    $conf = [];
    foreach (@file('/etc/proxyfibre/net.env') ?: [] as $l) {
        if (preg_match('/^\s*(WAN_IF|LAN_IF)\s*=\s*"?([^"\s]+)/', $l, $m)) $conf[$m[1]] = $m[2];
    }
    $roles = [($conf['WAN_IF'] ?? '') => 'WAN', ($conf['LAN_IF'] ?? '') => 'LAN'];
    $out = [];
    foreach (glob('/sys/class/net/*') ?: [] as $p) {
        $if = basename($p);
        if ($if === 'lo' || str_starts_with($if, 'veth')) continue;
        $lien = trim((string) @file_get_contents("$p/carrier"));   // « 1 » = câble détecté
        $deb  = trim((string) @file_get_contents("$p/speed"));
        $ips  = [];
        foreach (explode("\n", (string) shell_exec('ip -4 -br addr show ' . escapeshellarg($if) . ' 2>/dev/null')) as $l) {
            if (preg_match_all('/\d+\.\d+\.\d+\.\d+\/\d+/', $l, $mm)) $ips = $mm[0];
        }
        $out[] = [
            'if'    => $if,
            'role'  => $roles[$if] ?? '',
            'lien'  => $lien === '1',
            'debit' => ($deb > 0) ? (int) $deb : 0,
            'ips'   => $ips,
        ];
    }
    usort($out, fn($a, $b) => [$b['role'] === 'WAN', $b['role'] === 'LAN'] <=> [$a['role'] === 'WAN', $a['role'] === 'LAN']);
    return $out;
}
$pf_ports = pf_ports_reseau();

pf_header('Supervision réseau', 'reseau.php');
?>
<section class="panel">
  <div class="panel-head"><h2>🔌 Ports réseau</h2></div>
  <table class="tbl">
    <thead><tr><th>Rôle</th><th>Interface</th><th>Câble</th><th>Adresse</th><th>Lien</th></tr></thead>
    <tbody>
    <?php foreach ($pf_ports as $p): ?>
      <tr>
        <td><?= $p['role'] === 'WAN' ? '<b>WAN</b> — Internet'
              : ($p['role'] === 'LAN' ? '<b>LAN</b> — postes' : '<span class="muted">—</span>') ?></td>
        <td><code><?= htmlspecialchars($p['if']) ?></code></td>
        <td><?= $p['lien'] ? '<span class="badge ok">branché</span>'
                           : '<span class="badge warn">aucun câble</span>' ?></td>
        <td><?= $p['ips'] ? htmlspecialchars(implode(' · ', $p['ips'])) : '<span class="muted">—</span>' ?></td>
        <td><?= $p['debit'] ? $p['debit'] . ' Mb/s' : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted small" style="margin:.7rem 0 0">
    Le port <b>LAN</b> distribue les adresses <code>DHCP</code> et intercepte le DNS des postes.
    Il doit aller sur le <b>switch isolé du parc</b>, jamais sur un réseau déjà équipé d'un serveur
    DHCP : deux serveurs sur le même câble rendent les postes injoignables, et la panne est
    difficile à imputer. Le port <b>WAN</b> est celui qui va vers Internet.
  </p>
</section>
<?php ?>
<style>
  .net-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.8rem;margin-bottom:1rem}
  .net-kpi{border:1px solid var(--line);border-radius:12px;background:var(--bg);padding:.9rem 1.1rem}
  .net-kpi .lbl{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
  .net-kpi .val{font-size:1.5rem;font-weight:700;line-height:1.2;margin-top:.15rem}
  .net-kpi .val small{font-size:.85rem;font-weight:500;color:var(--muted)}
  .net-kpi.down .val{color:#38bdf8}.net-kpi.up .val{color:#a78bfa}
  .net-graph{width:100%;height:150px;display:block;border:1px solid var(--line);border-radius:12px;background:var(--bg)}
  .net-legend{display:flex;gap:1.2rem;font-size:.8rem;color:var(--muted);margin:.5rem 0 0}
  .net-legend b{display:inline-block;width:.7rem;height:.7rem;border-radius:2px;vertical-align:middle;margin-right:.3rem}
  .tt-bar{height:6px;border-radius:4px;background:var(--panel2);overflow:hidden;margin-top:.3rem}
  .tt-bar>span{display:block;height:100%;background:linear-gradient(90deg,var(--accent2),var(--accent));width:0;transition:width .8s ease}
  .tt-rate{font-variant-numeric:tabular-nums;font-weight:600}
  .net-off{color:var(--muted);text-align:center;padding:1.4rem}
</style>

<div class="net-kpis">
  <div class="net-kpi down"><div class="lbl">⬇ Débit descendant (WAN)</div><div class="val" id="k-down">—</div></div>
  <div class="net-kpi up"><div class="lbl">⬆ Débit montant (WAN)</div><div class="val" id="k-up">—</div></div>
  <div class="net-kpi"><div class="lbl">Postes connectés</div><div class="val" id="k-cli">—</div></div>
  <div class="net-kpi"><div class="lbl">Volume cumulé (sessions)</div><div class="val" id="k-vol">—</div></div>
</div>

<section class="panel">
  <div class="panel-head"><h2>📈 Débit WAN en direct <span class="muted small" id="net-if"></span></h2></div>
  <div style="padding:1rem 1.2rem">
    <canvas class="net-graph" id="net-graph" width="900" height="150"></canvas>
    <div class="net-legend"><span><b style="background:#38bdf8"></b>Descendant</span><span><b style="background:#a78bfa"></b>Montant</span>
      <span style="margin-left:auto" id="net-scale"></span></div>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>🏆 Top talkers — débit par poste</h2>
    <span class="muted small">actualisé toutes les 3 s</span></div>
  <div class="table-wrap" style="padding:.4rem .4rem 1rem">
    <table class="grid-table">
      <thead><tr><th>Agent / Poste</th><th>Adresse IP</th><th>⬇ Débit</th><th>⬆ Débit</th><th>Cumul session ⬇ / ⬆</th><th></th></tr></thead>
      <tbody id="tt-body"><tr><td colspan="6" class="net-off">Chargement…</td></tr></tbody>
    </table>
  </div>
</section>

<form id="pf-deauth" method="post" action="/index.php" style="display:none">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="deauth">
  <input type="hidden" name="mac" id="pf-deauth-mac" value="">
</form>

<script>
(function(){
  function fmtRate(bps){
    bps=Math.max(0,bps||0); var u=['o','Ko','Mo','Go'], i=0, v=bps;
    while(v>=1024 && i<u.length-1){ v/=1024; i++; }
    return (i? v.toFixed(1): Math.round(v))+' '+u[i]+'/s';
  }
  function fmtVol(n){
    n=Math.max(0,n||0); var u=['o','Ko','Mo','Go','To'], i=0;
    while(n>=1024 && i<u.length-1){ n/=1024; i++; }
    return (i? n.toFixed(1): Math.round(n))+' '+u[i];
  }
  function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

  var prev=null;                 // dernier échantillon { t, byMac:{mac:{dl,ul}} }
  var hist=[];                   // [{d,u}] pour la courbe (max 90 points)
  var HMAX=90;
  var cv=document.getElementById('net-graph'), cx=cv.getContext('2d');

  function drawGraph(){
    var W=cv.width, H=cv.height, pad=4;
    cx.clearRect(0,0,W,H);
    if(!hist.length) return;
    var max=1;
    hist.forEach(function(p){ max=Math.max(max,p.d,p.u); });
    // Échelle « jolie » (puissance de 2 en octets/s).
    document.getElementById('net-scale').textContent='échelle max ≈ '+fmtRate(max);
    function line(key,color){
      cx.beginPath(); cx.strokeStyle=color; cx.lineWidth=2;
      hist.forEach(function(p,i){
        var x=pad+(W-2*pad)*(i/(HMAX-1));
        var y=H-pad-(H-2*pad)*(p[key]/max);
        i? cx.lineTo(x,y): cx.moveTo(x,y);
      });
      cx.stroke();
      // remplissage léger
      cx.lineTo(pad+(W-2*pad)*((hist.length-1)/(HMAX-1)),H-pad); cx.lineTo(pad,H-pad); cx.closePath();
      cx.globalAlpha=.08; cx.fillStyle=color; cx.fill(); cx.globalAlpha=1;
    }
    line('u','#a78bfa'); line('d','#38bdf8');
  }

  function render(j){
    // KPI WAN (débit calculé côté serveur).
    document.getElementById('k-down').innerHTML=esc(fmtRate(j.net.down)).replace(/(\/s)$/,'<small>$1</small>');
    document.getElementById('k-up').innerHTML=esc(fmtRate(j.net.up)).replace(/(\/s)$/,'<small>$1</small>');
    document.getElementById('net-if').textContent=j.net.if? '· interface '+j.net.if : '';
    hist.push({d:j.net.down,u:j.net.up}); if(hist.length>HMAX) hist.shift();
    drawGraph();

    // Débit par client : delta des compteurs cumulés entre deux sondages.
    var auth=0, vol=0, rows=[];
    var cur={ t:j.t, byMac:{} };
    j.clients.forEach(function(c){
      if(c.auth) auth++; vol+=c.dl;
      var rd=0, ru=0;
      if(prev && prev.byMac[c.mac]){
        var dt=j.t-prev.t;
        if(dt>0.3){ rd=Math.max(0,(c.dl-prev.byMac[c.mac].dl)/dt); ru=Math.max(0,(c.ul-prev.byMac[c.mac].ul)/dt); }
      }
      cur.byMac[c.mac]={dl:c.dl,ul:c.ul};
      rows.push({c:c, rd:rd, ru:ru});
    });
    prev=cur;
    document.getElementById('k-cli').textContent=auth;
    document.getElementById('k-vol').textContent=fmtVol(vol);

    // Tri « top talkers » : débit descendant décroissant, puis cumul.
    rows.sort(function(a,b){ return (b.rd-a.rd) || (b.c.dl-a.c.dl); });
    var maxRate=1; rows.forEach(function(r){ maxRate=Math.max(maxRate,r.rd); });
    var tb=document.getElementById('tt-body');
    if(!rows.length){ tb.innerHTML='<tr><td colspan="6" class="net-off">Aucun poste connecté pour le moment.</td></tr>'; return; }
    tb.innerHTML=rows.map(function(r){
      var who=r.c.user? esc(r.c.user) : '<span class="muted">'+esc(r.c.mac)+'</span>';
      var badge=r.c.auth? '' : ' <span class="badge off">en attente</span>';
      return '<tr>'+
        '<td><strong>'+who+'</strong>'+badge+'<div class="tt-bar"><span style="width:'+Math.round(100*r.rd/maxRate)+'%"></span></div></td>'+
        '<td class="mono">'+esc(r.c.ip)+'</td>'+
        '<td class="tt-rate" style="color:#38bdf8">'+fmtRate(r.rd)+'</td>'+
        '<td class="tt-rate" style="color:#a78bfa">'+fmtRate(r.ru)+'</td>'+
        '<td class="muted">'+fmtVol(r.c.dl)+' / '+fmtVol(r.c.ul)+'</td>'+
        '<td>'+(r.c.auth? '<button type="button" class="btn-sm btn-danger" data-mac="'+esc(r.c.mac)+'" data-user="'+esc(r.c.user||r.c.ip)+'">Déconnecter</button>':'')+'</td>'+
      '</tr>';
    }).join('');
    Array.prototype.forEach.call(tb.querySelectorAll('button[data-mac]'), function(b){
      b.addEventListener('click', function(){
        if(confirm('Déconnecter '+b.getAttribute('data-user')+' ?')){
          document.getElementById('pf-deauth-mac').value=b.getAttribute('data-mac');
          document.getElementById('pf-deauth').submit();
        }
      });
    });
  }

  var timer=null, failed=0;
  function tick(){
    fetch('reseau.php?data=1', {headers:{'X-Requested-With':'fetch'}})
      .then(function(r){ return r.json(); })
      .then(function(j){ failed=0; render(j); })
      .catch(function(){ if(++failed>5){ document.getElementById('tt-body').innerHTML='<tr><td colspan="6" class="net-off">Supervision interrompue (serveur injoignable).</td></tr>'; } });
  }
  tick(); timer=setInterval(tick, 3000);
  document.addEventListener('visibilitychange', function(){
    if(document.hidden){ clearInterval(timer); timer=null; }
    else if(!timer){ tick(); timer=setInterval(tick,3000); }
  });
})();
</script>
<?php pf_footer(); ?>
