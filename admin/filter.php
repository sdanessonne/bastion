<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — filtrage de contenu (domaines bloqués via DNS). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        // Normaliser : minuscules, sans schéma/chemin/www.
        $dom = strtolower(trim((string) ($_POST['domain'] ?? '')));
        $dom = preg_replace('#^https?://#', '', $dom);
        $dom = preg_replace('#/.*$#', '', $dom);
        $dom = preg_replace('#^www\.#', '', $dom);
        if (preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $dom)) {
            $st = $db->prepare('INSERT IGNORE INTO pf_blocklist (domain,category,added_by) VALUES (?,?,?)');
            $st->execute([$dom, trim((string) ($_POST['category'] ?? 'manuel')) ?: 'manuel', $_SESSION['admin']]);
            shell_exec('sudo /usr/local/sbin/proxyfibre-apply-filter 2>/dev/null');
            $flash = ['Domaine « ' . $dom . ' » bloqué.', 'ok'];
        } else {
            $flash = ['Domaine invalide.', 'err'];
        }
    }
    if ($action === 'del' && !empty($_POST['id'])) {
        $db->prepare('DELETE FROM pf_blocklist WHERE id=?')->execute([(int) $_POST['id']]);
        shell_exec('sudo /usr/local/sbin/proxyfibre-apply-filter 2>/dev/null');
        $flash = ['Domaine débloqué.', 'ok'];
    }

    if ($action === 'adblock_enable') {
        shell_exec('sudo /usr/local/sbin/proxyfibre-update-adblock enable 2>/dev/null');
        $flash = ['Bloqueur de publicités activé / mis à jour.', 'ok'];
    }
    if ($action === 'adblock_disable') {
        shell_exec('sudo /usr/local/sbin/proxyfibre-update-adblock disable 2>/dev/null');
        $flash = ['Bloqueur de publicités désactivé.', 'ok'];
    }

    if ($action === 'import') {
        $cat = trim((string) ($_POST['category'] ?? 'liste')) ?: 'liste';
        $st  = $db->prepare('INSERT IGNORE INTO pf_blocklist (domain,category,added_by) VALUES (?,?,?)');
        $n = 0;
        foreach (preg_split('/\s+/', (string) ($_POST['domains'] ?? '')) as $d) {
            $d = strtolower(trim($d));
            $d = preg_replace(['#^https?://#', '#/.*$#', '#^www\.#'], '', $d);
            if (preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $d)) {
                $st->execute([$d, $cat, $_SESSION['admin']]); $n += $st->rowCount();
            }
        }
        shell_exec('sudo /usr/local/sbin/proxyfibre-apply-filter 2>/dev/null');
        $flash = ["$n domaine(s) importé(s) et bloqué(s).", 'ok'];
    }

    if ($action === 'cat_enable' || $action === 'cat_disable') {
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_POST['cat'] ?? '')));
        $cats = (function_exists('yaml_parse_file') && is_file('/etc/proxyfibre/categories.yaml'))
              ? (yaml_parse_file('/etc/proxyfibre/categories.yaml') ?: []) : [];
        if (isset($cats[$slug])) {
            if ($action === 'cat_enable') {
                $st = $db->prepare('INSERT IGNORE INTO pf_blocklist (domain,category,added_by) VALUES (?,?,?)');
                $n = 0;
                foreach (($cats[$slug]['domains'] ?? []) as $d) {
                    $d = strtolower(trim((string) $d));
                    if (preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $d)) { $st->execute([$d, $slug, 'catégorie']); $n += $st->rowCount(); }
                }
                $flash = ['Catégorie « ' . ($cats[$slug]['label'] ?? $slug) . " » activée ($n domaine(s)).", 'ok'];
            } else {
                $db->prepare('DELETE FROM pf_blocklist WHERE category=?')->execute([$slug]);
                $flash = ['Catégorie « ' . ($cats[$slug]['label'] ?? $slug) . ' » désactivée.', 'ok'];
            }
            shell_exec('sudo /usr/local/sbin/proxyfibre-apply-filter 2>/dev/null');
        }
    }
}

// Catégories thématiques (fichier YAML) + état (activée si des domaines sont présents).
$categories = (function_exists('yaml_parse_file') && is_file('/etc/proxyfibre/categories.yaml'))
            ? (yaml_parse_file('/etc/proxyfibre/categories.yaml') ?: []) : [];
$catCounts = [];
foreach ($db->query("SELECT category, COUNT(*) c FROM pf_blocklist GROUP BY category") as $r) { $catCounts[(string) $r['category']] = (int) $r['c']; }

$rows = $db->query('SELECT * FROM pf_blocklist ORDER BY domain')->fetchAll();

// État du bloqueur de publicités (table pf_settings)
$set = [];
try {
    foreach ($db->query("SELECT k,v FROM pf_settings WHERE k LIKE 'adblock%'") as $r) { $set[$r['k']] = $r['v']; }
} catch (Throwable $e) { /* table pas encore créée */ }
$adOn = ($set['adblock_enabled'] ?? '0') === '1';

pf_header('Filtrage', 'filter.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<section class="panel">
  <div class="panel-head"><h2>🛡️ Bloqueur de publicités &amp; traqueurs</h2>
    <span class="badge <?= $adOn ? 'on' : 'off' ?>"><?= $adOn ? 'Activé' : 'Désactivé' ?></span>
  </div>
  <div style="padding:1.2rem;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap">
    <div>
      <?php if ($adOn): ?>
        <div style="font-size:1.5rem;font-weight:700;color:#fff"><?= number_format((int) ($set['adblock_count'] ?? 0), 0, ',', ' ') ?></div>
        <div class="muted small">domaines bloqués · MAJ le <?= e($set['adblock_updated'] ?? '—') ?></div>
      <?php else: ?>
        <div class="muted">Bloque automatiquement les régies publicitaires et traqueurs pour tous les
        clients (liste communautaire, mise à jour hebdomadaire).</div>
      <?php endif; ?>
    </div>
    <form method="post" class="ml-auto" style="display:flex;gap:.6rem">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <?php if ($adOn): ?>
        <button name="action" value="adblock_enable" class="btn-sm">↻ Mettre à jour</button>
        <button name="action" value="adblock_disable" class="btn-sm btn-danger" onclick="return confirm('Désactiver le bloqueur de publicités ?')">Désactiver</button>
      <?php else: ?>
        <button name="action" value="adblock_enable" class="btn">Activer le bloqueur</button>
      <?php endif; ?>
    </form>
  </div>
  <?php if ($adOn): ?><p class="muted small" style="padding:0 1.2rem 1rem">L'activation/mise à jour télécharge la liste (~10 s).</p><?php endif; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>📚 Catégories thématiques (listes YAML)</h2>
    <span class="badge off"><?= count($categories) ?> catégorie(s)</span></div>
  <div class="table-wrap">
  <table class="grid-table">
    <thead><tr><th>Catégorie</th><th>Domaines</th><th>État</th><th></th></tr></thead>
    <tbody>
    <?php if (!$categories): ?>
      <tr><td colspan="4" class="muted center">Fichier de catégories introuvable (<code>/etc/proxyfibre/categories.yaml</code>).</td></tr>
    <?php else: foreach ($categories as $slug => $cat):
        $catOn = ($catCounts[$slug] ?? 0) > 0; ?>
      <tr>
        <td><strong><?= e($cat['label'] ?? $slug) ?></strong><br><span class="muted svc-meta"><?= e((string) $slug) ?></span></td>
        <td><?= count($cat['domains'] ?? []) ?></td>
        <td><span class="badge <?= $catOn ? 'on' : 'off' ?>"><?= $catOn ? 'Bloquée' : 'Autorisée' ?></span></td>
        <td>
          <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="cat" value="<?= e((string) $slug) ?>">
            <?php if ($catOn): ?>
              <button name="action" value="cat_disable" class="btn-sm btn-danger">Débloquer</button>
            <?php else: ?>
              <button name="action" value="cat_enable" class="btn-sm">Bloquer</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small" style="padding:0 1.2rem 1rem">Catégories définies dans
  <code>/etc/proxyfibre/categories.yaml</code>. Bloquer une catégorie ajoute ses domaines (et sous-domaines)
  à la liste noire pour tous les clients.</p>
</section>

<div class="split">
  <section class="panel form-panel">
    <div class="panel-head"><h2>Bloquer un domaine</h2></div>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add">
      <label>Domaine
        <input type="text" name="domain" placeholder="ex. facebook.com" required>
      </label>
      <label>Catégorie <span class="muted small">(libre)</span>
        <input type="text" name="category" placeholder="ex. réseaux sociaux, adulte…">
      </label>
      <div class="form-actions"><button class="btn">Bloquer</button></div>
      <p class="muted small">Le domaine et ses sous-domaines seront rendus inaccessibles à tous
      les clients (résolution DNS neutralisée). Prise en compte immédiate.</p>
    </form>
    <div class="panel-head" style="border-top:1px solid var(--line)"><h2>Importer une liste</h2></div>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="import">
      <label>Catégorie<input type="text" name="category" placeholder="ex. adulte, publicité, malware"></label>
      <label>Domaines <span class="muted small">(un par ligne)</span>
        <textarea name="domains" rows="6" style="width:100%;padding:.7rem .8rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:10px;font-family:ui-monospace,monospace;font-size:.85rem" placeholder="exemple1.com&#10;exemple2.net&#10;..."></textarea>
      </label>
      <div class="form-actions"><button class="btn">Importer & bloquer</button></div>
    </form>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Domaines bloqués (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
    <table class="grid-table">
      <thead><tr><th>Domaine</th><th>Catégorie</th><th>Ajouté le</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="4" class="muted center">Aucun domaine bloqué.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['domain']) ?></strong></td>
          <td><span class="badge off"><?= e($r['category']) ?></span></td>
          <td class="muted small"><?= e($r['added_at']) ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Débloquer <?= e($r['domain']) ?> ?')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="del">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn-sm btn-danger">Débloquer</button>
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
