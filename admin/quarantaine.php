<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — Quarantaine réseau : isoler un poste (coupe son trafic routé / Internet). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';
$db = pf_db();
try {
    $db->exec('CREATE TABLE IF NOT EXISTS pf_quarantine (id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(15) UNIQUE, label VARCHAR(96), since TIMESTAMP DEFAULT CURRENT_TIMESTAMP, added_by VARCHAR(64))');
} catch (Throwable $e) {}

function q_apply(): void { shell_exec('sudo /usr/local/sbin/proxyfibre-quarantine apply 2>/dev/null'); }

$GATEWAY_IPS = ['192.168.182.1', '192.168.182.2'];

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'block') {
        $ip  = trim((string) ($_POST['ip'] ?? ''));
        $lab = substr(trim((string) ($_POST['label'] ?? '')), 0, 96);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $flash = ['Adresse IP invalide.', 'err'];
        } elseif (in_array($ip, $GATEWAY_IPS, true)) {
            $flash = ["Impossible d'isoler la passerelle elle-même.", 'err'];
        } else {
            try {
                $db->prepare('INSERT INTO pf_quarantine (ip,label,added_by) VALUES (?,?,?)
                              ON DUPLICATE KEY UPDATE label=VALUES(label)')->execute([$ip, $lab, $_SESSION['admin'] ?? '']);
                q_apply();
                audit('quarantine.block', $ip . ($lab ? ' (' . $lab . ')' : ''));
                $flash = ["Poste $ip isolé du réseau. Son accès Internet et routé est coupé immédiatement.", 'ok'];
            } catch (Throwable $e) { $flash = ['Erreur : ' . $e->getMessage(), 'err']; }
        }
    } elseif ($do === 'unblock') {
        $ip = trim((string) ($_POST['ip'] ?? ''));
        $db->prepare('DELETE FROM pf_quarantine WHERE ip=?')->execute([$ip]);
        q_apply();
        audit('quarantine.release', $ip);
        $flash = ["Quarantaine levée pour $ip.", 'ok'];
    } elseif ($do === 'release_all') {
        $db->exec('DELETE FROM pf_quarantine');
        q_apply();
        audit('quarantine.release_all', '');
        $flash = ['Toutes les quarantaines ont été levées.', 'ok'];
    }
}

$q = [];
try { $q = $db->query('SELECT * FROM pf_quarantine ORDER BY since DESC')->fetchAll(); } catch (Throwable $e) {}
$qIps = array_column($q, 'ip');

// Clients actuellement connectés au portail (proposés à l'isolement).
$clients = [];
foreach (nds_clients() as $mac => $c) {
    $ip = (string) ($c['ip'] ?? '');
    if ($ip === '' || in_array($ip, $GATEWAY_IPS, true)) { continue; }
    $u = '';
    if (!empty($c['custom']) && ($d = base64_decode((string) $c['custom'], true)) && preg_match('/user=([^&\s]+)/', $d, $mm)) { $u = $mm[1]; }
    $clients[$ip] = ['mac' => (string) $mac, 'user' => $u];
}

pf_header('Quarantaine réseau', 'quarantaine.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<section class="panel">
  <div class="panel-head"><h2>🚫 Quarantaine réseau (<?= count($q) ?>)</h2>
    <?php if ($q): ?><form method="post" style="margin:0" onsubmit="return confirm('Lever TOUTES les quarantaines ?')">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="release_all">
      <button class="btn-sm">Tout lever</button></form><?php endif; ?></div>
  <div style="padding:1.2rem">
    <p class="muted small" style="margin-top:0">Isole un poste en cas d'incident : son accès Internet et tout son trafic
    <strong>routé</strong> par la passerelle sont coupés immédiatement (règle de pare-feu dédiée, sans toucher au
    portail). La quarantaine se lève d'un clic. <em>Limite : le trafic entre deux postes du même sous-réseau ne passe
    pas par la passerelle et n'est pas filtrable ici.</em></p>

    <?php if ($q): ?>
    <h3 style="font-size:.95rem;margin:.4rem 0 .3rem">Postes isolés</h3>
    <table class="grid-table" style="margin-bottom:1.2rem">
      <thead><tr><th>IP</th><th>Repère</th><th>Depuis</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($q as $r): ?>
          <tr>
            <td class="mono"><strong><?= e($r['ip']) ?></strong></td>
            <td><?= e($r['label']) ?: '<span class="muted">—</span>' ?></td>
            <td class="muted svc-meta"><?= e(date('d/m/Y H:i', strtotime((string) $r['since']))) ?><?= $r['added_by'] ? ' · ' . e($r['added_by']) : '' ?></td>
            <td class="row-actions">
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="unblock">
                <input type="hidden" name="ip" value="<?= e($r['ip']) ?>"><button class="btn-sm">Lever la quarantaine</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <h3 style="font-size:.95rem;margin:.4rem 0 .3rem">Isoler un poste</h3>
    <form method="post" class="dir-inline" style="margin-bottom:1rem">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="block">
      <input type="text" name="ip" required placeholder="Adresse IP du poste" style="max-width:160px;font-family:ui-monospace,monospace">
      <input type="text" name="label" placeholder="Repère (poste, agent…)" maxlength="96" style="min-width:170px">
      <button class="btn-sm btn-danger">🚫 Isoler</button>
    </form>

    <?php if ($clients): ?>
    <h4 style="font-size:.85rem;margin:1rem 0 .3rem;color:var(--muted)">Postes actuellement connectés</h4>
    <table class="grid-table">
      <thead><tr><th>IP</th><th>MAC</th><th>Agent</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($clients as $ip => $c): $isQ = in_array($ip, $qIps, true); ?>
          <tr<?= $isQ ? ' style="opacity:.5"' : '' ?>>
            <td class="mono"><?= e($ip) ?></td>
            <td class="mono small"><?= e($c['mac']) ?></td>
            <td><?= e($c['user']) ?: '<span class="muted">—</span>' ?></td>
            <td class="row-actions">
              <?php if ($isQ): ?><span class="badge off">isolé</span>
              <?php else: ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Isoler le poste <?= e($ip) ?> du réseau ?')">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="block">
                  <input type="hidden" name="ip" value="<?= e($ip) ?>"><input type="hidden" name="label" value="<?= e(trim($c['user'] . ' ' . $c['mac'])) ?>">
                  <button class="btn-sm btn-danger">Isoler</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</section>
<?php pf_footer(); ?>
