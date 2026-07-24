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
    // Format du contenu : 'markdown' (ancien) ou 'html' (éditeur WYSIWYG).
    $db->exec("ALTER TABLE pf_cms_pages ADD COLUMN IF NOT EXISTS format VARCHAR(10) DEFAULT 'markdown'");
    $db->exec("ALTER TABLE pf_cms_news ADD COLUMN IF NOT EXISTS format VARCHAR(10) DEFAULT 'markdown'");
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
            // Unicité du slug (colonne UNIQUE) : suffixe -2, -3… s'il est déjà pris par une AUTRE page,
            // plutôt qu'une erreur SQL brute à l'enregistrement.
            if ($title !== '') {
                $q = $db->prepare('SELECT 1 FROM pf_cms_pages WHERE slug=? AND id<>?');
                $s0 = $slug; $k = 1;
                while (true) { $q->execute([$slug, $id]); if (!$q->fetchColumn()) { break; } $slug = $s0 . '-' . (++$k); }
            }
            if ($title === '') { $flash = ['Titre requis.', 'err']; }
            elseif ($id > 0) {
                $db->prepare('UPDATE pf_cms_pages SET slug=?,title=?,body=?,format=?,menu_order=?,in_menu=?,published=?,group_required=?,updated_by=? WHERE id=?')
                   ->execute([$slug, $title, $body, 'html', $order, $inm, $pub, $grp, $_SESSION['admin'], $id]);
                $flash = ['Page mise à jour.', 'ok'];
            } else {
                $db->prepare('INSERT INTO pf_cms_pages (slug,title,body,format,menu_order,in_menu,published,group_required,updated_by) VALUES (?,?,?,?,?,?,?,?,?)')
                   ->execute([$slug, $title, $body, 'html', $order, $inm, $pub, $grp, $_SESSION['admin']]);
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
                $db->prepare('UPDATE pf_cms_news SET title=?,body=?,format=?,category=?,published=? WHERE id=?')->execute([$title, $body, 'html', $cat, $pub, $id]);
                $flash = ['Actualité mise à jour.', 'ok'];
            } else {
                $db->prepare('INSERT INTO pf_cms_news (title,body,format,author,category,published) VALUES (?,?,?,?,?,?)')->execute([$title, $body, 'html', $_SESSION['admin'], $cat, $pub]);
                $flash = ['Actualité publiée.', 'ok'];
            }
        }
        elseif ($do === 'news_delete') { $db->prepare('DELETE FROM pf_cms_news WHERE id=?')->execute([(int) $_POST['id']]); $flash = ['Actualité supprimée.', 'ok']; }
        elseif ($do === 'upload_media') {
            if (!is_dir($MEDIA)) { @mkdir($MEDIA, 0775, true); }
            $f = $_FILES['media'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
                $flash = ['Aucun fichier reçu.', 'err'];
            } elseif (($f['size'] ?? 0) > 4 * 1024 * 1024) {
                $flash = ['Image trop lourde (max 4 Mo).', 'err'];
            } elseif (!function_exists('imagecreatefromstring') || !function_exists('getimagesizefromstring')) {
                $flash = ["Traitement d'image indisponible (php-gd manquant).", 'err'];
            } else {
                // L'image atterrit dans l'arborescence web : on ne stocke JAMAIS les octets bruts
                // reçus. On RÉ-ENCODE (décodage des pixels + ré-émission) — un éventuel polyglotte
                // (image + code) ne survit pas. Cohérent avec avatars / fond d'écran / photos.
                $data = (string) @file_get_contents($f['tmp_name']);
                $info = @getimagesizefromstring($data);
                $ext  = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'][$info['mime'] ?? ''] ?? '';
                $w = (int) ($info[0] ?? 0); $h = (int) ($info[1] ?? 0);
                if (!$ext) {
                    $flash = ['Format non supporté (PNG, JPEG, GIF, WEBP).', 'err'];
                } elseif ($w < 1 || $h < 1 || $w * $h > 40 * 1000 * 1000) {   // garde anti-bombe de décompression
                    $flash = ["Dimensions d'image invalides ou trop grandes.", 'err'];
                } elseif ($ext === 'webp' && !function_exists('imagewebp')) {
                    $ext = 'png';   // serveur sans encodeur WebP → on ré-émet en PNG
                }
                if ($ext && !$flash) {
                    $src = @imagecreatefromstring($data);
                    if (!$src) {
                        $flash = ['Image illisible ou corrompue.', 'err'];
                    } else {
                        $base = trim(preg_replace('/[^a-z0-9_-]+/', '-', strtolower(pathinfo((string) $f['name'], PATHINFO_FILENAME))), '-') ?: 'image';
                        $name = "$base.$ext"; $i = 1;
                        while (file_exists("$MEDIA/$name")) { $name = "$base-$i.$ext"; $i++; }
                        $path = "$MEDIA/$name"; $ok = false;
                        if ($ext === 'png' || $ext === 'gif') { imagealphablending($src, false); imagesavealpha($src, true); }
                        if     ($ext === 'png')  { $ok = imagepng($src, $path, 6); }
                        elseif ($ext === 'jpg')  { $ok = imagejpeg($src, $path, 88); }
                        elseif ($ext === 'gif')  { $ok = imagegif($src, $path); }
                        elseif ($ext === 'webp') { $ok = imagewebp($src, $path, 88); }
                        imagedestroy($src);
                        if ($ok) { @chmod($path, 0644); $flash = ["Image téléversée : $name", 'ok']; }
                        else { $flash = ["Écriture impossible dans $MEDIA.", 'err']; }
                    }
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

pf_header('Portail intranet', 'cms.php');
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
  /* Éditeur WYSIWYG */
  .wz{border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--bg)}
  .wz-bar{display:flex;gap:.2rem;flex-wrap:wrap;align-items:center;background:#0d1728;border-bottom:1px solid var(--line);padding:.35rem}
  .wz-bar button,.wz-bar .wzc{background:#1c2b45;color:var(--text);border:none;border-radius:6px;padding:.3rem .5rem;font-size:.85rem;cursor:pointer;min-width:1.9rem;height:1.9rem;display:inline-flex;align-items:center;justify-content:center}
  .wz-bar button:hover,.wz-bar .wzc:hover{background:#294066}
  .wz-bar .sep{width:1px;height:1.4rem;background:var(--line);margin:0 .15rem}
  .wz-bar input[type=color]{width:1.2rem;height:1.2rem;border:none;background:none;padding:0;cursor:pointer;margin-left:.25rem}
  .wz-area{min-height:240px;max-height:60vh;overflow:auto;padding:.9rem 1rem;color:var(--text);line-height:1.7;outline:none}
  .wz-area:focus{box-shadow:inset 0 0 0 2px rgba(56,189,248,.22)}
  .wz-area:empty::before{content:"Rédigez ici — la mise en forme se fait avec la barre ci-dessus.";color:var(--muted)}
  .wz-area h2{font-size:1.15rem;color:var(--accent);margin:.9rem 0 .4rem}
  .wz-area h3{font-size:1rem;margin:.7rem 0 .3rem}
  .wz-area img{max-width:100%;border-radius:8px;margin:.4rem 0}
  .wz-area blockquote{border-left:3px solid var(--accent);margin:.6rem 0;padding:.2rem 0 .2rem .9rem;color:var(--muted)}
  .wz-area ul,.wz-area ol{padding-left:1.4rem} .wz-area a{color:var(--accent)}
  .wz-area table{border-collapse:collapse;margin:.5rem 0} .wz-area td,.wz-area th{border:1px solid var(--line);padding:.3rem .55rem}
  .wz-pick{display:none;grid-template-columns:repeat(auto-fill,minmax(78px,1fr));gap:.4rem;padding:.5rem;background:#0d1728;border-top:1px solid var(--line);max-height:180px;overflow:auto}
  .wz-pick.on{display:grid}
  .wz-pick img{width:100%;height:54px;object-fit:cover;border-radius:6px;cursor:pointer;border:1px solid var(--line)}
  .wz-pick img:hover{border-color:var(--accent)}
  .wz-pick .none{grid-column:1/-1;color:var(--muted);font-size:.8rem;padding:.4rem}
  .media{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.7rem}
  .media .m{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:.4rem;text-align:center}
  .media .m img{width:100%;height:70px;object-fit:cover;border-radius:6px}
  .media .m input{width:100%;font-size:.62rem;margin-top:.3rem;background:#0d1728;color:var(--muted);border:1px solid var(--line);border-radius:5px;padding:.2rem}
  .cms-tabs{display:flex;gap:.3rem;flex-wrap:wrap;margin:0 0 1.2rem;border-bottom:1px solid var(--line)}
  .cms-tab{background:transparent;border:1px solid transparent;border-bottom:none;color:var(--muted);cursor:pointer;padding:.6rem 1.05rem;font-size:.9rem;border-radius:10px 10px 0 0;font-weight:500}
  .cms-tab:hover{color:var(--text);background:var(--bg)}
  .cms-tab.active{color:#fff;background:var(--panel);border-color:var(--line);margin-bottom:-1px}
  .cms-frame{width:100%;border:1px solid var(--line);border-radius:12px;background:var(--panel);height:calc(100vh - 15rem);min-height:480px;display:block}
</style>
<div class="cms">
<nav class="cms-tabs" role="tablist" aria-label="Sections du portail intranet">
  <button type="button" class="cms-tab" data-tab="accueil">🏠 Accueil</button>
  <button type="button" class="cms-tab" data-tab="pages">📄 Pages</button>
  <button type="button" class="cms-tab" data-tab="actus">📰 Actualités</button>
  <button type="button" class="cms-tab" data-tab="media">🖼️ Médiathèque</button>
</nav>
<div class="cmspane" data-cmstab="accueil">
  <iframe class="cms-frame" title="Accueil du portail intranet (contenu et liens)" src="intranet.php?embed=1"></iframe>
</div>
<div class="cmspane" data-cmstab="pages" hidden>
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
      <div class="field" style="margin-bottom:.5rem">Contenu</div>
      <div class="wz" data-fmt="<?= e($editP['format'] ?? 'markdown') ?>">
        <div class="wz-bar"></div>
        <div class="wz-area" contenteditable="true"></div>
        <textarea id="pbody" name="body" hidden><?= e($editP['body']) ?></textarea>
      </div>
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
</div><!-- /pane pages -->

<div class="cmspane" data-cmstab="actus" hidden>
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
      <div class="field" style="margin-bottom:.5rem">Contenu</div>
      <div class="wz" data-fmt="<?= e($editN['format'] ?? 'markdown') ?>">
        <div class="wz-bar"></div>
        <div class="wz-area" contenteditable="true"></div>
        <textarea id="nbody" name="body" hidden><?= e($editN['body']) ?></textarea>
      </div>
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
</div><!-- /pane actus -->

<div class="cmspane" data-cmstab="media" hidden>
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
          <input type="text" readonly value="<?= e($url) ?>" onclick="this.select()" title="Adresse de l'image">
          <form method="post" onsubmit="return confirm('Supprimer <?= e($m) ?> ?')" style="margin-top:.3rem">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="media_delete">
            <input type="hidden" name="name" value="<?= e($m) ?>"><button class="btn-sm btn-danger" style="width:100%">Suppr.</button></form>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted small" style="margin-top:.8rem">Pour <strong>insérer une image</strong> dans une page ou une actualité, utilisez le bouton
    <strong>🖼️</strong> de l'éditeur : il propose directement ces images. L'adresse sous chaque image
    (<code>/portal/intranet/uploads/…</code>) peut aussi servir de cible pour un lien.</p>
    <?php else: ?><p class="muted">Aucune image. Téléversez-en une ci-dessus.</p><?php endif; ?>
  </div>
</section>
</div><!-- /pane media -->
</div>

<script>
// Onglets Contenu (Pages / Actualités / Médiathèque) — page longue, on la scinde.
(function(){
  var tabs=document.querySelectorAll('.cms-tab'), panes=document.querySelectorAll('.cmspane');
  function show(k){
    panes.forEach(function(p){ p.hidden = p.getAttribute('data-cmstab')!==k; });
    tabs.forEach(function(b){ b.classList.toggle('active', b.dataset.tab===k); });
    try{ localStorage.setItem('cms_tab',k); }catch(e){}
  }
  tabs.forEach(function(b){ b.addEventListener('click',function(){ show(b.dataset.tab); }); });
  // Onglet initial : édition d'une page → Pages ; d'une actu → Actualités ; sinon mémorisé.
  var qp=new URLSearchParams(location.search);
  var init = qp.has('p') ? 'pages' : (qp.has('n') ? 'actus' : null);
  if(!init){ try{ init=localStorage.getItem('cms_tab'); }catch(e){} }
  var valid=Array.prototype.some.call(tabs,function(b){return b.dataset.tab===init;});
  show(valid?init:'accueil');
})();
// Images de la médiathèque, pour le sélecteur d'image de l'éditeur.
var CMS_MEDIA = <?= json_encode(array_map(fn($m) => '/portal/intranet/uploads/' . $m, $media)) ?>;
// mdRender : convertit une ANCIENNE page Markdown en HTML à l'ouverture (migration douce).
function mdRender(t){
  t=t.replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});
  t=t.replace(/^### (.+)$/gm,'<h3>$1</h3>').replace(/^## (.+)$/gm,'<h2>$1</h2>').replace(/^# (.+)$/gm,'<h1>$1</h1>');
  // Images / liens : mêmes garde-fous d'URL que le rendu serveur (cms_render). L'aperçu
  // reflète ainsi EXACTEMENT ce que verront les agents (une URL non http(s)/interne est écartée).
  t=t.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g,function(m,alt,url){ return /^(https?:|\/)/.test(url)?'<img src="'+url+'" alt="'+alt+'">':''; });
  t=t.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/(?<!\*)\*(?!\*)(.+?)\*/g,'<em>$1</em>');
  t=t.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g,function(m,txt,url){ return '<a href="'+(/^(https?:|\/|mailto:)/.test(url)?url:'#')+'">'+txt+'</a>'; });
  t=t.replace(/(?:^- .*(?:\n|$))+/gm,function(m){return '<ul>'+m.trim().split(/\n/).map(function(l){return '<li>'+l.replace(/^- /,'')+'</li>';}).join('')+'</ul>';});
  return t.split(/\n{2,}/).map(function(b){b=b.trim();if(!b)return'';return /^<(h[1-3]|ul|img)/.test(b)?b:'<p>'+b.replace(/\n/g,'<br>')+'</p>';}).join('');
}
// Initialisation de chaque éditeur WYSIWYG (.wz) : contenteditable + barre d'outils.
document.querySelectorAll('.wz').forEach(function(wz){
  var bar=wz.querySelector('.wz-bar'), area=wz.querySelector('.wz-area'),
      ta=wz.querySelector('textarea'), fmt=wz.getAttribute('data-fmt');
  // Pré-remplissage : HTML direct, ou conversion de l'ancien Markdown (migration douce).
  var raw=ta.value||'';
  area.innerHTML = (fmt==='html') ? raw : (raw.trim()? mdRender(raw) : '');
  function sync(){ ta.value = area.innerHTML; }
  area.addEventListener('input', sync); area.addEventListener('blur', sync);
  function cmd(c,v){ area.focus(); try{document.execCommand('styleWithCSS',false,true);}catch(e){} document.execCommand(c,false,v||null); sync(); }
  function add(html,title,fn){ var b=document.createElement('button'); b.type='button'; b.title=title; b.innerHTML=html;
    b.addEventListener('mousedown',function(e){e.preventDefault();}); b.addEventListener('click',fn); bar.appendChild(b); }
  function sep(){ var s=document.createElement('span'); s.className='sep'; bar.appendChild(s); }
  add('P','Paragraphe',function(){cmd('formatBlock','p');});
  add('H2','Titre',function(){cmd('formatBlock','h2');});
  add('H3','Sous-titre',function(){cmd('formatBlock','h3');});
  sep();
  add('<b>G</b>','Gras',function(){cmd('bold');});
  add('<i>I</i>','Italique',function(){cmd('italic');});
  add('<u>S</u>','Souligné',function(){cmd('underline');});
  var lab=document.createElement('label'); lab.className='wzc'; lab.title='Couleur du texte'; lab.textContent='A';
  var col=document.createElement('input'); col.type='color'; col.value='#38bdf8';
  col.addEventListener('input',function(){cmd('foreColor',col.value);}); lab.appendChild(col); bar.appendChild(lab);
  sep();
  add('•','Liste à puces',function(){cmd('insertUnorderedList');});
  add('1.','Liste numérotée',function(){cmd('insertOrderedList');});
  add('❝','Citation',function(){cmd('formatBlock','blockquote');});
  sep();
  add('◧','Aligner à gauche',function(){cmd('justifyLeft');});
  add('☰','Centrer',function(){cmd('justifyCenter');});
  sep();
  add('🔗','Lien',function(){ var u=prompt('Adresse du lien (https://… , /page , mailto:…)'); if(u){cmd('createLink',u);} });
  add('🖼️','Insérer une image',function(){ pick.classList.toggle('on'); });
  add('―','Séparateur',function(){cmd('insertHorizontalRule');});
  sep();
  add('⌫','Effacer la mise en forme',function(){cmd('removeFormat');});
  // Sélecteur d'image (médiathèque)
  var pick=document.createElement('div'); pick.className='wz-pick';
  if(!CMS_MEDIA.length){ pick.innerHTML='<div class="none">Aucune image. Téléversez-en dans l\'onglet 🖼️ Médiathèque.</div>'; }
  else CMS_MEDIA.forEach(function(url){ var im=document.createElement('img'); im.src=url; im.title=url; im.loading='lazy';
    im.addEventListener('click',function(){ area.focus(); document.execCommand('insertHTML',false,'<img src="'+url+'" alt="">'); sync(); pick.classList.remove('on'); }); pick.appendChild(im); });
  wz.appendChild(pick);
  var form=wz.closest('form'); if(form){ form.addEventListener('submit',sync); }
});
</script>
<?php pf_footer(); ?>
