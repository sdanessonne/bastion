<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Santé & conformité sécurité.
 * Tableau de bord en LECTURE SEULE : passe en revue les points de sécurité de la
 * passerelle (2FA, chiffrement des sauvegardes, certificat, minuteries…) et guide
 * la correction. Aucun secret n'est affiché ; aucune action destructive.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';

$db = pf_db();

// Appel du gestionnaire de sauvegarde (déjà autorisé pour www-data via sudo).
function sec_bk(string ...$a): string {
    $c = 'sudo /usr/local/sbin/proxyfibre-backup';
    foreach ($a as $x) { $c .= ' ' . escapeshellarg($x); }
    return trim((string) shell_exec($c . ' 2>/dev/null'));
}

$checks = [];
// status : ok | warn | fail | info
function sec_add(array &$c, string $label, string $status, string $detail, string $action = '', string $url = ''): void {
    $c[] = compact('label', 'status', 'detail', 'action', 'url');
}

// ── Anomalies détectées : acquittement / analyse manuelle (POST) ─────────────
$anoFlash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ado = $_POST['do'] ?? '';
    if ($ado === 'anomaly_ack') {
        try { $db->prepare('UPDATE pf_anomaly SET acknowledged=1, ack_by=?, ack_at=NOW() WHERE id=?')
                 ->execute([(string) ($_SESSION['admin'] ?? ''), (int) ($_POST['id'] ?? 0)]); } catch (Throwable $e) {}
        audit('securite.anomaly_ack', 'anomalie #' . (int) ($_POST['id'] ?? 0));
        $anoFlash = ['Anomalie acquittée.', 'ok'];
    } elseif ($ado === 'anomaly_ack_all') {
        try { $db->exec('UPDATE pf_anomaly SET acknowledged=1, ack_by=' . $db->quote((string) ($_SESSION['admin'] ?? '')) . ', ack_at=NOW() WHERE acknowledged=0'); } catch (Throwable $e) {}
        audit('securite.anomaly_ack_all');
        $anoFlash = ['Toutes les anomalies ont été acquittées.', 'ok'];
    } elseif ($ado === 'anomaly_scan') {
        shell_exec('sudo /usr/local/sbin/proxyfibre-anomaly scan 2>/dev/null');
        $anoFlash = ['Analyse d\'anomalies terminée.', 'ok'];
    }
}
// Charger les anomalies (non acquittées en tête).
$anomalies = [];
try { $anomalies = $db->query('SELECT * FROM pf_anomaly ORDER BY acknowledged, ts DESC LIMIT 60')->fetchAll(); } catch (Throwable $e) {}
$anoOpen = 0; foreach ($anomalies as $a) { if (!$a['acknowledged']) { $anoOpen++; } }
sec_add($checks, 'Anomalies de sécurité', $anoOpen ? 'warn' : 'ok',
    $anoOpen ? "$anoOpen anomalie(s) non acquittée(s) — voir « Anomalies détectées » ci-dessous." : 'Aucune anomalie en attente.',
    $anoOpen ? 'Voir' : '', $anoOpen ? '#anomalies' : '');

// ── 1) Double authentification (2FA) des comptes administrateurs ─────────────
try {
    $admins = $db->query('SELECT username, IFNULL(totp_enabled,0) e FROM pf_admins')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $admins = []; }
$noTotp = array_values(array_filter($admins, fn($a) => !$a['e']));
if (!$admins) {
    sec_add($checks, 'Double authentification (2FA)', 'warn', 'Aucun compte administrateur détecté.');
} elseif (!$noTotp) {
    sec_add($checks, 'Double authentification (2FA)', 'ok', '2FA active sur les ' . count($admins) . ' compte(s) administrateur.');
} else {
    sec_add($checks, 'Double authentification (2FA)', 'warn',
        '2FA désactivée sur ' . count($noTotp) . ' compte(s) : ' . e(implode(', ', array_column($noTotp, 'username'))) . '.',
        'Activer la 2FA', '/profil.php');
}

// ── 2) Chiffrement des sauvegardes ───────────────────────────────────────────
$enc = sec_bk('key', 'status');
if (strpos($enc, 'key=yes') !== false) {
    sec_add($checks, 'Chiffrement des sauvegardes', 'ok', 'Sauvegardes chiffrées en AES-256.');
} else {
    sec_add($checks, 'Chiffrement des sauvegardes', 'fail',
        "Les sauvegardes ne sont pas chiffrées — elles contiennent l'annuaire AD (empreintes de mots de passe et clés BitLocker).",
        'Activer le chiffrement', '/sauvegarde.php');
}

// ── 3) Ancienneté de la dernière sauvegarde ──────────────────────────────────
$latest = 0;
foreach (explode("\n", sec_bk('list')) as $l) {
    $p = explode("\t", $l);
    if (count($p) >= 3) { $t = strtotime($p[2]); if ($t && $t > $latest) { $latest = $t; } }
}
if (!$latest) {
    sec_add($checks, 'Dernière sauvegarde', 'fail', 'Aucune sauvegarde trouvée.', 'Créer une sauvegarde', '/sauvegarde.php');
} else {
    $ageD = (int) floor((time() - $latest) / 86400);
    $when = date('d/m/Y à H\hi', $latest);
    if ($ageD <= 8)       { sec_add($checks, 'Dernière sauvegarde', 'ok',   "Datée du $when (il y a $ageD j)."); }
    elseif ($ageD <= 31)  { sec_add($checks, 'Dernière sauvegarde', 'warn', "Datée du $when (il y a $ageD j).", 'Sauvegarder', '/sauvegarde.php'); }
    else                  { sec_add($checks, 'Dernière sauvegarde', 'fail', "Datée du $when (il y a $ageD j) — trop ancienne.", 'Sauvegarder', '/sauvegarde.php'); }
}

// ── 4) Sauvegarde automatique planifiée ──────────────────────────────────────
$autoOn = strpos(sec_bk('auto', 'status'), 'enabled=enabled') !== false;
sec_add($checks, 'Sauvegarde automatique', $autoOn ? 'ok' : 'warn',
    $autoOn ? 'Sauvegarde hebdomadaire planifiée active.' : 'La sauvegarde automatique est désactivée.',
    $autoOn ? '' : 'Activer', $autoOn ? '' : '/sauvegarde.php');

// ── 5) Certificat HTTPS de la console (lu sur la connexion TLS en cours) ──────
$certDays = null;
$ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
$sock = @stream_socket_client('ssl://127.0.0.1:8443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
if ($sock) {
    $prm = stream_context_get_params($sock);
    if (!empty($prm['options']['ssl']['peer_certificate']) && function_exists('openssl_x509_parse')) {
        $info = openssl_x509_parse($prm['options']['ssl']['peer_certificate']);
        if (!empty($info['validTo_time_t'])) { $certDays = (int) floor(($info['validTo_time_t'] - time()) / 86400); }
    }
    fclose($sock);
}
if ($certDays === null) {
    sec_add($checks, 'Certificat HTTPS', 'info', 'Validité indéterminée (à vérifier manuellement).');
} elseif ($certDays < 0) {
    sec_add($checks, 'Certificat HTTPS', 'fail', 'Le certificat de la console est EXPIRÉ.', 'Régénérer', '/systeme.php');
} elseif ($certDays < 30) {
    sec_add($checks, 'Certificat HTTPS', 'warn', "Le certificat expire dans $certDays jour(s).", 'Régénérer', '/systeme.php');
} else {
    sec_add($checks, 'Certificat HTTPS', 'ok', "Valide encore $certDays jours.");
}

// ── 5 bis) Autorité racine « Bastion » (à approuver sur les postes) ───────────
// Empreinte SHA-256 + échéance de l'autorité, lues via l'extension OpenSSL de PHP
// (aucun secret : seul le certificat PUBLIC est lu). Sert la fiche « Approuver le
// certificat » plus bas, pour lever l'avertissement du navigateur.
$caFp = ''; $caExp = '';
$caRaw = @file_get_contents('/etc/proxyfibre/bastion-ca.crt');
if ($caRaw && function_exists('openssl_x509_read')) {
    $ca = @openssl_x509_read($caRaw);
    if ($ca) {
        $pca = openssl_x509_parse($ca);
        if (!empty($pca['validTo_time_t'])) { $caExp = date('d/m/Y', $pca['validTo_time_t']); }
        if (function_exists('openssl_x509_fingerprint')) {
            $fp = openssl_x509_fingerprint($ca, 'sha256');
            if ($fp) { $caFp = strtoupper(implode(':', str_split($fp, 2))); }
        }
    }
}

// ── 6) Comptes de test / par défaut résiduels (RADIUS) ───────────────────────
try {
    $nTest = (int) $db->query("SELECT COUNT(*) FROM radcheck WHERE username IN ('testuser','test','demo')")->fetchColumn();
} catch (Throwable $e) { $nTest = 0; }
sec_add($checks, 'Comptes de test résiduels', $nTest ? 'warn' : 'ok',
    $nTest ? "$nTest compte(s) de test présents dans RADIUS — à supprimer en production." : 'Aucun compte de test résiduel.',
    $nTest ? 'Gérer les comptes' : '', $nTest ? '/users.php' : '');

// ── 7) Minuteries de sécurité actives ────────────────────────────────────────
$timers = [
    'proxyfibre-watchdog.timer'     => 'Surveillance / alerte courriel',
    'proxyfibre-logseal.timer'      => 'Scellement des journaux',
    'proxyfibre-backup.timer'       => 'Sauvegarde automatique',
    'proxyfibre-updatecheck.timer'  => 'Recherche de mises à jour',
    'proxyfibre-account-expiry.timer' => 'Désactivation programmée des comptes',
];
$down = [];
foreach ($timers as $unit => $lbl) {
    if (trim((string) shell_exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null')) !== 'active') { $down[] = $lbl; }
}
sec_add($checks, 'Automatismes de sécurité', $down ? 'warn' : 'ok',
    $down ? 'Inactifs : ' . e(implode(', ', $down)) . '.' : 'Les ' . count($timers) . ' minuteries de sécurité sont actives.',
    $down ? 'Voir les services' : '', $down ? '/services.php' : '');

// ── 8) Dernier auto-test anti-régression ─────────────────────────────────────
$stLog = @file_get_contents('/var/log/proxyfibre-selftest.log');
if ($stLog !== false && preg_match('/Résumé\s*:\s*(\d+)\s*OK.*?(\d+)\s*échec/su', $stLog, $m)) {
    $fails = (int) $m[2];
    sec_add($checks, 'Auto-test de la passerelle', $fails ? 'fail' : 'ok',
        $fails ? "Dernier auto-test : $fails échec(s) — une page ou un service est cassé." : "Dernier auto-test : {$m[1]} contrôles OK, 0 échec.",
        $fails ? 'Voir le système' : '', $fails ? '/systeme.php' : '');
} else {
    sec_add($checks, 'Auto-test de la passerelle', 'info', 'Aucun résultat d\'auto-test disponible pour le moment.');
}

// ── 9) Mot de passe système (rappel : non détectable automatiquement) ─────────
sec_add($checks, 'Mot de passe système', 'info',
    "Le mot de passe du compte système (SSH/console) doit être long, unique et non partagé. S'il a pu être vu par un tiers, changez-le.",
    'Changer le mot de passe', '/systeme.php');

// Score global.
$score = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
foreach ($checks as $c) { $score[$c['status']]++; }
$posture = $score['fail'] ? 'À corriger' : ($score['warn'] ? 'À surveiller' : 'Conforme');
$postureClass = $score['fail'] ? 'fail' : ($score['warn'] ? 'warn' : 'ok');

pf_header('Santé & conformité sécurité', 'securite.php');
?>
<style>
  .sec-hero{display:flex;align-items:center;gap:1.2rem;flex-wrap:wrap;padding:1.1rem 1.3rem;border-radius:14px;
    border:1px solid var(--line);background:var(--bg);margin-bottom:1rem}
  .sec-score{font-size:2.2rem;font-weight:700;line-height:1}
  .sec-score.ok{color:#4ade80}.sec-score.warn{color:#eab308}.sec-score.fail{color:#f87171}
  .sec-pills{display:flex;gap:.5rem;flex-wrap:wrap}
  .sec-pill{font-size:.8rem;font-weight:600;padding:.25rem .7rem;border-radius:20px;border:1px solid var(--line)}
  .sec-pill.ok{color:#4ade80;border-color:rgba(74,222,128,.4);background:rgba(74,222,128,.08)}
  .sec-pill.warn{color:#eab308;border-color:rgba(234,179,8,.4);background:rgba(234,179,8,.08)}
  .sec-pill.fail{color:#f87171;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08)}
  .sec-list{display:flex;flex-direction:column;gap:.55rem}
  .sec-item{display:flex;align-items:flex-start;gap:.85rem;padding:.85rem 1rem;border:1px solid var(--line);
    border-radius:11px;background:var(--bg)}
  .sec-item.fail{border-color:rgba(248,113,113,.45)}
  .sec-item.warn{border-color:rgba(234,179,8,.35)}
  .sec-ic{font-size:1.2rem;line-height:1.4;flex:none;width:1.6rem;text-align:center}
  .sec-main{flex:1;min-width:0}
  .sec-lbl{font-weight:600;color:var(--text)}
  .sec-dtl{color:var(--muted);font-size:.88rem;line-height:1.5;margin-top:.15rem}
  .sec-act{flex:none;align-self:center}
</style>
<div class="sec-hero">
  <div class="sec-score <?= $postureClass ?>">🛡️</div>
  <div style="flex:1;min-width:200px">
    <div style="font-size:1.15rem;font-weight:700">Posture de sécurité : <span class="sec-score <?= $postureClass ?>" style="font-size:1.15rem"><?= $posture ?></span></div>
    <p class="muted small" style="margin:.3rem 0 0">Revue automatique des points de sécurité de la passerelle. Lecture seule.</p>
  </div>
  <div class="sec-pills">
    <span class="sec-pill ok"><?= $score['ok'] ?> conformes</span>
    <?php if ($score['warn']): ?><span class="sec-pill warn"><?= $score['warn'] ?> à surveiller</span><?php endif; ?>
    <?php if ($score['fail']): ?><span class="sec-pill fail"><?= $score['fail'] ?> à corriger</span><?php endif; ?>
  </div>
</div>

<section class="panel">
  <div class="panel-head"><h2>🔎 Points de contrôle</h2></div>
  <div style="padding:1rem 1.2rem">
    <div class="sec-list">
      <?php
      $icons = ['ok' => '✅', 'warn' => '⚠️', 'fail' => '⛔', 'info' => 'ℹ️'];
      // Trier : à corriger d'abord, puis à surveiller, puis info, puis conforme.
      $order = ['fail' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
      usort($checks, fn($a, $b) => $order[$a['status']] <=> $order[$b['status']]);
      foreach ($checks as $c): ?>
        <div class="sec-item <?= $c['status'] ?>">
          <div class="sec-ic"><?= $icons[$c['status']] ?></div>
          <div class="sec-main">
            <div class="sec-lbl"><?= e($c['label']) ?></div>
            <div class="sec-dtl"><?= $c['detail'] ?></div>
          </div>
          <?php if ($c['action'] && $c['url']): ?>
            <div class="sec-act"><a class="btn-sm" href="<?= e($c['url']) ?>"><?= e($c['action']) ?></a></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Approuver le certificat de la console -->
<section class="panel" id="ca-trust">
  <div class="panel-head"><h2>🔐 Approuver le certificat de la console</h2></div>
  <div style="padding:1rem 1.2rem">
    <p class="muted small" style="margin-top:0">
      La console est servie en HTTPS avec un certificat signé par l'autorité privée
      <strong>« Bastion »</strong>. Ce certificat couvre <strong>déjà</strong> <code>127.0.0.1</code>,
      <code>localhost</code>, <code>192.168.182.1</code> et <code>bastion.pn.int</code> — l'avertissement
      du navigateur vient <strong>uniquement</strong> du fait que cette autorité n'est pas encore
      approuvée sur votre poste. Approuvez-la <strong>une seule fois</strong> et l'avertissement
      disparaît sur <em>toutes</em> les adresses de la console.
    </p>
    <p style="margin:.6rem 0 1rem">
      <a class="btn" href="/ca.php" download>⬇️ Télécharger le certificat racine (bastion-ca.crt)</a>
    </p>
    <?php if ($caFp): ?>
    <p class="muted small" style="margin:0 0 .9rem">
      <strong>Empreinte SHA-256</strong> — à vérifier au moment de l'import :<br>
      <code style="word-break:break-all;font-size:.82rem"><?= e($caFp) ?></code>
      <?php if ($caExp): ?><br>Autorité valide jusqu'au <?= e($caExp) ?>.<?php endif; ?>
    </p>
    <?php endif; ?>
    <details>
      <summary style="cursor:pointer;font-weight:600;color:var(--text)">Comment l'approuver sur Windows ?</summary>
      <ol class="muted small" style="line-height:1.7;margin:.6rem 0 0">
        <li>Cliquez sur <strong>Télécharger le certificat racine</strong> ci-dessus.</li>
        <li>Double-cliquez sur le fichier <code>bastion-ca.crt</code> téléchargé, puis
            <strong>Installer un certificat…</strong></li>
        <li>Choisissez <strong>Ordinateur local</strong> (tout le poste) ou <strong>Utilisateur actuel</strong>,
            puis <em>Suivant</em>.</li>
        <li><strong>Placer tous les certificats dans le magasin suivant</strong> → <em>Parcourir…</em> →
            <strong>Autorités de certification racines de confiance</strong>.</li>
        <li>Terminez, <strong>vérifiez que l'empreinte affichée correspond</strong> à celle ci-dessus,
            confirmez, puis <strong>redémarrez le navigateur</strong>.</li>
      </ol>
      <p class="muted small" style="margin:.7rem 0 0">
        Variante en une commande (PowerShell <strong>administrateur</strong>, dans le dossier du fichier) :<br>
        <code style="font-size:.82rem">Import-Certificate -FilePath .\bastion-ca.crt -CertStoreLocation Cert:\LocalMachine\Root</code>
      </p>
      <p class="muted small" style="margin:.7rem 0 0">
        ⚠️ <strong>Firefox</strong> a son propre magasin : Paramètres → Vie privée et sécurité → Certificats →
        <em>Afficher les certificats</em> → onglet <em>Autorités</em> → <em>Importer</em>. Chrome et Edge
        utilisent le magasin Windows (rien de plus à faire).
      </p>
      <p class="muted small" style="margin:.7rem 0 0">
        💡 Sur un poste <strong>membre du domaine</strong>, cette autorité peut être déployée automatiquement
        à tout le parc par GPO — plus aucun avertissement nulle part.
      </p>
    </details>
  </div>
</section>

<!-- Anomalies détectées -->
<section class="panel" id="anomalies">
  <div class="panel-head"><h2>🚨 Anomalies détectées</h2>
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="anomaly_scan">
      <button class="btn-sm">🔄 Analyser maintenant</button>
    </form>
  </div>
  <div style="padding:1rem 1.2rem">
    <?php if ($anoFlash) { pf_flash($anoFlash[0], $anoFlash[1]); } ?>
    <p class="muted small" style="margin-top:0">Surveillance automatique (toutes les 20 min) : <strong>nouvel appareil</strong> sur le réseau,
    <strong>changement des administrateurs AD</strong>, <strong>GPO modifiée hors console</strong>. Une anomalie non acquittée
    remonte aussi dans les alertes du tableau de bord et par courriel.</p>
    <?php if (!$anomalies): ?>
      <p class="muted">Aucune anomalie enregistrée pour le moment.</p>
    <?php else: ?>
      <?php if ($anoOpen): ?>
        <form method="post" style="margin:0 0 .8rem" onsubmit="return confirm('Acquitter toutes les anomalies en attente ?')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="anomaly_ack_all">
          <button class="btn-sm">✓ Tout acquitter (<?= $anoOpen ?>)</button>
        </form>
      <?php endif; ?>
      <div class="table-wrap"><table class="grid-table">
        <thead><tr><th>Date</th><th>Type</th><th>Détail</th><th>État</th><th></th></tr></thead>
        <tbody>
        <?php
          $anoType = ['lan' => ['🖥️', 'Réseau'], 'admin' => ['👑', 'Admin AD'], 'gpo' => ['📋', 'GPO']];
          foreach ($anomalies as $a):
            [$ic, $tl] = $anoType[$a['type']] ?? ['❓', (string) $a['type']];
            $ack = (int) $a['acknowledged'];
        ?>
          <tr<?= $ack ? ' style="opacity:.5"' : '' ?>>
            <td class="muted svc-meta"><?= e(date('d/m/Y H:i', strtotime((string) $a['ts']))) ?></td>
            <td><span class="badge"><?= $ic ?> <?= e($tl) ?></span></td>
            <td><?= e($a['detail']) ?></td>
            <td><?php if ($ack): ?><span class="badge on">acquittée</span><?php else: ?><span class="badge <?= $a['severity'] === 'danger' ? 'danger' : 'warn' ?>"><?= $a['severity'] === 'danger' ? 'à vérifier' : 'à surveiller' ?></span><?php endif; ?></td>
            <td class="row-actions"><?php if (!$ack): ?>
              <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="anomaly_ack"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn-sm">Acquitter</button></form>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</section>
<?php pf_footer(); ?>
