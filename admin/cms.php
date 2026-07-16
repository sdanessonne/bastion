<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — CMS intranet : pages, actualités, médias (images), groupes, éditeur. */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();
$MEDIA = '/var/www/html/portal/intranet/uploads';
try {
    $db->exec('CREATE TABLE IF NOT EXISTS pf_cms_pages (
        id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(80) UNIQUE, title VARCHAR(160), body MEDIUMTEXT,
        menu_order INT DEFAULT 0, in_menu TINYINT(1) DEFAULT 1, published TINYINT(1) DEFAULT 1,
        group_required VARCHAR(64) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by VARCHAR(64))');
    $db->exec('CREATE TABLE IF NOT EXISTS pf_cms_news (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200), body MEDIUMTEXT, author VARCHAR(64),
        category VARCHAR(60) DEFAULT NULL, published TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('ALTER TABLE pf_cms_pages ADD COLUMN IF NOT EXISTS group_required VARCHAR(64) DEFAULT NULL');
    $db->exec('ALTER TABLE pf_cms_news ADD COLUMN IF NOT EXISTS category VARCHAR(60) DEFAULT NULL');
} catch (Throwable $e) {}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'page';
}

// Groupes disponibles (pour restreindre une page)
$groups = [];
try { foreach ($db->query('SELECT groupname FROM pf_groups') as $r) { $groups[$r['groupname']] = 1; } } catch (Throwable $e) {}
try { foreach ($db->query('SELECT DISTINCT groupname FROM radusergroup') as $r) { $groups[$r['groupname']] = 1; } } catch (Throwable $e) {}
$groups = array_keys($groups);

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    try {
        if ($do === 'page_save') {
            $id = (int) ($_POST['id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $slug  = slugify(((string) ($_POST['slug'] ?? '')) !== '' ? (string) $_POST['slug'] : $title);
            $body  = (string) ($_POST['body'] ?? '');
            $order = (int) ($_POST['menu_order'] ?? 0);
            $inm   = isset($_POST['in_menu']) ? 1 : 0;
            $pub   = isset($_POST['published']) ? 1 : 0;
            $grp   = trim((string) ($_POST['group_required'] ?? '')) ?: null;
            if ($title === '') { $flash = ['Titre requis.', 'err']; }
            elseif ($id > 0) {
                $db->prepare('UPDATE pf_cms_pages SET slug=?,title=?,body=?,menu_order=?,in_menu=?,published=?,group_required=?,updated_by=? WHERE id=?')
                   ->execute([$slug, $title, $body, $order, $inm, $pub, $grp, $_SESSION['admin'], $id]);
                $flash = ['Page mise à jour.', 'ok'];
            } else {
                $db->prepare('INSERT INTO pf_cms_pages (slug,title,body,menu_order,in_menu,published,group_required,updated_by) VALUES (?,?,?,?,?,?,?,?)')
                   ->execute([$slug, $title, $body, $order, $inm, $pub, $grp, $_SESSION['admin']]);
                $flash = ['Page créée.', 'ok'];
            }
        }
        elseif ($do === 'page_delete') { $db->prepare('DELETE FROM pf_cms_pages WHERE id=?')->execute([(int) $_POST['id']]); $flash = ['Page supprimée.', 'ok']; }
        elseif ($do === 'news_save') {
            $id = (int) ($_POST['id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $body  = (string) ($_POST['body'] ?? '');
            $cat   = trim((string) ($_POST['category'] ?? '')) ?: null;
            $pub   = isset($_POST['published']) ? 1 : 0;
            if ($title === '') { $flash = ['Titre requis.', 'err']; }
            elseif ($id > 0) {
                $db->prepare('UPDATE pf_cms_news SET title=?,body=?,category=?,published=? WHERE id=?')->execute([$title, $body, $cat, $pub, $id]);
                $flash = ['Actualité mise à jour.', 'ok'];
            } else {
                $db->prepare('INSERT INTO pf_cms_news (title,body,author,category,published) VALUES (?,?,?,?,?)')->execute([$title, $body, $_SESSION['admin'], $cat, $pub]);
                $flash = ['Actualité publiée.', 'ok'];
            }
        }
        elseif ($do === 'news_delete') { $db->prepare('DELETE FROM pf_cms_news WHERE id=?')->execute([(int) $_POST['id']]); $flash = ['Actualité supprimée.', 'ok']; }
        elseif ($do === 'upload_media') {
            if (!is_dir($MEDIA)) { @mkdir($MEDIA, 0775, true); }
            $f = $_FILES['media'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) { $flash = ['Aucun fichier reçu.', 'err']; }
            elseif ($f['size'] > 4 * 1024 * 1024) { $flash = ['Image trop lourde (max 4 Mo).', 'err']; }
            else {
                $info = @getimagesize($f['tmp_name']);
                $ext = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'][$info['mime'] ?? ''] ?? '';
                if (!$ext) { $flash = ['Format non supporté (PNG, JPEG, GIF, WEBP).', 'err']; }
                else {
                    $base = trim(preg_replace('/[^a-z0-9_-]+/', '-', strtolower(pathinfo((string) $f['name'], PATHINFO_FILENAME))), '-') ?: 'image';
                    $name = "$base.$ext"; $i = 1;
                    while (file_exists("$MEDIA/$name")) { $name = "$base-$i.$ext"; $i++; }
                    if (@move_uploaded_file($f['tmp_name'], "$MEDIA/$name")) { @chmod("$MEDIA/$name", 0644); $flash = ["Image téléversée : $name", 'ok']; }
                    else { $flash = ["Écriture impossible dans $MEDIA.", 'err']; }
                }
            }
        }
        elseif ($do === 'media_delete') {
            $name = basename((string) ($_POST['name'] ?? ''));
            if ($name !== '' && is_file("$MEDIA/$name")) { @unlink("$MEDIA/$name"); $flash = ['Image supprimée.', 'ok']; }
        }
    } catch (Throwable $e) { $flash = ['Erreur : ' . $e->getMessage(), 'err']; }
}

$editP = ['id'=>0,'slug'=>'','title'=>'','body'=>'','menu_order'=>0,'in_menu'=>1,'published'=>1,'group_required'=>''];
if (isset($_GET['p'])) { $r = $db->query('SELECT * FROM pf_cms_pages WHERE id='.(int)$_GET['p'])->fetch(); if ($r) { $editP = $r; } }
$editN = ['id'=>0,'title'=>'','body'=>'','category'=>'','published'=>1];
if (isset($_GET['n'])) { $r = $db->query('SELECT * FROM pf_cms_news WHERE id='.(int)$_GET['n'])->fetch(); if ($r) { $editN = $r; } }

$pages = $db->query('SELECT * FROM pf_cms_pages ORDER BY menu_order,title')->fetchAll();
$newsL = $db->query('SELECT * FROM pf_cms_news ORDER BY created_at DESC, id DESC LIMIT 50')->fetchAll();
$media = is_dir($MEDIA) ? array_values(array_filter(scandir($MEDIA), fn($f) => preg_match('/\.(png|jpe?g|gif|webp)$/i', $f))) : [];

pf_header('Intranet — Contenu', 'cms.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<style>
  .cms textarea{width:100%;min-height:200px;padding:.7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:0 0 8px 8px;font-family:ui-monospace,monospace;font-size:.85rem;line-height:1.6}
  .cms .field{display:grid;gap:.35rem;margin-bottom:.9rem;font-size:.82rem;color:var(--muted)}
  .cms .field input,.cms .field select{padding:.55rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px}
  .cms .row{display:flex;gap:1.2rem;flex-wrap:wrap;align-items:center}
  .cms .chk{display:inline-flex;align-items:center;gap:.4rem;color:var(--muted);font-size:.85rem}
  .mdhelp{font-size:.75rem;color:var(--muted)}
  .mdbar{display:flex;gap:.25rem;flex-wrap:wrap;background:#0d1728;border:1px solid var(--line);border-bottom:none;border-radius:8px 8px 0 0;padding:.35rem}
  .mdbar button{background:#1c2b45;color:var(--text);border:none;border-radius:6px;padding:.3rem .55rem;font-size:.8rem;cursor:pointer}
  .mdbar button:hover{background:#294066}
  .mdprev{margin-top:.6rem;padding:.9rem 1rem;background:var(--bg);border:1px dashed var(--line);border-radius:10px;font-size:.9rem;line-height:1.6}
  .mdprev:empty::before{content:"Aperçu…";color:var(--muted)}
  .mdprev img{max-width:100%;border-radius:8px} .mdprev h1{font-size:1.3rem}.mdprev h2{font-size:1.1rem;color:var(--accent)}
  .media{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.7rem}
  .media .m{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:.4rem;text-align:center}
  .media .m img{width:100%;height:70px;object-fit:cover;border-radius:6px}
  .media .m input{width:100%;font-size:.62rem;margin-top:.3rem;background:#0d1728;color:var(--muted);border:1px solid var(--line);border-radius:5px;padding:.2rem}
</style>
<div class="cms">
<div class="split">
  <section class="panel form-panel">
    <div class="panel-head"><h2><?= $editP['id'] ? 'Modifier la page' : 'Nouvelle page' ?></h2>
      <?php if ($editP['id']): ?><a class="btn-sm" href="/cms.php">+ Nouvelle</a><?php endif; ?></div>
    <form method="post" style="padding:1.2rem">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="page_save">
      <input type="hidden" name="id" value="<?= (int) $editP['id'] ?>">
      <label class="field">Titre<input type="text" name="title" value="<?= e($editP['title']) ?>" required></label>
      <label class="field">Adresse (slug) <span class="muted">— vide = auto</span>
        <input type="text" name="slug" value="<?= e($editP['slug']) ?>" placeholder="ex. reglement-interieur"></label>
      <label class="field">Contenu
        <div class="mdbar" data-t="pbody"></div>
        <textarea class="md" id="pbody" name="body"><?= e($editP['body']) ?></textarea>
        <div class="mdprev prose" data-src="pbody"></div>
      </label>
      <p class="mdhelp"><code># Titre</code> · <code>## Sous-titre</code> · <code>**gras**</code> · <code>*italique*</code> · <code>- liste</code> · <code>[lien](url)</code> · <code>![img](url)</code></p>
      <div class="row">
        <label class="field" style="margin:0">Ordre<input type="number" name="menu_order" value="<?= (int) $editP['menu_order'] ?>" style="width:80px"></label>
        <label class="field" style="margin:0">Réservée au groupe
          <select name="group_required"><option value="">— Tous —</option>
            <?php foreach ($groups as $g): ?><option value="<?= e($g) ?>" <?= ($editP['group_required'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
          </select></label>
        <label class="chk"><input type="checkbox" name="in_menu" <?= $editP['in_menu'] ? 'checked' : '' ?>> Menu</label>
        <label class="chk"><input type="checkbox" name="published" <?= $editP['published'] ? 'checked' : '' ?>> Publiée</label>
      </div>
      <div class="form-actions" style="margin-top:1rem"><button class="btn">Enregistrer</button></div>
    </form>
  </section>
  <section class="panel">
    <div class="panel-head"><h2>Pages (<?= count($pages) ?>)</h2></div>
    <div class="table-wrap"><table class="grid-table">
      <thead><tr><th>Titre</th><th>Accès</th><th>État</th><th></th></tr></thead><tbody>
      <?php if (!$pages): ?><tr><td colspan="4" class="muted center">Aucune page.</td></tr>
      <?php else: foreach ($pages as $p): ?>
        <tr>
          <td><strong><?= e($p['title']) ?></strong><br><span class="muted svc-meta"><?= e($p['slug']) ?></span></td>
          <td><?= $p['group_required'] ? '<span class="badge off">'.e($p['group_required']).'</span>' : '<span class="muted">Tous</span>' ?></td>
          <td><span class="badge <?= $p['published'] ? 'on' : 'off' ?>"><?= $p['published'] ? 'Publiée' : 'Brouillon' ?></span></td>
          <td class="row-actions">
            <a class="btn-sm" href="/cms.php?p=<?= (int) $p['id'] ?>">Modifier</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Supprimer ?')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="page_delete">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn-sm btn-danger">Suppr.</button></form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody></table></div>
  </section>
</div>

<div class="split">
  <section class="panel form-panel">
    <div class="panel-head"><h2><?= $editN['id'] ? 'Modifier l\'actualité' : 'Nouvelle actualité' ?></h2>
      <?php if ($editN['id']): ?><a class="btn-sm" href="/cms.php">+ Nouvelle</a><?php endif; ?></div>
    <form method="post" style="padding:1.2rem">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="news_save">
      <input type="hidden" name="id" value="<?= (int) $editN['id'] ?>">
      <label class="field">Titre<input type="text" name="title" value="<?= e($editN['title']) ?>" required></label>
      <label class="field">Catégorie <span class="muted">(optionnel)</span>
        <input type="text" name="category" value="<?= e($editN['category'] ?? '') ?>" placeholder="ex. Service, RH, Sécurité"></label>
      <label class="field">Contenu
        <div class="mdbar" data-t="nbody"></div>
        <textarea class="md" id="nbody" name="body"><?= e($editN['body']) ?></textarea>
        <div class="mdprev prose" data-src="nbody"></div>
      </label>
      <label class="chk"><input type="checkbox" name="published" <?= $editN['published'] ? 'checked' : '' ?>> Publiée</label>
      <div class="form-actions" style="margin-top:1rem"><button class="btn">Publier</button></div>
    </form>
  </section>
  <section class="panel">
    <div class="panel-head"><h2>Actualités (<?= count($newsL) ?>)</h2></div>
    <div class="table-wrap"><table class="grid-table">
      <thead><tr><th>Date</th><th>Titre</th><th>Catégorie</th><th>État</th><th></th></tr></thead><tbody>
      <?php if (!$newsL): ?><tr><td colspan="5" class="muted center">Aucune actualité.</td></tr>
      <?php else: foreach ($newsL as $n): ?>
        <tr>
          <td class="muted svc-meta"><?= e(date('d/m/Y', strtotime((string) $n['created_at']))) ?></td>
          <td><strong><?= e($n['title']) ?></strong></td>
          <td class="muted"><?= e($n['category'] ?? '—') ?></td>
          <td><span class="badge <?= $n['published'] ? 'on' : 'off' ?>"><?= $n['published'] ? 'Publiée' : 'Brouillon' ?></span></td>
          <td class="row-actions">
            <a class="btn-sm" href="/cms.php?n=<?= (int) $n['id'] ?>">Modifier</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Supprimer ?')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="news_delete">
              <input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="btn-sm btn-danger">Suppr.</button></form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody></table></div>
  </section>
</div>

<section class="panel">
  <div class="panel-head"><h2>🖼️ Médiathèque (<?= count($media) ?>)</h2></div>
  <div style="padding:1.2rem">
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:.6rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="upload_media">
      <input type="file" name="media" accept="image/*" required style="color:var(--muted)">
      <button class="btn-sm">⬆️ Téléverser une image</button>
      <span class="muted small">PNG, JPEG, GIF, WEBP — max 4 Mo. Copiez le code sous l'image pour l'insérer.</span>
    </form>
    <?php if ($media): ?>
    <div class="media">
      <?php foreach ($media as $m): $url = '/portal/intranet/uploads/' . $m; ?>
        <div class="m">
          <img src="/portal/intranet/uploads/<?= e($m) ?>" alt="<?= e($m) ?>" onerror="this.style.opacity=.3">
          <input type="text" readonly value="![](<?= e($url) ?>)" onclick="this.select()">
          <form method="post" onsubmit="return confirm('Supprimer <?= e($m) ?> ?')" style="margin-top:.3rem">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="media_delete">
            <input type="hidden" name="name" value="<?= e($m) ?>"><button class="btn-sm btn-danger" style="width:100%">Suppr.</button></form>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted small" style="margin-top:.8rem">Les images sont accessibles au chemin
    <code>/portal/intranet/uploads/…</code> — sur le site comme dans cet aperçu. Cliquez sur le code sous une image
    pour le copier, puis collez-le dans une page ou une actualité (bouton 🖼️ de l'éditeur).</p>
    <?php else: ?><p class="muted">Aucune image. Téléversez-en une ci-dessus.</p><?php endif; ?>
  </div>
</section>
</div>

<script>
// Éditeur : barre d'outils Markdown + aperçu live.
function mdInsert(ta, before, after, ph){
  var s=ta.selectionStart, e=ta.selectionEnd, v=ta.value, sel=v.slice(s,e)||ph;
  ta.value=v.slice(0,s)+before+sel+after+v.slice(e);
  ta.focus(); ta.selectionStart=s+before.length; ta.selectionEnd=s+before.length+sel.length;
  ta.dispatchEvent(new Event('input'));
}
var BTN=[['B','**','**','gras'],['I','*','*','italique'],['H2','## ','','Titre'],['•','\n- ','','élément'],['🔗','[','](https://)','texte'],['🖼️','![](','/portal/intranet/uploads/) ','']];
document.querySelectorAll('.mdbar').forEach(function(bar){
  var ta=document.getElementById(bar.getAttribute('data-t'));
  BTN.forEach(function(b){var btn=document.createElement('button');btn.type='button';btn.textContent=b[0];
    btn.onclick=function(){mdInsert(ta,b[1],b[2],b[3]);};bar.appendChild(btn);});
});
function mdRender(t){
  t=t.replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});
  t=t.replace(/^### (.+)$/gm,'<h3>$1</h3>').replace(/^## (.+)$/gm,'<h2>$1</h2>').replace(/^# (.+)$/gm,'<h1>$1</h1>');
  t=t.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g,'<img src="$2" alt="$1">');
  t=t.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/(?<!\*)\*(?!\*)(.+?)\*/g,'<em>$1</em>');
  t=t.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g,'<a href="$2">$1</a>');
  t=t.replace(/(?:^- .*(?:\n|$))+/gm,function(m){return '<ul>'+m.trim().split(/\n/).map(function(l){return '<li>'+l.replace(/^- /,'')+'</li>';}).join('')+'</ul>';});
  return t.split(/\n{2,}/).map(function(b){b=b.trim();if(!b)return'';return /^<(h[1-3]|ul|img)/.test(b)?b:'<p>'+b.replace(/\n/g,'<br>')+'</p>';}).join('');
}
document.querySelectorAll('.mdprev').forEach(function(prev){
  var ta=document.getElementById(prev.getAttribute('data-src'));
  function upd(){prev.innerHTML=mdRender(ta.value);}
  ta.addEventListener('input',upd); upd();
});
</script>
<?php pf_footer(); ?>
