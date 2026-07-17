<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — antivirus ClamAV (état, mise à jour, analyse des partages). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();
try {
    $db->exec('CREATE TABLE IF NOT EXISTS pf_avscan (
        id INT AUTO_INCREMENT PRIMARY KEY, ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        path VARCHAR(255), scanned INT DEFAULT 0, infected INT DEFAULT 0, detail TEXT, launched_by VARCHAR(64))');
} catch (Throwable $e) {}

$SCAN_TARGETS = ['/srv/partage' => 'Dossiers partagés', '/var/www' => 'Serveur web'];

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'update') {
        $out = shell_exec('sudo /usr/local/sbin/proxyfibre-clamav update 2>&1');
        $flash = ['Base virale : mise à jour lancée.' . (preg_match('/up-to-date|is up to date|updated/i', (string) $out) ? '' : ''), 'ok'];
    } elseif ($action === 'scan') {
        $dir = (string) ($_POST['dir'] ?? '');
        if (!isset($SCAN_TARGETS[$dir])) {
            $flash = ['Cible d\'analyse invalide.', 'err'];
        } else {
            $out = shell_exec('sudo /usr/local/sbin/proxyfibre-clamav scan ' . escapeshellarg($dir) . ' 2>&1');
            preg_match('/Infected files:\s*(\d+)/i', (string) $out, $mi);
            preg_match('/Scanned files:\s*(\d+)/i', (string) $out, $ms);
            $infected = (int) ($mi[1] ?? 0);
            $scanned  = (int) ($ms[1] ?? 0);
            // Lignes de détection « FOUND »
            $hits = implode("\n", preg_grep('/FOUND$/', explode("\n", (string) $out)));
            $db->prepare('INSERT INTO pf_avscan (path,scanned,infected,detail,launched_by) VALUES (?,?,?,?,?)')
               ->execute([$dir, $scanned, $infected, $hits ?: null, $_SESSION['admin']]);
            $flash = $infected > 0
                ? ["⚠️ $infected menace(s) détectée(s) dans « {$SCAN_TARGETS[$dir]} » !", 'err']
                : ["Analyse de « {$SCAN_TARGETS[$dir]} » terminée — aucune menace ($scanned fichiers).", 'ok'];
        }
    }
}

// ── État ClamAV ──────────────────────────────────────────────────────────────
$daemon   = trim((string) shell_exec('systemctl is-active clamav-daemon 2>/dev/null')) === 'active';
$fresh    = trim((string) shell_exec('systemctl is-active clamav-freshclam 2>/dev/null')) === 'active';
$dbDate   = 0;
foreach (['daily.cld', 'daily.cvd'] as $f) { if (is_file("/var/lib/clamav/$f")) { $dbDate = max($dbDate, (int) filemtime("/var/lib/clamav/$f")); } }
$version  = trim((string) shell_exec('clamscan --version 2>/dev/null')) ?: 'ClamAV';
$last     = null;
try { $last = $db->query('SELECT * FROM pf_avscan ORDER BY id DESC LIMIT 1')->fetch(); } catch (Throwable $e) {}
$rows     = [];
try { $rows = $db->query('SELECT * FROM pf_avscan ORDER BY id DESC LIMIT 20')->fetchAll(); } catch (Throwable $e) {}
$installed = trim((string) shell_exec('command -v clamscan 2>/dev/null')) !== '';

pf_header('Antivirus', 'antivirus.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<?php if (!$installed): ?>
  <div class="flash err">ClamAV n'est pas encore installé sur la passerelle. Lancez le provisioning
  (<code>setup-antivirus.sh</code> ou <code>apt install clamav clamav-daemon</code>).</div>
<?php endif; ?>

<section class="cards">
  <div class="kpi"><div class="kpi-val" style="color:<?= $daemon ? '#4ade80' : '#f87171' ?>"><?= $daemon ? 'Actif' : 'Arrêté' ?></div><div class="kpi-lbl">Moteur temps réel (clamd)</div></div>
  <div class="kpi"><div class="kpi-val" style="font-size:1rem"><?= $dbDate ? date('d/m/Y H:i', $dbDate) : '—' ?></div><div class="kpi-lbl">Base virale (dernière MAJ)</div></div>
  <?php $li = $last ? (int) $last['infected'] : 0; ?>
  <div class="kpi"><div class="kpi-val" style="<?= $li < 0 ? 'font-size:1rem;color:#eab308' : ($li > 0 ? 'color:#f87171' : '') ?>"><?= $li < 0 ? 'Non aboutie' : $li ?></div><div class="kpi-lbl">Menaces (dernière analyse)</div></div>
  <div class="kpi"><div class="kpi-val" style="color:<?= $fresh ? '#4ade80' : '#94a3b8' ?>"><?= $fresh ? 'Auto' : 'Manuel' ?></div><div class="kpi-lbl">MAJ base (freshclam)</div></div>
</section>

<div class="split">
  <section class="panel form-panel">
    <div class="panel-head"><h2>🛡️ Protection</h2></div>
    <div style="padding:1.2rem">
      <p class="muted small" style="margin-top:0"><?= e($version) ?></p>
      <form method="post" style="margin-bottom:1rem">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button name="action" value="update" class="btn">↻ Mettre à jour la base virale</button>
      </form>
      <p class="muted small">Analyser à la demande :</p>
      <?php foreach ($SCAN_TARGETS as $dir => $label): ?>
        <form method="post" style="margin-bottom:.5rem">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="scan">
          <input type="hidden" name="dir" value="<?= e($dir) ?>">
          <button class="btn-sm">🔎 Analyser « <?= e($label) ?> »</button>
          <span class="muted small"><?= e($dir) ?></span>
        </form>
      <?php endforeach; ?>
      <p class="hint muted small" style="margin-top:1rem">Les fichiers déposés par les clients dans les
      dossiers partagés sont analysés. Une analyse planifiée quotidienne tourne aussi automatiquement.</p>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Historique des analyses</h2></div>
    <div class="table-wrap">
    <table class="grid-table">
      <thead><tr><th>Date</th><th>Origine</th><th>Cible</th><th>Fichiers</th><th>Menaces</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="muted center">Aucune analyse enregistrée.</td></tr>
      <?php else: foreach ($rows as $r):
        $par      = (string) ($r['launched_by'] ?? '');
        $station  = str_starts_with($par, 'station:');
        $inf      = (int) $r['infected'];
        // -1 signifie « analyse NON aboutie » : ni saine, ni infectée. Sans ce cas, le
        // test « > 0 » la ferait passer en vert — un poste non analysé présenté comme sain.
        if ($inf < 0)      { $cls = 'warn'; $txt = 'non aboutie'; }
        elseif ($inf > 0)  { $cls = 'danger'; $txt = (string) $inf; }
        else               { $cls = 'on';   $txt = '0'; }
      ?>
        <tr>
          <td class="muted svc-meta"><?= e($r['ts']) ?></td>
          <td><?php if ($station): ?><span class="badge">🔌 Station</span>
              <span class="muted small"><?= e(substr($par, 8)) ?></span>
              <?php else: ?><span class="muted small">Passerelle<?= $par !== '' ? ' · ' . e($par) : '' ?></span><?php endif; ?></td>
          <td><?= e($SCAN_TARGETS[$r['path']] ?? $r['path']) ?></td>
          <td><?= (int) $r['scanned'] ?></td>
          <td><span class="badge <?= $cls ?>"><?= e($txt) ?></span>
              <?php if ($r['detail']): ?><div class="muted small" style="white-space:pre-wrap;max-width:32ch"><?= e(mb_strimwidth((string) $r['detail'], 0, 160, '…')) ?></div><?php endif; ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </section>
</div>
<?php pf_footer(); ?>
