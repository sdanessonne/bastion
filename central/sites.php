<?php
/** Bastion Central — registre des passerelles de site (ajout / édition / suppression). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id    = (int) ($_POST['id'] ?? 0);
        $name  = trim((string) ($_POST['name'] ?? ''));
        $comm  = trim((string) ($_POST['commissariat'] ?? ''));
        $url   = trim((string) ($_POST['base_url'] ?? ''));
        $token = trim((string) ($_POST['token'] ?? ''));
        $en    = isset($_POST['enabled']) ? 1 : 0;
        if ($name === '' || !preg_match('#^https?://#', $url) || $token === '') {
            $flash = ['Nom, URL (https://…) et token sont requis.', 'err'];
        } elseif ($id > 0) {
            $db->prepare('UPDATE pf_central_sites SET name=?,commissariat=?,base_url=?,token=?,enabled=? WHERE id=?')
               ->execute([$name, $comm, $url, $token, $en, $id]);
            $flash = ['Site mis à jour.', 'ok'];
        } else {
            $db->prepare('INSERT INTO pf_central_sites (name,commissariat,base_url,token,enabled) VALUES (?,?,?,?,?)')
               ->execute([$name, $comm, $url, $token, $en]);
            $flash = ['Site ajouté.', 'ok'];
        }
    }
    if ($action === 'delete' && !empty($_POST['id'])) {
        $db->prepare('DELETE FROM pf_central_sites WHERE id=?')->execute([(int) $_POST['id']]);
        $flash = ['Site supprimé.', 'ok'];
    }
    if ($action === 'test' && !empty($_POST['id'])) {
        $s = $db->query('SELECT * FROM pf_central_sites WHERE id=' . (int) $_POST['id'])->fetch();
        $r = $s ? capi($s, 'status', null, 5) : ['error' => 'introuvable'];
        $flash = empty($r['error']) && !empty($r['ok'])
            ? ['Connexion OK — ' . e($r['host']) . ' v' . e($r['version']) . ', ' . (int) $r['services_ok'] . '/' . (int) $r['services_total'] . ' services.', 'ok']
            : ['Échec : ' . e($r['error'] ?? 'réponse inattendue') . '.', 'err'];
    }
}

$edit = ['id' => 0, 'name' => '', 'commissariat' => '', 'base_url' => 'https://', 'token' => '', 'enabled' => 1];
if (isset($_GET['edit'])) {
    $row = $db->query('SELECT * FROM pf_central_sites WHERE id=' . (int) $_GET['edit'])->fetch();
    if ($row) { $edit = $row; }
}
$rows = sites_all();

pf_header('Sites / passerelles', 'sites.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<div class="split">
  <section class="panel form-panel">
    <div class="panel-head"><h2><?= $edit['id'] ? 'Modifier le site' : 'Ajouter un site' ?></h2></div>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
      <label>Commissariat / localisation
        <input type="text" name="commissariat" value="<?= e($edit['commissariat']) ?>" placeholder="ex. 91 — Évry-Courcouronnes"></label>
      <label>Nom de la passerelle
        <input type="text" name="name" value="<?= e($edit['name']) ?>" required placeholder="ex. Bastion-Evry"></label>
      <label>URL de l'API <span class="muted small">(console admin du site)</span>
        <input type="text" name="base_url" value="<?= e($edit['base_url']) ?>" required placeholder="https://10.x.x.x:8443"></label>
      <label>Jeton d'API
        <input type="text" name="token" value="<?= e($edit['token']) ?>" required placeholder="pf_settings.api_token du site"></label>
      <label class="chk" style="display:flex;align-items:center;gap:.5rem">
        <input type="checkbox" name="enabled" <?= $edit['enabled'] ? 'checked' : '' ?>> Superviser ce site</label>
      <div class="form-actions">
        <button class="btn">Enregistrer</button>
        <?php if ($edit['id']): ?><a class="btn-ghost" href="/sites.php">Annuler</a><?php endif; ?>
      </div>
      <p class="muted small">Le jeton se lit sur la passerelle :
      <code>SELECT v FROM pf_settings WHERE k='api_token'</code>.</p>
    </form>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Passerelles enregistrées (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
    <table class="grid-table">
      <thead><tr><th>Commissariat</th><th>Passerelle</th><th>Supervision</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="4" class="muted center">Aucun site. Ajoutez-en un à gauche.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['commissariat'] ?: '—') ?></strong></td>
          <td><?= e($r['name']) ?><br><span class="muted svc-meta"><?= e($r['base_url']) ?></span></td>
          <td><span class="badge <?= $r['enabled'] ? 'on' : 'off' ?>"><?= $r['enabled'] ? 'Active' : 'Désactivée' ?></span></td>
          <td class="row-actions">
            <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn-sm">Tester</button></form>
            <a class="btn-sm" href="/sites.php?edit=<?= (int) $r['id'] ?>">Modifier</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Supprimer ce site ?')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn-sm btn-danger">Supprimer</button></form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </section>
</div>
<?php pf_footer(); ?>
