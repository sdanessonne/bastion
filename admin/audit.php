<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — Journal d'audit : les actions d'ADMINISTRATION (qui a fait quoi, quand). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';
$db = pf_db();
audit_migre($db);

// Filtres (tous bornés / échappés).
$fAdmin  = preg_replace('/[^A-Za-z0-9._@-]/', '', (string) ($_GET['admin'] ?? ''));
$fAction = preg_replace('/[^a-zA-Z._]/', '', (string) ($_GET['action'] ?? ''));
$days = (int) ($_GET['days'] ?? 30);
if ($days < 1 || $days > 366) { $days = 30; }

$where = ['ts >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
$args  = [$days];
if ($fAdmin  !== '') { $where[] = 'admin = ?';       $args[] = $fAdmin; }
if ($fAction !== '') { $where[] = 'action LIKE ?';   $args[] = $fAction . '%'; }
$sqlWhere = implode(' AND ', $where);

$rows = [];
try {
    $st = $db->prepare('SELECT * FROM pf_audit WHERE ' . $sqlWhere . ' ORDER BY id DESC LIMIT 400');
    $st->execute($args);
    $rows = $st->fetchAll();
} catch (Throwable $e) {}

// Export CSV (mêmes filtres).
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-bastion.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");   // BOM UTF-8 pour Excel
    fputcsv($out, ['Date', 'Administrateur', 'Action', 'Détail', 'IP'], ';');
    foreach ($rows as $r) { fputcsv($out, [$r['ts'], $r['admin'], $r['action'], $r['detail'], $r['ip']], ';'); }
    fclose($out); exit;
}

$admins = $actions = [];
try { foreach ($db->query('SELECT DISTINCT admin FROM pf_audit WHERE admin IS NOT NULL ORDER BY admin') as $r) { $admins[] = $r['admin']; } } catch (Throwable $e) {}
try { foreach ($db->query('SELECT DISTINCT action FROM pf_audit ORDER BY action') as $r) { $actions[] = $r['action']; } } catch (Throwable $e) {}

// Libellés lisibles pour les actions les plus fréquentes.
$LBL = [
    'users.save' => 'Compte enregistré', 'users.delete' => 'Compte supprimé',
    'antivirus.token_revoke' => 'Jeton station révoqué', 'antivirus.token_delete' => 'Jeton station supprimé',
    'systeme.syspasswd' => 'Mot de passe système changé', 'systeme.update' => 'Mise à jour lancée',
    'ad.user_create' => 'Utilisateur AD créé', 'ad.user_delete' => 'Utilisateur AD supprimé',
    'ad.gpo_unlink' => 'GPO désactivée', 'ad.gpo_link' => 'GPO réactivée', 'ad.gpo_delete' => 'GPO supprimée',
    'ad.gpo_deploy' => 'GPO déployée', 'ad.gpo_cert' => 'Certificat déployé', 'ad.sysvol_reset' => 'Permissions SYSVOL réparées',
    'ad.share_create' => 'Partage créé', 'ad.share_delete' => 'Partage retiré', 'ad.drives_deploy' => 'Lecteurs réseau déployés',
    'ad.wallpaper_deploy' => "Fond d'écran déployé",
];

pf_header("Journal d'audit", 'audit.php');
?>
<section class="panel">
  <div class="panel-head"><h2>🛡️ Journal d'audit des administrateurs (<?= count($rows) ?>)</h2></div>
  <div style="padding:1.2rem">
    <p class="muted small" style="margin-top:0">Qui a fait quoi, et quand, dans cette console. Aucun secret (mot de
    passe, jeton) n'est enregistré — seulement l'action et sa cible.</p>
    <form method="get" class="dir-inline" style="margin-bottom:1rem;gap:.5rem">
      <?php if (($_GET['embed'] ?? '') === '1'): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
      <select name="admin" style="padding:.5rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
        <option value="">— Tous les administrateurs —</option>
        <?php foreach ($admins as $a): ?><option value="<?= e($a) ?>" <?= $fAdmin === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
      </select>
      <select name="action" style="padding:.5rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
        <option value="">— Toutes les actions —</option>
        <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $fAction === $a ? 'selected' : '' ?>><?= e($LBL[$a] ?? $a) ?></option><?php endforeach; ?>
      </select>
      <select name="days" style="padding:.5rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
        <?php foreach ([7=>'7 jours',30=>'30 jours',90=>'90 jours',366=>'1 an'] as $d => $lbl): ?>
          <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
      </select>
      <button class="btn-sm">Filtrer</button>
      <a class="btn-sm" href="?<?= e(http_build_query(['admin'=>$fAdmin,'action'=>$fAction,'days'=>$days,'csv'=>1] + ((($_GET['embed'] ?? '')==='1')?['embed'=>1]:[]))) ?>" download>⬇ Export CSV</a>
    </form>
    <div class="table-wrap"><table class="grid-table">
      <thead><tr><th style="width:145px">Date</th><th>Administrateur</th><th>Action</th><th>Détail</th><th>IP</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted center">Aucune action enregistrée sur cette période.</td></tr>
        <?php else: foreach ($rows as $r): $ech = strpos((string) $r['detail'], '[échec]') !== false; ?>
          <tr>
            <td class="muted svc-meta"><?= e(date('d/m/Y H:i:s', strtotime((string) $r['ts']))) ?></td>
            <td><strong><?= e($r['admin'] ?: '—') ?></strong></td>
            <td><span class="badge <?= $ech ? 'off' : 'on' ?>" style="font-size:.66rem"><?= e($r['action']) ?></span>
              <div class="muted small"><?= e($LBL[$r['action']] ?? '') ?></div></td>
            <td class="muted small" style="max-width:34ch;overflow:hidden;text-overflow:ellipsis"><?= e($r['detail'] ?? '') ?></td>
            <td class="muted svc-meta"><?= e($r['ip'] ?? '') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</section>
<?php pf_footer(); ?>
