<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Chiffrement des postes (console centrale, façon PRIM'X en équivalent libre).
 *
 * Vue d'ensemble du CHIFFREMENT INTÉGRAL DU DISQUE de tout le parc : quels postes sont chiffrés
 * (BitLocker TPM/PIN, clé de récupération séquestrée dans l'AD), et récupération des clés pour le
 * coffre. Le déploiement se fait par la GPO « Bastion — Chiffrement BitLocker » ; un poste installé
 * par PXE et joint au domaine est chiffré au 1er démarrage. Lecture seule (aucune clé n'est modifiée).
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';

function adx(string ...$a): string {
    $c = 'sudo /usr/local/sbin/proxyfibre-ad';
    foreach ($a as $x) { $c .= ' ' . escapeshellarg($x); }
    return (string) shell_exec($c . ' 2>/dev/null');
}

// Clés de récupération séquestrées (poste => [ [pw,when], … ]).
$keys = [];
foreach (explode("\n", adx('bitlocker', 'keys')) as $l) {
    $p = explode("\t", $l);
    $c = strtoupper(trim($p[0] ?? ''));
    if ($c === '' || $c === '?') { continue; }
    $keys[$c][] = ['pw' => trim($p[1] ?? ''), 'when' => trim($p[2] ?? '')];
}
// Inventaire des postes (nom => os, dernière ouverture).
$detail = [];
foreach (explode("\n", adx('computer', 'detail')) as $l) {
    $p = explode("\t", $l);
    $c = strtoupper(trim($p[0] ?? ''));
    if ($c !== '') { $detail[$c] = ['os' => trim($p[1] ?? ''), 'll' => (int) trim($p[2] ?? '0')]; }
}

// Fusion : tous les postes connus (inventaire ∪ postes ayant une clé), hors contrôleur de domaine.
$machines = [];
foreach (array_keys($detail + $keys) as $c) {
    if ($c === 'DC' || $c === strtoupper((string) gethostname())) { continue; }
    $enc = isset($keys[$c]);
    $machines[$c] = [
        'name' => $c,
        'os'   => $detail[$c]['os'] ?? '',
        'll'   => $detail[$c]['ll'] ?? 0,
        'enc'  => $enc,
        'keys' => $keys[$c] ?? [],
    ];
}
ksort($machines);
$nEnc = count(array_filter($machines, fn($m) => $m['enc']));
$nTot = count($machines);
$pct  = $nTot ? (int) round(100 * $nEnc / $nTot) : 0;

// ── Export CSV des clés de récupération (pour le coffre) ─────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    audit('chiffrement.export_cles');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bastion-cles-recuperation.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM UTF-8 (Excel)
    fputcsv($out, ['Poste', 'Etat', 'Systeme', 'Cle de recuperation', 'Sequestree le'], ';');
    foreach ($machines as $m) {
        if (!$m['keys']) { fputcsv($out, [$m['name'], $m['enc'] ? 'chiffre' : 'non chiffre', $m['os'], '', ''], ';'); }
        foreach ($m['keys'] as $k) { fputcsv($out, [$m['name'], 'chiffre', $m['os'], $k['pw'], $k['when']], ';'); }
    }
    fclose($out);
    exit;
}

$blGpo = false;
foreach (explode("\n", adx('gpo', 'list')) as $l) { if (stripos($l, 'Chiffrement BitLocker') !== false) { $blGpo = true; break; } }

pf_header('Chiffrement des postes', 'chiffrement.php');
?>
<style>
  .ch-hero{display:flex;align-items:center;gap:1.3rem;flex-wrap:wrap;padding:1.2rem 1.4rem;border-radius:14px;
    border:1px solid var(--line);background:linear-gradient(120deg,#14324f,#152238);margin-bottom:1rem}
  .ch-ring{--p:0;width:82px;height:82px;border-radius:50%;flex:none;display:grid;place-items:center;
    background:conic-gradient(#4ade80 calc(var(--p)*1%),var(--panel2) 0)}
  .ch-ring span{width:62px;height:62px;border-radius:50%;background:var(--panel);display:grid;place-items:center;font-weight:700}
  .ch-key{font-family:ui-monospace,monospace;letter-spacing:.02em}
  .ch-mask{color:var(--muted)}
</style>

<div class="ch-hero">
  <div class="ch-ring" style="--p:<?= $pct ?>"><span><?= $nEnc ?>/<?= $nTot ?></span></div>
  <div style="flex:1;min-width:220px">
    <div style="font-size:1.15rem;font-weight:700;color:#fff">Chiffrement intégral du disque</div>
    <p class="muted" style="margin:.35rem 0 0;line-height:1.6"><?= $nEnc ?> poste(s) chiffré(s) sur <?= $nTot ?>.
    Chaque disque est protégé par <strong>BitLocker</strong> (authentification pré-démarrage TPM/PIN) et sa
    <strong>clé de récupération est séquestrée dans l'annuaire</strong> — récupérable ici en cas de besoin.</p>
  </div>
  <div style="display:flex;flex-direction:column;gap:.5rem">
    <a class="btn-sm" href="/chiffrement.php?export=csv">⬇️ Exporter les clés (coffre)</a>
    <a class="btn-sm" href="/ad.php">🔐 Déployer / régler BitLocker</a>
  </div>
</div>

<section class="panel">
  <div class="panel-head"><h2>🔐 Postes du domaine (<?= $nTot ?>)</h2>
    <span class="badge <?= $blGpo ? 'on' : 'off' ?>"><?= $blGpo ? 'GPO de chiffrement active' : 'GPO de chiffrement non déployée' ?></span></div>
  <div style="padding:.4rem 1.2rem 1.2rem">
    <p class="ad-help" style="margin:.6rem 0 1rem">Le chiffrement est déployé par la <strong>GPO « Bastion — Chiffrement BitLocker »</strong>.
    Un poste <strong>installé par le réseau (PXE)</strong> et joint au domaine est chiffré <strong>au premier démarrage</strong>,
    sa clé remontant automatiquement ici. Réservez l'accès de cette page aux administrateurs habilités.</p>
    <div class="table-wrap"><table class="grid-table">
      <thead><tr><th>Poste</th><th>Système</th><th>Chiffrement</th><th>Clé de récupération</th><th>Séquestrée le</th></tr></thead>
      <tbody>
      <?php if (!$machines): ?><tr><td colspan="5" class="muted center">Aucun poste dans le domaine pour le moment.</td></tr>
      <?php else: foreach ($machines as $m): ?>
        <tr>
          <td><strong>💻 <?= e($m['name']) ?></strong></td>
          <td class="muted small"><?= e($m['os'] ?: '—') ?></td>
          <td><?php if ($m['enc']): ?><span class="badge on">✅ Chiffré</span>
              <?php else: ?><span class="badge warn">⚠️ Non chiffré</span><?php endif; ?></td>
          <td>
            <?php if ($m['keys']): $k = $m['keys'][0]; ?>
              <span class="ch-key ch-mask" data-key="<?= e($k['pw']) ?>">•••••• — cliquez pour afficher</span>
              <?php if (count($m['keys']) > 1): ?><span class="muted small"> (+<?= count($m['keys']) - 1 ?>)</span><?php endif; ?>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
          <td class="muted small"><?= $m['keys'] ? e($m['keys'][0]['when']) : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</section>

<script>
// Les clés restent masquées par défaut ; un clic les révèle (consultation ponctuelle).
document.querySelectorAll('.ch-key[data-key]').forEach(function(el){
  el.style.cursor='pointer';
  el.addEventListener('click', function(){
    if(el.classList.contains('ch-mask')){ el.textContent=el.getAttribute('data-key'); el.classList.remove('ch-mask'); }
    else { el.textContent='•••••• — cliquez pour afficher'; el.classList.add('ch-mask'); }
  });
});
</script>
<?php pf_footer(); ?>
