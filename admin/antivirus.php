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
    // Le jeton des stations est créé ICI, et pas seulement par deploy.sh : la mise à jour
    // depuis Git ne rejoue PAS deploy.sh (elle synchronise le code, pas l'infrastructure).
    // Une passerelle déjà en service recevrait donc l'API des stations sans jamais avoir le
    // jeton qui va avec — et toute station se ferait refuser en 401, sans que rien
    // n'explique pourquoi. « INSERT IGNORE » : une fois créé, il ne bouge plus, sinon
    // chaque visite de cette page invaliderait les postes déjà configurés.
    $db->exec("CREATE TABLE IF NOT EXISTS pf_settings (k VARCHAR(64) PRIMARY KEY, v TEXT)");
    $db->exec("INSERT IGNORE INTO pf_settings (k,v) VALUES ('station_token', SHA2(CONCAT(RAND(),UUID(),NOW(6)),256))");
    // Jetons PAR STATION (révocables un par un) : chaque ordinateur reçoit le sien. On voit
    // ainsi lequel se sert encore, et l'on peut révoquer un poste volé sans reconfigurer tous
    // les autres — contrairement au jeton partagé, dont le renouvellement invalide tout le parc.
    $db->exec('CREATE TABLE IF NOT EXISTS pf_station_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(96) NOT NULL DEFAULT \'\',
        token CHAR(64) NOT NULL UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(64) DEFAULT NULL, last_seen DATETIME NULL, last_ip VARCHAR(45) DEFAULT NULL,
        last_poste VARCHAR(96) DEFAULT NULL, revoked TINYINT(1) NOT NULL DEFAULT 0)');
} catch (Throwable $e) {}

$SCAN_TARGETS = ['/srv/partage' => 'Dossiers partagés', '/var/www' => 'Serveur web'];

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'station_token_new') {
        // Renouvellement : indispensable le jour où une station est volée ou son fichier de
        // configuration recopié. Toutes les stations devront être reconfigurées — c'est le
        // prix, et c'est annoncé.
        $db->exec("REPLACE INTO pf_settings (k,v) VALUES ('station_token', SHA2(CONCAT(RAND(),UUID(),NOW(6)),256))");
        $flash = ['Nouveau jeton partagé généré. Les stations sur ce jeton doivent être reconfigurées.', 'ok'];
    } elseif ($action === 'station_token_add') {
        $label = substr(trim((string) ($_POST['label'] ?? '')), 0, 96);
        if ($label === '') {
            $flash = ['Donnez un nom à la station (ex. « Poste accueil »).', 'err'];
        } else {
            // Le jeton est engendré côté base (SHA2 d'aléa + UUID + horodatage µs) : haute entropie.
            $db->prepare("INSERT INTO pf_station_tokens (label,token,created_by) VALUES (?, SHA2(CONCAT(RAND(),UUID(),NOW(6)),256), ?)")
               ->execute([$label, $_SESSION['admin'] ?? '']);
            $flash = ['Jeton créé pour « ' . $label . ' ». Reportez-le dans le station.json de ce poste.', 'ok'];
        }
    } elseif ($action === 'station_token_revoke') {
        $db->prepare('UPDATE pf_station_tokens SET revoked=1 WHERE id=?')->execute([(int) ($_POST['id'] ?? 0)]);
        $flash = ['Jeton révoqué — la station est refusée dès sa prochaine requête.', 'ok'];
    } elseif ($action === 'station_token_restore') {
        $db->prepare('UPDATE pf_station_tokens SET revoked=0 WHERE id=?')->execute([(int) ($_POST['id'] ?? 0)]);
        $flash = ['Jeton réactivé.', 'ok'];
    } elseif ($action === 'station_token_delete') {
        $db->prepare('DELETE FROM pf_station_tokens WHERE id=?')->execute([(int) ($_POST['id'] ?? 0)]);
        $flash = ['Jeton supprimé.', 'ok'];
    } elseif ($action === 'update') {
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

// ── Ce que la passerelle sert aux stations blanches ───────────────────────────
$stationToken = '';
try { $stationToken = (string) $db->query("SELECT v FROM pf_settings WHERE k='station_token'")->fetchColumn(); }
catch (Throwable $e) {}
$stationTokens = [];   // jetons par station (révocables)
try { $stationTokens = $db->query('SELECT * FROM pf_station_tokens ORDER BY revoked, label')->fetchAll(); }
catch (Throwable $e) {}
$baseFichiers = [];
$baseBloquee  = [];
foreach (['main.cvd', 'main.cld', 'daily.cvd', 'daily.cld', 'bytecode.cvd', 'bytecode.cld'] as $f) {
    $p = "/var/lib/clamav/$f";
    if (!is_file($p)) { continue; }
    // Lisible par le serveur web ? Un fichier présent mais interdit à Apache ne partira
    // jamais vers les stations, et rien ne le dirait — la station répéterait « base
    // absente » devant une passerelle qui en a une.
    if (!is_readable($p)) { $baseBloquee[] = $f; continue; }
    $baseFichiers[$f] = ['taille' => filesize($p), 'date' => filemtime($p)];
}
$lanIp = trim((string) shell_exec("ip -4 addr show scope global 2>/dev/null | awk '/inet /{print \$2}' | cut -d/ -f1 | head -1"));

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
  <!-- Colonne de gauche : les deux panneaux de réglage EMPILÉS. « .split » n'a que deux
       colonnes ; les laisser en enfants directs enverrait l'historique dans la colonne
       étroite de 320 px, illisible. -->
  <div style="display:grid;gap:1.4rem">
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

  <section class="panel form-panel">
    <div class="panel-head"><h2>🔌 Stations blanches</h2></div>
    <div style="padding:1.2rem">
      <p class="muted small" style="margin-top:0">Les stations d'analyse de clés USB déposent leurs
      résultats ici et récupèrent leur base virale sur cette passerelle — elles n'ont pas besoin d'Internet.</p>

      <?php if (!$baseFichiers): ?>
        <div class="flash err" style="margin:.6rem 0">
          <?php if ($baseBloquee): ?>
            Base virale présente mais <strong>illisible par le serveur web</strong>
            (<?= e(implode(', ', $baseBloquee)) ?>). Les stations ne recevront rien.
            Corrigez les droits : <code>chmod o+r /var/lib/clamav/*.c?d</code>
          <?php else: ?>
            <strong>Aucune base virale sur cette passerelle</strong> : les stations ne pourront pas se mettre
            à jour. Lancez <code>provisioning/setup-antivirus.sh</code>.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <p class="muted small">Base servie aux stations :</p>
        <table class="grid-table" style="margin-bottom:1rem">
          <tbody>
          <?php foreach ($baseFichiers as $n => $i): ?>
            <tr><td><code><?= e($n) ?></code></td>
                <td class="muted svc-meta"><?= number_format($i['taille'] / 1048576, 1, ',', ' ') ?> Mo</td>
                <td class="muted svc-meta"><?= date('d/m/Y H:i', $i['date']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <!-- Jetons PAR STATION : un par ordinateur, révocables un par un. -->
      <h3 style="font-size:.95rem;margin:1.1rem 0 .2rem">🔑 Jetons par station <span class="muted small">(recommandé)</span></h3>
      <p class="muted small" style="margin:.2rem 0 .6rem">Un jeton par ordinateur : on voit lequel se sert (et quand), et on
      peut en révoquer un seul — poste volé ou remplacé — sans reconfigurer les autres. Le jeton n'ouvre que le dépôt
      de résultats et la base virale, <strong>rien d'autre</strong>.</p>
      <form method="post" class="ad-inline" style="margin-bottom:.8rem">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="station_token_add">
        <input type="text" name="label" required maxlength="96" placeholder="Nom du poste (ex. Accueil brigade)"
               style="flex:1;min-width:170px;padding:.5rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
        <button class="btn-sm">＋ Créer un jeton</button>
      </form>
      <?php if ($stationTokens): ?>
      <div class="table-wrap"><table class="grid-table" style="margin-bottom:1rem">
        <thead><tr><th>Station</th><th>Jeton</th><th>Dernière activité</th><th>État</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($stationTokens as $t): $csrf = e(csrf_token()); $rev = (int) $t['revoked']; ?>
          <tr<?= $rev ? ' style="opacity:.55"' : '' ?>>
            <td><strong><?= e($t['label']) ?></strong><br><span class="muted svc-meta">créé le <?= e(date('d/m/Y', strtotime((string) $t['created_at']))) ?><?= $t['created_by'] ? ' · ' . e($t['created_by']) : '' ?></span></td>
            <td style="max-width:160px"><input type="password" readonly value="<?= e($t['token']) ?>"
                   onclick="this.type=this.type==='password'?'text':'password';this.select()" title="Cliquer pour afficher / copier"
                   style="width:100%;font-family:monospace;font-size:.7rem;background:#0d1728;color:var(--muted);border:1px solid var(--line);border-radius:6px;padding:.25rem .4rem"></td>
            <td class="muted svc-meta"><?php if ($t['last_seen']): ?><?= e(date('d/m/Y H:i', strtotime((string) $t['last_seen']))) ?><?php if ($t['last_poste']): ?><br><?= e($t['last_poste']) ?><?php endif; ?><?php if ($t['last_ip']): ?> · <?= e($t['last_ip']) ?><?php endif; ?><?php else: ?>jamais utilisé<?php endif; ?></td>
            <td><span class="badge <?= $rev ? 'off' : 'on' ?>"><?= $rev ? 'révoqué' : 'actif' ?></span></td>
            <td class="row-actions">
              <?php if ($rev): ?>
                <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="station_token_restore"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn-sm">Réactiver</button></form>
                <form method="post" style="display:inline" onsubmit="return confirm('Supprimer définitivement ce jeton ?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="station_token_delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn-sm btn-danger">Suppr.</button></form>
              <?php else: ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Révoquer « <?= e($t['label']) ?> » ? Cette station sera refusée immédiatement.')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="station_token_revoke"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn-sm btn-danger">Révoquer</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><p class="muted small" style="margin-bottom:1rem">Aucun jeton par station pour l'instant. Créez-en un ci-dessus.</p><?php endif; ?>

      <details style="margin-bottom:.6rem">
        <summary class="muted small" style="cursor:pointer">Contenu de <code>station.json</code> d'un poste</summary>
<pre style="font-size:.75rem;overflow-x:auto;background:rgba(0,0,0,.25);padding:.6rem;border-radius:6px">{
  "Passerelle": "https://<?= e($lanIp ?: '192.168.182.1') ?>:8443",
  "Jeton": "&lt;le jeton de la station&gt;",
  "Kiosque": true,
  "BoutonEteindre": true,
  "MajAuto": true,
  "DefenderEnSecondAvis": true,
  "AccepterCertificatInterne": true
}</pre>
      </details>

      <details style="margin-bottom:.4rem">
        <summary class="muted small" style="cursor:pointer">Jeton partagé (hérité) — un seul pour toutes les stations</summary>
        <p class="muted small" style="margin:.6rem 0 .4rem">Compatibilité : les stations configurées avec ce jeton unique
        fonctionnent toujours. Préférez désormais un jeton par station (ci-dessus), révocable individuellement.</p>
        <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.6rem">
          <input id="jetonSt" type="password" readonly value="<?= e($stationToken) ?>"
                 style="flex:1;font-family:monospace;font-size:.8rem" onclick="this.select()">
          <button type="button" class="btn-sm" onclick="var c=document.getElementById('jetonSt');c.type=c.type==='password'?'text':'password';">👁 Voir</button>
        </div>
        <form method="post" onsubmit="return confirm('Générer un nouveau jeton PARTAGÉ ?\n\nToutes les stations sur ce jeton (pas les jetons par station) seront refusées tant qu\'elles n\'auront pas reçu le nouveau.');">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button name="action" value="station_token_new" class="btn-sm">↻ Renouveler le jeton partagé</button>
        </form>
      </details>
    </div>
  </section>
  </div><!-- /colonne de gauche -->

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
