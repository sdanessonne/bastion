<?php
/**
 * Bastion — une actualité, à son adresse permanente.
 *
 * Sans cette page, une actualité n'existait qu'au sein du fil : elle n'avait pas
 * d'adresse, on ne pouvait donc ni l'envoyer à un collègue, ni la mettre en
 * signet, ni y renvoyer depuis une note de service. Et elle disparaissait de
 * l'accueil dès la sixième publication suivante.
 */
require_once __DIR__ . '/_common.php';

$id = (int) ($_GET['id'] ?? 0);
$n = null;
if ($id > 0 && ($db = intranet_db())) {
    try {
        // « published=1 » ici aussi : un brouillon a une adresse devinable (les
        // identifiants se suivent), et rien n'empêcherait de la saisir à la main.
        $st = $db->prepare('SELECT * FROM pf_cms_news WHERE id=? AND published=1');
        $st->execute([$id]);
        $n = $st->fetch();
    } catch (Throwable $e) {}
}

// Voisinage chronologique : depuis un lien reçu, on arrive au milieu du fil sans
// savoir ce qui précède ni ce qui suit. Ces deux liens rendent la suite lisible.
$prec = $suiv = null;
if ($n && ($db = intranet_db())) {
    try {
        $st = $db->prepare('SELECT id,title FROM pf_cms_news WHERE published=1 AND (created_at<? OR (created_at=? AND id<?)) ORDER BY created_at DESC, id DESC LIMIT 1');
        $st->execute([$n['created_at'], $n['created_at'], $n['id']]);
        $prec = $st->fetch() ?: null;
        $st = $db->prepare('SELECT id,title FROM pf_cms_news WHERE published=1 AND (created_at>? OR (created_at=? AND id>?)) ORDER BY created_at ASC, id ASC LIMIT 1');
        $st->execute([$n['created_at'], $n['created_at'], $n['id']]);
        $suiv = $st->fetch() ?: null;
    } catch (Throwable $e) {}
}

intranet_head($n ? (string) $n['title'] : 'Actualité introuvable', 'actualites');

if (!$n) {
    echo '<div class="card"><h1>Actualité introuvable</h1>'
       . '<p class="muted">Cette actualité n\'existe pas, ou elle n\'est plus publiée.</p>'
       . '<p><a class="back" href="/portal/intranet/actualites.php">← Toutes les actualités</a></p></div>';
    intranet_foot();
    return;
}
?>
<p style="margin:0 0 .8rem"><a class="back" href="/portal/intranet/actualites.php">← Toutes les actualités</a></p>

<article class="card prose">
  <div class="muted" style="font-size:.8rem">
    <?= e_(date('d/m/Y', strtotime((string) $n['created_at']))) ?>
    <?= $n['author'] ? ' · ' . e_($n['author']) : '' ?>
    <?php if (!empty($n['category'])): ?><span class="badge-cat"><?= e_($n['category']) ?></span><?php endif; ?>
  </div>
  <h1 style="margin:.35rem 0 1rem"><?= e_($n['title']) ?></h1>
  <?= cms_render((string) $n['body'], (string) ($n['format'] ?? 'markdown')) ?>
</article>

<?php if ($prec || $suiv): ?>
<nav class="ncards" style="grid-template-columns:1fr 1fr;gap:.8rem" aria-label="Actualités voisines">
  <?php if ($suiv): ?>
    <a class="ncard" href="<?= e_(news_url($suiv['id'])) ?>"><div class="bd">
      <div class="date">Actualité suivante</div><h3><?= e_($suiv['title']) ?></h3></div></a>
  <?php else: ?><span></span><?php endif; ?>
  <?php if ($prec): ?>
    <a class="ncard" href="<?= e_(news_url($prec['id'])) ?>"><div class="bd" style="text-align:right">
      <div class="date">Actualité précédente</div><h3><?= e_($prec['title']) ?></h3></div></a>
  <?php endif; ?>
</nav>
<?php endif; ?>
<?php intranet_foot();
