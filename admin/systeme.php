<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — vue d'ensemble système (état de toutes les fonctions). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';

// ── Mise à jour Debian : point d'entrée AJAX ─────────────────────────────────
// Doit passer AVANT toute sortie HTML. La console interroge l'état et le journal
// pendant qu'apt travaille en tâche de fond (unité systemd transitoire).
if (isset($_GET['apt'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // Le verrou de session PHP est tenu toute la requête : sans cette libération, un
    // sondage pendant une mise à jour figerait la navigation de l'administrateur.
    session_write_close();
    switch ((string) $_GET['apt']) {
        case 'state':
            echo trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-apt state 2>/dev/null')) ?: '{}';
            break;
        case 'list':
            echo trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-apt list 2>/dev/null')) ?: '[]';
            break;
        case 'log':
            echo json_encode(['log' => (string) shell_exec('sudo /usr/local/sbin/proxyfibre-apt log 2>/dev/null')],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;
        case 'gitstate':
            echo trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-selfupdate state 2>/dev/null')) ?: '{}';
            break;
        case 'gitlog':
            echo json_encode(['log' => (string) shell_exec('sudo /usr/local/sbin/proxyfibre-selfupdate log 2>/dev/null')],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;
        default:
            http_response_code(400); echo '{"error":"action inconnue"}';
    }
    exit;
}

// ── Mise à jour « tout en un » : lancement AJAX (JSON) ───────────────────────
// Un seul bouton côté console orchestre système (apt) PUIS Bastion (git). Chaque
// verbe ne fait que LANCER l'unité systemd correspondante (non bloquant) ; le suivi
// se fait ensuite par sondage de « ?apt=state » / « ?apt=gitstate ». Liste FERMÉE.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'update_ajax') {
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    session_write_close();
    $map = [
        'apt_check' => 'sudo /usr/local/sbin/proxyfibre-apt check',
        'apt_apply' => 'sudo /usr/local/sbin/proxyfibre-apt apply',
        'git_check' => 'sudo /usr/local/sbin/proxyfibre-selfupdate check',
        'git_apply' => 'sudo /usr/local/sbin/proxyfibre-selfupdate apply',
    ];
    $act = (string) ($_POST['act'] ?? '');
    if (!isset($map[$act])) { http_response_code(400); echo '{"ok":false,"error":"action inconnue"}'; exit; }
    $r = trim((string) shell_exec($map[$act] . ' 2>&1'));
    if ($act === 'apt_apply' || $act === 'git_apply') { audit('systeme.update', $act); }
    echo json_encode(['ok' => !str_starts_with($r, 'ERREUR'), 'r' => $r], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = pf_db();

$aptFlash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'apt') {
    csrf_check();
    // Liste FERMÉE : ces deux verbes seuls sont autorisés côté sudo également. Aucun
    // nom de paquet n'est accepté nulle part — sinon la console offrirait « apt install
    // n'importe quoi » en root à qui saurait lui faire exécuter une requête.
    $act = in_array($_POST['act'] ?? '', ['check', 'apply'], true) ? $_POST['act'] : '';
    if ($act !== '') {
        $r = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-apt ' . $act . ' 2>&1'));
        $msg = ['check' => 'Recherche des mises à jour lancée…', 'apply' => 'Mise à jour lancée — suivez la progression ci-dessous.'];
        $aptFlash = str_starts_with($r, 'ERREUR')
            ? [$r, 'err']
            : ($r === 'deja-en-cours' ? ['Une opération est déjà en cours.', 'warn'] : [$msg[$act], 'ok']);
    }
}

// ── Mise à jour de Bastion depuis son dépôt Git ──────────────────────────────
$gitFlash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'git') {
    csrf_check();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'conf') {
        // Le jeton part sur l'ENTRÉE STANDARD du script, jamais en argument : un argument
        // serait visible dans « ps » par n'importe quel utilisateur de la machine.
        $in = implode("\n", [
            trim((string) ($_POST['git_repo'] ?? '')),
            trim((string) ($_POST['git_branch'] ?? '')) ?: 'main',
            trim((string) ($_POST['git_token'] ?? '')),
        ]) . "\n";
        $p = proc_open('sudo /usr/local/sbin/proxyfibre-update-conf 2>&1',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        $out = '';
        if (is_resource($p)) {
            fwrite($pipes[0], $in); fclose($pipes[0]);
            $out = trim((string) stream_get_contents($pipes[1])); fclose($pipes[1]);
            proc_close($p);
        }
        $gitFlash = str_starts_with($out, 'ok') ? ['Dépôt enregistré.', 'ok'] : [$out ?: 'Enregistrement impossible.', 'err'];
    } elseif ($act === 'testssh') {
        $r = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-selfupdate testssh 2>&1'));
        $gitFlash = str_starts_with($r, 'OK:') ? [substr($r, 3), 'ok'] : [$r, 'err'];
    } elseif (in_array($act, ['check', 'apply'], true)) {
        $r = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-selfupdate ' . $act . ' 2>&1'));
        $gitFlash = str_starts_with($r, 'ERREUR')
            ? [$r, 'err']
            : ($r === 'deja-en-cours' ? ['Une opération est déjà en cours.', 'warn']
               : [$act === 'check' ? 'Recherche lancée…' : 'Mise à jour de Bastion lancée…', 'ok']);
    }
}
// ── Changement du mot de passe système ───────────────────────────────────────
$pwFlash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'syspw') {
    csrf_check();
    $compte  = in_array($_POST['compte'] ?? '', ['proxyfibre', 'root'], true) ? (string) $_POST['compte'] : '';
    $nouveau = (string) ($_POST['nouveau'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    $actuel  = (string) ($_POST['actuel'] ?? '');

    // RESSAISIE OBLIGATOIRE du mot de passe administrateur. Changer un mot de passe système
    // est une action à fort impact : une session ouverte et abandonnée, ou un jeton CSRF
    // dérobé, ne doivent pas suffire — il faut reprouver son identité à cet instant.
    $reauth = false;
    try {
        $st = $db->prepare('SELECT password_hash FROM pf_admins WHERE username = ?');
        $st->execute([$_SESSION['admin'] ?? '']);
        $h = (string) ($st->fetchColumn() ?: '');
        $reauth = $h !== '' && password_verify($actuel, $h);
    } catch (Throwable $e) {}

    if ($compte === '') {
        $pwFlash = ['Compte système non autorisé.', 'err'];
    } elseif (!$reauth) {
        $pwFlash = ['Mot de passe administrateur incorrect : changement refusé.', 'err'];
    } elseif (strlen($nouveau) < 8) {
        $pwFlash = ['Le nouveau mot de passe doit faire au moins 8 caractères.', 'err'];
    } elseif ($nouveau !== $confirm) {
        $pwFlash = ['Les deux nouveaux mots de passe ne correspondent pas.', 'err'];
    } else {
        // Le mot de passe part sur l'ENTRÉE STANDARD du script, jamais en argument (un
        // argument serait lisible dans « ps » par tout utilisateur de la machine).
        $out = '';
        $p = proc_open('sudo /usr/local/sbin/proxyfibre-syspasswd ' . escapeshellarg($compte) . ' 2>&1',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        if (is_resource($p)) {
            fwrite($pipes[0], $nouveau); fclose($pipes[0]);
            $out = trim((string) stream_get_contents($pipes[1])); fclose($pipes[1]);
            proc_close($p);
        }
        // Trace d'audit : QUI a changé QUEL compte. Jamais le mot de passe lui-même.
        error_log(sprintf('[bastion] syspasswd admin=%s compte=%s resultat=%s',
            $_SESSION['admin'] ?? '?', $compte, str_starts_with($out, 'OK') ? 'ok' : 'echec'));
        audit('systeme.syspasswd', 'compte=' . $compte . (str_starts_with($out, 'OK') ? '' : ' [échec]'));
        $pwFlash = str_starts_with($out, 'OK')
            ? ["Mot de passe du compte système « $compte » changé.", 'ok']
            : [$out ?: 'Changement impossible.', 'err'];
    }
}

// ── Serveur de temps (NTP) : changer la source amont / resynchroniser ────────
$timeFlash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'time') {
    csrf_check();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'set') {
        $srv = trim((string) ($_POST['server'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.:-]{1,}$/', $srv)) {
            $timeFlash = ['Nom de serveur de temps invalide.', 'err'];
        } else {
            $r  = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-time set ' . escapeshellarg($srv) . ' 2>&1'));
            $ok = strpos($r, 'ok server=') === 0;
            if (function_exists('audit')) { audit('systeme.time_set', $ok ? $srv : 'echec'); }
            $timeFlash = [$ok ? "Serveur de temps réglé sur « $srv » — chrony redémarré." : ('Échec : ' . $r), $ok ? 'ok' : 'err'];
        }
    } elseif ($act === 'resync') {
        shell_exec('sudo /usr/local/sbin/proxyfibre-time resync 2>&1');
        $timeFlash = ['Resynchronisation lancée — patientez quelques secondes puis rechargez.', 'ok'];
    }
}
// État courant du serveur de temps.
$timeSt = ['sources' => []];
foreach (explode("\n", (string) shell_exec('sudo /usr/local/sbin/proxyfibre-time status 2>/dev/null')) as $l) {
    if (substr($l, 0, 7) === "source\t") {
        $p = explode("\t", $l);
        $timeSt['sources'][] = ['ms' => $p[1] ?? '', 'name' => $p[2] ?? '', 'stratum' => $p[3] ?? '', 'reach' => $p[4] ?? '', 'lastrx' => $p[5] ?? ''];
    } elseif (preg_match('/^(\w+)=(.*)$/', $l, $m)) {
        $timeSt[$m[1]] = $m[2];
    }
}

$git = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-selfupdate state 2>/dev/null'), true) ?: [];
// Clé publique de la passerelle — engendrée au premier affichage de cette page.
$gitKey = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-selfupdate pubkey 2>/dev/null'));

// État courant (rapide : simulation locale, aucun accès réseau).
$apt = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-apt state 2>/dev/null'), true) ?: [];

function cnt(PDO $db, string $t): int {
    try { return (int) $db->query("SELECT COUNT(*) FROM $t")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
$setting = [];
try { foreach ($db->query("SELECT k,v FROM pf_settings") as $r) { $setting[$r['k']] = $r['v']; } } catch (Throwable $e) {}

// Sessions live via OpenNDS
$clients = nds_clients();
$authCount = 0;
foreach ($clients as $c) { if (($c['state'] ?? '') === 'Authenticated') { $authCount++; } }

$nbUsers    = (int) $db->query('SELECT COUNT(DISTINCT username) FROM radcheck')->fetchColumn();
$nbGroups   = cnt($db, 'pf_groups');
$nbBlock    = cnt($db, 'pf_blocklist');
$nbConn     = cnt($db, 'pf_connlog');
$nbWeb      = cnt($db, 'pf_weblog');
$adOn       = ($setting['adblock_enabled'] ?? '0') === '1';

$feat = [
    ['Portail captif HTTPS',    true,  'OpenNDS + FAS, redirection HTTPS (2443)'],
    ['Authentification',        true,  "$nbUsers compte(s) — FreeRADIUS + MariaDB"],
    ['Filtrage de contenu',     $nbBlock > 0, "$nbBlock domaine(s) bloqué(s) manuellement"],
    ['Bloqueur de publicités',  $adOn, $adOn ? number_format((int)($setting['adblock_count']??0),0,',',' ').' domaines' : 'désactivé'],
    ['Quotas & horaires',       $nbGroups > 0, "$nbGroups groupe(s)"],
    ['Journalisation légale',   true,  "$nbConn connexion(s) journalisée(s)"],
    ['Historique de navigation',$nbWeb > 0, "$nbWeb visite(s) enregistrée(s)"],
    ['Walled garden (MAJ/NTP)', true,  'serveurs de mise à jour + NTP ouverts sans auth'],
    ['Serveur de temps (NTP)',  true,  'chrony — source de temps du réseau'],
    ['Serveur PXE',             true,  'installation OS par le réseau (Debian/Ubuntu/Windows)'],
];

// ── Santé de la passerelle : charge processeur, mémoire, disque (lecture locale rapide) ──
$ncpu  = max(1, (int) trim((string) shell_exec('nproc 2>/dev/null')));
$la    = explode(' ', trim((string) @file_get_contents('/proc/loadavg')));
$load1 = (float) ($la[0] ?? 0);
$cpuPct = min(100, (int) round($load1 / $ncpu * 100));
$memTot = $memAvail = 0;
foreach (explode("\n", (string) @file_get_contents('/proc/meminfo')) as $l) {
    if (preg_match('/^MemTotal:\s+(\d+)/', $l, $m))     { $memTot = (int) $m[1]; }
    if (preg_match('/^MemAvailable:\s+(\d+)/', $l, $m)) { $memAvail = (int) $m[1]; }
}
$memPct = $memTot > 0 ? (int) round(($memTot - $memAvail) / $memTot * 100) : 0;
$dfPct = 0; $dfUsed = $dfTot = '';
if (preg_match('~(\d+)%\s+(\S+)\s+(\S+)~', trim((string) shell_exec("df -Ph / 2>/dev/null | awk 'NR==2{print \$5\" \"\$3\" \"\$2}'")), $m)) {
    $dfPct = (int) $m[1]; $dfUsed = $m[2]; $dfTot = $m[3];
}
$uptime = trim((string) shell_exec('uptime -p 2>/dev/null'));
$sante = [
    ['Processeur', $cpuPct, sprintf('charge %.2f · %d cœur(s)', $load1, $ncpu)],
    ['Mémoire',    $memPct, $memTot ? number_format(($memTot - $memAvail) / 1048576, 1, ',', ' ') . ' / ' . number_format($memTot / 1048576, 1, ',', ' ') . ' Go' : ''],
    ['Disque système', $dfPct, ($dfUsed && $dfTot) ? "$dfUsed / $dfTot" : ''],
];

pf_header('Système', 'systeme.php');
?>
<section class="cards">
  <div class="kpi"><div class="kpi-val"><?= $authCount ?></div><div class="kpi-lbl">Clients connectés</div></div>
  <div class="kpi"><div class="kpi-val"><?= $nbUsers ?></div><div class="kpi-lbl">Comptes</div></div>
  <div class="kpi"><div class="kpi-val"><?= number_format($nbWeb,0,',',' ') ?></div><div class="kpi-lbl">Visites enregistrées</div></div>
  <div class="kpi"><div class="kpi-val"><?= number_format($nbConn,0,',',' ') ?></div><div class="kpi-lbl">Connexions journalisées</div></div>
</section>

<section class="panel">
  <div class="panel-head"><h2>État des fonctions</h2></div>
  <div class="table-wrap">
  <table class="grid-table">
    <thead><tr><th>Fonction</th><th>État</th><th>Détail</th></tr></thead>
    <tbody>
    <?php foreach ($feat as [$name, $on, $detail]): ?>
      <tr>
        <td><strong><?= e($name) ?></strong></td>
        <td><span class="badge <?= $on ? 'on' : 'off' ?>"><?= $on ? 'Actif' : 'Inactif' ?></span></td>
        <td class="muted"><?= e($detail) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>🕒 Serveur de temps (NTP)</h2>
    <span class="badge <?= ($timeSt['synchronized'] ?? '') === 'yes' ? 'on' : 'off' ?>"><?= ($timeSt['synchronized'] ?? '') === 'yes' ? 'Synchronisé' : 'Non synchronisé' ?></span></div>
  <div style="padding:1.1rem 1.2rem">
    <?php if ($timeFlash) { pf_flash($timeFlash[0], $timeFlash[1]); } ?>
    <p class="muted small" style="margin-top:0">La passerelle est la <strong>référence de temps du domaine</strong> : elle se cale sur une source amont
    et sert l'heure aux postes. <strong>Indispensable à Kerberos</strong> — un écart supérieur à ~5 min bloque l'ouverture de session
    et l'application des stratégies de groupe sur les postes.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.7rem;margin:.7rem 0 1rem">
      <div style="border:1px solid var(--line);border-radius:10px;padding:.6rem .8rem;background:var(--bg)">
        <div class="muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em">Heure passerelle</div>
        <div style="font-weight:700;margin-top:.15rem"><?= e($timeSt['localtime'] ?? '—') ?></div></div>
      <div style="border:1px solid var(--line);border-radius:10px;padding:.6rem .8rem;background:var(--bg)">
        <div class="muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em">Source amont réglée</div>
        <div style="font-weight:700;margin-top:.15rem"><?= e($timeSt['server'] ?? '—') ?></div></div>
      <div style="border:1px solid var(--line);border-radius:10px;padding:.6rem .8rem;background:var(--bg)">
        <div class="muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em">Référence active</div>
        <div style="font-weight:700;margin-top:.15rem"><?= e($timeSt['refid'] ?? '—') ?></div></div>
      <div style="border:1px solid var(--line);border-radius:10px;padding:.6rem .8rem;background:var(--bg)">
        <div class="muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em">Sert l'heure au LAN</div>
        <div style="font-weight:700;margin-top:.15rem"><?= ($timeSt['serving'] ?? '') === 'yes' ? '✅ Oui' : '❌ Non' ?></div></div>
    </div>

    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
      <form method="post" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin:0;flex:1 1 320px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="time"><input type="hidden" name="act" value="set">
        <label style="flex:1 1 220px">Serveur de temps amont (NTP)
          <input type="text" name="server" value="<?= e($timeSt['server'] ?? '') ?>" placeholder="time.windows.com" required
                 pattern="[A-Za-z0-9][A-Za-z0-9.:-]+" title="Nom d'hôte ou IP (ex. time.windows.com, fr.pool.ntp.org, 192.168.0.4)"></label>
        <button class="btn" type="submit">💾 Enregistrer</button>
      </form>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="time"><input type="hidden" name="act" value="resync">
        <button class="btn-ghost" type="submit">🔄 Resynchroniser maintenant</button>
      </form>
    </div>
    <p class="muted small" style="margin:.5rem 0 0">Exemples : <code>time.windows.com</code> (Microsoft), <code>fr.pool.ntp.org</code>, ou l'IP d'un serveur de temps interne.
    Le pool Debian reste en secours automatique.</p>

    <?php if ($timeSt['sources']): ?>
    <div class="table-wrap" style="margin-top:1rem"><table class="grid-table">
      <thead><tr><th>État</th><th>Source</th><th>Strate</th><th>Atteignabilité</th><th>Dernier contact</th></tr></thead>
      <tbody>
      <?php foreach ($timeSt['sources'] as $s):
        $ms = $s['ms'];
        if (strpos($ms, '*') !== false)      { $st = '<span class="badge on">sélectionnée</span>'; }
        elseif (strpos($ms, '+') !== false)  { $st = '<span class="badge">candidate</span>'; }
        elseif (strpos($ms, '?') !== false)  { $st = '<span class="muted">en attente</span>'; }
        elseif (strpos($ms, 'x') !== false)  { $st = '<span class="badge off">rejetée</span>'; }
        else                                 { $st = '<span class="muted">secours</span>'; }
      ?>
        <tr>
          <td><?= $st ?></td>
          <td class="mono"><?= e($s['name']) ?></td>
          <td><?= e($s['stratum']) ?></td>
          <td><?= (int) $s['reach'] === 377 ? '✅ 377/377' : e($s['reach']) . '/377' ?></td>
          <td class="muted"><?= e($s['lastrx']) ?> s</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>💓 Santé de la passerelle</h2>
    <?php if ($uptime !== ''): ?><span class="muted small">en service <?= e($uptime) ?></span><?php endif; ?></div>
  <div style="padding:1.2rem;display:grid;gap:1rem">
    <?php foreach ($sante as [$lbl, $pct, $sub]):
      $col = $pct >= 90 ? '#f87171' : ($pct >= 75 ? '#eab308' : '#4ade80'); ?>
      <div>
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.3rem">
          <span><strong><?= e($lbl) ?></strong> <span class="muted small"><?= e($sub) ?></span></span>
          <strong style="font-variant-numeric:tabular-nums;color:<?= $col ?>"><?= $pct ?> %</strong>
        </div>
        <div style="height:10px;background:var(--bg);border-radius:6px;overflow:hidden">
          <div style="height:100%;border-radius:6px;width:<?= $pct ?>%;background:<?= $col ?>;transition:width .4s"></div></div>
      </div>
    <?php endforeach; ?>
    <?php if ($dfPct >= 90): ?>
      <div class="flash err" style="margin:0">⚠️ Disque système presque plein (<?= $dfPct ?> %) — les journaux et les
      sauvegardes risquent d'échouer. Purgez d'anciennes données ou agrandissez le disque.</div>
    <?php endif; ?>
  </div>
</section>

<?php
$nTotal  = (int) ($apt['total'] ?? 0);
$nSecu   = (int) ($apt['secu'] ?? 0);
$nRet    = (int) ($apt['retenus'] ?? 0);
$enCours = !empty($apt['en_cours']) || !empty($apt['check_en_cours']);
$dtCheck = (int) ($apt['dernier_check'] ?? 0);
$dtMaj   = (int) ($apt['derniere_maj'] ?? 0);
$dispo   = $apt !== [];
?>
<style>
  .apt-head{display:flex;align-items:center;gap:1.4rem;flex-wrap:wrap;padding:1.2rem}
  .apt-num{font-size:2.4rem;font-weight:700;line-height:1;color:#fff;font-variant-numeric:tabular-nums}
  .apt-num.warn{color:#eab308} .apt-num.danger{color:#f87171} .apt-num.ok{color:#4ade80}
  .apt-actions{margin-left:auto;display:flex;gap:.5rem;flex-wrap:wrap}
  .bar{height:10px;background:var(--bg);border-radius:6px;overflow:hidden}
  .bar .fill{height:100%;border-radius:6px;transition:width .4s ease}
  /* Rayures animées : une barre qui n'avance pas pendant une longue étape dpkg
     laisserait croire à un blocage. Le mouvement dit « ça travaille ». */
  #aptFill{background-image:linear-gradient(45deg,rgba(255,255,255,.18) 25%,transparent 25%,transparent 50%,rgba(255,255,255,.18) 50%,rgba(255,255,255,.18) 75%,transparent 75%,transparent);
           background-size:14px 14px;animation:aptRayures 1s linear infinite}
  @keyframes aptRayures{from{background-position:0 0}to{background-position:14px 0}}
  @media(prefers-reduced-motion:reduce){#aptFill{animation:none}}
  #aptLog{background:#0b1120;border:1px solid var(--line);border-radius:8px;padding:.8rem;margin:0 1.2rem 1.2rem;
          font-family:ui-monospace,monospace;font-size:.78rem;line-height:1.5;color:#cbd5e1;
          max-height:19rem;overflow:auto;white-space:pre-wrap;word-break:break-word}
  .apt-pkg{display:flex;justify-content:space-between;gap:1rem;padding:.35rem 0;border-bottom:1px solid var(--line);font-size:.85rem}
  .apt-pkg:last-child{border-bottom:0}
  .apt-pkg .v{color:var(--muted);font-family:ui-monospace,monospace;font-size:.76rem}
</style>
<?php
$gPret   = !empty($git['pret']);
$gClone  = !empty($git['clone']);
$gRetard = (int) ($git['retard'] ?? 0);
$sysN    = $dispo ? $nTotal : 0;
$gitN    = ($gPret && $gClone) ? $gRetard : 0;
$toutAJour = ($dispo ? $nTotal === 0 : true) && (($gPret && $gClone) ? $gRetard === 0 : true);
$aDeployer = ($sysN + $gitN) > 0;
?>
<style>
  .upd-row{display:flex;align-items:center;gap:.9rem;padding:.8rem 0;border-bottom:1px solid var(--line)}
  .upd-row:last-of-type{border-bottom:0}
  .upd-ico{font-size:1.5rem;width:2.1rem;text-align:center;flex:none}
  .upd-info{flex:1;min-width:0}
  .upd-actions{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1rem}
  .upd-bar-lbl{display:block;font-size:.82rem;margin-bottom:.35rem}
  #updProg .bar{margin-bottom:.2rem}
  #updLog{background:#0b1120;border:1px solid var(--line);border-radius:8px;padding:.8rem;margin:1rem 0 0;
          font-family:ui-monospace,monospace;font-size:.78rem;line-height:1.5;color:#cbd5e1;
          max-height:19rem;overflow:auto;white-space:pre-wrap;word-break:break-word}
  .fill-anim{background-image:linear-gradient(45deg,rgba(255,255,255,.18) 25%,transparent 25%,transparent 50%,rgba(255,255,255,.18) 50%,rgba(255,255,255,.18) 75%,transparent 75%,transparent);
             background-size:14px 14px;animation:aptRayures 1s linear infinite}
</style>
<section class="panel">
  <div class="panel-head"><h2>🔄 Mise à jour</h2>
    <span class="badge <?= $toutAJour ? 'on' : ($nSecu > 0 ? 'off' : 'warn') ?>">
      <?= $toutAJour ? 'tout est à jour' : (($sysN + $gitN) . ' mise(s) à jour disponible(s)') ?></span>
  </div>
  <div style="padding:1.2rem">
    <?php if ($aptFlash): ?><div class="flash <?= e($aptFlash[1]) ?>"><?= e($aptFlash[0]) ?></div><?php endif; ?>
    <?php if ($gitFlash): ?><div class="flash <?= e($gitFlash[1]) ?>"><?= e($gitFlash[0]) ?></div><?php endif; ?>

    <!-- Résumé combiné : système + Bastion, d'un coup d'œil -->
    <div class="upd-summary">
      <div class="upd-row">
        <div class="upd-ico">🖥️</div>
        <div class="upd-info"><strong>Système</strong> <span class="muted small"><?= e($apt['version'] ?? 'Debian') ?></span>
          <div class="muted small"><?php
            if (!$dispo) { echo 'service de mise à jour indisponible'; }
            elseif ($nTotal === 0) { echo 'à jour'; }
            else { echo $nTotal . ' mise(s) à jour' . ($nSecu > 0 ? ' — dont ' . $nSecu . ' de sécurité' : ''); }
          ?></div>
        </div>
        <div class="apt-num <?= !$dispo ? '' : ($nSecu > 0 ? 'danger' : ($nTotal > 0 ? 'warn' : 'ok')) ?>" style="font-size:1.7rem">
          <?= !$dispo ? '—' : ($nTotal > 0 ? $nTotal : '✓') ?></div>
      </div>
      <div class="upd-row">
        <div class="upd-ico">🐙</div>
        <div class="upd-info"><strong>Bastion</strong> <span class="muted small">application</span>
          <div class="muted small"><?php
            if (!$gPret) { echo 'dépôt non configuré (voir ci-dessous)'; }
            elseif (!$gClone) { echo 'dépôt configuré — lancez une vérification'; }
            elseif ($gRetard === 0) { echo 'à jour · version ' . e($git['local'] ?: '—'); }
            else { echo $gRetard . ' version(s) de retard'; }
          ?></div>
        </div>
        <div class="apt-num <?= (!$gPret || !$gClone) ? '' : ($gRetard > 0 ? 'warn' : 'ok') ?>" style="font-size:1.7rem">
          <?= (!$gPret || !$gClone) ? '—' : ($gRetard > 0 ? $gRetard : '✓') ?></div>
      </div>
    </div>

    <!-- Actions « tout en un » : un bouton vérifie/installe système ET Bastion -->
    <div class="upd-actions">
      <button class="btn-sm" id="updCheck">↻ Tout vérifier</button>
      <button class="btn" id="updApply" <?= $aDeployer ? '' : 'disabled' ?>>⬆️ Tout mettre à jour</button>
    </div>

    <!-- Progression unifiée (deux étapes : système puis Bastion) -->
    <div id="updProg" hidden style="margin-top:1.2rem">
      <div id="rowSys">
        <span class="upd-bar-lbl">🖥️ Système <span class="muted" id="sysPhase"></span></span>
        <div class="bar"><div class="fill" id="sysFill" style="width:0%;background:#38bdf8"></div></div>
      </div>
      <div id="rowGit" style="margin-top:.8rem">
        <span class="upd-bar-lbl">🐙 Bastion <span class="muted" id="gitPhase"></span></span>
        <div class="bar"><div class="fill" id="gitFill" style="width:0%;background:#a78bfa"></div></div>
      </div>
    </div>
    <pre id="updLog" hidden></pre>

    <?php if (!empty($apt['reboot'])): ?>
      <div class="flash warn" style="margin-top:1rem">⚠️ Un <strong>redémarrage</strong> reste nécessaire pour terminer
        une mise à jour précédente (noyau ou bibliothèque système). À planifier hors service : le réseau du commissariat
        sera coupé pendant l'opération.</div>
    <?php endif; ?>

    <!-- Détails système (paquets, dates, garanties) -->
    <?php if ($dispo): ?>
    <details style="margin-top:1.1rem">
      <summary class="muted small" style="cursor:pointer">Détails du système &amp; paquets</summary>
      <div style="margin-top:.7rem">
        <div class="muted small" style="line-height:1.7">
          Dernière recherche : <?= $dtCheck ? e(date('d/m/Y à H:i', $dtCheck)) : 'jamais' ?><br>
          Dernière installation : <?= $dtMaj ? e(date('d/m/Y à H:i', $dtMaj)) : 'jamais' ?>
          <?php if ($nRet > 0): ?><br><span style="color:#eab308"><?= $nRet ?> paquet(s) retenu(s)</span> — changement de dépendances, à traiter à la main<?php endif; ?>
        </div>
        <div id="aptPkgs" style="margin-top:.6rem"></div>
        <p class="hint" style="margin:.6rem 0 0">Seule la commande <code>apt upgrade</code> est lancée : elle ne
          <strong>supprime jamais</strong> un paquet (contrairement à <code>full-upgrade</code>). Vos fichiers de
          configuration modifiés (Samba, dnsmasq, OpenNDS, RADIUS) sont <strong>conservés</strong> en cas de conflit.</p>
      </div>
    </details>
    <?php endif; ?>

    <!-- Configuration du dépôt Bastion (adresse, jeton, clé de déploiement, test SSH) -->
    <details style="margin-top:.6rem" <?= $gPret ? '' : 'open' ?>>
      <summary class="muted small" style="cursor:pointer">Configuration du dépôt Bastion</summary>
      <?php if (!$gPret): ?>
        <p class="hint" style="margin:.7rem 0">Aucun dépôt configuré. Renseignez l'adresse de votre dépôt GitHub :
          Bastion pourra alors se mettre à jour d'un clic.</p>
      <?php endif; ?>
      <form method="post" style="margin-top:.7rem">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="git"><input type="hidden" name="act" value="conf">
        <div class="row3">
          <label class="field" style="grid-column:span 2">Adresse du dépôt
            <input type="text" name="git_repo" value="<?= e($git['repo'] ?? '') ?>" placeholder="https://github.com/mon-compte/bastion.git">
          </label>
          <label class="field">Branche
            <input type="text" name="git_branch" value="<?= e($git['branche'] ?? 'main') ?>" placeholder="main">
          </label>
        </div>
        <label class="field" style="max-width:30rem;margin-top:.6rem">Jeton d'accès — dépôt privé uniquement
          <input type="password" name="git_token" autocomplete="new-password"
                 placeholder="<?= !empty($git['jeton']) ? '•••••••• (jeton enregistré — le retaper pour le changer)' : 'ghp_… (laisser vide si le dépôt est public)' ?>">
        </label>
        <p class="hint" style="margin:.4rem 0 .8rem">Inutile avec une adresse <code>git@…</code> : la clé ci-dessous
          s'en charge (rien à stocker, révocable, limitée à un dépôt). Le jeton est conservé hors du dépôt, lisible du
          seul root, jamais écrit dans l'adresse.</p>
        <button class="btn-sm">Enregistrer le dépôt</button>
      </form>

      <?php if ($gitKey !== '' && !str_starts_with($gitKey, 'ERREUR')): ?>
      <div style="margin-top:1rem">
        <p class="hint" style="margin:.6rem 0">Clé de déploiement — ajoutez-la sur GitHub : <em>dépôt → Settings →
          Deploy keys → Add deploy key</em>. <strong>Ne cochez pas « Allow write access »</strong>.</p>
        <div style="display:flex;gap:.5rem;align-items:flex-start">
          <textarea id="gitKey" readonly rows="3" onclick="this.select()"
            style="flex:1;font-family:ui-monospace,monospace;font-size:.72rem;line-height:1.5;resize:vertical;background:#0b1120;color:#cbd5e1;border:1px solid var(--line);border-radius:8px;padding:.6rem"><?= e($gitKey) ?></textarea>
          <button type="button" class="btn-sm" id="gitKeyCopy" style="flex:none">Copier</button>
        </div>
        <?php
        $emp = trim((string) shell_exec('printf %s ' . escapeshellarg($gitKey) . ' | ssh-keygen -lf /dev/stdin 2>/dev/null'));
        $emp = preg_match('/(SHA256:\S+)/', $emp, $mm) ? $mm[1] : '';
        ?>
        <?php if ($emp !== ''): ?><p class="hint" style="margin:.5rem 0 0">Empreinte : <code><?= e($emp) ?></code></p><?php endif; ?>
        <form method="post" style="margin:.8rem 0 0">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="git"><input type="hidden" name="act" value="testssh">
          <button class="btn-sm" <?= $gPret ? '' : 'disabled' ?>>Tester l'accès au dépôt</button>
          <?php if (!$gPret): ?><span class="muted small" style="margin-left:.5rem">enregistrez d'abord une adresse</span><?php endif; ?>
        </form>
      </div>
      <?php endif; ?>
    </details>
  </div>
</section>


<!-- (Le suivi de « Mise à jour de Bastion » est désormais intégré au panneau unifié ci-dessus.) -->

<section class="panel form-panel">
  <div class="panel-head"><h2>🔑 Compte système</h2></div>
  <div style="padding:1.2rem">
    <?php if ($pwFlash): ?><div class="flash <?= e($pwFlash[1]) ?>"><?= e($pwFlash[0]) ?></div><?php endif; ?>
    <p class="muted small" style="margin-top:0">Change le mot de passe d'un compte de la machine
    (celui de la console physique et de l'accès SSH). Sans rapport avec le mot de passe de
    <em>cette</em> console web, qui se change dans « Administrateurs ».</p>

    <?php $syspwField = 'display:grid;gap:.3rem;font-size:.85rem;color:var(--muted)';
          $syspwInput = 'padding:.6rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px;width:100%'; ?>
    <form method="post" autocomplete="off" style="max-width:34rem;display:grid;gap:.9rem"
          onsubmit="if(this.nouveau.value!==this.confirm.value){alert('Les deux nouveaux mots de passe ne correspondent pas.');return false;}return confirm('Changer le mot de passe système ?');">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="syspw">
      <label style="<?= $syspwField ?>">Compte à modifier
        <select name="compte" style="<?= $syspwInput ?>">
          <option value="proxyfibre">proxyfibre — compte d'administration (recommandé)</option>
          <option value="root">root — superutilisateur</option>
        </select>
      </label>
      <label style="<?= $syspwField ?>">Nouveau mot de passe
        <input type="password" name="nouveau" required minlength="8" autocomplete="new-password" style="<?= $syspwInput ?>"></label>
      <label style="<?= $syspwField ?>">Confirmer le nouveau mot de passe
        <input type="password" name="confirm" required minlength="8" autocomplete="new-password" style="<?= $syspwInput ?>"></label>
      <hr style="border:none;border-top:1px solid rgba(120,150,190,.18);margin:.2rem 0">
      <label style="<?= $syspwField ?>">Votre mot de passe administrateur <span class="muted small">(pour confirmer que c'est bien vous)</span>
        <input type="password" name="actuel" required autocomplete="current-password" style="<?= $syspwInput ?>"></label>
      <div><button class="btn">Changer le mot de passe système</button></div>
    </form>

    <p class="hint muted small" style="margin-top:1rem">
      « proxyfibre » est le compte qui sert à se connecter à la machine puis à passer
      administrateur (<code>sudo</code>). C'est celui à changer si vous avez perdu l'accès.
      Choisissez « root » seulement si vous savez pourquoi : cela réactive la connexion
      directe en superutilisateur.</p>
  </div>
</section>

<script>
/* Bouton « Copier » de la clé de déploiement. */
(function () {
  var b = document.getElementById('gitKeyCopy'), t = document.getElementById('gitKey');
  if (b && t) b.addEventListener('click', function () {
    t.select();
    var fini = function () { b.textContent = 'Copié'; setTimeout(function () { b.textContent = 'Copier'; }, 1600); };
    if (navigator.clipboard) navigator.clipboard.writeText(t.value).then(fini, function () { document.execCommand('copy'); fini(); });
    else { document.execCommand('copy'); fini(); }
  });
})();

/* Mise à jour « tout en un » : orchestre le système (apt) PUIS Bastion (git) — séquentiellement
   pour éviter que les deux ne se disputent apache/dpkg — avec une progression unifiée. */
(function () {
  var CSRF = "<?= e(csrf_token()) ?>";
  var SYS_DISPO = <?= $dispo ? 'true' : 'false' ?>;
  var SYS_MAJ   = <?= ($dispo && $nTotal > 0) ? 'true' : 'false' ?>;
  var GIT_PRET  = <?= $gPret ? 'true' : 'false' ?>;
  var GIT_MAJ   = <?= ($gPret && $gClone && $gRetard > 0) ? 'true' : 'false' ?>;
  var btnC = document.getElementById('updCheck'), btnA = document.getElementById('updApply');
  if (!btnC) return;
  var prog = document.getElementById('updProg'), log = document.getElementById('updLog');
  var pcts = { apt: 0, git: 0 };

  function post(act) {
    return fetch('systeme.php', { method: 'POST', cache: 'no-store',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'do=update_ajax&act=' + act + '&csrf=' + encodeURIComponent(CSRF) })
      .then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
  }
  function state(which) {
    return fetch('systeme.php?apt=' + (which === 'git' ? 'gitstate' : 'state'), { cache: 'no-store' })
      .then(function (r) { return r.json(); }).catch(function () { return {}; });
  }
  function actif(which, s) { return which === 'git' ? !!s.en_cours : (!!s.en_cours || !!s.check_en_cours); }
  function pctOf(which, s) {
    if (which === 'git') { var p = parseInt(s.progres, 10); return isNaN(p) ? 0 : Math.min(100, p); }
    return (typeof s.pct === 'number' && s.pct >= 0) ? s.pct : -1;
  }
  function phaseOf(which, s) {
    return which === 'git' ? (s.etape || '…') : (s.phase || (s.check_en_cours ? 'recherche…' : '…'));
  }
  function setBar(which, pct, phase) {
    var fill = document.getElementById(which === 'git' ? 'gitFill' : 'sysFill');
    var ph   = document.getElementById(which === 'git' ? 'gitPhase' : 'sysPhase');
    if (fill) { fill.style.width = (pct === null ? 8 : pct) + '%'; fill.classList.toggle('fill-anim', pct !== 100); }
    if (ph) ph.textContent = phase ? ('· ' + phase + (pct !== null && pct >= 0 ? ' ' + pct + '%' : '')) : '';
  }
  function waitFor(which) {
    return new Promise(function (resolve) {
      var seen = false, n = 0;
      var iv = setInterval(async function () {
        var s = await state(which); n++;
        var a = actif(which, s);
        if (a) {
          seen = true; prog.hidden = false; log.hidden = false;
          var p = pctOf(which, s);
          if (p >= 0) pcts[which] = Math.max(pcts[which], p);   // la barre n'avance jamais à reculons
          setBar(which, p < 0 ? null : pcts[which], phaseOf(which, s));
          var lg = await fetch('systeme.php?apt=' + (which === 'git' ? 'gitlog' : 'log'), { cache: 'no-store' })
                     .then(function (r) { return r.json(); }).catch(function () { return {}; });
          if (lg && lg.log) { log.textContent = lg.log; log.scrollTop = log.scrollHeight; }
        }
        // Résout quand : on l'a vu actif puis inactif, OU après ~10 sondages sans jamais le voir
        // actif (rien à faire / opération déjà finie).
        if ((seen && !a) || (!seen && n > 8)) { clearInterval(iv); if (seen) setBar(which, 100, 'terminé'); resolve(s); }
      }, 1300);
    });
  }
  function busy(b) { btnC.disabled = b; btnA.disabled = b; }

  async function toutVerifier() {
    busy(true); prog.hidden = false; var jobs = [];
    if (SYS_DISPO) { await post('apt_check'); jobs.push(waitFor('apt')); }
    if (GIT_PRET)  { await post('git_check'); jobs.push(waitFor('git')); }
    if (!jobs.length) { busy(false); return; }
    await Promise.all(jobs);
    setTimeout(function () { location.reload(); }, 700);
  }
  async function toutMaj() {
    busy(true); prog.hidden = false;
    if (SYS_MAJ) { await post('apt_apply'); await waitFor('apt'); }   // système d'abord…
    if (GIT_MAJ) { await post('git_apply'); await waitFor('git'); }   // …puis Bastion
    setTimeout(function () { location.reload(); }, 900);
  }

  btnC.addEventListener('click', toutVerifier);
  btnA.addEventListener('click', function () {
    if (!confirm('Tout mettre à jour ?\n\nSystème : les services concernés peuvent redémarrer (brève coupure du portail possible).\nBastion : la console et le portail sont remplacés par le dépôt.\nRéglages, comptes et journaux sont préservés.')) return;
    toutMaj();
  });

  /* Liste des paquets système (volet Détails). */
  (function () {
    var box = document.getElementById('aptPkgs'); if (!box) return;
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    fetch('systeme.php?apt=list', { cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : []; }).then(function (l) {
      box.innerHTML = (!l || !l.length) ? '' : l.map(function (p) {
        return '<div class="apt-pkg"><span>' + esc(p.pkg) + (p.secu ? ' <span class="badge off" style="font-size:.68rem">sécurité</span>' : '')
             + '</span><span class="v">' + esc(p.cur) + ' → ' + esc(p.new) + '</span></div>';
      }).join('');
    }).catch(function () {});
  })();

  /* Reprise : si une opération tourne déjà au chargement (lancée ailleurs), on l'affiche. */
  (async function () {
    var sa = SYS_DISPO ? await state('apt') : {}, sg = GIT_PRET ? await state('git') : {};
    if (actif('apt', sa) || actif('git', sg)) {
      busy(true); prog.hidden = false; var jobs = [];
      if (actif('apt', sa)) jobs.push(waitFor('apt'));
      if (actif('git', sg)) jobs.push(waitFor('git'));
      await Promise.all(jobs);
      setTimeout(function () { location.reload(); }, 700);
    }
  })();
})();
</script>

<p class="muted small">Bastion — contrôleur d'accès réseau. Toutes les fonctions se gèrent depuis les
autres onglets (Utilisateurs, Groupes &amp; quotas, Filtrage, Navigation, Journaux).</p>
<?php pf_footer(); ?>
