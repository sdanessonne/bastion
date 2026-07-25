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
    /* « opacity:.7 » sur une couleur déjà atténuée descendait le contraste sous le seuil
       lisible. On garde la discrétion par la taille et la graisse, pas en effaçant le texte. */
    .login-tag{text-align:center;color:var(--muted);font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;
      margin-top:1.2rem}
    /* Les champs ne sont plus IMBRIQUÉS dans leur étiquette : il fallait sortir le bouton
       « afficher le mot de passe » du <label>, sinon cliquer dessus activait aussi le champ.
       « .login-card label » (admin.css:41) était une grille qui portait tout l'espacement —
       on le rétablit ici, sans quoi étiquettes et champs se collent. */
    .login-card form label{display:block;margin:0 0 .35rem;font-size:.82rem;color:var(--muted)}
    .login-card form #username{margin-bottom:0}
    .login-card .champ-mdp{margin-bottom:1rem}
    .champ-aide{margin:.4rem 0 .9rem;font-size:.76rem;color:var(--muted);line-height:1.4}
    .champ-aide code{background:rgba(120,150,190,.16);padding:.05rem .3rem;border-radius:4px;font-size:.95em}
    .champ-alerte{margin:.4rem 0 0;font-size:.78rem;color:#fbbf24}
    .champ-alerte[hidden]{display:none}
    /* Révélation du mot de passe : le bouton est DANS le champ, sans en réduire la zone
       de saisie utile. Un agent qui tape à l'aveugle sur un clavier de portable en a besoin. */
    .champ-mdp{position:relative;display:block}
    .champ-mdp input{width:100%;padding-right:2.9rem}
    .oeil{position:absolute;right:.45rem;top:50%;transform:translateY(-50%);
      width:2rem;height:2rem;display:grid;place-items:center;padding:0;margin:0;
      background:transparent;border:0;border-radius:8px;cursor:pointer;font-size:1rem;
      line-height:1;color:var(--muted);opacity:.75}
    .oeil:hover{opacity:1;background:rgba(120,150,190,.14)}
    .oeil[aria-pressed="true"]{opacity:1;color:var(--accent2,#38bdf8)}
    /* Focus VISIBLE : la navigation au clavier est le seul recours quand la souris lâche,
       et c'est aussi une exigence d'accessibilité. */
    .login-card :is(input,button,a):focus-visible{outline:2px solid #38bdf8;outline-offset:2px}
    /* Envoi en cours : la limitation de tentatives côté serveur peut rendre la réponse
       lente ; sans retour, l'agent reclique et déclenche une seconde tentative. */
    #btnLogin[aria-busy="true"]{opacity:.7;cursor:progress}
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
    <?php // role="alert" : sans lui, un lecteur d'écran n'annonce jamais l'échec — l'agent
          // ré-essaie sans savoir pourquoi ça a échoué. ?>
    <?php if ($error): ?><div class="flash err" role="alert"><?= e($error) ?></div><?php endif; ?>
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
      <?php
        // « autocomplete » ACTIVÉ, contrairement à l'usage réflexe. Le bloquer n'empêche
        // personne d'entrer : il empêche seulement le gestionnaire de mots de passe de
        // remplir le champ, ce qui pousse à choisir un mot de passe court et mémorisable —
        // exactement l'inverse du but recherché. Les navigateurs ignorent d'ailleurs
        // largement « autocomplete=off » sur les champs de connexion depuis des années.
      ?>
      <form method="post" id="fLogin">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="step" value="password">
        <label for="username">Identifiant</label>
        <input id="username" type="text" name="username" required autofocus
               autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false"
               inputmode="text" placeholder="0110480 ou admin-0110480"
               aria-describedby="aideId">
        <p id="aideId" class="champ-aide">Votre matricule. Préfixez-le de <code>admin-</code> pour un compte d'administration.</p>

        <label for="password">Mot de passe</label>
        <div class="champ-mdp">
          <input id="password" type="password" name="password" required autocomplete="current-password">
          <button type="button" class="oeil" id="voirMdp" aria-label="Afficher le mot de passe"
                  aria-pressed="false" title="Afficher le mot de passe">👁</button>
        </div>
        <p id="majAlerte" class="champ-alerte" hidden>⇪ Le verrouillage des majuscules est activé.</p>

        <button type="submit" id="btnLogin">Se connecter</button>
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
    // Confort et robustesse de la saisie. Tout est facultatif : si ce bloc ne s'exécute
    // pas, le formulaire reste parfaitement utilisable — aucune de ces aides n'est un
    // prérequis pour se connecter.
    (function(){
      var mdp=document.getElementById('password'), alerte=document.getElementById('majAlerte'),
          oeil=document.getElementById('voirMdp'), form=document.getElementById('fLogin'),
          btn=document.getElementById('btnLogin'), id=document.getElementById('username');

      // Verrouillage des majuscules : première cause d'échec sur un mot de passe saisi
      // à l'aveugle, et le message d'erreur du serveur ne peut pas le deviner.
      function maj(e){
        if(!alerte||typeof e.getModifierState!=='function') return;
        alerte.hidden=!e.getModifierState('CapsLock');
      }
      if(mdp){ mdp.addEventListener('keydown',maj); mdp.addEventListener('keyup',maj);
               mdp.addEventListener('blur',function(){ if(alerte) alerte.hidden=true; }); }

      if(oeil&&mdp){ oeil.addEventListener('click',function(){
        var vu=mdp.type==='text';
        mdp.type=vu?'password':'text';
        oeil.setAttribute('aria-pressed',String(!vu));
        oeil.setAttribute('aria-label',vu?'Afficher le mot de passe':'Masquer le mot de passe');
        oeil.title=oeil.getAttribute('aria-label');
        mdp.focus();
      }); }

      // Matricule : on retire les espaces collés depuis un tableur ou un courriel, cause
      // d'échec incompréhensible pour l'agent — l'identifiant a l'air correct à l'écran.
      if(id){ id.addEventListener('blur',function(){ id.value=id.value.trim(); }); }

      // Anti double-envoi : une seconde soumission consomme une tentative et rapproche
      // du verrouillage, pour rien.
      if(form&&btn){ form.addEventListener('submit',function(){
        if(btn.dataset.envoye){ return; }
        btn.dataset.envoye='1';
        btn.setAttribute('aria-busy','true');
        btn.textContent='Connexion…';
        setTimeout(function(){ btn.disabled=true; },0);   // après l'envoi, sinon rien ne part
      }); }

      // Code à 6 chiffres : accepte un collage avec espaces et envoie tout seul une fois
      // complet. Le code expire en 30 s, chaque frappe superflue compte.
      var code=document.querySelector('input[name="code"]');
      if(code){ code.addEventListener('input',function(){
        var v=code.value.replace(/\D/g,'').slice(0,6);
        if(v!==code.value) code.value=v;
        if(v.length===6&&code.form&&!code.dataset.envoye){ code.dataset.envoye='1'; code.form.submit(); }
      }); }
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
