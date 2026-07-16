<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — Tableau de bord utilisateur (self-service).
 *
 * Affiché à l'utilisateur authentifié après connexion au portail captif.
 * Interroge OpenNDS en direct (`ndsctl json`) pour présenter, pour le client
 * qui consulte la page (identifié par son IP) : état, durée de session, temps
 * restant, données consommées, débit, appareil, et un bouton de déconnexion.
 *
 * L'identifiant est stocké au login dans le champ `custom` d'OpenNDS (base64).
 */
require_once __DIR__ . '/https_guard.php';
require_once __DIR__ . '/nds.php';

// ── Récupération des données OpenNDS pour le client courant ──────────────────
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
// Mode aperçu (admin) : uniquement depuis la passerelle (localhost) — permet de
// prévisualiser le tableau de bord d'un client donné via ?preview=<ip>.
if (isset($_GET['preview']) && in_array($clientIp, ['127.0.0.1', '::1'], true)
        && filter_var($_GET['preview'], FILTER_VALIDATE_IP)) {
    $clientIp = $_GET['preview'];
}
// Requête CIBLÉE par IP + cache court en mémoire — la page se rafraîchit toutes les
// 15 s, inutile de rappeler ndsctl (lent) à chaque octet.
// La résilience aux refus de ndsctl est traitée dans pf_nds_client() : sans elle, cette
// page annonçait « vous n'êtes pas connecté » à un agent connecté dès que plusieurs
// consultations tombaient en même temps.
$client = pf_nds_client($clientIp, 10);

// ── Helpers ──────────────────────────────────────────────────────────────────
function fmtBytes($n): string {
    $n = (float) $n; $u = ['o', 'Ko', 'Mo', 'Go', 'To']; $i = 0;
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return number_format($n, $i ? 1 : 0, ',', ' ') . ' ' . $u[$i];
}
function fmtDuration(int $s): string {
    if ($s < 0) { $s = 0; }
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $sec = $s % 60;
    if ($h > 0) { return sprintf('%dh %02dmin', $h, $m); }
    if ($m > 0) { return sprintf('%dmin %02ds', $m, $sec); }
    return $sec . 's';
}
$esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// ── Extraction / calculs ─────────────────────────────────────────────────────
$authenticated = $client && ($client['state'] ?? '') === 'Authenticated';

$username = 'Utilisateur';
if ($client && !empty($client['custom'])) {
    $dec = base64_decode($client['custom'], true);
    if ($dec !== false && preg_match('/user=([^,]+)/', $dec, $m)) {
        $username = $m[1];
    }
}

$now       = time();
$start     = (int) ($client['session_start'] ?? 0);
$end       = (int) ($client['session_end'] ?? 0);
$lastSeen  = (int) ($client['last_active'] ?? 0);
$elapsed   = $start ? $now - $start : 0;
$remaining = $end ? $end - $now : 0;
$down      = (int) ($client['download_this_session'] ?? 0);
$up        = (int) ($client['upload_this_session'] ?? 0);
$downAvg   = (float) ($client['download_session_avg'] ?? 0);
$upAvg     = (float) ($client['upload_session_avg'] ?? 0);
$dQuota    = ($client['download_quota'] ?? 'null');
$hasQuota  = $dQuota !== 'null' && $dQuota !== null && (int) $dQuota > 0;
$quotaPct  = $hasQuota ? min(100, round($down / ((int) $dQuota * 1024) * 100)) : 0;

$ip     = $client['ip']     ?? $clientIp;
$mac    = $client['mac']    ?? '—';
$device = $client['client_type'] ?? '—';
$iface  = $client['clientif'] ?? '—';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?php if ($authenticated): ?><meta http-equiv="refresh" content="15"><?php endif; ?>
  <title>Bastion — Mon compte</title>
  <link rel="icon" href="/portal/assets/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="/portal/assets/bastion-icon.svg">
  <link rel="manifest" href="/portal/manifest.php">
  <meta name="theme-color" content="#0f172a">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="apple-touch-icon" href="/portal/assets/icon-192.png">
  <link rel="stylesheet" href="/portal/assets/account.css">
  <link rel="stylesheet" href="/portal/assets/bastion-fx.css">
  <style>
    .tabbar{display:none}
    @media(max-width:760px){
      body{padding-bottom:calc(74px + env(safe-area-inset-bottom,0))}
      .tabbar{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:40;background:rgba(13,23,40,.96);
        border-top:1px solid #334155;justify-content:space-around;padding:.3rem .2rem calc(.3rem + env(safe-area-inset-bottom,0))}
      .tabbar a{flex:1;display:flex;flex-direction:column;align-items:center;gap:.12rem;padding:.4rem 0;color:#94a3b8;text-decoration:none;font-size:.66rem}
      .tabbar a .i{font-size:1.35rem;line-height:1}
      .tabbar a.on{color:#38bdf8} .tabbar a:active{transform:scale(.9)}
    }
  </style>
</head>
<body>
<div class="bg" aria-hidden="true"><div class="aurora"></div><span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span><div class="grid"></div></div>
<canvas id="fx" aria-hidden="true"></canvas>
<?php if (!$authenticated): ?>
  <main class="card centered">
    <img class="logo" src="/portal/assets/bastion-icon.svg" alt="Bastion">
    <h1>Vous n'êtes pas connecté</h1>
    <p class="muted">Votre session n'est pas active. Connectez-vous au portail pour accéder à Internet.</p>
    <a class="btn" href="/portal/fas.php">Aller à la page de connexion</a>
  </main>
<?php else: ?>
  <main class="dash">
    <header class="dash-head">
      <div class="brand"><img class="logo" src="/portal/assets/bastion-icon.svg" alt="Bastion"><span>Bastion</span></div>
      <div class="user">
        <span class="status-dot"></span>
        <div>
          <div class="hello">Bonjour, <strong><?= $esc($username) ?></strong></div>
          <div class="muted small">Connecté · accès Internet actif</div>
        </div>
      </div>
    </header>

    <section class="grid">
      <div class="stat">
        <div class="stat-label">Temps de connexion</div>
        <div class="stat-value"><?= fmtDuration($elapsed) ?></div>
        <div class="muted small">depuis le <?= $start ? $esc(date('d/m/Y à H:i', $start)) : '—' ?></div>
      </div>
      <div class="stat">
        <div class="stat-label">Temps restant</div>
        <div class="stat-value"><?= $end ? fmtDuration($remaining) : '∞' ?></div>
        <div class="muted small"><?= $end ? 'avant fin de session' : 'session illimitée' ?></div>
      </div>
      <div class="stat">
        <div class="stat-label">Téléchargé (↓)</div>
        <div class="stat-value"><?= fmtBytes($down) ?></div>
        <div class="muted small">moy. <?= fmtBytes($downAvg) ?>/s</div>
      </div>
      <div class="stat">
        <div class="stat-label">Envoyé (↑)</div>
        <div class="stat-value"><?= fmtBytes($up) ?></div>
        <div class="muted small">moy. <?= fmtBytes($upAvg) ?>/s</div>
      </div>
    </section>

    <section class="card">
      <h2>Consommation de données</h2>
      <?php if ($hasQuota): ?>
        <div class="bar"><div class="bar-fill" style="width:<?= $quotaPct ?>%"></div></div>
        <div class="muted small"><?= fmtBytes($down) ?> utilisés sur <?= fmtBytes((int)$dQuota * 1024) ?> (<?= $quotaPct ?>%)</div>
      <?php else: ?>
        <div class="bar"><div class="bar-fill unlimited" style="width:100%"></div></div>
        <div class="muted small">Quota de données : <strong>illimité</strong> · Total échangé : <?= fmtBytes($down + $up) ?></div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Détails de la connexion</h2>
      <table class="details">
        <tr><th>Identifiant</th><td><?= $esc($username) ?></td></tr>
        <tr><th>Adresse IP</th><td><?= $esc($ip) ?></td></tr>
        <tr><th>Adresse MAC</th><td><?= $esc($mac) ?></td></tr>
        <tr><th>Type d'appareil</th><td><?= $esc($device) ?></td></tr>
        <tr><th>Interface</th><td><?= $esc($iface) ?></td></tr>
        <tr><th>Dernière activité</th><td><?= $lastSeen ? $esc(date('H:i:s', $lastSeen)) : '—' ?></td></tr>
      </table>
    </section>

    <section class="card" style="margin-top:1rem">
      <h2>Vitesse de connexion</h2>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.9rem;margin:.6rem 0 1rem">
        <div style="background:rgba(56,189,248,.08);border:1px solid #334155;border-radius:12px;padding:.9rem;text-align:center">
          <div style="font-size:.8rem;color:#94a3b8">↓ Descendant</div>
          <div style="font-size:1.9rem;font-weight:700;color:#38bdf8" id="dlVal">—</div>
          <div style="font-size:.72rem;color:#94a3b8">Mbit/s</div>
        </div>
        <div style="background:rgba(74,222,128,.08);border:1px solid #334155;border-radius:12px;padding:.9rem;text-align:center">
          <div style="font-size:.8rem;color:#94a3b8">↑ Montant</div>
          <div style="font-size:1.9rem;font-weight:700;color:#4ade80" id="upVal">—</div>
          <div style="font-size:.72rem;color:#94a3b8">Mbit/s</div>
        </div>
      </div>
      <button type="button" id="stBtn" class="btn" onclick="runSpeed()">Lancer le test</button>
      <div id="stStatus" class="muted small" style="margin-top:.5rem"></div>
      <p class="muted small" style="margin-top:.3rem">Mesure le débit réel entre votre appareil et la passerelle.</p>
    </section>
    <script>
    function stAnim(el,val){var v=0,t=Math.max(0,val),s=t/25;var id=setInterval(function(){v+=s;if(v>=t){v=t;clearInterval(id);}el.textContent=v.toFixed(1);},25);}
    async function runSpeed(){
      var b=document.getElementById('stBtn'),st=document.getElementById('stStatus');
      var dl=document.getElementById('dlVal'),up=document.getElementById('upVal');
      b.disabled=true;dl.textContent='…';up.textContent='…';
      try{
        st.textContent='Mesure du débit descendant…';
        var n=8*1024*1024,t0=performance.now();
        var r=await fetch('/portal/speedtest.php?dl='+n+'&_='+Date.now(),{cache:'no-store'});
        var buf=await r.arrayBuffer();var s0=(performance.now()-t0)/1000;
        stAnim(dl,(buf.byteLength*8/1e6)/s0);
        st.textContent='Mesure du débit montant…';
        var m=4*1024*1024,t1=performance.now();
        await fetch('/portal/speedtest.php',{method:'POST',body:new Uint8Array(m),cache:'no-store'});
        var s1=(performance.now()-t1)/1000;stAnim(up,(m*8/1e6)/s1);
        st.textContent='Test terminé.';
      }catch(e){st.textContent='Échec du test, réessayez.';}
      b.disabled=false;
    }
    </script>

    <form class="logout" method="post" action="/portal/logout.php">
      <button type="submit" class="btn btn-danger">Se déconnecter</button>
    </form>
    <p class="muted small center">Page actualisée automatiquement · Accès journalisé (art. L.34-1 CPCE)</p>
  </main>
<?php endif; ?>
<nav class="tabbar">
  <a href="/portal/intranet.php"><span class="i">🏠</span>Accueil</a>
  <a href="/portal/intranet/annuaire.php"><span class="i">👥</span>Annuaire</a>
  <a href="/portal/intranet/assistance.php"><span class="i">🛟</span>Aide</a>
  <a href="/portal/account.php" class="on"><span class="i">👤</span>Compte</a>
</nav>
<script src="/portal/assets/bastion-fx.js" defer></script>
</body>
</html>
