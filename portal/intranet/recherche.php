<?php
/**
 * Bastion — recherche dans l'intranet : pages, actualités, annuaire.
 *
 * Jusqu'ici, retrouver une information supposait de savoir OÙ elle se trouve :
 * dans une page du menu, dans une actualité passée, ou chez un agent de
 * l'annuaire. Un fonctionnaire qui cherche « congés » ne sait pas laquelle des
 * trois — il ouvrait donc les pages une à une.
 */
require_once __DIR__ . '/_common.php';

$q = trim((string) ($_GET['q'] ?? ''));
$pages = $news = $gens = [];
$trop = false;

if ($q !== '' && mb_strlen($q, 'UTF-8') >= 2) {
    // Les caractères propres à LIKE sont neutralisés : sans cela « % » seul
    // ramènerait TOUT l'intranet, et « _ » se substituerait à n'importe quelle
    // lettre. La requête reste préparée — c'est le motif lui-même qu'on borne.
    $motif = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    if ($db = intranet_db()) {
        $groupes = intranet_groups();
        try {
            $st = $db->prepare('SELECT slug,title,body,format,group_required FROM pf_cms_pages
                                WHERE published=1 AND (title LIKE ? OR body LIKE ?) ORDER BY title LIMIT 40');
            $st->execute([$motif, $motif]);
            foreach ($st->fetchAll() as $p) {
                // Une page réservée à un groupe ne doit pas apparaître dans les
                // résultats de qui n'en fait pas partie : le titre et l'extrait
                // révéleraient déjà ce que la restriction protège.
                if (!empty($p['group_required']) && !in_array($p['group_required'], $groupes, true)) { continue; }
                $pages[] = $p;
            }
        } catch (Throwable $e) {}

        try {
            $st = $db->prepare('SELECT id,title,body,format,category,author,created_at FROM pf_cms_news
                                WHERE published=1 AND (title LIKE ? OR body LIKE ?)
                                ORDER BY created_at DESC, id DESC LIMIT 40');
            $st->execute([$motif, $motif]);
            $news = $st->fetchAll();
        } catch (Throwable $e) {}
    }

    // Annuaire : mêmes sources que la page dédiée, filtrées ici côté serveur.
    $adOut = pf_cache_cmd('ad-users', 30, 'sudo /usr/local/sbin/proxyfibre-ad user list 2>/dev/null');
    $sys = ['Administrator', 'Guest', 'krbtgt'];
    $logins = [];
    foreach (array_filter(array_map('trim', explode("\n", $adOut))) as $u) {
        if ($u === '' || in_array($u, $sys, true) || stripos($u, 'dns-') === 0) { continue; }
        $logins[$u] = 'Fonctionnaire';
    }
    $profils = [];
    if ($logins && ($db = intranet_db())) {
        try {
            foreach ($db->query('SELECT username,nom,prenom,service FROM pf_user_profile') as $p) {
                $profils[(string) $p['username']] = $p;
            }
        } catch (Throwable $e) {}
    }
    $qn = rech_plat($q);
    foreach ($logins as $login => $role) {
        $p   = $profils[$login] ?? null;
        $nom = trim((string) ($p['nom'] ?? ''));
        $pre = trim((string) ($p['prenom'] ?? ''));
        $srv = trim((string) ($p['service'] ?? ''));
        $aff = ($nom !== '' || $pre !== '') ? trim(mb_strtoupper($nom, 'UTF-8') . ' ' . $pre)
                                            : str_replace('.', ' ', $login);
        if (strpos(rech_plat($aff . ' ' . $login . ' ' . $srv), $qn) === false) { continue; }
        $gens[] = ['aff' => $aff, 'login' => $login, 'srv' => $srv, 'role' => $role];
        if (count($gens) >= 40) { $trop = true; break; }
    }
}

$total = count($pages) + count($news) + count($gens);

/** Comparaison sans accents ni casse — « prefecture » doit trouver « Préfecture ». */
function rech_plat(string $v): string {
    $v = mb_strtolower($v, 'UTF-8');
    // iconv rend « é » → « e ». En cas d'échec (locale absente), on renvoie la
    // chaîne telle quelle plutôt qu'une chaîne vide : une recherche dégradée vaut
    // mieux qu'une recherche qui ne trouve plus rien.
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
    return $t === false ? $v : strtolower($t);
}

/** Extrait centré sur le terme cherché, pour montrer POURQUOI le résultat sort. */
function rech_extrait(string $body, string $format, string $q): string {
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags(cms_render($body, $format))));
    $pos = mb_stripos($t, $q, 0, 'UTF-8');
    if ($pos === false) { return cms_extrait($body, $format, 160); }
    // On recule d'environ soixante caractères pour donner le contexte AVANT le
    // terme : un extrait qui commence pile sur le mot cherché ne dit pas de quoi
    // la phrase parlait.
    $d = max(0, $pos - 60);
    $x = mb_substr($t, $d, 200, 'UTF-8');
    return ($d > 0 ? '…' : '') . $x . (mb_strlen($t, 'UTF-8') > $d + 200 ? '…' : '');
}

/** Met en évidence le terme dans un texte DÉJÀ échappé. */
function rech_marque(string $texte, string $q): string {
    $e = e_($texte);
    if ($q === '') { return $e; }
    // Le motif est construit sur le terme échappé : chercher la forme brute ne
    // trouverait rien dans « L&#039;accueil », et surtout la balise <mark> est le
    // SEUL fragment de HTML réinjecté ici.
    return preg_replace('/(' . preg_quote(e_($q), '/') . ')/iu', '<mark>$1</mark>', $e) ?? $e;
}

intranet_head('Recherche', 'recherche');
?>
<h1 style="margin-bottom:.8rem">Recherche</h1>
<form class="card" method="get" action="/portal/intranet/recherche.php" style="padding:1rem 1.15rem">
  <div style="display:flex;gap:.6rem;flex-wrap:wrap">
    <input type="search" name="q" value="<?= e_($q) ?>" autofocus
           placeholder="Un mot, un nom, un service…" style="flex:1;min-width:220px">
    <button type="submit">Rechercher</button>
  </div>
  <?php if ($q !== '' && mb_strlen($q, 'UTF-8') < 2): ?>
    <p class="muted" style="margin:.7rem 0 0;font-size:.85rem">Saisissez au moins deux caractères.</p>
  <?php elseif ($q !== ''): ?>
    <p class="muted" style="margin:.7rem 0 0;font-size:.85rem">
      <strong><?= $total ?></strong> résultat<?= $total > 1 ? 's' : '' ?> pour « <?= e_($q) ?> ».
    </p>
  <?php endif; ?>
</form>

<?php if ($q !== '' && mb_strlen($q, 'UTF-8') >= 2): ?>
  <?php if ($total === 0): ?>
    <div class="card">
      <p class="muted" style="margin:0">Aucun résultat. Vérifiez l'orthographe, ou essayez un mot plus court —
      la recherche porte sur les pages, les actualités et l'annuaire.</p>
    </div>
  <?php endif; ?>

  <?php if ($pages): ?>
    <h2 class="sec-t" style="margin-top:1.4rem">Pages · <?= count($pages) ?></h2>
    <div class="ncards" style="grid-template-columns:repeat(auto-fill,minmax(270px,1fr))">
      <?php foreach ($pages as $p): ?>
        <a class="ncard" href="/portal/intranet/page.php?slug=<?= urlencode($p['slug']) ?>"><div class="bd">
          <div class="date">📄 Page</div>
          <h3><?= rech_marque((string) $p['title'], $q) ?></h3>
          <p class="ex"><?= rech_marque(rech_extrait((string) $p['body'], (string) ($p['format'] ?? 'markdown'), $q), $q) ?></p>
          <span class="plus">Ouvrir <span class="chev">›</span></span>
        </div></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($news): ?>
    <h2 class="sec-t" style="margin-top:1.6rem">Actualités · <?= count($news) ?></h2>
    <div class="ncards" style="grid-template-columns:repeat(auto-fill,minmax(270px,1fr))">
      <?php foreach ($news as $n): ?>
        <a class="ncard" href="<?= e_(news_url($n['id'])) ?>"><div class="bd">
          <div class="date"><?= e_(date('d/m/Y', strtotime((string) $n['created_at']))) ?><?= $n['author'] ? ' · ' . e_($n['author']) : '' ?></div>
          <h3><?= rech_marque((string) $n['title'], $q) ?><?php if (!empty($n['category'])): ?><span class="badge-cat"><?= e_($n['category']) ?></span><?php endif; ?></h3>
          <p class="ex"><?= rech_marque(rech_extrait((string) $n['body'], (string) ($n['format'] ?? 'markdown'), $q), $q) ?></p>
          <span class="plus">Lire <span class="chev">›</span></span>
        </div></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($gens): ?>
    <h2 class="sec-t" style="margin-top:1.6rem">Annuaire · <?= count($gens) ?><?= $trop ? ' (premiers)' : '' ?></h2>
    <div class="grid">
      <?php foreach ($gens as $p): ?>
        <div class="person">
          <div class="n"><?= rech_marque($p['aff'], $q) ?></div>
          <div class="r">
            <?php if ($p['srv'] !== ''): ?><span class="badge-cat" style="margin-left:0"><?= rech_marque($p['srv'], $q) ?></span><br><?php endif; ?>
            <?= e_($p['role']) ?> · <span class="muted"><?= e_($p['login']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p style="margin-top:.8rem"><a class="back" href="/portal/intranet/annuaire.php?q=<?= urlencode($q) ?>">Ouvrir dans l'annuaire →</a></p>
  <?php endif; ?>
<?php endif; ?>
<p style="margin-top:1.4rem"><a class="back" href="/portal/intranet.php">← Retour à l'accueil</a></p>
<?php intranet_foot();
