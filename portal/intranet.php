<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion — Accueil de l'intranet CMS (héro + actualités + services). */
require_once __DIR__ . '/intranet/_common.php';
$me = intranet_user();

$news = [];
$total_news = 0;
$links = [];
if ($db = intranet_db()) {
    // Le total sert à savoir s'il existe des actualités AU-DELÀ des six affichées :
    // un lien « Toutes les actualités » qui mène aux six mêmes ne promet rien.
    try { $total_news = (int) $db->query('SELECT COUNT(*) FROM pf_cms_news WHERE published=1')->fetchColumn(); } catch (Throwable $e) {}
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

// Salutation selon l'heure. Un intranet consulté à 5 h par une équipe de nuit et à
// 14 h par une autre n'a pas à dire la même chose. Détail minuscule, mais c'est ce
// genre de détail qui distingue une page vivante d'une page figée.
$h = (int) date('G');
$salut = $h < 5 ? 'Bonne nuit' : ($h < 12 ? 'Bonjour' : ($h < 18 ? 'Bon après-midi' : 'Bonsoir'));
$jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
$mois  = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
          'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$dateFr = $jours[(int) date('w')] . ' ' . (int) date('j') . ' ' . $mois[(int) date('n')];

intranet_head('Accueil', 'home');
?>
<style>
  /* ── Entrée en cascade ──────────────────────────────────────────────────────
     Les blocs montent en apparaissant, décalés de 60 ms. Le DÉCALAGE est ce qui
     distingue une page qui s'installe d'une page qui clignote : l'œil suit l'ordre
     de lecture au lieu de tout recevoir d'un coup. */
  @keyframes intraUp { from { opacity: 0; transform: translateY(14px) } to { opacity: 1; transform: none } }
  .anim { animation: intraUp .55s cubic-bezier(.16,1,.3,1) both }
  .d1{animation-delay:.06s} .d2{animation-delay:.12s} .d3{animation-delay:.18s}
  .d4{animation-delay:.24s} .d5{animation-delay:.30s} .d6{animation-delay:.36s}

  /* Héro : un halo qui respire, très lentement — 14 s par cycle. Assez lent pour
     ne pas gêner un agent qui lit, assez présent pour que l'écran ne soit pas mort. */
  .hero-x{position:relative;overflow:hidden;border-radius:18px;padding:1.6rem 1.8rem;
    background:linear-gradient(135deg,rgba(56,189,248,.16),rgba(3,105,161,.10) 45%,transparent 75%);
    border:1px solid rgba(56,189,248,.22)}
  .hero-x::after{content:"";position:absolute;inset:-40% -10% auto auto;width:420px;height:420px;
    background:radial-gradient(circle,rgba(56,189,248,.20),transparent 62%);
    animation:halo 14s ease-in-out infinite;pointer-events:none}
  @keyframes halo{0%,100%{transform:translate(0,0) scale(1);opacity:.7}
                  50%{transform:translate(-26px,22px) scale(1.14);opacity:1}}
  .hero-x h1{margin:0 0 .35rem;font-size:1.85rem;line-height:1.15;position:relative}
  /* La main ne salue que trois fois, puis s'arrête. Une animation perpétuelle dans
     le champ de vision devient une nuisance au bout de la deuxième minute. */
  .hero-x .wave{display:inline-block;transform-origin:70% 70%;animation:wave 2.4s ease-in-out .8s 3}
  @keyframes wave{0%,60%,100%{transform:rotate(0)}10%,30%,50%{transform:rotate(14deg)}20%,40%{transform:rotate(-8deg)}}
  .hero-x .meta{display:flex;gap:1.2rem;flex-wrap:wrap;margin-top:1rem;font-size:.83rem;color:var(--muted);position:relative}
  .hero-x .meta b{color:#e6f2ff;font-weight:600}

  /* .sec-t est passe dans _common.php : la recherche s'en sert aussi, et un titre
     de section doit se ressembler d'une page a l'autre. */

  /* Actualités : le style des cartes est dans _common.php — l'accueil, l'archive et
     l'article le partagent, et une carte doit se ressembler d'une page à l'autre.
     Ne reste ici que la disposition propre à la colonne de l'accueil. */
  .home-news{grid-template-columns:repeat(auto-fill,minmax(240px,1fr))}

  /* Services : la tuile glisse, l'icône rebondit, un chevron apparaît. Trois signaux
     pour une seule intention — c'est cliquable, et ça se voit avant le clic. */
  .tile-x{display:flex;align-items:center;gap:.85rem;padding:.9rem 1.05rem;border-radius:13px;
    background:rgba(255,255,255,.045);border:1px solid rgba(255,255,255,.09);
    color:inherit;text-decoration:none;position:relative;overflow:hidden;
    transition:transform .2s ease,border-color .2s ease,background .2s ease}
  .tile-x::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent);
    transform:scaleY(0);transform-origin:bottom;transition:transform .25s ease}
  .tile-x:hover{transform:translateX(5px);border-color:rgba(56,189,248,.45);background:rgba(56,189,248,.09)}
  .tile-x:hover::before{transform:scaleY(1)}
  .tile-x .emo{font-size:1.35rem;flex:none;transition:transform .3s cubic-bezier(.34,1.56,.64,1)}
  .tile-x:hover .emo{transform:scale(1.18) rotate(-6deg)}
  .tile-x .lbl{flex:1;font-weight:500}
  .tile-x .chev{opacity:0;transform:translateX(-6px);transition:opacity .25s ease,transform .25s ease;color:var(--accent);font-size:1.2rem}
  .tile-x:hover .chev{opacity:1;transform:none}
  /* Le clavier doit voir la même chose que la souris : sans cela, la tuile survolée
     se distingue et la tuile sélectionnée au clavier reste muette. */
  .tile-x:focus-visible{outline:none;border-color:var(--accent);background:rgba(56,189,248,.09)}
  .tile-x:focus-visible .chev{opacity:1;transform:none}

  /* ── Téléphone ────────────────────────────────────────────────────────────
     La barre de navigation du bas est fixe : le socle commun réserve déjà la place
     sous le contenu. Ici on s'occupe de ce que cette page ajoute — le héros trop
     large, les métadonnées qui s'entassent, les bandeaux d'actualité démesurés. */
  @media(max-width:760px){
    .home-cols{grid-template-columns:1fr!important;gap:1.2rem}
    .hero-x{padding:1.2rem 1.15rem;border-radius:15px}
    .hero-x h1{font-size:1.4rem}
    .hero-x::after{width:260px;height:260px}          /* halo proportionné à l'écran */
    .hero-x .meta{gap:.5rem .9rem;font-size:.78rem;margin-top:.8rem}
    /* Les services d'abord : sur un téléphone on vient chercher un accès rapide,
       pas lire les actualités. L'ordre du HTML garde le sens pour un lecteur
       d'écran ; seul l'affichage change. */
    .home-cols > aside{order:-1}
    .tile-x{padding:1rem 1.05rem}                     /* cible tactile confortable */
    /* Une seule colonne : deux cartes côte à côte sur 360 px ne laissent de place
       ni au titre ni à l'extrait. */
    .home-news{grid-template-columns:1fr}
  }
  @media(max-width:430px){
    .hero-x h1{font-size:1.25rem}
    .hero-x .meta span:nth-child(n+3){display:none}   /* on garde l'essentiel */
    .sec-t{font-size:.7rem;letter-spacing:1.1px}
  }
  /* Sur écran tactile, « :hover » reste collé après le doigt : les effets de survol
     y sont donc inutiles, et trompeurs — une tuile paraît sélectionnée alors qu'on
     l'a simplement effleurée. On les réserve aux dispositifs de pointage. */
  @media(hover:none){
    .tile-x:hover{transform:none;background:rgba(255,255,255,.045);border-color:rgba(255,255,255,.09)}
    .tile-x:hover .emo{transform:none}
    .tile-x:hover::before{transform:scaleY(0)}
    .tile-x:active{transform:scale(.98);background:rgba(56,189,248,.12)}
  }

  /* Réglage système « animations réduites » : on coupe TOUT mouvement, halo compris.
     Ce n'est pas une préférence esthétique — certains troubles vestibulaires rendent
     ces mouvements réellement pénibles, et l'agent n'a pas à les subir. */
  @media(prefers-reduced-motion:reduce){
    .anim,.hero-x::after,.hero-x .wave{animation:none!important}
    .tile-x,.tile-x .emo,.tile-x .chev{transition:none!important}
  }
</style>

<div class="hero-x anim">
  <?php
  // Le prénom et le matricule sont remplis par le navigateur, pas écrits ici :
  // cette page est conservée pour la lecture hors ligne, et l'identité y serait
  // partie avec elle. Sur un téléphone de service partagé, l'agent suivant aurait
  // été accueilli par le prénom du précédent.
  // Le salut, lui, reste côté serveur : il dépend de l'heure, pas de la personne.
  ?>
  <h1><?= $salut ?><span id="heroNom"></span> <span class="wave">👋</span></h1>
  <p class="muted" style="margin:0;max-width:64ch;position:relative"><?= e_(intranet_setting('intranet_welcome', 'Bienvenue sur l’espace interne. Retrouvez ici l’actualité et vos services.')) ?></p>
  <div class="meta">
    <span>📅 <b><?= e_($dateFr) ?></b></span>
    <span id="heroMat" hidden>🆔 Matricule <b></b></span>
    <?php if (!empty($news)): $nb = $total_news ?: count($news); ?><span>📰 <b><?= $nb ?></b> actualité<?= $nb > 1 ? 's' : '' ?></span><?php endif; ?>
  </div>
</div>

<?php
// Le bandeau n'avait pas de fin. « Maintenance prevue vendredi » serait encore
// affiche en mars, et un bandeau qu'on a appris a ignorer ne sert plus a rien --
// y compris le jour ou l'annonce compte vraiment.
$notice = trim(intranet_setting('intranet_notice'));
$jusqu  = trim(intranet_setting('intranet_notice_until'));
if ($notice !== '' && $jusqu !== '') {
    $t = strtotime($jusqu);
    // Fin de la journee indiquee : une annonce « jusqu'au 5 » vaut tout le 5.
    if ($t !== false && time() > $t + 86399) { $notice = ''; }
}
?>
<?php if ($notice !== ''): ?>
  <div class="ok anim d1" style="background:rgba(56,189,248,.1);border-color:rgba(56,189,248,.3);color:#bae6fd;margin-top:1.1rem">📢 <?= e_($notice) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:1.6rem;margin-top:1.5rem" class="home-cols">
  <section class="anim d2">
    <h2 class="sec-t">Actualités</h2>
    <?php if (!$news): ?>
      <p class="muted">Aucune actualité pour le moment.</p>
    <?php else: $i = 0; ?>
      <div class="ncards home-news">
      <?php foreach ($news as $n): $i++;
          $vis = cms_image_une((string) $n['body'], (string) ($n['format'] ?? 'markdown')); ?>
        <a class="ncard anim d<?= min(6, $i + 1) ?>" href="<?= e_(news_url($n['id'])) ?>">
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
      <?php if ($total_news > count($news)): ?>
        <p style="margin-top:1rem"><a class="back" href="/portal/intranet/actualites.php">Toutes les actualités (<?= (int) $total_news ?>) →</a></p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <aside class="anim d3">
    <h2 class="sec-t">Services</h2>
    <div style="display:grid;gap:.7rem">
      <?php $j = 0; foreach ($links as $l): $j++; ?>
        <a class="tile-x anim d<?= min(6, $j + 2) ?>" href="<?= e_($l['url']) ?>"<?= strpos($l['url'], 'http') === 0 ? ' target="_blank" rel="noopener"' : '' ?>>
          <span class="emo"><?= e_($l['icon']) ?></span>
          <span class="lbl"><?= e_($l['label']) ?></span>
          <span class="chev" aria-hidden="true">›</span>
        </a>
      <?php endforeach; ?>
    </div>
  </aside>
</div>
<script>
// L'en-tête a déjà demandé « /portal/moi.php » ; il republie le résultat plutôt
// que d'imposer un second appel — la réponse traverse ndsctl, qui prend ~1,7 s.
function bastionMoi(d) {
  d = d || {};
  if (!d.auth) { return; }
  if (d.prenom) { document.getElementById('heroNom').textContent = ' ' + d.prenom; }
  var m = document.getElementById('heroMat');
  if (m && d.user) { m.querySelector('b').textContent = d.user; m.hidden = false; }
}
document.addEventListener('bastion:moi', function (e) { bastionMoi(e.detail); });
// Si la reponse est deja arrivee, l'evenement est passe : on la reprend.
if (window.__moi) { bastionMoi(window.__moi); }
</script>
<?php intranet_foot();
