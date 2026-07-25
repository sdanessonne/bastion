<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — redémarrage / arrêt de la passerelle.
 *
 * Ces deux actions coupent le réseau de TOUS les postes, et personne ne peut rallumer la
 * machine à distance. On ne les déclenche donc jamais d'un simple clic : cette page confirme
 * l'intention, avertit des conséquences, et pour l'arrêt exige une case cochée explicitement.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';

$a = (string) ($_GET['a'] ?? $_POST['a'] ?? '');
$a = in_array($a, ['reboot', 'shutdown'], true) ? $a : '';
if ($a === '') { header('Location: /index.php'); exit; }

$verbe = $a === 'reboot' ? 'reboot' : 'poweroff';
$titre = $a === 'reboot' ? 'Redémarrer le serveur' : 'Arrêter le serveur';

$err = null;
$lance = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // L'arrêt est irréversible à distance : on exige une confirmation qui ne peut pas être
    // un clic réflexe. Le redémarrage revient tout seul : un bouton suffit.
    if ($a === 'shutdown' && ($_POST['ack'] ?? '') !== '1') {
        $err = 'Cochez la case de confirmation : après l\'arrêt, le serveur devra être rallumé à la main.';
    } else {
        // Qui a coupé la passerelle, et quand : trace d'audit.
        error_log(sprintf('[bastion] power %s par admin=%s', $verbe, $_SESSION['admin'] ?? '?'));
        $lance = true;
    }
}

// Aperçu de l'écran d'attente (la jauge) SANS rien déclencher : power.php?a=reboot&apercu=1.
// Sert à visualiser/valider l'animation sans redémarrer le serveur.
$apercu = !$lance && $a === 'reboot' && isset($_GET['apercu']);

pf_header($titre, '');

if ($lance || $apercu):
    // On affiche la page d'attente ET on la POUSSE au navigateur AVANT de couper : sinon
    // les services s'arrêteraient pendant l'envoi et l'écran resterait blanc, sans savoir
    // si l'action a été prise en compte.
    if ($a === 'reboot'):
      // ── Écran de redémarrage AVEC jauge : la console tourne sur le serveur qui redémarre,
      // donc c'est le NAVIGATEUR (déjà chargé) qui sonde son retour et suit la progression. ──
      ?>
      <section class="panel" style="max-width:640px;margin:2rem auto;text-align:center">
        <div style="padding:2.2rem 1.5rem">
          <div id="rbico" style="font-size:2.6rem;margin-bottom:.6rem">🔄</div>
          <h2 id="rbtitle" style="margin:.2rem 0">Redémarrage du serveur…</h2>
          <p class="muted" id="rbstep" style="margin:.3rem 0 1.3rem">Arrêt des services en cours…</p>
          <div class="gauge" style="max-width:420px;margin:0 auto"><div class="gauge-bar run" id="rbbar" style="width:4%"></div></div>
          <div class="muted small" id="rbpct" style="margin-top:.5rem">4 %</div>
          <p class="muted small" id="rbnote" style="margin-top:1.3rem">Ne fermez pas cette page : elle se rechargera d'elle-même dès le retour du serveur.</p>
        </div>
      </section>
      <script>
      (function(){
        var ESTIME=90000, t0=Date.now(), fails=0, oks=0, wasDown=false, back=false;
        var bar=document.getElementById('rbbar'), pct=document.getElementById('rbpct'),
            step=document.getElementById('rbstep'), title=document.getElementById('rbtitle'),
            ico=document.getElementById('rbico'), note=document.getElementById('rbnote');
        function setPct(p){ p=Math.max(4,Math.min(100,p)); bar.style.width=p+'%'; pct.textContent=Math.round(p)+' %'; }
        var tick=setInterval(function(){
          if(back) return;
          setPct(Math.min(92, (Date.now()-t0)/ESTIME*100));
        }, 400);
        function arrive(){
          back=true; clearInterval(tick); setPct(100);
          bar.className='gauge-bar'; ico.textContent='✅';
          title.textContent='Le serveur est revenu'; step.textContent='Reconnexion à la console…'; note.textContent='';
          setTimeout(function(){ location.href='/index.php'; }, 1200);
        }
        function ping(){
          if(back) return;
          // Une réponse (200/302/…) = serveur joignable ; une erreur réseau = injoignable.
          // On n'annonce le retour QUE s'il a d'abord été VU indisponible (sinon le tout premier
          // sondage, alors que les services n'ont pas encore coupé, croirait à tort au retour).
          //
          // DÉLAI D'ATTENTE INDISPENSABLE : pendant l'arrêt puis le démarrage, la machine ne
          // REFUSE pas la connexion, elle ne répond simplement plus — la requête reste donc en
          // attente très longtemps au lieu d'échouer. Sans ce délai, l'indisponibilité n'était
          // jamais constatée, « wasDown » restait faux, et la page tournait indéfiniment à 92 %
          // alors que le serveur était déjà revenu.
          var ctl = new AbortController();
          var to  = setTimeout(function(){ ctl.abort(); }, 2500);
          fetch('/login.php?ping=' + Date.now(), { cache:'no-store', redirect:'manual', signal: ctl.signal })
            .then(function(){ clearTimeout(to); fails=0; oks++; if(wasDown) arrive(); })
            .catch(function(){ clearTimeout(to); oks=0; fails++;
                               if(fails>=2){ wasDown=true; step.textContent='Redémarrage du serveur…'; } })
            .finally(function(){
              // Filet de sécurité : si le serveur répond de façon stable bien au-delà du temps
              // d'un redémarrage, c'est qu'il est revenu (ou qu'il n'est jamais parti) — on
              // recharge plutôt que de laisser la page bloquée.
              if(!back && oks>=3 && (Date.now()-t0) > ESTIME*1.6){ arrive(); return; }
              if(!back) setTimeout(ping, 3000);
            });
        }
        // Laisser au serveur le temps de commencer à s'arrêter avant de sonder.
        setTimeout(ping, 4000);
      })();
      </script>
      <?php
    else:
      // ── Arrêt : pas de jauge, le serveur ne revient pas tout seul. ──
      ?>
      <section class="panel" style="max-width:640px;margin:2rem auto;text-align:center">
        <div style="padding:2.4rem 1.5rem">
          <div style="font-size:2.6rem;margin-bottom:.6rem">⏻</div>
          <h2 style="margin:.2rem 0">Le serveur s'arrête.</h2>
          <p class="muted">Le réseau est coupé. La machine devra être rallumée manuellement
          (bouton d'alimentation ou console de l'hyperviseur).</p>
        </div>
      </section>
      <?php
    endif;
    pf_footer();
    // Aperçu : on ne coupe rien. Sinon on vide le tampon vers le navigateur (la page est
    // déjà chez le client), PUIS seulement on déclenche la coupure.
    if ($lance) {
        while (ob_get_level() > 0) { @ob_end_flush(); }
        flush();
        shell_exec('sudo /usr/local/sbin/proxyfibre-power ' . escapeshellarg($verbe) . ' >/dev/null 2>&1 &');
    }
    exit;
endif;
?>
<section class="panel" style="max-width:640px;margin:2rem auto">
  <div class="panel-head"><h2><?= $a === 'reboot' ? '🔄' : '⏻' ?> <?= e($titre) ?></h2></div>
  <div style="padding:1.4rem">
    <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>

    <?php if ($a === 'reboot'): ?>
      <p>Le serveur va redémarrer. <strong>L'accès au réseau sera interrompu pour tous les
      postes pendant une à deux minutes</strong>, le temps du redémarrage, puis reviendra
      tout seul.</p>
    <?php else: ?>
      <div class="flash err" style="margin-bottom:1rem">
        <strong>Attention — action à conséquence.</strong> Arrêter le serveur coupe l'accès
        au réseau de tous les postes. <strong>Personne ne pourra le rallumer à distance :</strong>
        il faudra appuyer sur le bouton d'alimentation de la machine (ou la démarrer depuis
        la console de l'hyperviseur).
      </div>
    <?php endif; ?>

    <form method="post" style="margin-top:1rem">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="a" value="<?= e($a) ?>">
      <?php if ($a === 'shutdown'): ?>
        <label style="display:flex;gap:.6rem;align-items:flex-start;margin-bottom:1rem;cursor:pointer">
          <input type="checkbox" name="ack" value="1" style="margin-top:.2rem">
          <span>Je comprends que le serveur devra être <strong>rallumé manuellement</strong>.</span>
        </label>
      <?php endif; ?>
      <div style="display:flex;gap:.7rem;align-items:center">
        <button class="btn" style="background:var(--danger,#dc2626);color:#fff">
          <?= $a === 'reboot' ? 'Redémarrer maintenant' : 'Arrêter maintenant' ?></button>
        <a href="/index.php" class="btn-sm">Annuler</a>
      </div>
    </form>
  </div>
</section>
<?php pf_footer();
