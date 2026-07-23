<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — Réservations DHCP : IP fixe par adresse MAC (imprimantes, serveurs…). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';
$db = pf_db();
try {
    $db->exec('CREATE TABLE IF NOT EXISTS pf_dhcp (id INT AUTO_INCREMENT PRIMARY KEY,
        mac VARCHAR(17) UNIQUE, ip VARCHAR(15), label VARCHAR(64),
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, added_by VARCHAR(64))');
} catch (Throwable $e) {}

function dhcp_apply(): void { shell_exec('sudo /usr/local/sbin/proxyfibre-dhcp apply 2>/dev/null'); }

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'add') {
        $mac = strtolower(trim((string) ($_POST['mac'] ?? '')));
        $mac = preg_replace('/[^0-9a-f:]/', '', str_replace('-', ':', $mac));
        $ip  = trim((string) ($_POST['ip'] ?? ''));
        $lab = trim((string) ($_POST['label'] ?? ''));
        if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
            $flash = ['Adresse MAC invalide (format aa:bb:cc:dd:ee:ff).', 'err'];
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $flash = ['Adresse IP invalide.', 'err'];
        } else {
            try {
                $db->prepare('INSERT INTO pf_dhcp (mac,ip,label,added_by) VALUES (?,?,?,?)
                              ON DUPLICATE KEY UPDATE ip=VALUES(ip), label=VALUES(label)')
                   ->execute([$mac, $ip, $lab, $_SESSION['admin'] ?? '']);
                dhcp_apply();
                audit('dhcp.reserve', $mac . ' → ' . $ip);
                $flash = ['Réservation enregistrée et appliquée. Le poste prendra cette IP à son prochain bail.', 'ok'];
            } catch (Throwable $e) { $flash = ['Erreur : ' . $e->getMessage(), 'err']; }
        }
    } elseif ($do === 'del') {
        $db->prepare('DELETE FROM pf_dhcp WHERE id=?')->execute([(int) ($_POST['id'] ?? 0)]);
        dhcp_apply();
        audit('dhcp.remove', 'id=' . (int) ($_POST['id'] ?? 0));
        $flash = ['Réservation retirée.', 'ok'];
    }
}

$rows = [];
try { $rows = $db->query('SELECT * FROM pf_dhcp ORDER BY INET_ATON(ip)')->fetchAll(); } catch (Throwable $e) {}
$lanNet = trim((string) shell_exec("ip -4 addr show scope global 2>/dev/null | awk '/inet /{print \$2}' | cut -d/ -f1 | head -1"));
$lanPre = $lanNet ? preg_replace('/\.\d+$/', '.', $lanNet) : '192.168.182.';

// Clients actuellement en ligne (pour proposer leur MAC en un clic).
$live = [];
foreach (nds_clients() as $mac => $c) {
    $m = strtolower((string) $mac);
    if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $m)) { $live[$m] = (string) ($c['ip'] ?? ''); }
}

pf_header('Réservations DHCP', 'dhcp.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<section class="panel">
  <div class="panel-head"><h2>🔌 Réservations DHCP (<?= count($rows) ?>)</h2></div>
  <div style="padding:1.2rem">
    <p class="muted small" style="margin-top:0">Attribue toujours la même adresse IP à un appareil (repéré par son
    adresse MAC) — imprimantes, serveurs, bornes… L'appareil prend l'IP réservée à son prochain renouvellement de bail.</p>

    <form method="post" class="ad-inline" style="margin-bottom:1rem">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="add">
      <input type="text" name="mac" required placeholder="Adresse MAC (aa:bb:cc:dd:ee:ff)" list="livemac"
             style="min-width:210px;font-family:ui-monospace,monospace">
      <datalist id="livemac"><?php foreach ($live as $m => $ip): ?><option value="<?= e($m) ?>"><?= e($ip) ?></option><?php endforeach; ?></datalist>
      <input type="text" name="ip" required placeholder="<?= e($lanPre) ?>50" style="max-width:150px;font-family:ui-monospace,monospace">
      <input type="text" name="label" placeholder="Nom (ex. Imprimante accueil)" maxlength="64" style="min-width:180px">
      <button class="btn-sm">＋ Réserver</button>
    </form>
    <?php if ($live): ?><p class="muted small" style="margin:-.4rem 0 1rem">Astuce : la liste du champ MAC propose les appareils actuellement connectés.</p><?php endif; ?>

    <table class="grid-table">
      <thead><tr><th>Adresse MAC</th><th>IP réservée</th><th>Appareil</th><th></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="4" class="muted center">Aucune réservation.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td class="mono"><?= e($r['mac']) ?><?php if (isset($live[strtolower((string) $r['mac'])])): ?> <span class="badge on" style="font-size:.6rem">en ligne</span><?php endif; ?></td>
            <td class="mono"><strong><?= e($r['ip']) ?></strong></td>
            <td><?= e($r['label']) ?: '<span class="muted">—</span>' ?></td>
            <td class="row-actions">
              <form method="post" style="display:inline" onsubmit="return confirm('Retirer cette réservation ?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="del">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn-sm btn-danger">Suppr.</button></form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php pf_footer(); ?>
