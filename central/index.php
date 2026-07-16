<?php
/** Bastion Central — vue d'ensemble de la flotte (supervision temps réel). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';

$sites = sites_all();
$st = [];               // id => status
$agg = ['online' => 0, 'sessions' => 0, 'users' => 0, 'alerts' => 0, 'blocklist' => 0];
foreach ($sites as $s) {
    if (!$s['enabled']) { $st[$s['id']] = ['error' => 'désactivé']; continue; }
    $r = capi($s, 'status', null, 4);
    $st[$s['id']] = $r;
    if (empty($r['error']) && !empty($r['ok'])) {
        $agg['online']++;
        $agg['sessions']  += (int) ($r['sessions'] ?? 0);
        $agg['users']     += (int) ($r['users'] ?? 0);
        $agg['blocklist'] += (int) ($r['blocklist'] ?? 0);
        if (($r['services_ok'] ?? 0) < ($r['services_total'] ?? 0)) { $agg['alerts']++; }
    } else {
        $agg['alerts']++;
    }
}
$total = count($sites);

pf_header('Vue d\'ensemble', 'index.php');
?>
<section class="cards">
  <div class="kpi"><div class="kpi-val"><?= $agg['online'] ?>/<?= $total ?></div><div class="kpi-lbl">Passerelles en ligne</div></div>
  <div class="kpi"><div class="kpi-val"><?= $agg['sessions'] ?></div><div class="kpi-lbl">Sessions actives (flotte)</div></div>
  <div class="kpi"><div class="kpi-val"><?= number_format($agg['users'], 0, ',', ' ') ?></div><div class="kpi-lbl">Comptes (cumul)</div></div>
  <div class="kpi"><div class="kpi-val" style="color:<?= $agg['alerts'] ? '#f87171' : 'inherit' ?>"><?= $agg['alerts'] ?></div><div class="kpi-lbl">Sites en alerte</div></div>
</section>

<section class="panel">
  <div class="panel-head"><h2>État des passerelles</h2>
    <a class="btn-sm" href="index.php">↻ Actualiser</a>
  </div>
  <div class="table-wrap">
  <table class="grid-table">
    <thead><tr><th>Commissariat</th><th>Passerelle</th><th>État</th><th>Services</th><th>Sessions</th><th>Comptes</th><th>Version</th><th></th></tr></thead>
    <tbody>
    <?php if (!$sites): ?>
      <tr><td colspan="8" class="muted center">Aucun site enregistré. Ajoutez vos passerelles dans « Sites / passerelles ».</td></tr>
    <?php else: foreach ($sites as $s): $r = $st[$s['id']];
        $ok = empty($r['error']) && !empty($r['ok']);
        $degraded = $ok && (($r['services_ok'] ?? 0) < ($r['services_total'] ?? 0)); ?>
      <tr>
        <td><strong><?= e($s['commissariat'] ?: '—') ?></strong></td>
        <td><?= e($s['name']) ?><br><span class="muted svc-meta"><?= e($s['base_url']) ?></span></td>
        <td>
          <?php if (!$s['enabled']): ?><span class="badge off">Désactivé</span>
          <?php elseif (!$ok): ?><span class="badge off">Hors ligne</span>
          <?php elseif ($degraded): ?><span class="badge warn">Dégradé</span>
          <?php else: ?><span class="badge on">En ligne</span><?php endif; ?>
        </td>
        <td><?= $ok ? e($r['services_ok']) . '/' . e($r['services_total']) : '<span class="muted">—</span>' ?></td>
        <td><?= $ok ? (int) $r['sessions'] : '<span class="muted">—</span>' ?></td>
        <td><?= $ok ? (int) $r['users'] : '<span class="muted">—</span>' ?></td>
        <td class="muted svc-meta"><?= $ok ? e($r['version']) : (e($r['error'] ?? '—')) ?></td>
        <td><a class="btn-sm" href="site.php?id=<?= (int) $s['id'] ?>">Détails</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small" style="padding:0 1.2rem 1rem">Supervision en direct : le central interroge chaque passerelle
  via son API sécurisée (délai 4 s/site). Les sites injoignables apparaissent « hors ligne ».</p>
</section>
<?php pf_footer(); ?>
