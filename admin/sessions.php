<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — lignes du tableau des sessions (rafraîchissement live du tableau de bord). */
require_once __DIR__ . '/inc/config.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['admin'])) { http_response_code(401); exit; }

$clients = nds_clients();
if (!$clients) {
    echo '<tr><td colspan="7" class="muted center">Aucun client connecté.</td></tr>';
    exit;
}
foreach ($clients as $mac => $c) {
    $user = 'inconnu';
    if (!empty($c['custom']) && ($d = base64_decode($c['custom'], true)) && preg_match('/user=([^,]+)/', $d, $mm)) { $user = $mm[1]; }
    $auth = ($c['state'] ?? '') === 'Authenticated';
    $dur  = time() - (int) ($c['session_start'] ?? time());
    ?>
    <tr>
      <td><strong><?= e($user) ?></strong></td>
      <td><span class="badge <?= $auth ? 'on' : 'off' ?>"><?= $auth ? 'Connecté' : 'En attente' ?></span></td>
      <td class="mono"><?= e($c['ip'] ?? '') ?></td>
      <td class="mono"><?= e($mac) ?></td>
      <td><?= $auth ? fmtDuration($dur) : '—' ?></td>
      <td><?= fmtBytes($c['download_this_session'] ?? 0) ?> / <?= fmtBytes($c['upload_this_session'] ?? 0) ?></td>
      <td>
        <?php if ($auth): ?>
        <form method="post" action="/index.php" onsubmit="return confirm('Déconnecter <?= e($user) ?> ?')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="deauth">
          <input type="hidden" name="mac" value="<?= e($mac) ?>">
          <button class="btn-sm btn-danger">Déconnecter</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php
}
