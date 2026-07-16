<?php
/** Bastion Central — actions groupées (pousse une config vers plusieurs passerelles). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();

$sites   = sites_all(true);            // seulement les sites supervisés
$byId    = [];
foreach ($sites as $s) { $byId[$s['id']] = $s; }

$results = [];
$flash   = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op      = $_POST['op'] ?? '';
    $targets = array_map('intval', (array) ($_POST['targets'] ?? []));
    $targets = array_values(array_filter($targets, fn($i) => isset($byId[$i])));

    if (!$targets) {
        $flash = ['Sélectionnez au moins une passerelle cible.', 'err'];
    } else {
        foreach ($targets as $tid) {
            $site = $byId[$tid];
            if ($op === 'filter') {
                $r = capi($site, 'filter.add', ['domains' => (string) ($_POST['domains'] ?? ''), 'category' => (string) ($_POST['category'] ?? 'central')], 15);
                $msg = empty($r['error']) ? ((int) ($r['added'] ?? 0)) . ' domaine(s) ajouté(s)' : 'échec : ' . $r['error'];
            } elseif ($op === 'user') {
                $r = capi($site, 'users.add', ['username' => (string) ($_POST['username'] ?? ''), 'password' => (string) ($_POST['password'] ?? ''), 'groupname' => (string) ($_POST['groupname'] ?? '')], 12);
                $msg = empty($r['error']) ? 'compte créé' : 'échec : ' . $r['error'];
            } elseif ($op === 'service') {
                $r = capi($site, 'service', ['svc' => (string) ($_POST['svc'] ?? ''), 'do' => (string) ($_POST['do'] ?? '')], 15);
                $msg = empty($r['error']) ? 'action envoyée' : 'échec : ' . $r['error'];
            } elseif ($op === 'ad_user') {
                $r = capi($site, 'ad.user.add', ['username' => (string) ($_POST['username'] ?? ''), 'password' => (string) ($_POST['password'] ?? ''), 'groupname' => (string) ($_POST['groupname'] ?? '')], 20);
                $msg = empty($r['error']) ? 'fonctionnaire créé (AD)' : 'échec : ' . $r['error'];
            } else {
                $msg = 'opération inconnue';
            }
            $results[] = [$site['commissariat'] ?: $site['name'], $msg, empty($r['error'] ?? 'x')];
        }
        $flash = [count($results) . ' passerelle(s) traitée(s).', 'ok'];
    }
}

function site_checks(array $sites): string {
    if (!$sites) { return '<p class="muted small">Aucun site supervisé. Ajoutez-en dans « Sites / passerelles ».</p>'; }
    $h = '<div class="targets"><label class="chk"><input type="checkbox" onchange="this.closest(\'form\').querySelectorAll(\'.tgt\').forEach(c=>c.checked=this.checked)"> <strong>Tous</strong></label>';
    foreach ($sites as $s) {
        $h .= '<label class="chk"><input class="tgt" type="checkbox" name="targets[]" value="' . (int) $s['id'] . '"> '
            . e($s['commissariat'] ?: $s['name']) . '</label>';
    }
    return $h . '</div>';
}

pf_header('Actions groupées', 'push.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<style>
  .targets{display:flex;flex-wrap:wrap;gap:.4rem 1.2rem;padding:.6rem .8rem;background:var(--bg);
    border:1px solid var(--line);border-radius:10px;margin-bottom:.8rem}
  .targets .chk{display:inline-flex;align-items:center;gap:.4rem;font-size:.9rem}
  .push-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem}
  @media(max-width:820px){.push-grid{grid-template-columns:1fr}}
</style>

<?php if ($results): ?>
<section class="panel">
  <div class="panel-head"><h2>Résultat du déploiement</h2></div>
  <div class="table-wrap"><table class="grid-table">
    <thead><tr><th>Passerelle</th><th>Résultat</th></tr></thead>
    <tbody><?php foreach ($results as [$name, $msg, $good]): ?>
      <tr><td><strong><?= e($name) ?></strong></td>
        <td><span class="badge <?= $good ? 'on' : 'off' ?>"><?= e($msg) ?></span></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>

<div class="push-grid">
  <section class="panel form-panel">
    <div class="panel-head"><h2>⛔ Pousser des domaines à bloquer</h2></div>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="op" value="filter">
      <?= site_checks($sites) ?>
      <label>Catégorie<input type="text" name="category" placeholder="ex. central, adulte, malware" value="central"></label>
      <label>Domaines <span class="muted small">(un par ligne)</span>
        <textarea name="domains" rows="5" style="width:100%;padding:.6rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:10px;font-family:ui-monospace,monospace;font-size:.85rem" placeholder="exemple1.com&#10;exemple2.net"></textarea></label>
      <div class="form-actions"><button class="btn">Déployer sur les sites cochés</button></div>
    </form>
  </section>

  <section class="panel form-panel">
    <div class="panel-head"><h2>👤 Créer un compte utilisateur</h2></div>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="op" value="user">
      <?= site_checks($sites) ?>
      <label>Identifiant<input type="text" name="username" required placeholder="ex. agent.dupont"></label>
      <label>Mot de passe<input type="text" name="password" required></label>
      <label>Groupe <span class="muted small">(optionnel)</span><input type="text" name="groupname" placeholder="ex. default"></label>
      <div class="form-actions"><button class="btn">Créer sur les sites cochés</button></div>
    </form>
  </section>

  <section class="panel form-panel">
    <div class="panel-head"><h2>🗄️ Créer un fonctionnaire (Active Directory)</h2></div>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="op" value="ad_user">
      <?= site_checks($sites) ?>
      <label>Identifiant<input type="text" name="username" required placeholder="ex. prenom.nom"></label>
      <label>Mot de passe<input type="text" name="password" required placeholder="complexité AD (8+, Maj, chiffre)"></label>
      <label>Groupe AD <span class="muted small">(optionnel)</span><input type="text" name="groupname" placeholder="ex. Fonctionnaires"></label>
      <div class="form-actions"><button class="btn">Créer dans l'AD des sites cochés</button></div>
      <p class="hint">Crée le compte dans le domaine Active Directory de chaque passerelle cochée.</p>
    </form>
  </section>

  <section class="panel form-panel" style="grid-column:1/-1">
    <div class="panel-head"><h2>🧰 Piloter un service</h2></div>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="op" value="service">
      <?= site_checks($sites) ?>
      <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <label style="flex:1;min-width:180px">Service
          <select name="svc" style="width:100%;padding:.6rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:10px">
            <?php foreach (['opennds' => 'Portail captif', 'freeradius' => 'Authentification', 'dnsmasq' => 'DHCP/DNS/PXE', 'chrony' => 'Serveur de temps', 'proxyfibre-weblog' => 'Historique navigation', 'apache2' => 'Serveur web'] as $u => $l): ?>
              <option value="<?= $u ?>"><?= e($l) ?> (<?= $u ?>)</option>
            <?php endforeach; ?>
          </select></label>
        <label style="flex:1;min-width:140px">Action
          <select name="do" style="width:100%;padding:.6rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:10px">
            <option value="restart">Redémarrer</option><option value="start">Démarrer</option><option value="stop">Arrêter</option><option value="reload">Recharger</option>
          </select></label>
      </div>
      <div class="form-actions"><button class="btn">Exécuter sur les sites cochés</button></div>
    </form>
  </section>
</div>
<?php pf_footer(); ?>
