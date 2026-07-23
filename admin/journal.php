<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Journalisation : réunit les quatre outils de traçabilité (recherche
 * d'agent, historique de navigation, journaux légaux, réquisition) en une seule page à
 * onglets. Chaque onglet charge la page correspondante en mode « embarqué » (?embed=1) :
 * elle s'affiche sans barre latérale ni en-tête, juste son contenu.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';

pf_header('Journalisation', 'journal.php');

$jtabs = [
    'recherche'   => ['Recherche agent',  '🔎', 'recherche.php'],
    'weblog'      => ['Navigation',        '🌐', 'weblog.php'],
    'logs'        => ['Journaux légaux',   '📄', 'logs.php'],
    'requisition' => ['Réquisition',       '⚖️', 'requisition.php'],
];
?>
<style>
  .jtabs{display:flex;gap:.3rem;flex-wrap:wrap;margin:0 0 1rem;border-bottom:1px solid var(--line)}
  .jtab{background:transparent;border:1px solid transparent;border-bottom:none;color:var(--muted);cursor:pointer;
        padding:.6rem 1.05rem;font-size:.9rem;border-radius:10px 10px 0 0;font-weight:500;white-space:nowrap}
  .jtab:hover{color:var(--text);background:var(--bg)}
  .jtab.active{color:#fff;background:var(--panel);border-color:var(--line);margin-bottom:-1px}
  .jframe{width:100%;border:1px solid var(--line);border-radius:12px;background:var(--panel);
          height:calc(100vh - 12rem);min-height:520px;display:block}
</style>
<nav class="jtabs" role="tablist" aria-label="Outils de journalisation">
  <?php foreach ($jtabs as $k => [$lbl, $ic, $file]): ?>
    <button type="button" class="jtab" data-tab="<?= e($k) ?>" data-src="<?= e($file) ?>?embed=1"><?= $ic ?> <?= e($lbl) ?></button>
  <?php endforeach; ?>
</nav>
<iframe id="jframe" class="jframe" title="Journalisation" referrerpolicy="same-origin"></iframe>
<script>
(function () {
  var tabs = document.querySelectorAll('.jtab'), fr = document.getElementById('jframe');
  function show(k) {
    tabs.forEach(function (b) {
      var on = b.dataset.tab === k;
      b.classList.toggle('active', on);
      if (on && fr.getAttribute('data-cur') !== b.dataset.src) { fr.src = b.dataset.src; fr.setAttribute('data-cur', b.dataset.src); }
    });
    try { localStorage.setItem('journal_tab', k); } catch (e) {}
  }
  tabs.forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab); }); });
  var init = null; try { init = localStorage.getItem('journal_tab'); } catch (e) {}
  var valid = Array.prototype.some.call(tabs, function (b) { return b.dataset.tab === init; });
  show(valid ? init : 'recherche');
})();
</script>
<?php pf_footer(); ?>
