<?php
/**
 * Bastion — Annuaire du personnel (fonctionnaires Active Directory, sinon comptes portail).
 *
 * L'annuaire ne listait que des identifiants de connexion : chercher « Dupont »
 * ne donnait rien si le compte s'appelait « 0110480 », et rien ne permettait de
 * trouver qui travaille à tel service. Le nom, le prénom et le service sont
 * pourtant déjà en base (pf_user_profile) — ils sont désormais joints.
 */
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

// ── Identités : nom, prénom, service ────────────────────────────────────────
// Une seule requête pour tout le monde, et non une par agent : sur un service de
// deux cents fonctionnaires, la boucle ferait deux cents allers-retours à la base
// pour afficher une seule page.
$profils = [];
if ($people && ($db = intranet_db())) {
    try {
        foreach ($db->query('SELECT username,nom,prenom,service FROM pf_user_profile') as $p) {
            $profils[(string) $p['username']] = $p;
        }
    } catch (Throwable $e) {}
}

$fiches = [];
$services = [];
foreach ($people as $login => $role) {
    $p   = $profils[$login] ?? null;
    $nom = trim((string) ($p['nom'] ?? ''));
    $pre = trim((string) ($p['prenom'] ?? ''));
    $srv = trim((string) ($p['service'] ?? ''));
    // Sans état civil renseigné on retombe sur l'identifiant rendu lisible : c'était
    // le comportement précédent, et il vaut mieux qu'une ligne vide.
    $aff = ($nom !== '' || $pre !== '')
         ? trim(mb_strtoupper($nom, 'UTF-8') . ' ' . $pre)
         : str_replace('.', ' ', $login);
    $fiches[] = [
        'login' => $login, 'role' => $role, 'aff' => $aff, 'srv' => $srv,
        // Ce qui est confronté à la saisie : nom, prénom, identifiant ET service,
        // pour que « informatique » ramène les agents de ce service.
        'rech'  => mb_strtolower($aff . ' ' . $login . ' ' . $srv, 'UTF-8'),
    ];
    if ($srv !== '') { $services[$srv] = ($services[$srv] ?? 0) + 1; }
}
// Tri sur le nom affiché et non sur l'identifiant : un annuaire se parcourt par nom.
usort($fiches, fn($a, $b) => strcasecmp($a['aff'], $b['aff']));
ksort($services);

$q = trim((string) ($_GET['q'] ?? ''));
// Le service demandé est confronté aux services existants plutôt que réinjecté tel
// quel : il arrive de l'adresse, donc de l'extérieur.
$srvSel = trim((string) ($_GET['service'] ?? ''));
if ($srvSel !== '' && !isset($services[$srvSel])) { $srvSel = ''; }

intranet_head('Annuaire du personnel', 'annuaire');
?>
<h1>Annuaire du personnel</h1>
<div class="card">
  <p class="muted" style="margin-top:0">
    Répertoire des agents déclarés sur le domaine. <strong><?= count($fiches) ?></strong> personne(s)<?php
    if ($services) { echo ', ' . count($services) . ' service(s)'; } ?>.
  </p>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
    <input type="search" id="f" placeholder="Nom, prénom, matricule ou service…" value="<?= e_($q) ?>"
           oninput="filtrer()" style="flex:1;min-width:210px">
    <?php if ($services): ?>
    <select id="s" onchange="filtrer()" style="min-width:170px">
      <option value="">Tous les services</option>
      <?php foreach ($services as $nomSrv => $n): ?>
        <option value="<?= e_($nomSrv) ?>"<?= $srvSel === $nomSrv ? ' selected' : '' ?>><?= e_($nomSrv) ?> (<?= $n ?>)</option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </div>
  <p class="muted" id="cpt" style="font-size:.8rem;margin:.6rem 0 0"></p>
</div>
<div class="grid" id="lst">
  <?php if (!$fiches): ?>
    <p class="muted">Aucune personne enregistrée pour le moment.</p>
  <?php else: foreach ($fiches as $p): ?>
    <div class="person" data-n="<?= e_($p['rech']) ?>" data-s="<?= e_($p['srv']) ?>">
      <div class="n"><?= e_($p['aff']) ?></div>
      <div class="r">
        <?php if ($p['srv'] !== ''): ?><span class="badge-cat" style="margin-left:0"><?= e_($p['srv']) ?></span><br><?php endif; ?>
        <?= e_($p['role']) ?> · <span class="muted"><?= e_($p['login']) ?></span>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>
<p class="muted" id="vide" hidden>Aucun agent ne correspond à cette recherche.</p>
<script>
(function () {
  var f = document.getElementById('f'), sel = document.getElementById('s'),
      cpt = document.getElementById('cpt'), vide = document.getElementById('vide');

  // Les accents sont retirés des DEUX côtés : sans cela « prefecture » ne trouve pas
  // « Préfecture », et personne ne saisit les accents dans un champ de recherche.
  function plat(v) {
    v = (v || '').toLowerCase();
    return v.normalize ? v.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : v;
  }

  window.filtrer = function () {
    var q = plat(f ? f.value : ''), s = sel ? sel.value : '', n = 0;
    // « dupont info » exige les deux termes, dans n'importe quel ordre : on ne sait
    // pas si l'agent tape le nom avant le service.
    var mots = q.split(/\s+/).filter(Boolean);
    document.querySelectorAll('#lst .person').forEach(function (p) {
      var t  = plat(p.getAttribute('data-n'));
      var ok = (s === '' || p.getAttribute('data-s') === s)
            && mots.every(function (m) { return t.indexOf(m) >= 0; });
      p.style.display = ok ? '' : 'none';
      if (ok) { n++; }
    });
    if (cpt)  { cpt.textContent = (q || s) ? (n + ' résultat' + (n > 1 ? 's' : '')) : ''; }
    if (vide) { vide.hidden = (n !== 0); }
    // L'adresse suit la recherche : l'agent peut mettre « le service informatique »
    // en signet, ou l'envoyer, au lieu de refaire la saisie à chaque visite.
    if (history.replaceState) {
      var p = [];
      if (f && f.value) { p.push('q=' + encodeURIComponent(f.value)); }
      if (s)            { p.push('service=' + encodeURIComponent(s)); }
      history.replaceState(null, '', location.pathname + (p.length ? '?' + p.join('&') : ''));
    }
  };
  // Le filtre est appliqué au chargement, pas seulement à la frappe : sinon un lien
  // « ?q=dupont » afficherait le champ rempli ET la liste entière, et l'agent
  // conclurait que la recherche ne fonctionne pas.
  filtrer();
})();
</script>
<?php intranet_foot();
