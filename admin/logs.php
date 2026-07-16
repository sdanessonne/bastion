<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — journaux de connexion (traçabilité légale, art. L.34-1 CPCE). */
require_once __DIR__ . '/inc/auth.php';
$db = pf_db();

// ── Filtres ──────────────────────────────────────────────────────────────────
$q    = trim((string) ($_GET['q'] ?? ''));
$days = (int) ($_GET['days'] ?? 7);
$days = in_array($days, [1, 7, 30, 90, 365], true) ? $days : 7;

$where = 'ts >= (NOW() - INTERVAL :days DAY)';
$params = [':days' => $days];
if ($q !== '') { $where .= ' AND (username LIKE :q OR mac LIKE :q OR ip LIKE :q)'; $params[':q'] = "%$q%"; }

// ── Export CSV ───────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $st = $db->prepare("SELECT ts,event,username,groupname,ip,mac,bytes_in,bytes_out,duration_s FROM pf_connlog WHERE $where ORDER BY ts DESC");
    $st->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="proxyfibre-journal-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Horodatage','Événement','Utilisateur','Groupe','IP','MAC','Octets reçus','Octets envoyés','Durée (s)'], ';');
    foreach ($st as $r) { fputcsv($out, $r, ';'); }
    fclose($out);
    exit;
}

require_once __DIR__ . '/inc/layout.php';
$st = $db->prepare("SELECT * FROM pf_connlog WHERE $where ORDER BY ts DESC LIMIT 500");
$st->execute($params);
$rows = $st->fetchAll();
$total = (int) $db->query('SELECT COUNT(*) FROM pf_connlog')->fetchColumn();

pf_header('Journaux', 'logs.php');
?>
<section class="panel">
  <form class="toolbar" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Rechercher (utilisateur, IP, MAC)">
    <select name="days" onchange="this.form.submit()">
      <?php foreach ([1=>'24 h',7=>'7 jours',30=>'30 jours',90=>'90 jours',365=>'1 an'] as $d=>$l): ?>
        <option value="<?= $d ?>" <?= $days===$d?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn-sm">Filtrer</button>
    <a class="btn-sm ml-auto" href="/logs.php?export=csv&q=<?= urlencode($q) ?>&days=<?= $days ?>">⬇ Export CSV (RGPD)</a>
  </form>
  <div class="table-wrap">
  <table class="grid-table">
    <thead><tr><th>Horodatage</th><th>Événement</th><th>Utilisateur</th><th>IP</th><th>MAC</th><th>Reçu / Envoyé</th><th>Durée</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="7" class="muted center">Aucune entrée sur la période.</td></tr>
    <?php else: foreach ($rows as $r):
      $conn = $r['event'] === 'connect';
    ?>
      <tr>
        <td class="mono"><?= e($r['ts']) ?></td>
        <td><span class="badge <?= $conn ? 'on' : 'off' ?>"><?= $conn ? 'Connexion' : 'Déconnexion' ?></span></td>
        <td><strong><?= e($r['username']) ?></strong></td>
        <td class="mono"><?= e($r['ip']) ?></td>
        <td class="mono"><?= e($r['mac']) ?></td>
        <td><?= $conn ? '—' : fmtBytes($r['bytes_in']) . ' / ' . fmtBytes($r['bytes_out']) ?></td>
        <td><?= $conn ? '—' : fmtDuration((int) $r['duration_s']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</section>
<p class="muted small">Total en base : <?= number_format($total, 0, ',', ' ') ?> entrées ·
Conservation conforme à l'obligation légale (art. L.34-1 CPCE / R.10-13) — purge automatique au-delà de la durée de rétention configurée.</p>
<?php pf_footer(); ?>
