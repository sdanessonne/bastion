<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — demandes d'assistance informatique envoyées depuis l'intranet. */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();
try {
    $db->exec('CREATE TABLE IF NOT EXISTS pf_support (
        id INT AUTO_INCREMENT PRIMARY KEY, ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        username VARCHAR(64), client_ip VARCHAR(45), subject VARCHAR(160), message TEXT,
        status VARCHAR(20) DEFAULT "nouveau")');
} catch (Throwable $e) {}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'done')   { $db->prepare('UPDATE pf_support SET status="traité" WHERE id=?')->execute([$id]); $flash = ['Demande marquée traitée.', 'ok']; }
    if (($_POST['action'] ?? '') === 'reopen') { $db->prepare('UPDATE pf_support SET status="nouveau" WHERE id=?')->execute([$id]); $flash = ['Demande rouverte.', 'ok']; }
    if (($_POST['action'] ?? '') === 'delete') { $db->prepare('DELETE FROM pf_support WHERE id=?')->execute([$id]); $flash = ['Demande supprimée.', 'ok']; }
}

$rows = [];
try { $rows = $db->query('SELECT * FROM pf_support ORDER BY (status="nouveau") DESC, id DESC LIMIT 200')->fetchAll(); } catch (Throwable $e) {}
$nb = 0; foreach ($rows as $r) { if ($r['status'] === 'nouveau') { $nb++; } }

pf_header('Assistance', 'assistance.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<section class="cards">
  <div class="kpi"><div class="kpi-val" style="color:<?= $nb ? '#f87171' : 'inherit' ?>"><?= $nb ?></div><div class="kpi-lbl">Demandes en attente</div></div>
  <div class="kpi"><div class="kpi-val"><?= count($rows) ?></div><div class="kpi-lbl">Total (200 récentes)</div></div>
</section>
<section class="panel">
  <div class="panel-head"><h2>Demandes d'assistance</h2></div>
  <div class="table-wrap">
  <table class="grid-table">
    <thead><tr><th>Date</th><th>Agent</th><th>Objet / message</th><th>État</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="5" class="muted center">Aucune demande.</td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td class="muted svc-meta"><?= e($r['ts']) ?></td>
        <td><strong><?= e($r['username']) ?></strong><br><span class="muted svc-meta"><?= e($r['client_ip']) ?></span></td>
        <td><strong><?= e($r['subject']) ?></strong><br><span class="muted" style="white-space:pre-wrap"><?= e($r['message']) ?></span></td>
        <td><span class="badge <?= $r['status'] === 'nouveau' ? 'off' : 'on' ?>"><?= e($r['status']) ?></span></td>
        <td class="row-actions">
          <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <?php if ($r['status'] === 'nouveau'): ?>
              <button name="action" value="done" class="btn-sm">Marquer traité</button>
            <?php else: ?>
              <button name="action" value="reopen" class="btn-sm">Rouvrir</button>
            <?php endif; ?>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette demande ?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button name="action" value="delete" class="btn-sm btn-danger">Suppr.</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php pf_footer(); ?>
