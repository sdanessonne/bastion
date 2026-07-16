<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — historique de navigation par utilisateur + statistiques. */
require_once __DIR__ . '/inc/auth.php';
$db = pf_db();

// ── Filtres ──────────────────────────────────────────────────────────────────
$user = trim((string) ($_GET['user'] ?? ''));
$q    = trim((string) ($_GET['q'] ?? ''));
$days = (int) ($_GET['days'] ?? 7);
$days = in_array($days, [1, 7, 30, 90, 365], true) ? $days : 7;

$where = 'ts >= (NOW() - INTERVAL :d DAY)';
$p = [':d' => $days];
if ($user !== '') { $where .= ' AND username = :u'; $p[':u'] = $user; }
if ($q !== '')    { $where .= ' AND domain LIKE :q'; $p[':q'] = "%$q%"; }

function q1(PDO $db, string $sql, array $p) { $s = $db->prepare($sql); $s->execute($p); return $s; }

// ── Export CSV ───────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $st = q1($db, "SELECT ts,username,client_ip,domain FROM pf_weblog WHERE $where ORDER BY ts DESC", $p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="proxyfibre-navigation-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Horodatage', 'Utilisateur', 'IP', 'Domaine'], ';');
    foreach ($st as $r) { fputcsv($o, $r, ';'); }
    fclose($o); exit;
}

require_once __DIR__ . '/inc/layout.php';

// Données
$users   = $db->query('SELECT username, COUNT(*) c FROM pf_weblog GROUP BY username ORDER BY c DESC')->fetchAll();
$total   = (int) q1($db, "SELECT COUNT(*) FROM pf_weblog WHERE $where", $p)->fetchColumn();
$uniq    = (int) q1($db, "SELECT COUNT(DISTINCT domain) FROM pf_weblog WHERE $where", $p)->fetchColumn();
$nbUsers = (int) q1($db, "SELECT COUNT(DISTINCT username) FROM pf_weblog WHERE $where", $p)->fetchColumn();
$top     = q1($db, "SELECT domain, COUNT(*) c FROM pf_weblog WHERE $where GROUP BY domain ORDER BY c DESC LIMIT 12", $p)->fetchAll();
$recent  = q1($db, "SELECT ts, username, client_ip, domain FROM pf_weblog WHERE $where ORDER BY ts DESC LIMIT 300", $p)->fetchAll();
$maxc    = $top ? max(array_column($top, 'c')) : 1;

pf_header('Historique de navigation', 'weblog.php');
?>
<section class="panel">
  <form class="toolbar" method="get">
    <select name="user">
      <option value="">— Tous les utilisateurs —</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= e($u['username']) ?>" <?= $user===$u['username']?'selected':'' ?>>
          <?= e($u['username']) ?> (<?= (int)$u['c'] ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Filtrer par domaine">
    <select name="days" onchange="this.form.submit()">
      <?php foreach ([1=>'24 h',7=>'7 j',30=>'30 j',90=>'90 j',365=>'1 an'] as $d=>$l): ?>
        <option value="<?= $d ?>" <?= $days===$d?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn-sm">Filtrer</button>
    <a class="btn-sm ml-auto" href="?export=csv&user=<?= urlencode($user) ?>&q=<?= urlencode($q) ?>&days=<?= $days ?>">⬇ Export CSV</a>
  </form>
</section>

<section class="cards">
  <div class="kpi"><div class="kpi-val"><?= number_format($total,0,',',' ') ?></div><div class="kpi-lbl">Sites consultés<?= $user?' par '.e($user):'' ?></div></div>
  <div class="kpi"><div class="kpi-val"><?= number_format($uniq,0,',',' ') ?></div><div class="kpi-lbl">Domaines uniques</div></div>
  <?php if ($user === ''): ?>
    <div class="kpi"><div class="kpi-val"><?= $nbUsers ?></div><div class="kpi-lbl">Utilisateurs actifs</div></div>
  <?php endif; ?>
  <div class="kpi"><div class="kpi-val"><?= $days ?> j</div><div class="kpi-lbl">Période analysée</div></div>
</section>

<div class="split" style="grid-template-columns:360px 1fr">
  <section class="panel">
    <div class="panel-head"><h2>Sites les plus consultés<?= $user?' — '.e($user):'' ?></h2></div>
    <div style="padding:1rem 1.2rem;display:grid;gap:.7rem">
      <?php if (!$top): ?><span class="muted">Aucune donnée.</span><?php endif; ?>
      <?php foreach ($top as $t): ?>
        <div>
          <div style="display:flex;justify-content:space-between;font-size:.85rem">
            <span><?= e($t['domain']) ?></span><span class="muted"><?= (int)$t['c'] ?></span>
          </div>
          <div class="bar" style="height:8px;margin-top:.2rem"><div class="bar-fill" style="width:<?= round(100*$t['c']/$maxc) ?>%"></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Historique détaillé (<?= count($recent) ?> dernières visites)</h2></div>
    <div class="table-wrap">
    <table class="grid-table">
      <thead><tr><th>Horodatage</th><?php if($user===''):?><th>Utilisateur</th><?php endif;?><th>Domaine</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="4" class="muted center">Aucune visite sur la période.</td></tr>
      <?php else: foreach ($recent as $r): ?>
        <tr>
          <td class="mono"><?= e($r['ts']) ?></td>
          <?php if($user===''):?><td><strong><?= e($r['username']) ?></strong></td><?php endif;?>
          <td><?= e($r['domain']) ?></td>
          <td class="mono"><?= e($r['client_ip']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </section>
</div>
<p class="muted small">Journalisation des domaines consultés (résolution DNS) — conforme à l'obligation de traçabilité.
Les URLs complètes et le contenu chiffré (HTTPS) ne sont pas enregistrés (respect de la vie privée).</p>
<?php pf_footer(); ?>
