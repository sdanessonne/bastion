<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Annuaire visuel (trombinoscope) des fonctionnaires : photo, identité,
 * service, commissariat, droits et présence en ligne. Lecture seule ; l'édition se fait
 * depuis « Utilisateurs & droits ».
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/userphoto.php';
$db = pf_db();

function ad(...$args): string {
    $cmd = 'sudo /usr/local/sbin/proxyfibre-ad';
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
    return (string) shell_exec($cmd . ' 2>&1');
}
function ad_lines(...$args): array {
    return array_values(array_filter(array_map('trim', explode("\n", ad(...$args))), fn($l) => $l !== ''));
}
$dcUp = trim((string) shell_exec('systemctl is-active samba-ad-dc 2>/dev/null')) === 'active';
$sys  = ['Administrator', 'Guest', 'krbtgt', 'admin'];

// ── Recensement des comptes « humains » (portail + domaine + identité) ───────────
$names = [];
try { foreach ($db->query('SELECT DISTINCT username FROM radcheck WHERE attribute="Cleartext-Password"') as $r) { $names[(string) $r['username']] = 1; } } catch (Throwable $e) {}
try { foreach ($db->query('SELECT username FROM pf_user_profile') as $r) { $names[(string) $r['username']] = 1; } } catch (Throwable $e) {}
$adUsers = [];
if ($dcUp) { foreach (ad_lines('user', 'list') as $u) { if (stripos($u, 'dns-') !== 0) { $names[$u] = 1; $adUsers[$u] = 1; } } }
$portalG = [];
try { foreach ($db->query('SELECT username FROM radcheck WHERE attribute="Cleartext-Password"') as $r) { $portalG[(string) $r['username']] = 1; } } catch (Throwable $e) {}
$consoleAdmins = [];
try { foreach ($db->query('SELECT username FROM pf_admins') as $r) { $consoleAdmins[(string) $r['username']] = 1; } } catch (Throwable $e) {}

$agents = array_values(array_filter(array_keys($names), fn($u) => !in_array($u, $sys, true) && $u !== ''));
sort($agents);

// ── Identités, photos, commissariats, présence ───────────────────────────────────
$profiles = [];
try { foreach ($db->query('SELECT username,nom,prenom,service FROM pf_user_profile') as $r) { $profiles[(string) $r['username']] = $r; } } catch (Throwable $e) {}
userphoto_migre($db);
$photoV = userphoto_all_versions($db);
$sites = [];
try { foreach ($db->query('SELECT id,name,cpn FROM pf_commissariats') as $r) { $sites[(int) $r['id']] = $r; } } catch (Throwable $e) {}
$userSite = [];
try { foreach ($db->query('SELECT username,commissariat_id FROM pf_user_site') as $r) { $userSite[(string) $r['username']] = (int) $r['commissariat_id']; } } catch (Throwable $e) {}
$online = [];
foreach (nds_clients() as $c) {
    if (!empty($c['custom']) && ($d = base64_decode((string) $c['custom'], true)) && preg_match('/user=([^&\s]+)/', $d, $m)) { $online[$m[1]] = 1; }
}

pf_header('Annuaire', 'annuaire.php');
?>
<style>
  .tromb{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem}
  .tromb-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:1rem;display:flex;gap:.9rem;align-items:center;transition:border-color .15s}
  .tromb-card:hover{border-color:rgba(56,189,248,.5)}
  .tromb-ph{width:60px;height:60px;border-radius:14px;object-fit:cover;flex:none;border:1px solid var(--line);background:var(--bg)}
  .tromb-ph.ini{display:inline-flex;align-items:center;justify-content:center;font-size:1.5rem;color:var(--muted)}
  .tromb-main{min-width:0;flex:1}
  .tromb-nm{font-weight:600;line-height:1.2}
  .tromb-mat{font-family:ui-monospace,monospace;font-size:.74rem;color:var(--muted)}
  .tromb-svc{font-size:.8rem;color:var(--muted);margin:.15rem 0 .3rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .tromb-badges{display:flex;gap:.25rem;flex-wrap:wrap}
  .tromb-badges .rbadge{font-size:.62rem}
  .tromb-dot{width:9px;height:9px;border-radius:50%;flex:none}
  .tromb-dot.on{background:#4ade80} .tromb-dot.off{background:#475569}
  .tromb-empty{display:none}
  .rbadge{display:inline-block;padding:.1rem .4rem;border-radius:6px;font-size:.66rem;font-weight:600}
  .r-portal{background:rgba(56,189,248,.18);color:#38bdf8} .r-ad{background:rgba(52,211,153,.18);color:#34d399}
  .r-adm{background:rgba(250,204,21,.18);color:#eab308} .r-site{background:rgba(168,139,250,.18);color:#a78bfa}
</style>
<section class="panel">
  <div class="panel-head"><h2>📇 Annuaire des fonctionnaires (<?= count($agents) ?>)</h2>
    <a class="btn-sm" href="/users.php">Gérer les comptes</a></div>
  <div style="padding:1.2rem">
    <input type="search" id="q" placeholder="🔎 Rechercher : matricule, nom, service, commissariat…"
           style="width:100%;max-width:460px;margin-bottom:1rem;padding:.6rem .8rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:10px">
    <?php if (!$agents): ?><p class="muted">Aucun fonctionnaire enregistré. Créez des comptes depuis « Utilisateurs &amp; droits ».</p>
    <?php else: ?>
    <div class="tromb">
      <?php foreach ($agents as $u):
        $p = $profiles[$u] ?? ['nom'=>'','prenom'=>'','service'=>''];
        $nom = trim(($p['prenom'] ?? '') . ' ' . ($p['nom'] ?? ''));
        $sid = $userSite[$u] ?? 0; $site = $sites[$sid]['name'] ?? '';
        $on  = isset($online[$u]);
        $hay = strtolower($u . ' ' . $nom . ' ' . ($p['service'] ?? '') . ' ' . $site);
      ?>
        <div class="tromb-card" data-f="<?= e($hay) ?>">
          <?php if (!empty($photoV[$u])): ?><img class="tromb-ph" src="user-photo.php?u=<?= e($u) ?>&amp;v=<?= e($photoV[$u]) ?>" alt="">
          <?php else: ?><span class="tromb-ph ini">👤</span><?php endif; ?>
          <div class="tromb-main">
            <div class="tromb-nm"><?= $nom !== '' ? e($nom) : e($u) ?></div>
            <div class="tromb-mat"><?= e($u) ?></div>
            <div class="tromb-svc"><?= ($p['service'] ?? '') !== '' ? e($p['service']) : '<span style="opacity:.6">—</span>' ?></div>
            <div class="tromb-badges">
              <span class="tromb-dot <?= $on ? 'on' : 'off' ?>" title="<?= $on ? 'En ligne' : 'Hors ligne' ?>"></span>
              <?php if ($site !== ''): ?><span class="rbadge r-site">🏢 <?= e($site) ?></span><?php endif; ?>
              <?php if (isset($portalG[$u])): ?><span class="rbadge r-portal">Internet</span><?php endif; ?>
              <?php if (isset($adUsers[$u])): ?><span class="rbadge r-ad">Domaine</span><?php endif; ?>
              <?php if (isset($consoleAdmins[$u])): ?><span class="rbadge r-adm">Admin</span><?php endif; ?>
            </div>
            <a href="badge.php?u=<?= e($u) ?>" class="btn-sm" style="display:inline-block;margin-top:.55rem;font-size:.68rem;padding:.2rem .55rem">🪪 Badge</a>
          </div>
        </div>
      <?php endforeach; ?>
      <p class="tromb-empty muted" id="noresult" style="grid-column:1/-1">Aucun agent ne correspond à la recherche.</p>
    </div>
    <?php endif; ?>
  </div>
</section>
<script>
(function(){
  var q=document.getElementById('q'); if(!q) return;
  var cards=document.querySelectorAll('.tromb-card'), empty=document.getElementById('noresult');
  q.addEventListener('input',function(){
    var v=this.value.trim().toLowerCase(), shown=0;
    cards.forEach(function(c){ var ok=!v||(c.getAttribute('data-f')||'').indexOf(v)>=0; c.style.display=ok?'':'none'; if(ok)shown++; });
    if(empty) empty.style.display=shown?'none':'block';
  });
})();
</script>
<?php pf_footer(); ?>
