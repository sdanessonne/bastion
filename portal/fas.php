<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — Portail captif FAS (Forwarding Authentication Service) pour OpenNDS.
 *
 * Remplace le formulaire "nom/email" de l'exemple OpenNDS (fas-hid.php) par une
 * authentification réelle identifiant/mot de passe contre FreeRADIUS.
 *
 * Protocole (OpenNDS, fas_secure_enabled = 1) :
 *   1. OpenNDS redirige le client non authentifié vers cette page avec ?fas=<base64>.
 *      Le blob décodé contient : clientip, clientmac, hid, gatewayaddress, gatewayname,
 *      originurl, clientif, authdir, version…
 *   2. On affiche un formulaire de connexion.
 *   3. À la soumission, on valide les identifiants via RADIUS (radtest).
 *   4. Si OK : on calcule tok = sha256(hid + faskey) et on renvoie le client vers
 *      http://<gatewayaddress>/<authdir>/?tok=<tok>&redir=<originurl> → OpenNDS ouvre l'accès.
 *
 * Secrets lus hors webroot dans /etc/proxyfibre/portal.env : FAS_KEY, RADIUS_SECRET.
 */

// ── Forcer HTTPS pour la saisie des identifiants ─────────────────────────────
// OpenNDS redirige d'abord le client en HTTP (port 2080). On rebondit vers le
// portail HTTPS (port 2443) en conservant les paramètres, pour que l'affichage et
// l'envoi du mot de passe se fassent chiffrés.
require_once __DIR__ . '/https_guard.php';

// ── Secrets (hors racine web) ────────────────────────────────────────────────
$FAS_KEY = $RADIUS_SECRET = '';
$envFile = '/etc/proxyfibre/portal.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if (preg_match('/^FAS_KEY="?([^"]*)"?$/', $l, $m))        { $FAS_KEY = $m[1]; }
        if (preg_match('/^RADIUS_SECRET="?([^"]*)"?$/', $l, $m))  { $RADIUS_SECRET = $m[1]; }
    }
}

// ── Décodage des paramètres OpenNDS ──────────────────────────────────────────
$P = [];
$fas = $_REQUEST['fas'] ?? '';
if ($fas !== '') {
    foreach (explode(', ', (string) base64_decode($fas)) as $pair) {
        $kv = explode('=', $pair, 2);
        if (count($kv) === 2) { $P[$kv[0]] = $kv[1]; }
    }
}
$hid            = $P['hid'] ?? '';
$gatewayaddress = $P['gatewayaddress'] ?? '';
// Nom de la passerelle, sans le suffixe technique « Node:xxxx » ajouté par OpenNDS.
$gatewayname    = trim(preg_replace('/\s*Node:.*/i', '', rawurldecode($P['gatewayname'] ?? 'Bastion')));
if ($gatewayname === '') { $gatewayname = 'Bastion'; }
$originurl      = $P['originurl'] ?? '';
$authdir        = $P['authdir'] ?? 'opennds_auth';
$clientip       = $P['clientip'] ?? '';

$error = '';

// ── Authentification RADIUS ──────────────────────────────────────────────────
function radius_auth(string $user, string $pass, string $secret): bool {
    if ($user === '' || $pass === '' || $secret === '') { return false; }
    $cmd = sprintf(
        'radtest %s %s 127.0.0.1 0 %s 2>&1',
        escapeshellarg($user), escapeshellarg($pass), escapeshellarg($secret)
    );
    exec($cmd, $out, $rc);
    return (bool) preg_grep('/Access-Accept/', $out);
}

// ── Traitement du formulaire ─────────────────────────────────────────────────
$submitted = isset($_POST['username']);
if ($submitted && $fas !== '' && $hid !== '') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');

    if (radius_auth($user, $pass, $RADIUS_SECRET)) {
        // Succès : construire le jeton d'autorisation OpenNDS.
        $tok = hash('sha256', $hid . $FAS_KEY);
        // Stocker l'identifiant dans le champ "custom" d'OpenNDS (lu par le tableau de bord).
        $custom = base64_encode('user=' . $user);
        // Après authentification, envoyer l'utilisateur vers le site intranet.
        $gwIp     = explode(':', $gatewayaddress)[0];
        $landing  = sprintf('https://%s:2443/portal/intranet.php', $gwIp);
        $authURL  = sprintf('http://%s/%s/?tok=%s&custom=%s&redir=%s',
                        $gatewayaddress, trim($authdir, '/'),
                        rawurlencode($tok), rawurlencode($custom), rawurlencode($landing));
        header('Location: ' . $authURL);
        exit;
    }
    $error = 'Identifiant ou mot de passe incorrect.';
}

$esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $esc($gatewayname) ?> — Accès Internet</title>
  <style>
    *{box-sizing:border-box}
    html,body{height:100%}
    body{margin:0;min-height:100vh;display:grid;place-items:center;position:relative;overflow-x:hidden;
      font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#e2e8f0;padding:1.5rem;background:#060b16}
    /* ── Fond dynamique ── */
    .bg{position:fixed;inset:0;z-index:0;overflow:hidden}
    .bg .aurora{position:absolute;inset:-35%;opacity:.5;filter:blur(70px);animation:spin 42s linear infinite;
      background:conic-gradient(from 0deg at 50% 50%,rgba(14,165,233,.35),rgba(30,58,95,0) 25%,rgba(56,189,248,.35) 50%,rgba(15,23,42,0) 75%,rgba(14,165,233,.35))}
    .bg .blob{position:absolute;border-radius:50%;filter:blur(64px);opacity:.5;mix-blend-mode:screen}
    .b1{width:44vmax;height:44vmax;left:-12vmax;top:-14vmax;background:radial-gradient(circle,#0ea5e9,transparent 62%);animation:float1 19s ease-in-out infinite}
    .b2{width:36vmax;height:36vmax;right:-10vmax;top:8vmax;background:radial-gradient(circle,#2563eb,transparent 60%);animation:float2 25s ease-in-out infinite}
    .b3{width:32vmax;height:32vmax;left:22vmax;bottom:-14vmax;background:radial-gradient(circle,#22d3ee,transparent 60%);animation:float3 22s ease-in-out infinite}
    .bg .grid{position:absolute;inset:-2px;background-image:linear-gradient(rgba(56,189,248,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(56,189,248,.06) 1px,transparent 1px);
      background-size:46px 46px;mask-image:radial-gradient(circle at 50% 38%,#000 42%,transparent 80%);-webkit-mask-image:radial-gradient(circle at 50% 38%,#000 42%,transparent 80%);animation:drift 32s linear infinite}
    #fx{position:fixed;inset:0;z-index:1;pointer-events:none}
    @keyframes spin{to{transform:rotate(360deg)}}
    @keyframes drift{to{background-position:46px 46px}}
    @keyframes float1{50%{transform:translate(6vmax,4vmax) scale(1.1)}}
    @keyframes float2{50%{transform:translate(-5vmax,6vmax) scale(1.08)}}
    @keyframes float3{50%{transform:translate(4vmax,-5vmax) scale(1.12)}}
    /* ── Carte ── */
    .card{position:relative;z-index:2;width:100%;max-width:390px;border-radius:18px;padding:2.1rem 2rem;
      background:rgba(18,28,46,.72);backdrop-filter:blur(14px) saturate(140%);-webkit-backdrop-filter:blur(14px) saturate(140%);
      border:1px solid rgba(120,160,210,.18);box-shadow:0 30px 80px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.05);
      animation:pop .7s cubic-bezier(.16,1,.3,1) both}
    .card::before{content:"";position:absolute;inset:-1px;border-radius:18px;padding:1px;z-index:-1;opacity:.55;
      background:conic-gradient(from var(--a,0deg),#0ea5e9,#22d3ee,#2563eb,#0ea5e9);
      -webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;
      mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);mask-composite:exclude;animation:brd 6s linear infinite}
    @property --a{syntax:'<angle>';inherits:false;initial-value:0deg}
    @keyframes brd{to{--a:360deg}}
    @keyframes pop{from{opacity:0;transform:translateY(22px) scale(.96)}to{opacity:1;transform:none}}
    .brand{text-align:center;margin-bottom:1.6rem}
    .logo{display:block;width:70px;height:70px;margin:0 auto .6rem;filter:drop-shadow(0 6px 20px rgba(56,189,248,.55));animation:bob 5s ease-in-out infinite}
    @keyframes bob{50%{transform:translateY(-6px)}}
    h1{margin:.2rem 0 0;font-size:1.5rem;font-weight:700;letter-spacing:.4px;
      background:linear-gradient(90deg,#fff,#7dd3fc);-webkit-background-clip:text;background-clip:text;color:transparent}
    .tag{margin:.3rem 0 0;color:#9fb3cc;font-size:.85rem}
    form{display:grid;gap:1rem}
    label{display:grid;gap:.35rem;font-size:.8rem;color:#9fb3cc;letter-spacing:.02em}
    input{width:100%;padding:.75rem .85rem;background:rgba(8,15,28,.7);color:#e2e8f0;border:1px solid rgba(51,65,85,.6);
      border-radius:11px;font-size:1rem;outline:none;transition:border-color .2s,box-shadow .2s,background .2s}
    input:focus{border-color:#38bdf8;background:rgba(8,15,28,.95);box-shadow:0 0 0 3px rgba(56,189,248,.22)}
    button{position:relative;overflow:hidden;width:100%;padding:.8rem 1rem;margin-top:.4rem;
      background:linear-gradient(90deg,#0ea5e9,#2563eb);background-size:200% 100%;color:#fff;
      font-weight:700;font-size:1rem;border:none;border-radius:11px;cursor:pointer;letter-spacing:.03em;
      box-shadow:0 10px 24px rgba(14,165,233,.35);transition:transform .15s,box-shadow .2s,background-position .5s}
    button:hover{transform:translateY(-2px);background-position:100% 0;box-shadow:0 14px 30px rgba(37,99,235,.5)}
    button:active{transform:translateY(0)}
    button::after{content:"";position:absolute;top:0;left:-120%;width:60%;height:100%;transform:skewX(-20deg);
      background:linear-gradient(100deg,transparent,rgba(255,255,255,.35),transparent)}
    button:hover::after{animation:sheen .8s ease}
    @keyframes sheen{to{left:130%}}
    .err{background:rgba(248,113,113,.12);color:#fca5a5;border:1px solid rgba(248,113,113,.3);
      padding:.6rem .8rem;border-radius:10px;font-size:.875rem;margin-bottom:1rem;animation:shake .4s}
    @keyframes shake{25%{transform:translateX(-5px)}75%{transform:translateX(5px)}}
    .hint{color:#9fb3cc;font-size:.8rem;margin-top:1rem;text-align:center;line-height:1.5}
    footer{margin-top:1.6rem;padding-top:1rem;border-top:1px solid rgba(120,160,210,.15);color:#8fa3bd;
      font-size:.72rem;text-align:center}
    @media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
  </style>
  <link rel="icon" href="/portal/assets/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="/portal/assets/bastion-icon.svg">
</head>
<body>
  <div class="bg" aria-hidden="true">
    <div class="aurora"></div>
    <span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span>
    <div class="grid"></div>
  </div>
  <canvas id="fx" aria-hidden="true"></canvas>
  <main class="card">
    <div class="brand">
      <img class="logo" src="/portal/assets/bastion-icon.svg" width="64" height="64" alt="Bastion">
      <h1><?= $esc($gatewayname) ?></h1>
      <p class="tag">Portail d'accès Internet sécurisé</p>
    </div>

    <?php if ($fas === ''): ?>
      <p class="hint">Connectez-vous d'abord au réseau Bastion : cette page s'ouvrira
      automatiquement pour vous authentifier.</p>
    <?php else: ?>
      <?php if ($error): ?><p class="err"><?= $esc($error) ?></p><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="fas" value="<?= $esc($fas) ?>">
        <label>Identifiant
          <input type="text" name="username" required autofocus>
        </label>
        <label>Mot de passe
          <input type="password" name="password" required>
        </label>
        <button type="submit">Se connecter</button>
      </form>
    <?php endif; ?>

    <footer>Accès soumis à authentification et journalisation (art. L.34-1 CPCE).</footer>
  </main>
  <script>
  /* Réseau de particules (constellation) — 100% autonome, dégradé si non supporté. */
  (function(){
    try{
      if(matchMedia('(prefers-reduced-motion:reduce)').matches) return;
      var c=document.getElementById('fx'); if(!c||!c.getContext) return;
      var x=c.getContext('2d'), w,h, pts, DPR=Math.min(window.devicePixelRatio||1,2);
      function size(){w=c.width=innerWidth*DPR; h=c.height=innerHeight*DPR; c.style.width=innerWidth+'px'; c.style.height=innerHeight+'px';}
      function init(){var n=Math.min(80,Math.floor(innerWidth/20)); pts=[]; for(var i=0;i<n;i++)pts.push({x:Math.random()*w,y:Math.random()*h,vx:(Math.random()-.5)*.25*DPR,vy:(Math.random()-.5)*.25*DPR});}
      var LINK=140*DPR, LINK2=LINK*LINK;
      function loop(){
        x.clearRect(0,0,w,h);
        for(var i=0;i<pts.length;i++){
          var p=pts[i]; p.x+=p.vx; p.y+=p.vy;
          if(p.x<0||p.x>w)p.vx*=-1; if(p.y<0||p.y>h)p.vy*=-1;
          x.beginPath(); x.arc(p.x,p.y,1.6*DPR,0,6.283); x.fillStyle='rgba(125,211,252,.75)'; x.fill();
          for(var j=i+1;j<pts.length;j++){
            var q=pts[j], dx=p.x-q.x, dy=p.y-q.y, d=dx*dx+dy*dy;
            if(d<LINK2){ x.globalAlpha=1-d/LINK2; x.strokeStyle='rgba(56,189,248,.4)'; x.lineWidth=DPR*.6;
              x.beginPath(); x.moveTo(p.x,p.y); x.lineTo(q.x,q.y); x.stroke(); x.globalAlpha=1; }
          }
        }
        requestAnimationFrame(loop);
      }
      size(); init(); loop();
      var t; addEventListener('resize',function(){clearTimeout(t); t=setTimeout(function(){size(); init();},200);});
    }catch(e){}
  })();
  </script>
</body>
</html>
