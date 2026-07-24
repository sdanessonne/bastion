<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion — Accueil de l'intranet CMS (héro + actualités + services). */
require_once __DIR__ . '/intranet/_common.php';
$me = intranet_user();

$news = [];
$links = [];
if ($db = intranet_db()) {
    try { $news = $db->query('SELECT * FROM pf_cms_news WHERE published=1 ORDER BY created_at DESC, id DESC LIMIT 6')->fetchAll(); }
    catch (Throwable $e) {}
}
// Services (liens rapides) : réglage intranet_links « Libellé|url|emoji » par ligne.
foreach (preg_split('/\r?\n/', intranet_setting('intranet_links',
    "Mon compte & consommation|/portal/account.php|📊\nAnnuaire du personnel|/portal/intranet/annuaire.php|📇\nAssistance informatique|/portal/intranet/assistance.php|🛠️")) as $line) {
    $line = trim($line);
    if ($line === '') { continue; }
    $p = array_map('trim', explode('|', $line));
    if ($p[0] !== '') { $links[] = ['label' => $p[0], 'url' => $p[1] ?? '#', 'icon' => $p[2] ?? '🔗']; }
}

intranet_head('Accueil', 'home');
?>
<div class="card hero">
  <h1 style="margin:0 0 .3rem">Bonjour<?= $me['user'] !== '' ? ' ' . e_($me['user']) : '' ?> 👋</h1>
  <p class="muted" style="margin:0"><?= e_(intranet_setting('intranet_welcome', 'Bienvenue sur l’espace interne. Retrouvez ici l’actualité et vos services.')) ?></p>
</div>

<?php if (trim(intranet_setting('intranet_notice')) !== ''): ?>
  <div class="ok" style="background:rgba(56,189,248,.1);border-color:rgba(56,189,248,.3);color:#bae6fd">📢 <?= e_(intranet_setting('intranet_notice')) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:1.4rem" class="home-cols">
  <section>
    <h2 style="font-size:1.05rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin:.2rem 0 1rem">Actualités</h2>
    <?php if (!$news): ?>
      <p class="muted">Aucune actualité pour le moment.</p>
    <?php else: foreach ($news as $n): ?>
      <article class="news">
        <div class="date"><?= e_(date('d/m/Y', strtotime((string) $n['created_at']))) ?><?= $n['author'] ? ' · ' . e_($n['author']) : '' ?></div>
        <h3><?= e_($n['title']) ?><?php if (!empty($n['category'])): ?><span class="badge-cat"><?= e_($n['category']) ?></span><?php endif; ?></h3>
        <div class="prose"><?= cms_render((string) $n['body'], (string) ($n['format'] ?? 'markdown')) ?></div>
      </article>
    <?php endforeach; endif; ?>
  </section>
  <aside>
    <h2 style="font-size:1.05rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin:.2rem 0 1rem">Services</h2>
    <div style="display:grid;gap:.7rem">
      <?php foreach ($links as $l): ?>
        <a class="tile" href="<?= e_($l['url']) ?>"<?= strpos($l['url'], 'http') === 0 ? ' target="_blank" rel="noopener"' : '' ?>>
          <span class="emo"><?= e_($l['icon']) ?></span><span><?= e_($l['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </aside>
</div>
<style>@media(max-width:760px){.home-cols{grid-template-columns:1fr!important}}</style>
<?php intranet_foot();
