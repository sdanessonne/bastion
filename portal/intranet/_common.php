<?php
/** Bastion — socle commun de l'intranet CMS (auth, base, Markdown, menu, effets). */
require_once __DIR__ . '/../https_guard.php';
require_once __DIR__ . '/../nds.php';

/**
 * Cache court en mémoire (tmpfs) pour éviter de rappeler des commandes lentes.
 *
 * $valide dit si une sortie est EXPLOITABLE. Indispensable pour les commandes qui
 * échouent en écrivant leur message sur la sortie STANDARD, avec un code de retour 0 :
 * « sortie non vide » ne prouve alors rien. Sans ce contrôle, l'ERREUR était mise en
 * cache et rejouée pendant tout le TTL.
 * En cas d'échec, on rend la dernière valeur connue — même périmée, elle vaut mieux
 * qu'une valeur fausse.
 */
function pf_cache_cmd(string $key, int $ttl, string $cmd, ?callable $valide = null): string {
    $f = '/dev/shm/pf-' . preg_replace('/[^a-z0-9._:-]/i', '', $key) . '.cache';
    $valide = $valide ?? static fn(string $s): bool => trim($s) !== '';

    if (is_file($f) && (time() - filemtime($f)) < $ttl) {
        $r = @file_get_contents($f);
        if ($r !== false && $valide($r)) { return $r; }
    }
    $r = (string) shell_exec($cmd);
    if ($valide($r)) { @file_put_contents($f, $r); return $r; }
    if (is_file($f)) {
        $old = @file_get_contents($f);
        if ($old !== false && $valide($old)) { return $old; }
    }
    return '';
}

function intranet_user(): array {
    static $c = null;
    if ($c !== null) { return $c; }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $u = ''; $auth = false;
    // Requête CIBLÉE par IP + cache 60 s : le nom et les groupes sont stables pendant
    // toute la session, inutile de réinterroger OpenNDS (lent) à chaque page.
    // pf_nds_client() encaisse les refus de ndsctl : auparavant, le message d'erreur
    // était mis en cache et l'agent paraissait déconnecté pendant une minute entière.
    $x = pf_nds_client($ip, 60);
    if ($x !== null) {
        $auth = ($x['state'] ?? '') === 'Authenticated';
        if (!empty($x['custom']) && ($d = base64_decode($x['custom'], true)) && preg_match('/user=([^,]+)/', $d, $m)) { $u = $m[1]; }
    }
    return $c = ['user' => $u, 'auth' => $auth];
}

function intranet_db(): ?PDO {
    static $pdo = null; static $tried = false;
    if ($tried) { return $pdo; }
    $tried = true;
    $env = [];
    foreach (@file('/etc/proxyfibre/admin.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        if (preg_match('/^(\w+)="?([^"]*)"?$/', $l, $m)) { $env[$m[1]] = $m[2]; }
    }
    try {
        $pdo = new PDO(sprintf('mysql:host=localhost;dbname=%s;charset=utf8mb4', $env['DB_NAME'] ?? 'radius'),
            $env['DB_USER'] ?? 'radius', $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (Throwable $e) { $pdo = null; }
    return $pdo;
}

function e_($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/** Groupes de l'utilisateur courant (pour les pages réservées). */
function intranet_groups(): array {
    static $g = null;
    if ($g !== null) { return $g; }
    $g = [];
    $u = intranet_user()['user'];
    if ($u !== '' && ($db = intranet_db())) {
        try { $st = $db->prepare('SELECT groupname FROM radusergroup WHERE username=?'); $st->execute([$u]);
              foreach ($st as $r) { $g[] = $r['groupname']; } } catch (Throwable $e) {}
    }
    return $g;
}

function intranet_setting(string $k, string $def = ''): string {
    static $S = null;
    if ($S === null) {
        $S = [];
        if ($db = intranet_db()) {
            try { foreach ($db->query("SELECT k,v FROM pf_settings WHERE k LIKE 'intranet\\_%'") as $r) { $S[$r['k']] = $r['v']; } }
            catch (Throwable $e) {}
        }
    }
    return ($S[$k] ?? '') !== '' ? $S[$k] : $def;
}

/** Pages publiées visibles dans le menu (filtrées selon les groupes de l'utilisateur). */
function cms_menu(): array {
    if (!($db = intranet_db())) { return []; }
    try { $rows = $db->query("SELECT slug,title,group_required FROM pf_cms_pages WHERE published=1 AND in_menu=1 ORDER BY menu_order,title")->fetchAll(); }
    catch (Throwable $e) { return []; }
    $groups = intranet_groups();
    return array_values(array_filter($rows, fn($p) => empty($p['group_required']) || in_array($p['group_required'], $groups, true)));
}

/**
 * Rendu d'un contenu CMS selon son format.
 *   - 'html'     : contenu de l'éditeur WYSIWYG → assaini par ALLOWLIST (cms_sanitize_html).
 *   - 'markdown' : ancien format → converti par cms_render_md (compat descendante).
 * Les deux passent par un filtrage strict : l'auteur est un admin de confiance, mais le contenu
 * est LU par les agents — on ne laisse donc jamais passer script/handlers/URL dangereuses.
 */
function cms_render(string $body, string $format = 'markdown'): string {
    return $format === 'html' ? cms_sanitize_html($body) : cms_render_md($body);
}

/** Ne garde que des propriétés CSS sûres (couleur, alignement, graisse…) — jamais url()/expression. */
function cms_clean_style(string $s): string {
    static $ok = ['color', 'background-color', 'text-align', 'font-weight', 'font-style', 'text-decoration'];
    $out = [];
    foreach (explode(';', $s) as $decl) {
        if (strpos($decl, ':') === false) { continue; }
        [$p, $v] = explode(':', $decl, 2);
        $p = strtolower(trim($p)); $v = trim($v);
        if (!in_array($p, $ok, true)) { continue; }
        if ($v === '' || preg_match('/url\s*\(|expression|javascript:|@import|<|>/i', $v)) { continue; }
        $out[] = $p . ':' . $v;
        if (count($out) >= 6) { break; }
    }
    return implode(';', $out);
}

/**
 * Assainit du HTML par ALLOWLIST (balises + attributs + styles) via DOMDocument.
 * Balises dangereuses (script/iframe/…) supprimées ; balises inconnues « déballées » (on garde
 * le texte) ; attributs on*, href/src non http(s)/relatifs, styles non sûrs : retirés.
 */
function cms_sanitize_html(string $html): string {
    $html = trim($html);
    if ($html === '') { return ''; }
    static $tags = ['p','br','h2','h3','h4','strong','b','em','i','u','s','ul','ol','li','a','img',
        'blockquote','hr','span','div','table','thead','tbody','tr','td','th','caption','figure','figcaption','pre','code','mark','sub','sup'];
    static $dangerous = ['script','style','iframe','object','embed','form','input','textarea','button','link','meta','svg','math'];
    static $attrByTag = ['a'=>['href','target','rel','title'], 'img'=>['src','alt','loading','width','height'],
        'td'=>['colspan','rowspan'], 'th'=>['colspan','rowspan'], 'table'=>[]];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__cmsroot">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('__cmsroot');
    if (!$root) { return ''; }

    $walk = function (DOMNode $node) use (&$walk, $tags, $dangerous, $attrByTag) {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) { $child->parentNode->removeChild($child); continue; }
            if (!($child instanceof DOMElement)) { continue; }   // texte : conservé tel quel
            $tag = strtolower($child->tagName);
            if (!in_array($tag, $tags, true)) {
                if (in_array($tag, $dangerous, true)) { $child->parentNode->removeChild($child); continue; }
                // Balise inconnue mais inoffensive : nettoyer son contenu puis la « déballer ».
                $walk($child);
                while ($child->firstChild) { $child->parentNode->insertBefore($child->firstChild, $child); }
                $child->parentNode->removeChild($child);
                continue;
            }
            $allowed = array_merge(['style', 'class'], $attrByTag[$tag] ?? []);
            foreach (iterator_to_array($child->attributes) as $a) {
                $an = strtolower($a->name); $av = $a->value;
                if (stripos($an, 'on') === 0 || !in_array($an, $allowed, true)) { $child->removeAttribute($a->name); continue; }
                if ($an === 'href' && !preg_match('#^(https?:|mailto:|tel:|/|\#)#i', $av)) { $child->removeAttribute($a->name); }
                if ($an === 'src'  && !preg_match('#^(https?:|/)#i', $av)) { $child->removeAttribute($a->name); }
                if ($an === 'class' && !preg_match('/^[A-Za-z0-9 _-]{0,60}$/', $av)) { $child->removeAttribute($a->name); }
                if ($an === 'style') { $cl = cms_clean_style($av); $cl === '' ? $child->removeAttribute('style') : $child->setAttribute('style', $cl); }
            }
            if ($tag === 'a' && strtolower($child->getAttribute('target')) === '_blank') { $child->setAttribute('rel', 'noopener'); }
            if ($tag === 'img') { $child->setAttribute('loading', 'lazy'); }
            $walk($child);
        }
    };
    $walk($root);

    $out = '';
    foreach ($root->childNodes as $c) { $out .= $doc->saveHTML($c); }
    return trim($out);
}

/** Rendu Markdown-léger et SÛR (échappe avant transformation ; images + liens autorisés). */
function cms_render_md(string $md): string {
    $h = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');
    $h = str_replace("\r\n", "\n", $h);
    $h = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $h);
    $h = preg_replace('/^## (.+)$/m',  '<h2>$1</h2>', $h);
    $h = preg_replace('/^# (.+)$/m',   '<h1>$1</h1>', $h);
    // Images : ![alt](url) — AVANT les liens.
    $h = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) {
        $url = preg_match('#^(https?:|/)#', $m[2]) ? $m[2] : '';
        return $url === '' ? '' : '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . $m[1] . '" loading="lazy">';
    }, $h);
    $h = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $h);
    $h = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $h);
    $h = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url = preg_match('#^(https?:|/|mailto:)#', $m[2]) ? $m[2] : '#';
        $ext = strpos($url, 'http') === 0 ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '"' . $ext . '>' . $m[1] . '</a>';
    }, $h);
    $h = preg_replace_callback('/(?:^- .*(?:\n|$))+/m', function ($m) {
        $li = '';
        foreach (preg_split('/\n/', trim($m[0])) as $l) { $l = preg_replace('/^- /', '', $l); if ($l !== '') { $li .= '<li>' . $l . '</li>'; } }
        return "<ul>$li</ul>\n";
    }, $h);
    $out = '';
    foreach (preg_split('/\n{2,}/', $h) as $b) {
        $b = trim($b);
        if ($b === '') { continue; }
        $out .= preg_match('/^<(h[1-3]|ul|ol|blockquote|p|img)/', $b) ? $b : '<p>' . nl2br($b) . '</p>';
    }
    return $out;
}

function intranet_head(string $title, string $active = ''): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $menu = cms_menu();
    $newsMax = 0;
    if ($db = intranet_db()) { try { $newsMax = (int) $db->query('SELECT COALESCE(MAX(id),0) FROM pf_cms_news WHERE published=1')->fetchColumn(); } catch (Throwable $e) {} }
    ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <script>(function(){try{var t=localStorage.getItem('theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}})();</script>
  <title><?= e_($title) ?> — <?= e_(intranet_setting('intranet_title', 'Intranet')) ?></title>
  <link rel="icon" type="image/svg+xml" href="/portal/assets/bastion-icon.svg">
  <!-- Application mobile installable (PWA) -->
  <link rel="manifest" href="/portal/manifest.php">
  <meta name="theme-color" content="#0f172a">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="<?= e_(intranet_setting('intranet_title', 'Intranet')) ?>">
  <link rel="apple-touch-icon" href="/portal/assets/icon-192.png">
  <link rel="stylesheet" href="/portal/assets/bastion-fx.css">
  <style>
    :root{--bg:#0f172a;--card:#1e293b;--line:#334155;--text:#e2e8f0;--muted:#94a3b8;--accent:#38bdf8}
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--text)}
    header.top{display:flex;align-items:center;gap:1rem;padding:.9rem 1.5rem;background:#111c30;border-bottom:1px solid var(--line);flex-wrap:wrap;position:sticky;top:0;z-index:20}
    header.top img{width:36px;height:36px;transition:transform .4s}
    header.top img:hover{transform:rotate(-8deg) scale(1.08)}
    header.top .ttl{font-size:1.1rem;font-weight:600}
    header.top .sp{flex:1}
    header.top a.act{color:var(--muted);text-decoration:none;font-size:.85rem;padding:.35rem .6rem;border-radius:8px;transition:.2s}
    header.top a.act:hover{background:#1c2b45;color:var(--text)}
    nav.menu{display:flex;gap:.2rem;padding:.4rem 1.2rem;background:#0d1728;border-bottom:1px solid var(--line);flex-wrap:wrap;overflow-x:auto;position:sticky;top:57px;z-index:19}
    nav.menu a{color:var(--muted);text-decoration:none;font-size:.9rem;padding:.45rem .8rem;border-radius:8px;white-space:nowrap;position:relative;transition:color .2s}
    nav.menu a::after{content:"";position:absolute;left:.8rem;right:.8rem;bottom:.2rem;height:2px;background:var(--accent);transform:scaleX(0);transform-origin:left;transition:transform .25s}
    nav.menu a:hover{color:var(--text)} nav.menu a:hover::after{transform:scaleX(1)}
    nav.menu a.on{color:var(--accent)} nav.menu a.on::after{transform:scaleX(1)}
    main{max-width:980px;margin:0 auto;padding:1.6rem 1.5rem}
    h1{font-size:1.5rem;margin:0 0 1rem}
    .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:1.3rem 1.5rem;margin-bottom:1.1rem;transition:transform .25s,border-color .25s,box-shadow .25s}
    .muted{color:var(--muted)} .prose p,.prose li{line-height:1.75;color:#cbd5e1} .prose h1{font-size:1.4rem} .prose h2{font-size:1.2rem;color:var(--accent);margin-top:1.4rem} .prose h3{font-size:1.05rem} .prose a{color:var(--accent)} .prose img{max-width:100%;border-radius:10px;margin:.6rem 0;border:1px solid var(--line)}
    input,textarea{width:100%;padding:.6rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px;font-size:.95rem;font-family:inherit;transition:border-color .2s,box-shadow .2s}
    input:focus,textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(56,189,248,.15)}
    label{display:block;margin-bottom:.9rem;font-size:.85rem;color:var(--muted)}
    button{padding:.7rem 1.1rem;background:#0ea5e9;color:#04283a;font-weight:600;border:none;border-radius:9px;cursor:pointer;font-size:.95rem;transition:background .2s,transform .1s}
    button:hover{background:#38bdf8} button:active{transform:scale(.97)}
    .ok{background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.35);color:#86efac;padding:.7rem .9rem;border-radius:10px;margin-bottom:1rem}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:.9rem}
    a.tile{display:flex;align-items:center;gap:.8rem;padding:1rem 1.1rem;background:var(--card);border:1px solid var(--line);border-radius:12px;text-decoration:none;color:var(--text);transition:transform .2s,border-color .2s,box-shadow .2s}
    a.tile:hover{border-color:var(--accent);transform:translateY(-3px);box-shadow:0 10px 24px rgba(2,10,25,.5)}
    a.tile .emo{font-size:1.5rem;transition:transform .3s} a.tile:hover .emo{transform:scale(1.18)}
    .news{border-left:3px solid var(--accent);padding:.2rem 0 .2rem 1rem;margin-bottom:1.3rem}
    .news .date{font-size:.75rem;color:var(--muted)} .news h3{margin:.2rem 0 .4rem;font-size:1.1rem}
    .badge-cat{display:inline-block;font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;background:rgba(56,189,248,.15);color:var(--accent);padding:.1rem .5rem;border-radius:20px;margin-left:.4rem}
    .person{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:.9rem 1.1rem;transition:transform .2s,border-color .2s}
    .person:hover{transform:translateY(-2px);border-color:var(--accent)}
    .person .n{font-weight:600} .person .r{font-size:.78rem;color:var(--muted)}
    a.back{color:var(--accent);text-decoration:none;font-size:.9rem}
    .hero{position:relative;overflow:hidden;background:linear-gradient(120deg,#1e3a5f,#152238,#1e3a5f);background-size:200% 200%;animation:heroMove 12s ease infinite}
    @keyframes heroMove{0%{background-position:0 50%}50%{background-position:100% 50%}100%{background-position:0 50%}}
    .reveal{opacity:0;transform:translateY(16px)}
    .reveal.in{opacity:1;transform:none;transition:opacity .5s ease,transform .5s ease}
    @media(prefers-reduced-motion:reduce){.reveal{opacity:1;transform:none}.hero{animation:none}}
    /* ---- Web-app mobile : barre d'onglets + responsive ---- */
    .tabbar{display:none}
    @media(max-width:760px){
      header.top{padding:.7rem 1rem;top:env(safe-area-inset-top,0)}
      header.top .ttl{font-size:1rem}
      nav.menu{top:calc(53px + env(safe-area-inset-top,0));padding:.35rem .7rem}
      main{padding:1.1rem 1rem calc(74px + env(safe-area-inset-bottom,0))}
      h1{font-size:1.3rem}
      .card{padding:1.1rem;border-radius:12px}
      .grid{grid-template-columns:1fr 1fr}
      .tabbar{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:40;background:rgba(13,23,40,.96);
        backdrop-filter:blur(8px);border-top:1px solid var(--line);justify-content:space-around;
        padding:.3rem .2rem calc(.3rem + env(safe-area-inset-bottom,0))}
      .tabbar a{flex:1;display:flex;flex-direction:column;align-items:center;gap:.12rem;padding:.4rem 0;
        color:var(--muted);text-decoration:none;font-size:.66rem;transition:color .2s,transform .1s}
      .tabbar a .i{font-size:1.35rem;line-height:1}
      .tabbar a.on{color:var(--accent)} .tabbar a:active{transform:scale(.9)}
    }
    @media(max-width:430px){.grid{grid-template-columns:1fr}}
    /* ---- Thème clair ---- */
    :root[data-theme="light"]{--bg:#eef2f7;--card:#ffffff;--line:#dbe3ec;--text:#0f172a;--muted:#5b6b7f;--accent:#0284c7}
    :root[data-theme="light"] header.top{background:#ffffff}
    :root[data-theme="light"] nav.menu{background:#f4f7fb}
    :root[data-theme="light"] .tabbar{background:rgba(255,255,255,.96)!important;border-top-color:#dbe3ec}
    :root[data-theme="light"] input,:root[data-theme="light"] textarea{background:#fff}
    :root[data-theme="light"] .prose p,:root[data-theme="light"] .prose li{color:#334155}
    :root[data-theme="light"] a.tile:hover{box-shadow:0 8px 20px rgba(100,116,139,.18)}
    /* ---- Bouton thème + point de notification ---- */
    .themebtn{background:transparent;border:none;color:var(--muted);font-size:1.15rem;cursor:pointer;padding:.2rem .4rem;border-radius:8px}
    .themebtn:hover{background:#1c2b45}
    :root[data-theme="light"] .themebtn:hover{background:#e8eef6}
    .notif-dot{position:absolute;top:.3rem;right:.5rem;width:8px;height:8px;background:#f87171;border-radius:50%}
    .tabbar a{position:relative}
  </style>
</head>
<body>
  <div class="bg" aria-hidden="true"><div class="aurora"></div><span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span><div class="grid"></div></div>
  <canvas id="fx" aria-hidden="true"></canvas>
  <header class="top">
    <img src="/portal/assets/bastion-icon.svg" alt="">
    <div class="ttl"><?= e_(intranet_setting('intranet_title', 'Intranet')) ?></div>
    <div class="sp"></div>
    <button class="themebtn" id="themeBtn" aria-label="Changer de thème" title="Thème clair / sombre">🌓</button>
    <a class="act" href="/portal/account.php">Mon compte</a>
    <a class="act" href="/portal/logout.php">Se déconnecter</a>
  </header>
  <script>window.__intranet={newsMax:<?= (int) $newsMax ?>,active:<?= json_encode($active) ?>};</script>
  <nav class="menu">
    <a href="/portal/intranet.php" class="<?= $active === 'home' ? 'on' : '' ?>">Accueil</a>
    <?php foreach ($menu as $p): ?>
      <a href="/portal/intranet/page.php?slug=<?= urlencode($p['slug']) ?>" class="<?= $active === $p['slug'] ? 'on' : '' ?>"><?= e_($p['title']) ?></a>
    <?php endforeach; ?>
    <a href="/portal/intranet/annuaire.php" class="<?= $active === 'annuaire' ? 'on' : '' ?>">Annuaire</a>
    <a href="/portal/intranet/assistance.php" class="<?= $active === 'assistance' ? 'on' : '' ?>">Assistance</a>
  </nav>
  <nav class="tabbar">
    <a href="/portal/intranet.php" class="<?= $active === 'home' ? 'on' : '' ?>"><span class="i">🏠</span>Accueil</a>
    <a href="/portal/intranet/annuaire.php" class="<?= $active === 'annuaire' ? 'on' : '' ?>"><span class="i">👥</span>Annuaire</a>
    <a href="/portal/intranet/assistance.php" class="<?= $active === 'assistance' ? 'on' : '' ?>"><span class="i">🛟</span>Aide</a>
    <a href="/portal/account.php"><span class="i">👤</span>Compte</a>
  </nav>
  <main>
    <div id="pwaBanner" style="display:none;align-items:center;gap:.7rem;background:#152a45;border:1px solid var(--accent);border-radius:12px;padding:.7rem .9rem;margin-bottom:1.1rem">
      <img src="/portal/assets/icon-192.png" alt="" style="width:34px;height:34px;border-radius:8px;flex:none">
      <div style="flex:1;font-size:.85rem;line-height:1.35"><strong style="color:#fff">Installer l'application</strong><br><span class="muted" id="pwaHint">Accédez à l'intranet en un geste depuis votre écran d'accueil.</span></div>
      <button id="pwaInstall" style="padding:.45rem .8rem;font-size:.85rem">Installer</button>
      <button id="pwaClose" aria-label="Fermer" style="background:transparent;color:var(--muted);border:none;font-size:1.2rem;cursor:pointer;padding:0 .2rem">×</button>
    </div>
    <?php
}

function intranet_foot(): void {
    echo '<p class="muted" style="font-size:.75rem;margin-top:2rem">' . e_(intranet_setting('intranet_title', 'Intranet'))
       . ' — propulsé par Bastion.</p></main>';
    ?>
<script>
(function(){
  var els=document.querySelectorAll('.card,.news,.tile,.person,.prose');
  els.forEach(function(el){el.classList.add('reveal');});
  if(!('IntersectionObserver' in window)){els.forEach(function(el){el.classList.add('in');});return;}
  var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target);}});},{threshold:.08});
  els.forEach(function(el,i){el.style.transitionDelay=(Math.min(i,8)*40)+'ms';io.observe(el);});
})();
if('serviceWorker' in navigator){navigator.serviceWorker.register('/portal/sw.js').catch(function(){});}
(function(){
  var b=document.getElementById('pwaBanner'); if(!b)return;
  var standalone=window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone;
  if(standalone||localStorage.getItem('pwaDismiss')==='1')return;
  var deferred=null, install=document.getElementById('pwaInstall'), close=document.getElementById('pwaClose'), hint=document.getElementById('pwaHint');
  window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();deferred=e;b.style.display='flex';});
  install.addEventListener('click',function(){if(deferred){deferred.prompt();deferred.userChoice.finally(function(){b.style.display='none';deferred=null;});}});
  close.addEventListener('click',function(){b.style.display='none';localStorage.setItem('pwaDismiss','1');});
  if(/iphone|ipad|ipod/i.test(navigator.userAgent)&&!standalone){install.style.display='none';hint.textContent='Appuyez sur Partager puis « Sur l\'écran d\'accueil ».';b.style.display='flex';}
})();
(function(){
  var tb=document.getElementById('themeBtn');
  if(tb)tb.addEventListener('click',function(){
    var light=document.documentElement.getAttribute('data-theme')==='light';
    if(light){document.documentElement.removeAttribute('data-theme');}else{document.documentElement.setAttribute('data-theme','light');}
    try{localStorage.setItem('theme',light?'dark':'light');}catch(e){}
  });
})();
(function(){
  try{
    var st=window.__intranet||{}, nm=st.newsMax||0, act=st.active||'';
    var seen=parseInt(localStorage.getItem('lastNewsId')||'0',10);
    if(act==='home'){localStorage.setItem('lastNewsId',String(nm));}
    else if(nm>seen){
      document.querySelectorAll('a[href="/portal/intranet.php"]').forEach(function(a){
        if(!a.querySelector('.notif-dot')){var d=document.createElement('span');d.className='notif-dot';a.appendChild(d);}
      });
    }
  }catch(e){}
})();
</script>
<script src="/portal/assets/bastion-fx.js" defer></script>
</body></html>
    <?php
}
