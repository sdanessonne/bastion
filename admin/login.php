<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — page de connexion (avec double authentification optionnelle). */
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/totp.php';
require_once __DIR__ . '/inc/throttle.php';
require_once __DIR__ . '/inc/avatar.php';

if (!empty($_SESSION['admin'])) { header('Location: /index.php'); exit; }

$error = '';
$stage = 'password';   // 'password' | 'totp'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    // ── Étape 2 : vérification du code 2FA ──
    if (($_POST['step'] ?? '') === 'totp' && !empty($_SESSION['pending_admin'])) {
        $pu = (string) $_SESSION['pending_admin'];
        // Le 2FA se brute-force aussi : une fois « pending_admin » en session, un script
        // peut enchaîner les codes. On limite donc cette étape comme la première.
        $th = throttle_begin(pf_db(), $ip, $pu);
        if (!$th['ok']) {
            $stage = 'totp';
            $error = $th['msg'];
        } else {
            $vrai = false;
            try {
                $st = pf_db()->prepare('SELECT totp_secret FROM pf_admins WHERE username = ?');
                $st->execute([$pu]);
                $sec = (string) ($st->fetchColumn() ?: '');
                $code = (string) ($_POST['code'] ?? '');
                $vrai = $sec !== '' && totp_verify($sec, $code);
            } catch (Throwable $ex) { /* erreur générique */ }
            throttle_finish(pf_db(), $th['id'], $vrai);
            if ($vrai) {
                unset($_SESSION['pending_admin']);
                session_regenerate_id(true);
                $_SESSION['admin'] = $pu;
                $_SESSION['avatar_v'] = avatar_version(pf_db(), $pu);   // photo affichée dès l'ouverture
                header('Location: /index.php');
                exit;
            }
            $stage = 'totp';
            $error = 'Code de vérification invalide.';
        }
    }
    // ── Étape 1 : identifiant + mot de passe ──
    elseif (($_POST['step'] ?? '') === 'password') {
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        $th = throttle_begin(pf_db(), $ip, $u);
        if (!$th['ok']) {
            $error = $th['msg'];
        } else {
            $ok = false; $row = false;
            try {
                $st = pf_db()->prepare('SELECT password_hash, totp_enabled FROM pf_admins WHERE username = ?');
                $st->execute([$u]);
                $row = $st->fetch();
                $ok = $row && password_verify($p, $row['password_hash']);
            } catch (Throwable $ex) { /* $ok reste false */ }
            // Un mot de passe correct N'EST PAS un échec, même s'il reste à faire le 2FA :
            // la ligne réservée passe en ok=1 et cesse de compter. L'étape 2FA aura sa
            // propre réservation.
            throttle_finish(pf_db(), $th['id'], $ok);
            if ($ok) {
                if (!empty($row['totp_enabled'])) {
                    $_SESSION['pending_admin'] = $u;   // en attente du code
                    $stage = 'totp';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['admin'] = $u;
                    $_SESSION['avatar_v'] = avatar_version(pf_db(), $u);   // photo affichée dès l'ouverture
                    header('Location: /index.php');
                    exit;
                }
            } else {
                $error = 'Identifiant ou mot de passe incorrect.';
            }
        }
    }
}

// Reprise directe de l'étape 2FA si une session d'attente existe (rechargement).
if ($stage === 'password' && !empty($_SESSION['pending_admin']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stage = 'totp';
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bastion Admin — Connexion</title>
  <link rel="icon" href="/assets/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="/assets/bastion-icon.svg">
  <link rel="stylesheet" href="/assets/admin.css">
  <style>
    /* Fond animé + carte moderne « glass » de la page de connexion */
    .login-body{position:relative;overflow:hidden;
      background:linear-gradient(-45deg,#0b1120,#0e1e3a,#122a4d,#0b1120);background-size:400% 400%;
      animation:loginGrad 18s ease infinite}
    @keyframes loginGrad{0%{background-position:0 50%}50%{background-position:100% 50%}100%{background-position:0 50%}}
    #bgnet{position:fixed;inset:0;width:100%;height:100%;z-index:0;opacity:.55}
    .orb{position:fixed;border-radius:50%;filter:blur(70px);z-index:0;opacity:.5;animation:orbFloat 22s ease-in-out infinite}
    .orb.a{width:340px;height:340px;background:#0ea5e9;top:-90px;left:-80px}
    .orb.b{width:300px;height:300px;background:#6366f1;bottom:-100px;right:-70px;animation-delay:-8s}
    .orb.c{width:220px;height:220px;background:#22d3ee;top:40%;right:12%;animation-delay:-14s;opacity:.35}
    @keyframes orbFloat{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(40px,30px) scale(1.08)}
      66%{transform:translate(-30px,20px) scale(.95)}}
    .login-card{position:relative;z-index:2;background:rgba(21,31,50,.72);backdrop-filter:blur(16px) saturate(140%);
      -webkit-backdrop-filter:blur(16px) saturate(140%);border:1px solid rgba(120,150,190,.22);
      box-shadow:0 30px 90px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06);animation:cardUp .6s cubic-bezier(.16,1,.3,1)}
    @keyframes cardUp{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:none}}
    .login-card::before{content:"";position:absolute;inset:-1px;border-radius:16px;padding:1px;pointer-events:none;
      background:linear-gradient(120deg,rgba(56,189,248,.5),transparent 40%,transparent 60%,rgba(99,102,241,.5));
      -webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude}
    .brand-center .logo{animation:logoPulse 3s ease-in-out infinite}
    @keyframes logoPulse{0%,100%{transform:scale(1);filter:drop-shadow(0 0 0 rgba(56,189,248,0))}
      50%{transform:scale(1.05);filter:drop-shadow(0 6px 20px rgba(56,189,248,.5))}}
    .login-tag{text-align:center;color:var(--muted);font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;
      margin-top:1.2rem;opacity:.7}
    @media(prefers-reduced-motion:reduce){.login-body,.orb,.brand-center .logo{animation:none}#bgnet{display:none}}
  </style>
</head>
<body class="login-body">
  <canvas id="bgnet"></canvas>
  <div class="orb a"></div><div class="orb b"></div><div class="orb c"></div>
  <div id="splash" class="splash">
    <div class="splash-inner">
      <img class="splash-logo" src="/assets/bastion-icon.svg" alt="Bastion">
      <div class="splash-title">Bastion</div>
      <div class="splash-sub">Console d'administration</div>
      <div class="splash-bar"><span></span></div>
    </div>
  </div>
  <script>if(sessionStorage.getItem('pf_splash')){var s=document.getElementById('splash');if(s)s.style.display='none';}</script>
  <main class="login-card">
    <div class="brand-center"><img class="logo" src="/assets/bastion-icon.svg" alt="Bastion"><h1>Bastion</h1><p class="muted">Console d'administration</p></div>
    <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
    <?php if ($stage === 'totp'): ?>
      <p class="muted" style="text-align:center;margin:.2rem 0 1rem">🔐 Saisissez le code à 6 chiffres de votre application d'authentification.</p>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="step" value="totp">
        <label>Code de vérification
          <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
                 required autofocus autocomplete="one-time-code"
                 style="text-align:center;letter-spacing:.4em;font-size:1.3rem"></label>
        <button type="submit">Vérifier</button>
      </form>
      <p style="text-align:center;margin-top:1rem"><a class="muted" href="/logout.php" style="font-size:.8rem">← Annuler</a></p>
    <?php else: ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="step" value="password">
        <label>Identifiant<input type="text" name="username" required autofocus placeholder="0110480 ou admin-0110480"></label>
        <label>Mot de passe<input type="password" name="password" required></label>
        <button type="submit">Se connecter</button>
      </form>
    <?php endif; ?>
    <div class="login-tag">🛡️ Contrôle d'accès réseau sécurisé</div>
  </main>
  <script>
    // Fond animé : réseau de nœuds/liens (esthétique « réseau », léger).
    (function(){
      var c=document.getElementById('bgnet'); if(!c||window.matchMedia('(prefers-reduced-motion:reduce)').matches) return;
      var x=c.getContext('2d'), W,H,pts,DPR=Math.min(window.devicePixelRatio||1,2);
      function size(){ W=c.width=innerWidth*DPR; H=c.height=innerHeight*DPR; c.style.width=innerWidth+'px'; c.style.height=innerHeight+'px';
        var n=Math.min(90,Math.round(innerWidth*innerHeight/16000)); pts=[];
        for(var i=0;i<n;i++) pts.push({x:Math.random()*W,y:Math.random()*H,vx:(Math.random()-.5)*.25*DPR,vy:(Math.random()-.5)*.25*DPR}); }
      function frame(){
        x.clearRect(0,0,W,H); var D=140*DPR;
        for(var i=0;i<pts.length;i++){ var p=pts[i]; p.x+=p.vx; p.y+=p.vy;
          if(p.x<0||p.x>W)p.vx*=-1; if(p.y<0||p.y>H)p.vy*=-1;
          for(var j=i+1;j<pts.length;j++){ var q=pts[j],dx=p.x-q.x,dy=p.y-q.y,d=Math.hypot(dx,dy);
            if(d<D){ x.strokeStyle="rgba(56,189,248,"+(1-d/D)*.5+")"; x.lineWidth=DPR; x.beginPath(); x.moveTo(p.x,p.y); x.lineTo(q.x,q.y); x.stroke(); } }
          x.fillStyle="rgba(125,211,252,.9)"; x.beginPath(); x.arc(p.x,p.y,1.6*DPR,0,6.28); x.fill(); }
        requestAnimationFrame(frame);
      }
      size(); frame(); addEventListener('resize',size);
    })();
    (function(){
      var s=document.getElementById('splash'); if(!s||s.style.display==='none') return;
      function done(){ setTimeout(function(){ s.classList.add('hide'); sessionStorage.setItem('pf_splash','1');
        setTimeout(function(){ if(s.parentNode) s.parentNode.removeChild(s); },650); }, 650); }
      if(document.readyState==='complete') done(); else window.addEventListener('load',done);
    })();
  </script>
</body>
</html>
