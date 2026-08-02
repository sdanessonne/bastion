<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — filtrage de contenu (domaines bloqués via DNS). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';
$db = pf_db();

// ── Listes noires de Toulouse : état lu par la jauge ─────────────────────────
// Point d'entrée séparé, interrogé toutes les deux secondes pendant un téléchargement.
// Il doit rester léger : il ne fait que lire deux petits fichiers d'état.
if (isset($_GET['blstate'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    session_write_close();
    echo trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-blacklist state 2>/dev/null')) ?: '{}';
    exit;
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'bl_update' || $action === 'bl_rebuild') {
        // Détaché : le téléchargement puis la construction prennent plusieurs minutes.
        // Lancé au premier plan, la requête HTTP expirerait et l'administrateur croirait
        // à un échec alors que le travail continue.
        $verbe = $action === 'bl_update' ? 'update' : 'rebuild';
        shell_exec('sudo /usr/local/sbin/proxyfibre-blacklist ' . $verbe . ' >/dev/null 2>&1 &');
        audit('filter.blacklist', $verbe);
        $flash = ['Mise à jour lancée. La progression s’affiche ci-dessous.', 'ok'];
    }
    if ($action === 'bl_check') {
        $r = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-blacklist check 2>&1'));
        $flash = [$r !== '' ? 'Source interrogée : ' . $r : 'Source injoignable.', $r !== '' ? 'ok' : 'err'];
    }
    if ($action === 'bl_cats') {
        // Liste fermée : ce qui arrive du navigateur ne devient jamais un nom de
        // répertoire sans être filtré caractère par caractère.
        $cats = array_filter(array_map(
            static fn($c) => preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $c)),
            (array) ($_POST['cats'] ?? [])));
        $db->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')
           ->execute(['blacklist_cats', implode(',', $cats)]);
        shell_exec('sudo /usr/local/sbin/proxyfibre-blacklist rebuild >/dev/null 2>&1 &');
        audit('filter.blacklist.cats', implode(',', $cats));
        $flash = [count($cats) . ' catégorie(s) retenue(s). Reconstruction en cours.', 'ok'];
    }

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

$bl = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-blacklist state 2>/dev/null'), true) ?: [];
$blCats = [];
foreach (explode(',', (string) ($bl['categories'] ?? '')) as $p) {
    if (strpos($p, ':') !== false) { [$n, $c] = explode(':', $p, 2); $blCats[$n] = (int) $c; }
}
$blDispo = [];   // catalogue complet, tel que présent dans l'archive extraite
foreach (explode("\n", (string) shell_exec('sudo /usr/local/sbin/proxyfibre-blacklist categories 2>/dev/null')) as $l) {
    $p = explode("\t", trim($l));
    if (count($p) === 2 && $p[0] !== '') { $blDispo[$p[0]] = (int) $p[1]; }
}

pf_header('Filtrage', 'filter.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<!-- ── Listes noires de l'université de Toulouse ───────────────────────────── -->
<section class="panel">
  <div class="panel-head"><h2>🇫🇷 Listes noires — université de Toulouse</h2>
    <?php $blOn = (int) ($bl['domaines'] ?? 0) > 0; ?>
    <span class="badge <?= $blOn ? 'on' : 'off' ?>"><?= $blOn ? 'Actif' : 'Inactif' ?></span>
    <?php if (!empty($bl['maj'])): ?><span class="badge warn" style="margin-left:.4rem">mise à jour disponible</span><?php endif; ?>
  </div>
  <div style="padding:1.2rem">
    <div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap">
      <div style="min-width:200px">
        <div style="font-size:1.5rem;font-weight:700;color:#fff"><?= number_format((int) ($bl['domaines'] ?? 0), 0, ',', ' ') ?></div>
        <div class="muted small">domaines filtrés</div>
        <div class="muted small" style="margin-top:.5rem">
          <?php if (!empty($bl['installe_date'])): ?>
            Liste du <b><?= date('d/m/Y', (int) $bl['installe_date']) ?></b><br>
            installée le <?= date('d/m/Y à H\hi', (int) ($bl['installe_le'] ?? 0)) ?>
          <?php else: ?>
            Aucune liste installée.
          <?php endif; ?>
        </div>
        <?php if (!empty($bl['distant_date'])): ?>
          <div class="muted small" style="margin-top:.4rem">
            Disponible en ligne : <b><?= date('d/m/Y', (int) $bl['distant_date']) ?></b>
            (<?= round(((int) ($bl['distant_taille'] ?? 0)) / 1048576, 1) ?> Mo)
          </div>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:260px">
        <p class="muted small" style="margin:0 0 .6rem;max-width:66ch">
          Référence française du filtrage par catégories, maintenue par l’université de
          Toulouse et <b>hébergée en France</b> — contrairement à la liste publicitaire.
          Le contrôle de nouveauté ne télécharge rien : il lit la date de l’archive.
        </p>
        <form method="post" style="display:flex;gap:.6rem;flex-wrap:wrap">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button name="action" value="bl_check" class="btn-sm">🔍 Vérifier les nouveautés</button>
          <button name="action" value="bl_update" class="btn" id="bl-go">
            <?= $blOn ? '↻ Télécharger la mise à jour' : '⬇ Télécharger les listes' ?>
          </button>
        </form>
      </div>
    </div>

    <!-- Jauge : masquée tant qu'aucun travail n'est en cours. -->
    <div id="bl-prog" style="margin-top:1.1rem" hidden>
      <div class="gauge"><div class="gauge-bar run" id="bl-bar" style="width:2%"></div></div>
      <div class="muted small" id="bl-msg" style="margin-top:.35rem">…</div>
    </div>

    <?php if ($blCats): ?>
      <div style="margin-top:1.1rem;padding-top:.9rem;border-top:1px solid var(--line)">
        <div class="muted small" style="margin-bottom:.4rem">Catégories en vigueur :</div>
        <?php foreach ($blCats as $n => $c): if ($c <= 0) continue; ?>
          <span class="badge on" style="margin:0 .3rem .3rem 0"><?= e($n) ?> · <?= number_format($c, 0, ',', ' ') ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($blDispo): ?>
      <details style="margin-top:1rem">
        <summary style="cursor:pointer;font-weight:600">Choisir les catégories (<?= count($blDispo) ?> disponibles)</summary>
        <p class="muted small" style="margin:.6rem 0;max-width:72ch">
          L’archive est une <b>classification</b>, pas une liste de choses à bloquer :
          on y trouve <code>press</code>, <code>bank</code>, <code>mail</code>, <code>jobsearch</code>.
          Tout cocher rendrait le réseau inutilisable — et la catégorie <code>adult</code>
          représente à elle seule 4,6 millions de domaines, soit environ 500 Mo de mémoire
          pour le résolveur.
        </p>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:.3rem;max-height:340px;overflow:auto;padding:.3rem">
            <?php foreach ($blDispo as $n => $c): ?>
              <label style="display:flex;gap:.45rem;align-items:center;font-size:.85rem;color:var(--text)">
                <input type="checkbox" name="cats[]" value="<?= e($n) ?>" <?= isset($blCats[$n]) ? 'checked' : '' ?>>
                <span><?= e($n) ?> <span class="muted">(<?= number_format($c, 0, ',', ' ') ?>)</span></span>
              </label>
            <?php endforeach; ?>
          </div>
          <button name="action" value="bl_cats" class="btn" style="margin-top:.8rem">Appliquer la sélection</button>
        </form>
      </details>
    <?php endif; ?>
  </div>
</section>
<script>
(function () {
  var zone = document.getElementById('bl-prog'), bar = document.getElementById('bl-bar'),
      msg = document.getElementById('bl-msg'), timer = null;
  function sonder() {
    fetch('filter.php?blstate=1', {cache: 'no-store'}).then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.etape) return;
        if (j.etape === 'cours') {
          zone.hidden = false;
          bar.style.width = Math.max(2, j.pct || 0) + '%';
          msg.textContent = j.message || '';
          if (!timer) timer = setInterval(sonder, 2000);
        } else if (timer) {
          // Terminé : on montre le resultat une seconde, puis on recharge pour
          // afficher les compteurs et les dates a jour.
          clearInterval(timer); timer = null;
          bar.classList.remove('run');
          bar.style.width = '100%';
          msg.textContent = j.message || (j.etape === 'echec' ? 'Échec' : 'Terminé');
          setTimeout(function () { location.reload(); }, 1200);
        }
      }).catch(function () {});
  }
  sonder();   // au cas ou un travail serait deja en cours a l'ouverture de la page
  var go = document.getElementById('bl-go');
  if (go) go.form.addEventListener('submit', function () {
    zone.hidden = false; msg.textContent = 'Démarrage…';
    setTimeout(sonder, 1500);
  });
})();
</script>
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
