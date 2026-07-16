<?php
/** Bastion Central — détail d'une passerelle : état complet + pilotage à distance. */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();

$id   = (int) ($_GET['id'] ?? 0);
$site = $db->query('SELECT * FROM pf_central_sites WHERE id=' . $id)->fetch();
if (!$site) { header('Location: /index.php'); exit; }

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'service') {
        $r = capi($site, 'service', ['svc' => (string) ($_POST['svc'] ?? ''), 'do' => (string) ($_POST['do'] ?? '')], 12);
        $flash = empty($r['error']) ? ['Action « ' . e($_POST['do']) . ' » envoyée à ' . e($_POST['svc']) . '.', 'ok']
                                    : ['Échec : ' . e($r['error']) . '.', 'err'];
    }
}

$s = capi($site, 'status', null, 6);
$ok = empty($s['error']) && !empty($s['ok']);
$ad = $ok ? capi($site, 'ad.status', null, 6) : ['error' => 'hors ligne'];

$labels = [
    'opennds' => 'Portail captif', 'freeradius' => 'Authentification', 'mariadb' => 'Base de données',
    'apache2' => 'Serveur web', 'dnsmasq' => 'DHCP / DNS / PXE', 'chrony' => 'Serveur de temps',
    'proxyfibre-weblog' => 'Historique navigation', 'proxyfibre-walledgarden' => 'Walled garden',
];

pf_header('Site — ' . ($site['commissariat'] ?: $site['name']), 'index.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<p><a class="btn-sm" href="/index.php">← Vue d'ensemble</a> &nbsp;
   <a class="btn-sm" href="/sites.php?edit=<?= $id ?>">Modifier ce site</a></p>

<?php if (!$ok): ?>
  <div class="flash err">Passerelle injoignable : <?= e($s['error'] ?? 'réponse inattendue') ?>
   (<?= e($site['base_url']) ?>).</div>
<?php else: ?>
<section class="cards">
  <div class="kpi"><div class="kpi-val"><?= e($s['services_ok']) ?>/<?= e($s['services_total']) ?></div><div class="kpi-lbl">Services OK</div></div>
  <div class="kpi"><div class="kpi-val"><?= (int) $s['sessions'] ?></div><div class="kpi-lbl">Sessions actives</div></div>
  <div class="kpi"><div class="kpi-val"><?= (int) $s['users'] ?></div><div class="kpi-lbl">Comptes</div></div>
  <div class="kpi"><div class="kpi-val" style="font-size:1rem"><?= e($s['uptime'] ?: '—') ?></div><div class="kpi-lbl">Disponibilité</div></div>
</section>

<section class="panel">
  <div class="panel-head"><h2><?= e($site['name']) ?> <span class="muted svc-meta"><?= e($s['host']) ?> · v<?= e($s['version']) ?></span></h2>
    <a class="btn-sm" href="site.php?id=<?= $id ?>">↻ Actualiser</a>
  </div>
  <div class="table-wrap">
  <table class="grid-table">
    <thead><tr><th>Service</th><th>État</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($s['services'] as $unit => $state):
        $isOk = $state === 'ok'; ?>
      <tr>
        <td><strong><?= e($labels[$unit] ?? $unit) ?></strong><br><span class="muted svc-meta"><?= e($unit) ?></span></td>
        <td><span class="badge <?= $isOk ? 'on' : 'off' ?>"><?= $isOk ? 'Actif' : 'Arrêté' ?></span></td>
        <td class="row-actions">
          <?php foreach (['restart' => '↻ Redémarrer', 'start' => '▶ Démarrer', 'stop' => '■ Arrêter'] as $do => $lbl):
              if ($unit === 'apache2' && $do !== 'restart') continue; ?>
            <form method="post" style="display:inline"<?= $do === 'stop' ? ' onsubmit="return confirm(\'Arrêter ce service à distance ?\')"' : '' ?>>
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="service">
              <input type="hidden" name="svc" value="<?= e($unit) ?>">
              <input type="hidden" name="do" value="<?= $do ?>">
              <button class="btn-sm<?= $do === 'stop' ? ' btn-danger' : '' ?>"><?= $lbl ?></button>
            </form>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small" style="padding:0 1.2rem 1rem">
    Charge : <?= e($s['load'] ?: '—') ?> · Filtrage : <?= (int) $s['blocklist'] ?> domaine(s) ·
    Bloqueur pub : <?= !empty($s['adblock']) ? number_format((int) $s['adblock_count'], 0, ',', ' ') . ' domaines' : 'désactivé' ?>.
  </p>
</section>

<section class="panel">
  <div class="panel-head"><h2>🗄️ Active Directory</h2>
    <?php if (!empty($ad['dc'])): ?><span class="badge on">Contrôleur actif</span>
    <?php else: ?><span class="badge off">Non déployé</span><?php endif; ?>
  </div>
  <?php if (!empty($ad['dc'])): ?>
    <section class="cards" style="padding:1rem 1.2rem">
      <div class="kpi"><div class="kpi-val"><?= (int) $ad['fonctionnaires'] ?></div><div class="kpi-lbl">Fonctionnaires</div></div>
      <div class="kpi"><div class="kpi-val"><?= (int) $ad['ordinateurs'] ?></div><div class="kpi-lbl">Ordinateurs</div></div>
      <div class="kpi"><div class="kpi-val"><?= (int) $ad['gpo'] ?></div><div class="kpi-lbl">GPO</div></div>
      <div class="kpi"><div class="kpi-val"><?= (int) $ad['partages'] ?></div><div class="kpi-lbl">Partages</div></div>
    </section>
  <?php else: ?>
    <p class="muted small" style="padding:0 1.2rem 1rem">Aucun Active Directory sur ce site
    (<code>setup-ad.sh</code> non exécuté).</p>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php pf_footer(); ?>
