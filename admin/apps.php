<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Store d'applications.
 * Les installeurs (MSI/EXE) sont hébergés sur la passerelle ; une GPO à script de
 * démarrage (« Bastion — Applications ») les installe en silence sur les postes du
 * domaine, au boot, sans intervention. Un marqueur registre évite la réinstallation.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();

const APPS_DIR = '/var/www/html/apps';
$dcUp = trim((string) shell_exec('systemctl is-active samba-ad-dc 2>/dev/null')) === 'active';
// IP LAN de la passerelle (URL atteignable par les postes avant ouverture de session).
$gw = trim((string) shell_exec("hostname -I 2>/dev/null | tr ' ' '\\n' | grep -m1 '^192\\.168\\.182\\.'")) ?: '192.168.182.1';

$db->exec('CREATE TABLE IF NOT EXISTS pf_apps (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(96), description TEXT,
    filename VARCHAR(160), args VARCHAR(255) DEFAULT "", icon VARCHAR(16) DEFAULT "📦", deployed TINYINT(1) NOT NULL DEFAULT 0,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');

// ── Catalogue Bastion : applications courantes récupérables depuis leur source officielle ──
$CATALOG = require __DIR__ . '/inc/app-catalog.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'fetch') {
        $key = (string) ($_POST['key'] ?? '');
        if (!isset($CATALOG[$key])) {
            $flash = ['Application de catalogue inconnue.', 'err'];
        } else {
            $c = $CATALOG[$key];
            $ext = $c['msi'] ? 'msi' : 'exe';
            $fname = date('YmdHis') . '-' . $key . '.' . $ext;
            $dest = APPS_DIR . '/' . $fname;
            @set_time_limit(600);
            $rc = 1; $o = [];
            exec('curl -fsSL --max-time 400 -o ' . escapeshellarg($dest) . ' ' . escapeshellarg($c['url']) . ' 2>&1', $o, $rc);
            if ($rc === 0 && is_file($dest) && filesize($dest) > 10000) {
                @chmod($dest, 0644);
                $db->prepare('INSERT INTO pf_apps (name,description,filename,args,icon) VALUES (?,?,?,?,?)')
                   ->execute([$c['name'], $c['desc'], $fname, $c['args'], $c['icon']]);
                $flash = ['« ' . $c['name'] . ' » récupéré (' . round(filesize($dest) / 1048576, 1) . ' Mo) et ajouté au store. Activez-le puis « Appliquer sur les postes ».', 'ok'];
            } else {
                @unlink($dest);
                $flash = ['Échec du téléchargement de « ' . $c['name'] . ' » (source indisponible ?). Vous pouvez ajouter l\'installeur manuellement.', 'err'];
            }
        }
    }

    if ($action === 'add') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));
        $icon = trim((string) ($_POST['icon'] ?? '')) ?: '📦';
        $args = trim((string) ($_POST['args'] ?? ''));
        $f = $_FILES['installer'] ?? null;
        if ($name === '' || !$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flash = ['Nom et fichier installeur requis.', 'err'];
        } elseif ($f['size'] > 800 * 1024 * 1024) {
            $flash = ['Installeur trop volumineux (max 800 Mo).', 'err'];
        } else {
            $orig = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $f['name']));
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['msi', 'exe'], true)) {
                $flash = ['Format non supporté : fournissez un .msi ou .exe.', 'err'];
            } else {
                $fname = date('YmdHis') . '-' . $orig;
                if (@move_uploaded_file($f['tmp_name'], APPS_DIR . '/' . $fname)) {
                    @chmod(APPS_DIR . '/' . $fname, 0644);
                    if ($args === '') { $args = $ext === 'msi' ? '/qn /norestart' : '/S'; }
                    $db->prepare('INSERT INTO pf_apps (name,description,filename,args,icon) VALUES (?,?,?,?,?)')
                       ->execute([$name, $desc, $fname, $args, $icon]);
                    $flash = ['Application « ' . $name . ' » ajoutée au store.', 'ok'];
                } else {
                    $flash = ["Écriture impossible dans " . APPS_DIR . '.', 'err'];
                }
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->prepare('UPDATE pf_apps SET deployed = 1 - deployed WHERE id=?')->execute([$id]);
        $flash = ['Sélection mise à jour — cliquez sur « Appliquer sur les postes » pour actualiser la GPO.', 'ok'];
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = $db->query('SELECT filename FROM pf_apps WHERE id=' . $id)->fetch();
        if ($row && $row['filename']) { @unlink(APPS_DIR . '/' . basename($row['filename'])); }
        $db->prepare('DELETE FROM pf_apps WHERE id=?')->execute([$id]);
        $flash = ['Application retirée du store.', 'ok'];
    }

    if ($action === 'apply') {
        if (!$dcUp) {
            $flash = ['Contrôleur de domaine inactif : impossible de déployer.', 'err'];
        } else {
            $apps = [];
            foreach ($db->query('SELECT id,name,filename,args FROM pf_apps WHERE deployed=1') as $r) {
                $ext = strtolower(pathinfo((string) $r['filename'], PATHINFO_EXTENSION));
                $apps[] = [
                    'marker' => 'app' . (int) $r['id'],
                    // Le NOM voyage avec l'application : le poste doit pouvoir dire
                    // « Installation de Firefox » à l'agent, pas « app6 ».
                    'nom'    => (string) $r['name'],
                    'url'    => 'http://' . $gw . ':2080/apps/' . rawurlencode((string) $r['filename']),
                    'args'   => (string) $r['args'],
                    'msi'    => $ext === 'msi',
                ];
            }
            // ── RETRAITS ────────────────────────────────────────────────────
            // Décocher une application la retirait simplement de la liste : le script
            // cessait de l'installer, et elle restait en place sur tous les postes qui
            // l'avaient déjà. « Désactivé » ne voulait donc rien dire pour le parc.
            //
            // On transmet désormais aussi ce qui NE doit PLUS être là. Le poste ne
            // désinstalle que si le marqueur posé par Bastion existe dans son registre :
            // un logiciel installé par quelqu'un d'autre, ou avant Bastion, n'est jamais
            // touché. C'est ce marqueur qui distingue « nous l'avons mis » de « il était
            // déjà là », et c'est la seule garantie qui compte ici.
            $retraits = [];
            foreach ($db->query('SELECT id,name FROM pf_apps WHERE deployed=0') as $r) {
                $retraits[] = ['marker' => 'app' . (int) $r['id'], 'nom' => (string) $r['name']];
            }
            $tmp = tempnam(sys_get_temp_dir(), 'apps');
            file_put_contents($tmp, json_encode(['gw' => $gw, 'apps' => $apps, 'retraits' => $retraits],
                                                JSON_UNESCAPED_SLASHES));
            $out = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-ad gpo appstore ' . escapeshellarg($tmp) . ' 2>&1'));
            @unlink($tmp);
            $ok = strpos($out, 'applications deployees') !== false;
            $msg = count($apps) . ' application(s) déployée(s) via la GPO « Bastion — Applications ». '
                 . 'Elles s\'installeront au prochain démarrage des postes.';
            if ($retraits) {
                $msg .= ' ' . count($retraits) . ' désactivée(s) seront désinstallées des postes '
                      . 'où Bastion les avait installées — les autres ne sont pas touchées.';
            }
            $flash = [$ok ? $msg : 'Échec : ' . $out, $ok ? 'ok' : 'err'];
        }
    }
}

$apps = $db->query('SELECT * FROM pf_apps ORDER BY name')->fetchAll();
$nbDep = 0; foreach ($apps as $a) { if ($a['deployed']) { $nbDep++; } }
$fmt = function ($n) { $u = ['o','Ko','Mo','Go']; $i = 0; while ($n >= 1024 && $i < 3) { $n /= 1024; $i++; } return number_format($n, $i ? 1 : 0, ',', ' ') . ' ' . $u[$i]; };

pf_header('Store d\'applications', 'apps.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<style>
  .app-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.9rem}
  .app-card{border:1px solid var(--line);border-radius:14px;background:var(--panel);padding:1rem;display:flex;flex-direction:column;gap:.5rem}
  .app-h{display:flex;align-items:center;gap:.7rem}
  .app-ico{font-size:1.9rem;line-height:1}
  .app-h .nm{font-weight:600}
  .app-d{color:var(--muted);font-size:.83rem;line-height:1.5;flex:1;min-height:1.5rem}
  .app-meta{font-size:.72rem;color:var(--muted)}
  .app-meta code{font-size:.72rem}
  .app-foot{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-top:.2rem}
  .switch{position:relative;display:inline-block;width:46px;height:26px;flex:none}
  .switch input{opacity:0;width:0;height:0}
  .switch .sl{position:absolute;inset:0;background:var(--bg);border:1px solid var(--line);border-radius:99px;cursor:pointer;transition:.2s}
  .switch .sl::before{content:"";position:absolute;height:18px;width:18px;left:3px;top:3px;background:var(--muted);border-radius:50%;transition:.22s}
  .switch input:checked + .sl{background:rgba(74,222,128,.25);border-color:var(--ok)}
  .switch input:checked + .sl::before{transform:translateX(20px);background:var(--ok)}
  .up-form label{display:grid;gap:.3rem;font-size:.8rem;color:var(--muted)}
  .up-form input[type=text],.up-form textarea{padding:.55rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px;width:100%}
</style>

<div class="dir-intro" style="background:linear-gradient(120deg,#1e3a5f,#152238);border:1px solid var(--line);border-radius:14px;padding:1.1rem 1.4rem;margin-bottom:1.2rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
  <span style="font-size:2rem">🏪</span>
  <div style="flex:1;min-width:220px">
    <div style="font-size:1.15rem;font-weight:600;color:#fff">Store d'applications</div>
    <div style="color:var(--muted);font-size:.9rem">Déployez des logiciels sur <strong>tous les postes du domaine</strong> via
    une GPO : ils s'installent automatiquement et en silence au démarrage, depuis la passerelle.</div>
  </div>
  <?php if (!$dcUp): ?><span class="badge off">Domaine inactif</span><?php else: ?><span class="badge on"><?= $nbDep ?> déployée(s)</span><?php endif; ?>
</div>

<section class="panel" style="margin-bottom:1.2rem">
  <div class="panel-head"><h2>📚 Applications du store (<?= count($apps) ?>)</h2>
    <form method="post" style="margin:0" onsubmit="this.querySelector('button').textContent='Déploiement…';this.querySelector('button').disabled=true">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="apply">
      <button class="btn"<?= $dcUp ? '' : ' disabled' ?>>🚀 Appliquer sur les postes</button>
    </form>
  </div>
  <div style="padding:1.1rem 1.2rem">
    <?php if (!$apps): ?>
      <p class="muted">Aucune application. Ajoutez un installeur ci-dessous, puis activez-le et cliquez sur
      « Appliquer sur les postes ».</p>
    <?php else: ?>
    <div class="app-grid">
      <?php foreach ($apps as $a): $sz = @filesize(APPS_DIR . '/' . $a['filename']); ?>
        <div class="app-card">
          <div class="app-h"><span class="app-ico"><?= e($a['icon']) ?></span><span class="nm"><?= e($a['name']) ?></span></div>
          <div class="app-d"><?= e($a['description']) ?: '<span class="muted">—</span>' ?></div>
          <div class="app-meta"><?= e($a['filename']) ?><?= $sz ? ' · ' . e($fmt($sz)) : '' ?><br>Silencieux : <code><?= e($a['args']) ?></code></div>
          <div class="app-foot">
            <label class="switch" title="Déployer cette application">
              <form method="post" style="display:contents">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <input type="checkbox" <?= $a['deployed'] ? 'checked' : '' ?> onchange="this.form.submit()"><span class="sl"></span>
              </form>
            </label>
            <div class="row-actions">
              <a class="btn-sm" href="http://<?= e($gw) ?>:2080/apps/<?= rawurlencode($a['filename']) ?>" target="_blank">⬇</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Retirer « <?= e($a['name']) ?> » du store ?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button class="btn-sm btn-danger">Suppr.</button></form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="panel" style="margin-bottom:1.2rem">
  <div class="panel-head"><h2>🛍️ Catalogue Bastion — récupération en un clic (<?= count($CATALOG) ?>)</h2></div>
  <p class="muted small" style="padding:.2rem 1.2rem 0">Applications courantes téléchargées depuis leur
  <strong>source officielle</strong> vers le store, avec leurs arguments d'installation silencieuse. Le
  téléchargement peut prendre un moment selon la taille.</p>
  <?php
    $names = array_column($apps ?? [], 'name');
    // Regrouper le catalogue par catégorie (ordre d'apparition dans le fichier).
    $byCat = [];
    foreach ($CATALOG as $k => $c) { $byCat[$c['cat'] ?? 'Divers'][$k] = $c; }
  ?>
  <div style="padding:.4rem 1.2rem 0" class="cat-filter">
    <input type="search" id="appsearch" placeholder="🔎 Filtrer les applications…" style="width:100%;max-width:420px;padding:.5rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
  </div>
  <?php foreach ($byCat as $cat => $items): ?>
    <h3 class="app-cat-h" style="padding:1rem 1.2rem .2rem;margin:0;font-size:.95rem;color:var(--muted)"><?= e($cat) ?> <span class="muted" style="font-weight:400">(<?= count($items) ?>)</span></h3>
    <div style="padding:.2rem 1.2rem 0" class="app-grid">
      <?php foreach ($items as $k => $c): $have = in_array($c['name'], $names, true); ?>
        <div class="app-card" data-search="<?= e(strtolower($c['name'] . ' ' . $c['desc'] . ' ' . $cat)) ?>">
          <div class="app-h"><span class="app-ico"><?= $c['icon'] ?></span><span class="nm"><?= e($c['name']) ?></span></div>
          <div class="app-d"><?= e($c['desc']) ?></div>
          <div class="app-meta"><?= $c['msi'] ? 'MSI' : 'EXE' ?> · silencieux <code><?= e($c['args']) ?: '—' ?></code></div>
          <div class="app-foot" style="justify-content:flex-end">
            <?php if ($have): ?><span class="badge on">✓ Dans le store</span>
            <?php else: ?>
            <form method="post" onsubmit="this.querySelector('button').textContent='Téléchargement…';this.querySelector('button').disabled=true">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="fetch"><input type="hidden" name="key" value="<?= e($k) ?>">
              <button class="btn-sm">⬇ Récupérer</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <p class="muted small" style="padding:1rem 1.2rem 1.2rem">💡 D'autres logiciels ? Ajoutez leur installeur manuellement
  ci-dessous. <span style="opacity:.8">Note : les versions du catalogue évoluent ; les liens pointent vers la dernière
  version connue de l'éditeur. Un lien « GitHub Releases » renvoie la page des versions : récupérez alors l'installeur
  et ajoutez-le manuellement.</span></p>
</section>
<script>
(function(){
  var q=document.getElementById('appsearch'); if(!q) return;
  q.addEventListener('input',function(){
    var v=this.value.trim().toLowerCase();
    document.querySelectorAll('.app-cat-h').forEach(function(h){
      var grid=h.nextElementSibling, shown=0;
      grid.querySelectorAll('.app-card').forEach(function(card){
        var ok=!v||(card.getAttribute('data-search')||'').indexOf(v)>=0;
        card.style.display=ok?'':'none'; if(ok) shown++;
      });
      h.style.display=shown?'':'none';
    });
  });
})();
</script>

<section class="panel">
  <div class="panel-head"><h2>➕ Ajouter une application</h2></div>
  <form method="post" enctype="multipart/form-data" class="up-form" style="padding:1.2rem;display:grid;gap:.8rem;max-width:640px">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add">
    <div style="display:grid;grid-template-columns:80px 1fr;gap:.8rem">
      <label>Icône<input type="text" name="icon" value="📦" maxlength="4" style="text-align:center;font-size:1.2rem"></label>
      <label>Nom de l'application<input type="text" name="name" required placeholder="ex. 7-Zip, VLC, Firefox ESR…"></label>
    </div>
    <label>Description <span class="muted small">(optionnel)</span><textarea name="description" rows="2" placeholder="À quoi sert ce logiciel…"></textarea></label>
    <label>Installeur (.msi ou .exe)<input type="file" name="installer" accept=".msi,.exe" required></label>
    <label>Arguments d'installation silencieuse
      <input type="text" name="args" placeholder="MSI : /qn /norestart — EXE : /S (NSIS), /silent (Inno)…">
      <span class="muted small">Laissé vide : <code>/qn /norestart</code> (MSI) ou <code>/S</code> (EXE). Voir la doc de l'éditeur pour l'option silencieuse exacte.</span></label>
    <div><button class="btn">⬆️ Ajouter au store</button></div>
  </form>
  <p class="muted small" style="padding:0 1.2rem 1.2rem">Les installeurs sont hébergés sur la passerelle
  (<code>http://<?= e($gw) ?>:2080/apps/</code>) et téléchargés par les postes au démarrage. La GPO
  « Bastion — Applications » exécute un script de démarrage (compte SYSTEM) qui installe puis marque chaque
  application (pas de réinstallation ensuite). Testez d'abord sur un poste pilote.</p>
</section>
<?php pf_footer(); ?>
