<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — vue d'ensemble système (état de toutes les fonctions). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';

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
        $pwFlash = str_starts_with($out, 'OK')
            ? ["Mot de passe du compte système « $compte » changé.", 'ok']
            : [$out ?: 'Changement impossible.', 'err'];
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
<section class="panel">
  <div class="panel-head"><h2>⬆️ Mise à jour du système</h2>
    <span class="muted small"><?= e($apt['version'] ?? 'Debian') ?></span>
  </div>

  <?php if (!$dispo): ?>
    <div style="padding:1.2rem"><p class="muted" style="margin:0">Le service de mise à jour ne répond pas
      (<code>proxyfibre-apt</code> absent, ou non autorisé en sudo).</p></div>
  <?php else: ?>
    <?php if ($aptFlash): ?>
      <div style="padding:0 1.2rem"><div class="flash <?= e($aptFlash[1]) ?>"><?= e($aptFlash[0]) ?></div></div>
    <?php endif; ?>

    <div class="apt-head">
      <div>
        <div class="apt-num <?= $nSecu > 0 ? 'danger' : ($nTotal > 0 ? 'warn' : 'ok') ?>"><?= $nTotal ?></div>
        <div class="muted small">
          <?= $nTotal === 0 ? 'système à jour' : ($nTotal === 1 ? 'mise à jour disponible' : 'mises à jour disponibles') ?>
          <?php if ($nSecu > 0): ?> — dont <strong style="color:#f87171"><?= $nSecu ?> de sécurité</strong><?php endif; ?>
        </div>
      </div>
      <div class="muted small" style="line-height:1.7">
        Dernière recherche : <?= $dtCheck ? e(date('d/m/Y à H:i', $dtCheck)) : 'jamais' ?><br>
        Dernière installation : <?= $dtMaj ? e(date('d/m/Y à H:i', $dtMaj)) : 'jamais' ?>
        <?php if ($nRet > 0): ?><br><span style="color:#eab308"><?= $nRet ?> paquet(s) retenu(s)</span> — ils exigent
          un changement de dépendances, à traiter à la main<?php endif; ?>
      </div>
      <div class="apt-actions">
        <form method="post" style="margin:0">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="apt"><input type="hidden" name="act" value="check">
          <button class="btn-sm" id="aptCheck" <?= $enCours ? 'disabled' : '' ?>>↻ Rechercher</button>
        </form>
        <form method="post" style="margin:0" onsubmit="return confirm('Installer <?= $nTotal ?> mise(s) à jour ?\n\nLes services concernés seront redémarrés : une brève coupure du portail est possible.\nVos fichiers de configuration sont préservés.')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="apt"><input type="hidden" name="act" value="apply">
          <button class="btn" id="aptApply" <?= ($enCours || $nTotal === 0) ? 'disabled' : '' ?>>Installer</button>
        </form>
      </div>
    </div>

    <?php if (!empty($apt['reboot'])): ?>
      <div style="padding:0 1.2rem 1rem"><div class="flash warn" style="margin:0">
        ⚠️ Un <strong>redémarrage</strong> reste nécessaire pour terminer une mise à jour précédente (noyau ou
        bibliothèque système). À planifier hors service : le réseau du commissariat sera coupé pendant l'opération.
      </div></div>
    <?php endif; ?>

    <div id="aptJauge" hidden style="padding:0 1.2rem 1rem">
      <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.4rem">
        <span class="muted small" id="aptPhase">Préparation…</span>
        <strong id="aptPct" style="font-variant-numeric:tabular-nums">0 %</strong>
      </div>
      <div class="bar"><div class="fill" id="aptFill" style="width:0%;background:#38bdf8"></div></div>
    </div>

    <div style="padding:0 1.2rem 1rem" id="aptPkgs"></div>
    <pre id="aptLog" hidden></pre>

    <div style="padding:0 1.2rem 1.2rem"><p class="hint" style="margin:0">Seule la commande
      <code>apt upgrade</code> est lancée : elle ne <strong>supprime jamais</strong> un paquet, contrairement à
      <code>full-upgrade</code> qui pourrait retirer un service pour résoudre une dépendance. Vos fichiers de
      configuration modifiés (Samba, dnsmasq, OpenNDS, RADIUS) sont <strong>conservés</strong> en cas de conflit.</p></div>
  <?php endif; ?>
</section>

<script>
(function(){
  var elLog=document.getElementById('aptLog'), elPkgs=document.getElementById('aptPkgs'),
      bChk=document.getElementById('aptCheck'), bApp=document.getElementById('aptApply');
  if(!elPkgs) return;
  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}

  async function pkgs(){
    try{
      var r=await fetch('systeme.php?apt=list',{cache:'no-store'}); if(!r.ok)return;
      var l=await r.json();
      elPkgs.innerHTML = !l.length ? '' : l.map(function(p){
        return '<div class="apt-pkg"><span>'+esc(p.pkg)
             + (p.secu?' <span class="badge off" style="font-size:.68rem">sécurité</span>':'')
             + '</span><span class="v">'+esc(p.cur)+' → '+esc(p.new)+'</span></div>';
      }).join('');
    }catch(e){}
  }

  var suivi=null, vuActif=false;
  async function suivre(){
    try{
      var r=await fetch('systeme.php?apt=state',{cache:'no-store'}); if(!r.ok)return;
      var s=await r.json();
      var actif = s.en_cours || s.check_en_cours;
      if(bChk) bChk.disabled = actif;
      if(bApp) bApp.disabled = actif || s.total===0;
      // Jauge. « pct » vaut -1 tant qu'apt n'a rien annoncé (résolution des dépendances) :
      // on montre alors une barre indéterminée plutôt qu'un 0 % figé qui ferait croire
      // à un blocage.
      var jauge=document.getElementById('aptJauge');
      if(jauge){
        if(actif){
          jauge.hidden=false;
          var p = (typeof s.pct==='number' && s.pct>=0) ? s.pct : null;
          document.getElementById('aptFill').style.width = (p===null?8:p)+'%';
          document.getElementById('aptPct').textContent  = (p===null?'…':p+' %');
          document.getElementById('aptPhase').textContent =
            s.phase || (s.check_en_cours ? 'Recherche des mises à jour…' : 'Préparation…');
        } else { jauge.hidden=true; }
      }
      if(actif){
        vuActif=true; elLog.hidden=false;
        var lr=await fetch('systeme.php?apt=log',{cache:'no-store'});
        if(lr.ok){
          var d=await lr.json();
          // Ne pas arracher la vue si l'administrateur a remonté pour lire : on ne
          // recolle en bas que s'il y était déjà.
          var enBas = elLog.scrollTop + elLog.clientHeight >= elLog.scrollHeight - 20;
          elLog.textContent = d.log || '(démarrage…)';
          if(enBas) elLog.scrollTop = elLog.scrollHeight;
        }
      } else if(vuActif){
        // L'opération vient de se terminer : on recharge pour repartir d'un état franc
        // (compteurs, « redémarrage requis », date de dernière installation).
        clearInterval(suivi); suivi=null;
        location.reload();
      }
    }catch(e){}
  }

  pkgs();
  suivre();
  suivi = setInterval(suivre, 2500);
})();
</script>


<?php
$gPret   = !empty($git['pret']);
$gClone  = !empty($git['clone']);
$gActif  = !empty($git['en_cours']);
$gRetard = (int) ($git['retard'] ?? 0);
?>
<section class="panel">
  <div class="panel-head"><h2>🐙 Mise à jour de Bastion</h2>
    <?php if ($gPret && $gClone): ?>
      <span class="badge <?= $gRetard > 0 ? 'warn' : 'on' ?>">
        <?= $gRetard > 0 ? $gRetard . ' version(s) de retard' : 'à jour' ?></span>
    <?php endif; ?>
  </div>
  <div style="padding:1.2rem">
    <?php if ($gitFlash): ?><div class="flash <?= e($gitFlash[1]) ?>"><?= e($gitFlash[0]) ?></div><?php endif; ?>

    <?php if ($gPret && $gClone): ?>
      <div class="apt-head" style="padding:0 0 1rem">
        <div>
          <div class="apt-num <?= $gRetard > 0 ? 'warn' : 'ok' ?>"><?= $gRetard ?></div>
          <div class="muted small"><?= $gRetard === 0 ? 'Bastion est à jour' : 'version(s) disponible(s)' ?></div>
        </div>
        <div class="muted small" style="line-height:1.7">
          Version installée : <code><?= e($git['local'] ?: '—') ?></code>
          <?php if (!empty($git['sujet'])): ?><br><?= e($git['sujet']) ?><?php endif; ?>
          <?php if (!empty($git['distant']) && $git['distant'] !== $git['local']): ?>
            <br>Disponible : <code><?= e($git['distant']) ?></code><?php endif; ?>
        </div>
        <div class="apt-actions">
          <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="git"><input type="hidden" name="act" value="check">
            <button class="btn-sm" <?= $gActif ? 'disabled' : '' ?>>↻ Rechercher</button>
          </form>
          <form method="post" style="margin:0" onsubmit="return confirm('Mettre à jour Bastion vers la dernière version du dépôt ?\n\nLa console et le portail seront remplacés par le code du dépôt.\nLes réglages, les comptes et les journaux ne sont pas touchés.')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="git"><input type="hidden" name="act" value="apply">
            <button class="btn" <?= ($gActif || $gRetard === 0) ? 'disabled' : '' ?>>Mettre à jour</button>
          </form>
        </div>
      </div>
      <div id="gitJauge" hidden style="padding-bottom:1rem">
        <div class="muted small" style="margin-bottom:.4rem" id="gitPhase">Opération en cours…</div>
        <div class="bar"><div class="fill" id="gitFill" style="width:8%;background:#38bdf8"></div></div>
      </div>
      <pre id="gitLog" hidden style="margin:0 0 1rem"></pre>
    <?php elseif (!$gPret): ?>
      <p class="hint" style="margin:0 0 1rem">Aucun dépôt configuré. Renseignez l'adresse de votre dépôt GitHub :
        Bastion pourra alors se mettre à jour d'un clic, sans passer par SSH.</p>
    <?php else: ?>
      <p class="hint" style="margin:0 0 1rem">Dépôt configuré mais pas encore rattaché — lancez une première recherche.</p>
      <form method="post" style="margin:0 0 1rem">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="git"><input type="hidden" name="act" value="check">
        <button class="btn-sm">↻ Rattacher et rechercher</button>
      </form>
    <?php endif; ?>

    <details <?= $gPret ? '' : 'open' ?>>
      <summary class="muted small" style="cursor:pointer;margin-bottom:.6rem">Configuration du dépôt</summary>
      <form method="post" style="margin-top:.6rem">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="git"><input type="hidden" name="act" value="conf">
        <div class="row3">
          <label class="field" style="grid-column:span 2">Adresse du dépôt
            <input type="text" name="git_repo" value="<?= e($git['repo'] ?? '') ?>"
                   placeholder="https://github.com/mon-compte/bastion.git">
          </label>
          <label class="field">Branche
            <input type="text" name="git_branch" value="<?= e($git['branche'] ?? 'main') ?>" placeholder="main">
          </label>
        </div>
        <label class="field" style="max-width:30rem;margin-top:.6rem">Jeton d'accès — dépôt privé uniquement
          <input type="password" name="git_token" autocomplete="new-password"
                 placeholder="<?= !empty($git['jeton']) ? '•••••••• (jeton enregistré — laisser vide pour le conserver… non : le retaper pour le changer)' : 'ghp_… (laisser vide si le dépôt est public)' ?>">
        </label>
        <p class="hint" style="margin:.4rem 0 .8rem">Inutile si vous utilisez une adresse <code>git@…</code> : la clé
          ci-dessous s'en charge, et c'est préférable — rien à stocker, révocable d'un clic, limité à un seul dépôt.
          Le jeton, lui, est conservé hors du dépôt, lisible par le seul compte root, et n'est jamais écrit dans
          l'adresse (il apparaîtrait sinon dans les journaux).</p>
        <button class="btn-sm">Enregistrer le dépôt</button>
      </form>
    </details>

    <?php if ($gitKey !== '' && !str_starts_with($gitKey, 'ERREUR')): ?>
    <details style="margin-top:1rem" <?= $gPret ? '' : 'open' ?>>
      <summary class="muted small" style="cursor:pointer;margin-bottom:.6rem">Clé d'accès de cette passerelle</summary>
      <p class="hint" style="margin:.6rem 0">Ajoutez cette clé sur votre dépôt GitHub :
        <em>dépôt → Settings → Deploy keys → Add deploy key</em>. <strong>Ne cochez pas « Allow write access »</strong> :
        la passerelle n'a besoin que de lire.</p>
      <p class="hint" style="margin:0 0 .6rem">Une clé dédiée plutôt qu'un jeton de compte : cette machine est exposée
        et porte un serveur web. Si elle était compromise, une clé en lecture seule ne donnerait accès qu'à
        <em>ce seul dépôt</em> — un jeton personnel ouvrirait tout votre compte GitHub, en écriture.
        La clé privée, elle, ne quitte jamais la passerelle.</p>
      <div style="display:flex;gap:.5rem;align-items:flex-start">
        <textarea id="gitKey" readonly rows="3" onclick="this.select()"
          style="flex:1;font-family:ui-monospace,monospace;font-size:.72rem;line-height:1.5;resize:vertical;
                 background:#0b1120;color:#cbd5e1;border:1px solid var(--line);border-radius:8px;padding:.6rem"><?= e($gitKey) ?></textarea>
        <button type="button" class="btn-sm" id="gitKeyCopy" style="flex:none">Copier</button>
      </div>
      <?php
      // Un TUBE, pas un « here-string » : shell_exec passe par sh (dash), qui ne connaît
      // pas la syntaxe « <<< » de bash — elle échouait sur « redirection unexpected ».
      $emp = trim((string) shell_exec('printf %s ' . escapeshellarg($gitKey)
           . ' | ssh-keygen -lf /dev/stdin 2>/dev/null'));
      $emp = preg_match('/(SHA256:\S+)/', $emp, $mm) ? $mm[1] : '';
      ?>
      <?php if ($emp !== ''): ?>
      <p class="hint" style="margin:.5rem 0 0">Empreinte : <code><?= e($emp) ?></code> — GitHub l'affiche après ajout,
        elle doit correspondre.</p>
      <?php endif; ?>
      <form method="post" style="margin:.8rem 0 0">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="git"><input type="hidden" name="act" value="testssh">
        <button class="btn-sm" <?= $gPret ? '' : 'disabled' ?>>Tester l'accès au dépôt</button>
        <?php if (!$gPret): ?><span class="muted small" style="margin-left:.5rem">enregistrez d'abord une adresse de dépôt</span><?php endif; ?>
      </form>
    </details>
    <?php endif; ?>
  </div>
</section>

<section class="panel form-panel">
  <div class="panel-head"><h2>🔑 Compte système</h2></div>
  <div style="padding:1.2rem">
    <?php if ($pwFlash): ?><div class="flash <?= e($pwFlash[1]) ?>"><?= e($pwFlash[0]) ?></div><?php endif; ?>
    <p class="muted small" style="margin-top:0">Change le mot de passe d'un compte de la machine
    (celui de la console physique et de l'accès SSH). Sans rapport avec le mot de passe de
    <em>cette</em> console web, qui se change dans « Administrateurs ».</p>

    <form method="post" autocomplete="off" style="max-width:32rem"
          onsubmit="if(this.nouveau.value!==this.confirm.value){alert('Les deux nouveaux mots de passe ne correspondent pas.');return false;}return confirm('Changer le mot de passe système ?');">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="syspw">
      <label>Compte
        <select name="compte">
          <option value="proxyfibre">proxyfibre — compte d'administration (recommandé)</option>
          <option value="root">root — superutilisateur</option>
        </select>
      </label>
      <label>Nouveau mot de passe
        <input type="password" name="nouveau" required minlength="8" autocomplete="new-password"></label>
      <label>Confirmer le nouveau mot de passe
        <input type="password" name="confirm" required minlength="8" autocomplete="new-password"></label>
      <hr style="border:none;border-top:1px solid rgba(120,150,190,.18);margin:1rem 0">
      <label>Votre mot de passe administrateur <span class="muted small">(pour confirmer que c'est bien vous)</span>
        <input type="password" name="actuel" required autocomplete="current-password"></label>
      <button class="btn" style="margin-top:.9rem">Changer le mot de passe système</button>
    </form>

    <p class="hint muted small" style="margin-top:1rem">
      « proxyfibre » est le compte qui sert à se connecter à la machine puis à passer
      administrateur (<code>sudo</code>). C'est celui à changer si vous avez perdu l'accès.
      Choisissez « root » seulement si vous savez pourquoi : cela réactive la connexion
      directe en superutilisateur.</p>
  </div>
</section>

<script>
(function(){
  var b=document.getElementById('gitKeyCopy'), t=document.getElementById('gitKey');
  if(b && t) b.addEventListener('click', function(){
    t.select();
    // navigator.clipboard exige un contexte sécurisé : la console est en HTTPS, mais
    // execCommand reste le repli si le certificat auto-signé fait tiquer le navigateur.
    var fini = function(){ b.textContent='Copié'; setTimeout(function(){ b.textContent='Copier'; }, 1600); };
    if(navigator.clipboard) navigator.clipboard.writeText(t.value).then(fini, function(){ document.execCommand('copy'); fini(); });
    else { document.execCommand('copy'); fini(); }
  });
})();
(function(){
  var j=document.getElementById('gitJauge'), lg=document.getElementById('gitLog');
  if(!j) return;
  var vu=false, t=setInterval(async function(){
    try{
      var r=await fetch('systeme.php?apt=gitstate',{cache:'no-store'}); if(!r.ok)return;
      var s=await r.json();
      if(s.en_cours){
        vu=true; j.hidden=false; lg.hidden=false;
        var lr=await fetch('systeme.php?apt=gitlog',{cache:'no-store'});
        if(lr.ok){ var d=await lr.json(); lg.textContent=d.log||'(démarrage…)'; lg.scrollTop=lg.scrollHeight; }
      } else if(vu){ clearInterval(t); location.reload(); }
    }catch(e){}
  }, 2000);
})();
</script>

<p class="muted small">Bastion — contrôleur d'accès réseau. Toutes les fonctions se gèrent depuis les
autres onglets (Utilisateurs, Groupes &amp; quotas, Filtrage, Navigation, Journaux).</p>
<?php pf_footer(); ?>
