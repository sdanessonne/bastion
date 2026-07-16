<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — groupes & quotas (limites appliquées à la connexion via binauth). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $g = trim((string) ($_POST['groupname'] ?? ''));

    if ($action === 'save' && preg_match('/^[A-Za-z0-9._-]+$/', $g)) {
        $vals = [
            'session_timeout_min' => max(0, (int) ($_POST['session_timeout_min'] ?? 0)),
            'down_rate_kbps'      => max(0, (int) ($_POST['down_rate_kbps'] ?? 0)),
            'up_rate_kbps'        => max(0, (int) ($_POST['up_rate_kbps'] ?? 0)),
            'down_quota_mb'       => max(0, (int) ($_POST['down_quota_mb'] ?? 0)),
            'up_quota_mb'         => max(0, (int) ($_POST['up_quota_mb'] ?? 0)),
            'hours_start'         => min(24, max(0, (int) ($_POST['hours_start'] ?? 0))),
            'hours_end'           => min(24, max(0, (int) ($_POST['hours_end'] ?? 24))),
        ];
        $sql = 'INSERT INTO pf_groups (groupname,session_timeout_min,down_rate_kbps,up_rate_kbps,down_quota_mb,up_quota_mb,hours_start,hours_end)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE session_timeout_min=VALUES(session_timeout_min),down_rate_kbps=VALUES(down_rate_kbps),
                up_rate_kbps=VALUES(up_rate_kbps),down_quota_mb=VALUES(down_quota_mb),up_quota_mb=VALUES(up_quota_mb),
                hours_start=VALUES(hours_start),hours_end=VALUES(hours_end)';
        $db->prepare($sql)->execute([$g, ...array_values($vals)]);
        $flash = ['Groupe « ' . $g . ' » enregistré. Appliqué à la prochaine connexion.', 'ok'];
    }
    if ($action === 'delete' && $g !== '') {
        $db->prepare('DELETE FROM pf_groups WHERE groupname=?')->execute([$g]);
        $flash = ['Groupe supprimé.', 'ok'];
    }
}

$edit = ['groupname'=>'','session_timeout_min'=>0,'down_rate_kbps'=>0,'up_rate_kbps'=>0,'down_quota_mb'=>0,'up_quota_mb'=>0,'hours_start'=>0,'hours_end'=>24,'is_edit'=>false];
if (isset($_GET['edit'])) {
    $st = $db->prepare('SELECT * FROM pf_groups WHERE groupname=?');
    $st->execute([(string) $_GET['edit']]);
    if ($row = $st->fetch()) { $edit = $row + ['is_edit' => true]; }
}
$rows = $db->query('SELECT * FROM pf_groups ORDER BY groupname')->fetchAll();
$lim = fn($v, $u) => (int) $v > 0 ? number_format((int) $v, 0, ',', ' ') . ' ' . $u : '<span class="muted">∞</span>';

pf_header('Groupes & quotas', 'groups.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<div class="split">
  <section class="panel form-panel">
    <div class="panel-head"><h2><?= $edit['is_edit'] ? 'Modifier le groupe' : 'Nouveau groupe' ?></h2></div>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <label>Nom du groupe
        <input type="text" name="groupname" value="<?= e($edit['groupname']) ?>" <?= $edit['is_edit'] ? 'readonly' : 'required' ?>>
      </label>
      <label>Durée max de session (minutes) <span class="muted small">0 = illimité</span>
        <input type="number" name="session_timeout_min" min="0" value="<?= (int) $edit['session_timeout_min'] ?>">
      </label>
      <div class="two">
        <label>Débit ↓ (kb/s)<input type="number" name="down_rate_kbps" min="0" value="<?= (int) $edit['down_rate_kbps'] ?>"></label>
        <label>Débit ↑ (kb/s)<input type="number" name="up_rate_kbps" min="0" value="<?= (int) $edit['up_rate_kbps'] ?>"></label>
      </div>
      <div class="two">
        <label>Quota ↓ (Mo)<input type="number" name="down_quota_mb" min="0" value="<?= (int) $edit['down_quota_mb'] ?>"></label>
        <label>Quota ↑ (Mo)<input type="number" name="up_quota_mb" min="0" value="<?= (int) $edit['up_quota_mb'] ?>"></label>
      </div>
      <div class="two">
        <label>Accès de (h)<input type="number" name="hours_start" min="0" max="24" value="<?= (int) $edit['hours_start'] ?>"></label>
        <label>à (h)<input type="number" name="hours_end" min="0" max="24" value="<?= (int) $edit['hours_end'] ?>"></label>
      </div>
      <p class="muted small">Plage horaire : 0 → 24 = sans restriction. Ex. 8 → 22 = accès de 8h à 22h.</p>
      <div class="form-actions">
        <button class="btn">Enregistrer</button>
        <?php if ($edit['is_edit']): ?><a class="btn-ghost" href="/groups.php">Annuler</a><?php endif; ?>
      </div>
    </form>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Groupes (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
    <table class="grid-table">
      <thead><tr><th>Groupe</th><th>Session</th><th>Débit ↓/↑</th><th>Quota ↓/↑</th><th>Horaires</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="muted center">Aucun groupe. Créez-en un à gauche.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['groupname']) ?></strong></td>
          <td><?= (int) $r['session_timeout_min'] > 0 ? (int) $r['session_timeout_min'] . ' min' : '<span class="muted">∞</span>' ?></td>
          <td><?= $lim($r['down_rate_kbps'], 'kb/s') ?> / <?= $lim($r['up_rate_kbps'], 'kb/s') ?></td>
          <td><?= $lim($r['down_quota_mb'], 'Mo') ?> / <?= $lim($r['up_quota_mb'], 'Mo') ?></td>
          <td><?= ((int) $r['hours_start'] === 0 && (int) $r['hours_end'] === 24) ? '<span class="muted">24h/24</span>' : (int) $r['hours_start'] . 'h–' . (int) $r['hours_end'] . 'h' ?></td>
          <td class="row-actions">
            <a class="btn-sm" href="/groups.php?edit=<?= urlencode($r['groupname']) ?>">Modifier</a>
            <form method="post" onsubmit="return confirm('Supprimer le groupe <?= e($r['groupname']) ?> ?')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="groupname" value="<?= e($r['groupname']) ?>">
              <button class="btn-sm btn-danger">Suppr.</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </section>
</div>
<?php pf_footer(); ?>
