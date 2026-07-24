<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Accès visiteur (bons temporaires).
 *
 * Génère des identifiants d'accès Internet À DURÉE LIMITÉE pour des visiteurs/intervenants,
 * validés par le portail captif comme n'importe quel compte (radcheck). Un ramasse-miettes
 * (proxyfibre-voucher-gc, toutes les 10 min) supprime le compte ET déconnecte la session dès
 * l'échéance — granularité horaire, contrairement à la désactivation programmée (quotidienne).
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';

$db = pf_db();
$db->exec('CREATE TABLE IF NOT EXISTS pf_voucher (
    username VARCHAR(64) PRIMARY KEY, password VARCHAR(64), label VARCHAR(96),
    grp VARCHAR(64), expires_at DATETIME, created_by VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, revoked TINYINT(1) DEFAULT 0)');

// Alphabet sans caractères ambigus (pas de O/0, I/1/l) — un visiteur recopie à la main.
function vc_code(int $n): string {
    $a = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; $s = '';
    for ($i = 0; $i < $n; $i++) { $s .= $a[random_int(0, strlen($a) - 1)]; }
    return $s;
}

$flash = null; $created = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'create') {
        $hours = max(1, min(720, (int) ($_POST['hours'] ?? 4)));       // 1 h … 30 j
        $count = max(1, min(50, (int) ($_POST['count'] ?? 1)));
        $label = trim((string) ($_POST['label'] ?? ''));
        $grp   = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($_POST['grp'] ?? ''));
        $exp   = (new DateTime())->modify("+{$hours} hours")->format('Y-m-d H:i:s');
        for ($i = 0; $i < $count; $i++) {
            // Identifiant unique et lisible ; mot de passe court séparé.
            do { $u = 'visiteur-' . vc_code(4); } while ($db->query('SELECT 1 FROM radcheck WHERE username=' . $db->quote($u))->fetchColumn());
            $p = vc_code(6);
            $db->prepare('INSERT INTO radcheck (username,attribute,op,value) VALUES (?,"Cleartext-Password",":=",?)')->execute([$u, $p]);
            if ($grp !== '') { $db->prepare('INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)')->execute([$u, $grp]); }
            $db->prepare('INSERT INTO pf_voucher (username,password,label,grp,expires_at,created_by) VALUES (?,?,?,?,?,?)')
               ->execute([$u, $p, $label, $grp, $exp, (string) ($_SESSION['admin'] ?? '')]);
            $created[] = ['username' => $u, 'password' => $p, 'expires_at' => $exp, 'label' => $label];
        }
        audit('visiteurs.create', $count . ' bon(s), ' . $hours . ' h' . ($grp ? ", groupe $grp" : ''));
        $flash = [$count . ' bon(s) visiteur créé(s), valable(s) ' . $hours . ' h.', 'ok'];
    } elseif ($do === 'revoke') {
        $u = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($_POST['username'] ?? ''));
        if ($u !== '') {
            $db->prepare('DELETE FROM radcheck WHERE username=?')->execute([$u]);
            $db->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$u]);
            $db->prepare('UPDATE pf_voucher SET revoked=1 WHERE username=?')->execute([$u]);
            // Déconnexion immédiate de la session en cours (si le visiteur est en ligne).
            foreach (nds_clients() as $mac => $c) {
                if (!empty($c['custom']) && ($d = base64_decode($c['custom'], true)) && preg_match('/user=([^,]+)/', $d, $m) && $m[1] === $u) {
                    shell_exec('sudo /usr/bin/ndsctl deauth ' . escapeshellarg((string) ($c['ip'] ?? $mac)) . ' 2>/dev/null');
                }
            }
            audit('visiteurs.revoke', $u);
            $flash = ['Bon révoqué et session coupée.', 'ok'];
        }
    }
}

// Groupes portail disponibles (pour restreindre les visiteurs : quota, filtrage, débit).
$groups = [];
try { foreach ($db->query('SELECT DISTINCT groupname FROM radgroupcheck ORDER BY groupname') as $r) { $groups[] = (string) $r['groupname']; } } catch (Throwable $e) {}

// Bons actifs (non révoqués, non expirés) et historique récent.
$now = date('Y-m-d H:i:s');
$active = $db->query('SELECT * FROM pf_voucher WHERE revoked=0 AND expires_at > ' . $db->quote($now) . ' ORDER BY expires_at ASC')->fetchAll();
$past   = $db->query('SELECT * FROM pf_voucher WHERE revoked=1 OR expires_at <= ' . $db->quote($now) . ' ORDER BY created_at DESC LIMIT 20')->fetchAll();

$lanIp = '192.168.182.1';
foreach (@file('/etc/proxyfibre/net.env') ?: [] as $l) { if (preg_match('/^LAN_IP="?([\d.]+)"?/', $l, $m)) { $lanIp = $m[1]; break; } }

pf_header('Accès visiteur', 'visiteurs.php');
?>
<style>
  .vc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:.9rem}
  .vc-card{border:1px solid var(--line);border-radius:14px;background:linear-gradient(135deg,#14324f,#0f1b30);padding:1rem 1.1rem}
  .vc-card h4{margin:0 0 .5rem;font-size:.9rem;color:#7dd3fc;display:flex;align-items:center;gap:.4rem}
  .vc-cred{display:flex;justify-content:space-between;gap:.5rem;font-size:.85rem;padding:.25rem 0;border-bottom:1px dashed rgba(148,163,184,.25)}
  .vc-cred b{font-family:ui-monospace,monospace;font-size:1.05rem;color:#fff;letter-spacing:.05em}
  .vc-meta{font-size:.75rem;color:var(--muted);margin-top:.55rem}
  .vc-count{font-weight:700}
  @media print{ body *{visibility:hidden} #vc-print,#vc-print *{visibility:visible} #vc-print{position:absolute;inset:0} .vc-noprint{display:none} }
</style>

<?php if ($flash) { pf_flash($flash[0], $flash[1]); } ?>

<?php if ($created): ?>
<section class="panel" id="vc-print">
  <div class="panel-head"><h2>🎟️ Bons créés — à remettre au visiteur</h2>
    <button class="btn-sm vc-noprint" onclick="window.print()">🖨️ Imprimer</button></div>
  <div style="padding:1rem 1.2rem">
    <p class="muted small vc-noprint" style="margin-top:0">Notez ou imprimez ces identifiants <strong>maintenant</strong> : le mot de passe n'est plus affiché en clair ensuite.</p>
    <div class="vc-grid">
      <?php foreach ($created as $v): ?>
        <div class="vc-card">
          <h4>🎟️ Accès Internet visiteur<?= $v['label'] ? ' — ' . e($v['label']) : '' ?></h4>
          <div class="vc-cred"><span>Identifiant</span><b><?= e($v['username']) ?></b></div>
          <div class="vc-cred"><span>Mot de passe</span><b><?= e($v['password']) ?></b></div>
          <div class="vc-meta">Valable jusqu'au <strong><?= e(date('d/m/Y à H\hi', strtotime($v['expires_at']))) ?></strong>.<br>
            Connectez-vous au réseau puis ouvrez un navigateur : la page de connexion s'affiche.</div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="panel">
  <div class="panel-head"><h2>🎟️ Créer des bons visiteur</h2></div>
  <div style="padding:1rem 1.2rem">
    <p class="ad-help" style="margin-top:0">Des identifiants d'accès Internet <strong>temporaires</strong>, validés par le portail comme un compte agent,
    mais <strong>supprimés automatiquement à l'échéance</strong> (et la session coupée). Idéal pour un intervenant ou un visiteur.</p>
    <form method="post" class="stack" style="display:flex;gap:.8rem;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="create">
      <label style="flex:0 0 auto">Durée
        <select name="hours">
          <option value="2">2 heures</option><option value="4" selected>4 heures</option>
          <option value="8">8 heures (journée)</option><option value="24">1 jour</option>
          <option value="48">2 jours</option><option value="168">1 semaine</option>
        </select></label>
      <label style="flex:0 0 auto">Nombre <input type="number" name="count" value="1" min="1" max="50" style="width:5rem"></label>
      <label style="flex:1 1 160px">Motif (facultatif) <input type="text" name="label" placeholder="ex. Maintenance ascenseur" maxlength="96"></label>
      <?php if ($groups): ?>
      <label style="flex:0 0 auto">Groupe (facultatif)
        <select name="grp"><option value="">Accès standard</option>
          <?php foreach ($groups as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
        </select></label>
      <?php endif; ?>
      <button class="btn" type="submit">🎟️ Générer</button>
    </form>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>Bons actifs (<?= count($active) ?>)</h2></div>
  <div class="table-wrap" style="padding:.4rem .4rem 1rem">
    <table class="grid-table">
      <thead><tr><th>Identifiant</th><th>Mot de passe</th><th>Motif</th><th>Groupe</th><th>Expire</th><th></th></tr></thead>
      <tbody>
      <?php if (!$active): ?><tr><td colspan="6" class="muted center">Aucun bon actif.</td></tr>
      <?php else: foreach ($active as $v): $rem = strtotime($v['expires_at']) - time(); ?>
        <tr>
          <td class="mono"><?= e($v['username']) ?></td>
          <td class="mono"><?= e($v['password']) ?></td>
          <td><?= e($v['label'] ?: '—') ?></td>
          <td><?= $v['grp'] ? e($v['grp']) : '<span class="muted">standard</span>' ?></td>
          <td><?= e(date('d/m H\hi', strtotime($v['expires_at']))) ?>
            <span class="badge <?= $rem < 3600 ? 'warn' : 'on' ?>"><?= fmtDuration($rem) ?> restant</span></td>
          <td><form method="post" style="margin:0" onsubmit="return confirm('Révoquer ce bon et couper la session ?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="revoke">
            <input type="hidden" name="username" value="<?= e($v['username']) ?>">
            <button class="btn-sm btn-danger">Révoquer</button></form></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($past): ?>
<section class="panel">
  <div class="panel-head"><h2>Historique récent</h2></div>
  <div class="table-wrap" style="padding:.4rem .4rem 1rem">
    <table class="grid-table">
      <thead><tr><th>Identifiant</th><th>Motif</th><th>Créé le</th><th>État</th></tr></thead>
      <tbody>
      <?php foreach ($past as $v): ?>
        <tr style="opacity:.65">
          <td class="mono"><?= e($v['username']) ?></td>
          <td><?= e($v['label'] ?: '—') ?></td>
          <td class="muted"><?= e(date('d/m/Y H\hi', strtotime($v['created_at']))) ?></td>
          <td><span class="badge off"><?= (int) $v['revoked'] ? 'révoqué' : 'expiré' ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php pf_footer(); ?>
