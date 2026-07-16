<?php
/** Bastion — Annuaire du personnel (fonctionnaires Active Directory, sinon comptes portail). */
require_once __DIR__ . '/_common.php';
$me = intranet_user();

// Source 1 : fonctionnaires Active Directory.
$people = [];
$adOut = pf_cache_cmd('ad-users', 30, 'sudo /usr/local/sbin/proxyfibre-ad user list 2>/dev/null');
$sys = ['Administrator', 'Guest', 'krbtgt'];
foreach (array_filter(array_map('trim', explode("\n", $adOut))) as $u) {
    if ($u === '' || in_array($u, $sys, true) || stripos($u, 'dns-') === 0) { continue; }
    $people[$u] = 'Fonctionnaire';
}
// Source 2 (repli) : comptes du portail.
if (!$people && ($db = intranet_db())) {
    try {
        foreach ($db->query('SELECT DISTINCT username FROM radcheck ORDER BY username') as $r) {
            $people[$r['username']] = 'Utilisateur';
        }
    } catch (Throwable $e) {}
}
ksort($people);

$q = strtolower(trim((string) ($_GET['q'] ?? '')));

intranet_head('Annuaire du personnel', 'annuaire');
?>
<h1>Annuaire du personnel</h1>
<div class="card">
  <p class="muted">Répertoire des agents déclarés sur le domaine. <strong><?= count($people) ?></strong> personne(s).</p>
  <input type="search" id="f" placeholder="Rechercher un nom…" value="<?= e_($q) ?>" oninput="filtrer(this.value)" style="margin-top:.6rem">
</div>
<div class="grid" id="lst">
  <?php if (!$people): ?>
    <p class="muted">Aucune personne enregistrée pour le moment.</p>
  <?php else: foreach ($people as $name => $role): ?>
    <div class="person" data-n="<?= e_(strtolower($name)) ?>">
      <div class="n"><?= e_(str_replace('.', ' ', $name)) ?></div>
      <div class="r"><?= e_($role) ?> · <span class="muted"><?= e_($name) ?></span></div>
    </div>
  <?php endforeach; endif; ?>
</div>
<script>
function filtrer(v){v=(v||'').toLowerCase();
  document.querySelectorAll('#lst .person').forEach(function(p){
    p.style.display = p.getAttribute('data-n').indexOf(v)>=0 ? '' : 'none';});}
</script>
<?php intranet_foot();
