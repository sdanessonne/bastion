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

pf_header($titre, '');

if ($lance):
    // On affiche la page d'attente ET on la POUSSE au navigateur AVANT de couper : sinon
    // les services s'arrêteraient pendant l'envoi et l'écran resterait blanc, sans savoir
    // si l'action a été prise en compte.
    $min = $a === 'reboot' ? 'Le serveur redémarre.' : 'Le serveur s\'arrête.';
    ?>
    <section class="panel" style="max-width:640px;margin:2rem auto;text-align:center">
      <div style="padding:2.4rem 1.5rem">
        <div style="font-size:2.6rem;margin-bottom:.6rem"><?= $a === 'reboot' ? '🔄' : '⏻' ?></div>
        <h2 style="margin:.2rem 0"><?= e($min) ?></h2>
        <p class="muted"><?= $a === 'reboot'
            ? 'La console sera de nouveau accessible dans une à deux minutes. Rechargez la page à ce moment-là.'
            : 'Le réseau est coupé. La machine devra être rallumée manuellement (bouton d\'alimentation ou console de l\'hyperviseur).' ?></p>
      </div>
    </section>
    <?php
    pf_footer();
    // On vide tout ce que PHP a mis en tampon vers le navigateur, puis SEULEMENT APRÈS on
    // déclenche la coupure. La page est déjà chez le client quand les services tombent.
    while (ob_get_level() > 0) { @ob_end_flush(); }
    flush();
    shell_exec('sudo /usr/local/sbin/proxyfibre-power ' . escapeshellarg($verbe) . ' >/dev/null 2>&1 &');
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
