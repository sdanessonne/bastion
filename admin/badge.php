<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — Badge agent imprimable (photo + identité + QR). ?u=<matricule> */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/userphoto.php';
$db = pf_db();

$u = preg_replace('/[^A-Za-z0-9._@-]/', '', (string) ($_GET['u'] ?? ''));

$prof = ['nom' => '', 'prenom' => '', 'service' => ''];
try { $st = $db->prepare('SELECT nom,prenom,service FROM pf_user_profile WHERE username=?'); $st->execute([$u]); if ($r = $st->fetch()) { $prof = $r; } } catch (Throwable $e) {}
$photoV = userphoto_all_versions($db)[$u] ?? '';
$site = $cpn = '';
try {
    $st = $db->prepare('SELECT c.name,c.cpn FROM pf_user_site s JOIN pf_commissariats c ON c.id=s.commissariat_id WHERE s.username=?');
    $st->execute([$u]);
    if ($r = $st->fetch()) { $site = (string) $r['name']; $cpn = (string) $r['cpn']; }
} catch (Throwable $e) {}

$nom    = trim(($prof['prenom'] ?? '') . ' ' . strtoupper((string) ($prof['nom'] ?? '')));
if ($nom === '') { $nom = $u; }

// QR : vCard-lite, lisible par un téléphone. Généré par qrencode (déjà présent, utilisé
// pour la 2FA) et intégré en data-URI — aucun fichier écrit, aucune dépendance réseau.
$qr = '';
if ($u !== '') {
    $vcard = "BEGIN:VCARD\nVERSION:3.0\nN:" . str_replace(["\n", ':'], ' ', (string) $prof['nom']) . ';' . str_replace(["\n", ':'], ' ', (string) $prof['prenom'])
           . "\nFN:" . str_replace(["\n", ':'], ' ', $nom)
           . "\nORG:Police nationale" . ($site !== '' ? ' — ' . str_replace(["\n", ':'], ' ', $site) : '')
           . "\nTITLE:" . str_replace(["\n", ':'], ' ', (string) $prof['service'])
           . "\nNOTE:Matricule " . $u . "\nEND:VCARD\n";
    $p = proc_open('qrencode -o - -t PNG -m 1 -s 5', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($p)) {
        fwrite($pipes[0], $vcard); fclose($pipes[0]);
        $png = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        if ($png !== '' && $png !== false) { $qr = 'data:image/png;base64,' . base64_encode($png); }
    }
}

pf_header('Badge agent', 'annuaire.php');
?>
<style>
  .badge-tools{margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
  .agent-badge{width:340px;background:linear-gradient(150deg,#152238,#1e3a5f);border:1px solid var(--line);border-radius:16px;
    overflow:hidden;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,.35)}
  .agent-badge .bh{display:flex;align-items:center;gap:.6rem;padding:.8rem 1rem;background:rgba(0,0,0,.25)}
  .agent-badge .bh img{width:26px;height:26px}
  .agent-badge .bh b{font-size:1rem;letter-spacing:.5px}
  .agent-badge .bh span{margin-left:auto;font-size:.7rem;color:#9fb3d1}
  .agent-badge .bbody{display:flex;gap:1rem;padding:1.1rem 1rem}
  .agent-badge .bphoto{width:96px;height:120px;border-radius:10px;object-fit:cover;border:2px solid rgba(255,255,255,.25);background:#0b1120;flex:none}
  .agent-badge .bphoto.ini{display:flex;align-items:center;justify-content:center;font-size:2.4rem;color:#5b7aa0}
  .agent-badge .binfo{flex:1;min-width:0}
  .agent-badge .bname{font-size:1.15rem;font-weight:700;line-height:1.2}
  .agent-badge .bmat{font-family:ui-monospace,monospace;font-size:.8rem;color:#9fb3d1;margin:.15rem 0 .5rem}
  .agent-badge .brow{font-size:.82rem;margin:.15rem 0}
  .agent-badge .brow .k{color:#9fb3d1}
  .agent-badge .bfoot{display:flex;align-items:center;gap:.7rem;padding:.6rem 1rem;background:rgba(0,0,0,.2)}
  .agent-badge .bfoot img{width:66px;height:66px;background:#fff;border-radius:8px;padding:3px}
  .agent-badge .bfoot .fx{font-size:.66rem;color:#9fb3d1;line-height:1.4}
  @media print {
    .sidebar,.topbar,.badge-tools,.nav-backdrop{display:none!important}
    .content{margin:0!important} body{background:#fff!important}
    .agent-badge{box-shadow:none;border-color:#999}
  }
</style>
<div class="badge-tools">
  <a class="btn-sm" href="/annuaire.php">← Annuaire</a>
  <button type="button" class="btn" onclick="window.print()">🖨️ Imprimer le badge</button>
  <span class="muted small">Astuce : imprimez, puis découpez et plastifiez.</span>
</div>
<div class="agent-badge">
  <div class="bh"><img src="/assets/bastion-icon.svg" alt=""><b>POLICE NATIONALE</b><span>Bastion</span></div>
  <div class="bbody">
    <?php if ($photoV !== ''): ?><img class="bphoto" src="user-photo.php?u=<?= e($u) ?>&amp;v=<?= e($photoV) ?>" alt="">
    <?php else: ?><span class="bphoto ini">👤</span><?php endif; ?>
    <div class="binfo">
      <div class="bname"><?= e($nom) ?></div>
      <div class="bmat">Matricule <?= e($u) ?></div>
      <?php if (($prof['service'] ?? '') !== ''): ?><div class="brow"><span class="k">Service :</span> <?= e($prof['service']) ?></div><?php endif; ?>
      <?php if ($site !== ''): ?><div class="brow"><span class="k">Affectation :</span> <?= e($site) ?></div><?php endif; ?>
      <?php if ($cpn !== ''): ?><div class="brow"><span class="k">CPN :</span> <?= e($cpn) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="bfoot">
    <?php if ($qr !== ''): ?><img src="<?= e($qr) ?>" alt="QR"><?php endif; ?>
    <div class="fx">Carte de service interne — usage réservé.<br>Bastion — contrôle d'accès au réseau du commissariat.</div>
  </div>
</div>
<?php pf_footer(); ?>
