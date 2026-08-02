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
    } elseif ($do === 'dhcpcfg') {
        // Scope DHCP : plage + durée du bail. La passerelle et le DNS servi aux clients
        // ne sont pas exposés (les changer casserait le routage / le filtrage).
        $rs    = trim((string) ($_POST['range_start'] ?? ''));
        $re    = trim((string) ($_POST['range_end'] ?? ''));
        $lease = strtolower(trim((string) ($_POST['lease'] ?? '1h')));
        if (filter_var($rs, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || filter_var($re, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $flash = ['Plage invalide (adresses IPv4).', 'err'];
        } elseif (!preg_match('/^([0-9]+[smhd]|infinite)$/', $lease)) {
            $flash = ['Durée de bail invalide (ex. 30m, 1h, 12h, 7d, ou infinite).', 'err'];
        } else {
            $up = $db->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
            $up->execute(['dhcp_range_start', $rs]);
            $up->execute(['dhcp_range_end', $re]);
            $up->execute(['dhcp_lease', $lease]);
            $out = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-dhcp config 2>&1'));
            $ok  = strpos($out, 'appliquee') !== false;
            audit('dhcp.config', "$rs-$re bail=$lease" . ($ok ? '' : ' (echec)'));
            $flash = [$ok
                ? "Paramètres DHCP appliqués : plage $rs → $re, bail $lease."
                : 'Échec : ' . ($out ?: 'configuration refusée, changement annulé.'), $ok ? 'ok' : 'err'];
        }
    }
}

$rows = [];
try { $rows = $db->query('SELECT * FROM pf_dhcp ORDER BY INET_ATON(ip)')->fetchAll(); } catch (Throwable $e) {}
// Paramètres du scope DHCP (défauts alignés sur proxyfibre.conf).
$cfg = ['dhcp_range_start' => '192.168.182.10', 'dhcp_range_end' => '192.168.182.254', 'dhcp_lease' => '1h'];
try { foreach ($db->query("SELECT k,v FROM pf_settings WHERE k LIKE 'dhcp\\_%'") as $r) { $cfg[$r['k']] = $r['v']; } }
catch (Throwable $e) {}
$lanNet = trim((string) shell_exec("ip -4 addr show scope global 2>/dev/null | awk '/inet /{print \$2}' | cut -d/ -f1 | head -1"));
$lanPre = $lanNet ? preg_replace('/\.\d+$/', '.', $lanNet) : '192.168.182.';

// Baux DHCP en cours : adresses réellement distribuées par dnsmasq.
// Format d'une ligne : « <expiration epoch> <MAC> <IP> <nom du poste> <identifiant client> ».
$leases = [];
foreach (@file('/var/lib/misc/dnsmasq.leases', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    $p = preg_split('/\s+/', trim($l));
    if (count($p) >= 3 && filter_var($p[2], FILTER_VALIDATE_IP)) {
        $leases[] = ['exp' => (int) $p[0], 'mac' => strtolower($p[1]), 'ip' => $p[2],
                     'host' => ($p[3] ?? '*') === '*' ? '' : ($p[3] ?? '')];
    }
}
usort($leases, fn($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));

// Clients actuellement en ligne (pour proposer leur MAC en un clic).
$live = [];
foreach (nds_clients() as $mac => $c) {
    $m = strtolower((string) $mac);
    if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $m)) { $live[$m] = (string) ($c['ip'] ?? ''); }
}

pf_header('DHCP', 'dhcp.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<style>
  .dhcp-f{display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end}
  .dhcp-f label{display:grid;gap:.3rem;font-size:.82rem;color:var(--muted)}
  .dhcp-f input{padding:.55rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px}
</style>
<section class="panel">
  <div class="panel-head"><h2>⚙️ Paramètres du serveur DHCP</h2></div>
  <div style="padding:1.2rem">
    <p class="muted small" style="margin-top:0">Plage d'adresses distribuées automatiquement aux appareils, et durée du bail.
    La passerelle (<code><?= e($lanNet ?: '192.168.182.1') ?></code>) et le DNS servi aux postes restent la passerelle
    elle-même — indispensable au filtrage — et ne se règlent pas ici.</p>
    <form method="post" class="dhcp-f" onsubmit="return confirm('Appliquer les paramètres DHCP ?\n\ndnsmasq redémarre brièvement ; les baux en cours restent valides.')">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="dhcpcfg">
      <label>Début de plage
        <input type="text" name="range_start" value="<?= e($cfg['dhcp_range_start']) ?>" style="font-family:ui-monospace,monospace;min-width:150px"></label>
      <label>Fin de plage
        <input type="text" name="range_end" value="<?= e($cfg['dhcp_range_end']) ?>" style="font-family:ui-monospace,monospace;min-width:150px"></label>
      <label>Durée du bail
        <input type="text" name="lease" value="<?= e($cfg['dhcp_lease']) ?>" placeholder="1h" style="max-width:110px"></label>
      <button class="btn">💾 Appliquer</button>
    </form>
    <p class="hint muted small" style="margin:.7rem 0 0">Bail : <code>30m</code>, <code>1h</code>, <code>12h</code>, <code>7d</code>
    ou <code>infinite</code>. Les réservations ci-dessous restent prioritaires. Une configuration invalide est
    <strong>automatiquement annulée</strong> — dnsmasq est testé avant d'être appliqué.</p>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>📋 Adresses attribuées (<?= count($leases) ?>)</h2></div>
  <div style="padding:1.2rem">
    <p class="muted small" style="margin-top:0">Adresses <strong>réellement distribuées</strong> par le serveur DHCP
    en ce moment, avec le nom que le poste a annoncé. Un appareil éteint reste listé jusqu'à l'expiration de son bail.</p>
    <table class="grid-table">
      <thead><tr><th style="width:150px">Adresse IP</th><th style="width:170px">Adresse MAC</th><th>Nom du poste</th>
        <th style="width:150px">Bail expire</th><th style="width:120px">État</th><th style="width:120px"></th></tr></thead>
      <tbody>
        <?php if (!$leases): ?>
          <tr><td colspan="6" class="muted center">Aucun bail en cours. Les appareils apparaîtront ici dès qu'ils
            demanderont une adresse.</td></tr>
        <?php else: $now = time(); foreach ($leases as $lz):
                $resv = false; foreach ($rows as $rw) { if (strtolower($rw['mac']) === $lz['mac']) { $resv = true; break; } }
                $online = isset($live[$lz['mac']]); ?>
          <tr>
            <td class="mono"><strong><?= e($lz['ip']) ?></strong></td>
            <td class="mono small"><?= e($lz['mac']) ?></td>
            <td><?= $lz['host'] !== '' ? e($lz['host']) : '<span class="muted">(sans nom)</span>' ?></td>
            <td class="small"><?= $lz['exp'] > 0
                  ? ($lz['exp'] > $now ? e(date('d/m/Y H:i', $lz['exp'])) : '<span class="muted">expiré</span>')
                  : '<span class="muted">illimité</span>' ?></td>
            <td>
              <?php if ($online): ?><span class="badge on">En ligne</span><?php endif; ?>
              <?php if ($resv): ?><span class="badge">Réservée</span><?php endif; ?>
              <?php if (!$online && !$resv): ?><span class="muted small">—</span><?php endif; ?>
            </td>
            <td class="row-actions">
              <?php if (!$resv): ?>
                <button type="button" class="btn-sm js-resv" data-mac="<?= e($lz['mac']) ?>" data-ip="<?= e($lz['ip']) ?>"
                  title="Toujours attribuer cette adresse à cet appareil">Réserver</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>🔌 Réservations DHCP (<?= count($rows) ?>)</h2></div>
  <div style="padding:1.2rem">
    <p class="muted small" style="margin-top:0">Attribue toujours la même adresse IP à un appareil (repéré par son
    adresse MAC) — imprimantes, serveurs, bornes… L'appareil prend l'IP réservée à son prochain renouvellement de bail.</p>

    <form method="post" class="dir-inline" style="margin-bottom:1rem" id="resvForm">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="add">
      <input type="text" name="mac" id="resvMac" required placeholder="Adresse MAC (aa:bb:cc:dd:ee:ff)" list="livemac"
             style="min-width:210px;font-family:ui-monospace,monospace">
      <datalist id="livemac"><?php foreach ($live as $m => $ip): ?><option value="<?= e($m) ?>"><?= e($ip) ?></option><?php endforeach; ?></datalist>
      <input type="text" name="ip" id="resvIp" required placeholder="<?= e($lanPre) ?>50" style="max-width:150px;font-family:ui-monospace,monospace">
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

<script>
// « Réserver » depuis la liste des adresses attribuées : pré-remplit le formulaire ci-dessous
// avec l'adresse MAC et l'IP déjà obtenue par l'appareil, puis y amène l'administrateur.
document.querySelectorAll('.js-resv').forEach(function (b) {
  b.addEventListener('click', function () {
    var m = document.getElementById('resvMac'), i = document.getElementById('resvIp');
    if (!m || !i) { return; }
    m.value = b.dataset.mac; i.value = b.dataset.ip;
    document.getElementById('resvForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
    i.focus();
  });
});
</script>
