<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — recherche globale.
 *
 * Un champ, toutes les sources. L'administrateur qui a « 192.168.182.47 », « DUPONT »
 * ou « bitlocker » en tête n'a plus à deviner dans laquelle des vingt-huit pages
 * chercher : il tape, et le résultat le mène directement au bon endroit.
 *
 * Le moteur et la liste des sources vivent dans inc/recherche-globale.php ; cette page
 * ne fait que présenter. Voir l'en-tête de ce fichier pour ce qui est délibérément
 * exclu de la recherche (secrets) et pour le respect des rôles d'administration.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/recherche-globale.php';

$db = pf_db();
$q  = trim((string) ($_GET['q'] ?? ''));
// Filtre facultatif sur une seule source (lien « tout afficher » d'un groupe).
$src = preg_replace('/[^a-z_]/', '', (string) ($_GET['src'] ?? ''));

$res = ['groupes' => [], 'total' => 0, 'ecartees' => []];
$t0  = microtime(true);
if ($q !== '') {
    $res = rg_chercher($db, $q, $src !== '' ? 40 : 6);
    if ($src !== '') {
        $res['groupes'] = array_values(array_filter($res['groupes'], fn($g) => $g['cle'] === $src));
        $res['total']   = array_sum(array_map(fn($g) => $g['total'], $res['groupes']));
    }
}
$ms = (int) round((microtime(true) - $t0) * 1000);

pf_header('Recherche', 'chercher.php');
?>
<style>
  .rg-form{display:flex;gap:.6rem;flex-wrap:wrap;margin:0 0 1.2rem}
  .rg-form input[type=search]{flex:1;min-width:240px;padding:.75rem .9rem;font-size:1rem;
    background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:11px}
  .rg-form input[type=search]:focus{outline:2px solid var(--acc,#38bdf8);outline-offset:1px}
  .rg-meta{color:var(--muted);font-size:.83rem;margin:0 0 1.1rem}
  .rg-grp{background:var(--card);border:1px solid var(--line);border-radius:13px;margin:0 0 1rem;overflow:hidden}
  .rg-grp > h3{margin:0;padding:.7rem 1rem;font-size:.9rem;border-bottom:1px solid var(--line);
    display:flex;align-items:center;gap:.5rem;background:rgba(56,189,248,.05)}
  .rg-grp > h3 .n{margin-left:auto;font-weight:400;color:var(--muted);font-size:.8rem}
  .rg-item{display:block;padding:.6rem 1rem;border-bottom:1px solid var(--line);text-decoration:none;color:inherit}
  .rg-item:last-child{border-bottom:0}
  .rg-item:hover,.rg-item:focus{background:rgba(56,189,248,.09);outline:none}
  .rg-t{font-size:.92rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
  .rg-s{font-size:.79rem;color:var(--muted);margin-top:.12rem;word-break:break-word}
  .rg-tag{font-size:.68rem;padding:.08rem .42rem;border:1px solid var(--line);border-radius:999px;color:var(--muted)}
  mark{background:rgba(250,204,21,.28);color:inherit;border-radius:3px;padding:0 .1em}
  .rg-vide{background:var(--card);border:1px solid var(--line);border-radius:13px;padding:1.6rem;text-align:center;color:var(--muted)}
  .rg-ex{display:flex;gap:.4rem;flex-wrap:wrap;margin:.8rem 0 0;justify-content:center}
  .rg-ex a{font-size:.8rem;padding:.25rem .6rem;border:1px solid var(--line);border-radius:999px;
    color:var(--muted);text-decoration:none}
  .rg-ex a:hover{color:var(--text);border-color:var(--acc,#38bdf8)}
</style>

<form class="rg-form" method="get" action="/chercher.php" role="search">
  <input type="search" name="q" value="<?= e($q) ?>" autofocus
         placeholder="Un nom, un matricule, une adresse IP, une adresse MAC, un domaine, une stratégie…"
         aria-label="Rechercher dans toute la console">
  <button class="btn" type="submit">🔎 Rechercher</button>
</form>

<?php if ($q === ''): ?>
  <div class="rg-vide">
    <p style="margin:0 0 .2rem;font-size:1rem;color:var(--text)">Cherchez dans toute la console</p>
    <p style="margin:0;font-size:.85rem">Fonctionnaires, postes, adresses, groupes, applications, pages de
       l’intranet, domaines bloqués, historique de navigation, journal d’audit, stratégies de groupe,
       et les pages de la console elles-mêmes.</p>
    <div class="rg-ex">
      <a href="?q=bitlocker">bitlocker</a><a href="?q=quota">quota</a><a href="?q=192.168.182">192.168.182</a>
      <a href="?q=firefox">firefox</a><a href="?q=usb">usb</a>
    </div>
  </div>

<?php elseif (mb_strlen($q) < 2): ?>
  <div class="rg-vide">Tapez au moins deux caractères.</div>

<?php elseif ($res['total'] === 0): ?>
  <div class="rg-vide">
    <p style="margin:0 0 .3rem;color:var(--text)">Aucun résultat pour « <?= e($q) ?> ».</p>
    <p style="margin:0;font-size:.85rem">Vérifiez l’orthographe, ou essayez un fragment plus court —
       la recherche accepte les morceaux de mot.</p>
  </div>

<?php else: ?>
  <p class="rg-meta">
    <strong><?= $res['total'] ?></strong> résultat<?= $res['total'] > 1 ? 's' : '' ?>
    dans <?= count($res['groupes']) ?> catégorie<?= count($res['groupes']) > 1 ? 's' : '' ?>
    · <?= $ms ?> ms
    <?php if ($src !== ''): ?> · <a href="?q=<?= rawurlencode($q) ?>">◀ toutes les catégories</a><?php endif; ?>
    <?php if ($res['ecartees']): ?>
      <br><span title="Votre rôle d'administration ne donne pas accès à ces pages">
        Non consultées, hors de votre périmètre : <?= e(implode(', ', $res['ecartees'])) ?>.</span>
    <?php endif; ?>
  </p>

  <?php foreach ($res['groupes'] as $g): ?>
    <section class="rg-grp">
      <h3><span><?= $g['icone'] ?></span><?= e($g['titre']) ?>
        <span class="n"><?= $g['total'] ?>
          <?php if ($src === '' && $g['total'] >= 6): ?>
            · <a href="?q=<?= rawurlencode($q) ?>&amp;src=<?= e($g['cle']) ?>">tout afficher</a>
          <?php endif; ?>
        </span></h3>
      <?php foreach ($g['resultats'] as $it): ?>
        <a class="rg-item" href="<?= e($it['url']) ?>">
          <span class="rg-t"><?= rg_marquer($it['titre'], $q) ?>
            <?php if ($it['tag'] !== ''): ?><span class="rg-tag"><?= e($it['tag']) ?></span><?php endif; ?></span>
          <?php if ($it['sous'] !== ''): ?><span class="rg-s"><?= rg_marquer($it['sous'], $q) ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

<?php pf_footer(); ?>
