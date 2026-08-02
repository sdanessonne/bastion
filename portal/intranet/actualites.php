<?php
/**
 * Bastion — archive des actualités, paginée et filtrable par catégorie.
 *
 * L'accueil n'en montre que six. Au-delà, une publication devenait
 * définitivement inatteignable : elle existait en base, mais plus aucune page de
 * l'intranet n'y menait. Une note de service de la semaine passée était donc
 * perdue pour qui ne l'avait pas lue le jour même.
 */
require_once __DIR__ . '/_common.php';

const PAR_PAGE = 9;

$page = max(1, (int) ($_GET['page'] ?? 1));
$cat  = trim((string) ($_GET['cat'] ?? ''));

$news = [];
$total = 0;
$cats = [];
if ($db = intranet_db()) {
    try {
        // Les catégories réellement utilisées, pas une liste figée : une catégorie
        // proposée mais vide mène à une page « aucun résultat », ce qui ressemble à
        // une panne.
        foreach ($db->query("SELECT category, COUNT(*) n FROM pf_cms_news WHERE published=1 AND category IS NOT NULL AND category<>'' GROUP BY category ORDER BY category") as $r) {
            $cats[(string) $r['category']] = (int) $r['n'];
        }
        // La catégorie demandée est confrontée aux catégories existantes plutôt
        // qu'insérée telle quelle : elle arrive de l'adresse, donc de l'extérieur.
        if ($cat !== '' && !isset($cats[$cat])) { $cat = ''; }

        $ou = 'published=1' . ($cat !== '' ? ' AND category=?' : '');
        $arg = $cat !== '' ? [$cat] : [];

        $st = $db->prepare("SELECT COUNT(*) FROM pf_cms_news WHERE $ou");
        $st->execute($arg);
        $total = (int) $st->fetchColumn();

        // La page est bornée APRÈS avoir compté : « ?page=9999 » afficherait sinon
        // une page vide indistinguable d'une archive vide.
        $pages = max(1, (int) ceil($total / PAR_PAGE));
        if ($page > $pages) { $page = $pages; }

        // LIMIT/OFFSET sont injectés en tant qu'ENTIERS déjà bornés, et non liés :
        // MariaDB refuse un paramètre lié à cet endroit en mode émulation désactivée.
        $off = ($page - 1) * PAR_PAGE;
        $st = $db->prepare("SELECT * FROM pf_cms_news WHERE $ou ORDER BY created_at DESC, id DESC LIMIT " . PAR_PAGE . " OFFSET " . (int) $off);
        $st->execute($arg);
        $news = $st->fetchAll();
    } catch (Throwable $e) {}
}
$pages = max(1, (int) ceil($total / PAR_PAGE));

/** Adresse d'une page de l'archive, catégorie conservée. */
function arch_url(int $p, string $cat): string {
    $q = ['page' => $p] + ($cat !== '' ? ['cat' => $cat] : []);
    return '/portal/intranet/actualites.php?' . http_build_query($q);
}

intranet_head('Actualités', 'actualites');
?>
<h1 style="margin-bottom:.3rem">Actualités</h1>
<p class="muted" style="margin-top:0">
  <?= $total ?> publication<?= $total > 1 ? 's' : '' ?><?= $cat !== '' ? ' dans « ' . e_($cat) . ' »' : '' ?>.
</p>

<?php if ($cats): ?>
<div class="card" style="padding:.85rem 1rem">
  <div style="display:flex;gap:.45rem;flex-wrap:wrap;align-items:center">
    <a class="badge-cat" style="margin:0;<?= $cat === '' ? 'background:var(--accent);color:#04121f' : '' ?>"
       href="/portal/intranet/actualites.php">Toutes</a>
    <?php foreach ($cats as $c => $n): ?>
      <a class="badge-cat" style="margin:0;<?= $cat === $c ? 'background:var(--accent);color:#04121f' : '' ?>"
         href="<?= e_(arch_url(1, $c)) ?>"><?= e_($c) ?> · <?= $n ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!$news): ?>
  <p class="muted">Aucune actualité publiée pour le moment.</p>
<?php else: ?>
<div class="ncards" style="grid-template-columns:repeat(auto-fill,minmax(270px,1fr))">
  <?php foreach ($news as $n):
      $vis = cms_image_une((string) $n['body'], (string) ($n['format'] ?? 'markdown')); ?>
    <a class="ncard" href="<?= e_(news_url($n['id'])) ?>">
      <?php if ($vis !== ''): ?><div class="vis" style="background-image:url('<?= e_($vis) ?>')"></div><?php endif; ?>
      <div class="bd">
        <div class="date"><?= e_(date('d/m/Y', strtotime((string) $n['created_at']))) ?><?= $n['author'] ? ' · ' . e_($n['author']) : '' ?></div>
        <h3><?= e_($n['title']) ?><?php if (!empty($n['category'])): ?><span class="badge-cat"><?= e_($n['category']) ?></span><?php endif; ?></h3>
        <p class="ex"><?= e_(cms_extrait((string) $n['body'], (string) ($n['format'] ?? 'markdown'))) ?></p>
        <span class="plus">Lire <span class="chev">›</span></span>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
  <?php if ($page > 1): ?><a href="<?= e_(arch_url($page - 1, $cat)) ?>" rel="prev">‹ Précédent</a>
  <?php else: ?><span class="off">‹ Précédent</span><?php endif; ?>
  <?php for ($p = 1; $p <= $pages; $p++): ?>
    <?php if ($p === $page): ?><span class="cur" aria-current="page"><?= $p ?></span>
    <?php else: ?><a href="<?= e_(arch_url($p, $cat)) ?>"><?= $p ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($page < $pages): ?><a href="<?= e_(arch_url($page + 1, $cat)) ?>" rel="next">Suivant ›</a>
  <?php else: ?><span class="off">Suivant ›</span><?php endif; ?>
</nav>
<?php endif; ?>
<p><a class="back" href="/portal/intranet.php">← Retour à l'accueil</a></p>
<?php intranet_foot();
