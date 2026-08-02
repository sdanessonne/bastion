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
    $auth = $x !== null && ($x['state'] ?? '') === 'Authenticated';

    // ── CACHE ASYMÉTRIQUE, ET LA RAISON EST CONCRÈTE ────────────────────────
    // Le cache de 60 s économise un appel lent à ndsctl. Mais il gardait AUSSI les
    // réponses négatives : un agent qui venait de s'identifier voyait l'intranet
    // continuer à le déclarer « non identifié » pendant une minute entière, et
    // recommençait à se connecter en croyant avoir échoué. Constaté sur la
    // passerelle, portail authentifié et bandeau toujours affiché.
    //
    // Les deux erreurs ne coûtent pas la même chose. Un « authentifié » périmé dure
    // au plus 60 s après une déconnexion — sans conséquence, le pare-feu ayant déjà
    // coupé l'accès. Un « non authentifié » périmé, lui, tombe précisément au moment
    // où l'agent vient d'agir. On ne met donc en cache QUE les réponses positives.
    if (!$auth) {
        $x = pf_nds_client($ip, 0);   // 0 = on redemande à OpenNDS, sans se fier au cache
        $auth = $x !== null && ($x['state'] ?? '') === 'Authenticated';
    }

    if ($x !== null && !empty($x['custom'])
        && ($d = base64_decode($x['custom'], true)) && preg_match('/user=([^,]+)/', $d, $m)) {
        $u = $m[1];
    }
    // ── LE MATRICULE NE SUFFIT PAS ──────────────────────────────────────────
    // L'intranet accueillait l'agent par « Bonjour 0110480 ». C'est son
    // identifiant technique, pas son nom : la page compte affichait deja
    // « MONESTIER Mickael » au meme instant, ce qui rendait l'ecart d'autant plus
    // visible. On resout donc l'identite ici, une fois, pour tout l'intranet.
    $nom = ''; $prenom = ''; $photo = '';
    if ($auth && $u !== '' && ($db = intranet_db()) !== null) {
        try {
            $st = $db->prepare('SELECT nom, prenom FROM pf_user_profile WHERE username = ? LIMIT 1');
            $st->execute([$u]);
            if ($p = $st->fetch(PDO::FETCH_ASSOC)) { $nom = (string) $p['nom']; $prenom = (string) $p['prenom']; }
        } catch (Throwable $e) {}
        // Seulement la version de la photo : inutile de charger 65 Ko de binaire
        // pour savoir s'il y en a une.
        try {
            $st = $db->prepare('SELECT v FROM pf_user_photo WHERE username = ? LIMIT 1');
            $st->execute([$u]);
            $photo = (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
    }
    return $c = [
        'user'    => $u,
        'auth'    => $auth,
        'nom'     => $nom,
        'prenom'  => $prenom,
        'photo'   => $photo,
        // Ce qu'on affiche : le prenom si on le connait, le matricule sinon.
        'affiche' => $prenom !== '' ? $prenom : $u,
        'complet' => trim($prenom . ' ' . $nom) ?: $u,
    ];
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
 * Assainit du HTML par ALLOWLIST — 100 % PHP natif, SANS extension (ni DOM ni libxml, absentes
 * sur une passerelle autonome). Tokenise les balises : blocs dangereux supprimés (contenu inclus),
 * balises hors allowlist retirées en gardant leur TEXTE, attributs filtrés (on*, href/src non
 * http(s)/relatifs, styles non sûrs). Contenu rédigé par un admin de confiance, lu par les agents.
 */
function cms_sanitize_html(string $html): string {
    $html = trim($html);
    if ($html === '') { return ''; }

    // 1) Supprimer entièrement (balise + contenu) les éléments dangereux, puis les commentaires
    //    et les balises orphelines de ces éléments.
    $html = preg_replace('#<(script|style|iframe|object|embed|form|textarea|noscript|template|svg|math)\b[^>]*>.*?</\s*\1\s*>#is', '', $html);
    $html = preg_replace('#</?\s*(script|style|iframe|object|embed|form|textarea|noscript|template|svg|math|link|meta|base|input|button|title|head|html|body)\b[^>]*>#is', '', $html);
    $html = preg_replace('#<!--.*?-->#s', '', $html);

    static $allowed = ['p','br','h2','h3','h4','strong','b','em','i','u','s','ul','ol','li','a','img',
        'blockquote','hr','span','div','table','thead','tbody','tr','td','th','caption','figure','figcaption','pre','code','mark','sub','sup'];
    static $selfclosing = ['br','hr','img'];
    static $attrOk = ['href','src','alt','title','colspan','rowspan','style','class','target','rel','loading','width','height'];

    // 2) Réécrire chaque balise en ne gardant que ce qui est autorisé.
    $out = preg_replace_callback('#<(/?)([a-zA-Z][a-zA-Z0-9]*)((?:"[^"]*"|\'[^\']*\'|[^>])*)>#s', function ($m) use ($allowed, $selfclosing, $attrOk) {
        $close = $m[1]; $tag = strtolower($m[2]); $raw = $m[3];
        if (!in_array($tag, $allowed, true)) { return ''; }        // balise interdite : on garde le texte
        if ($close === '/') { return "</$tag>";  }
        $attrs = '';
        if (preg_match_all('#([a-zA-Z][a-zA-Z0-9:-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#s', $raw, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $a) {
                $an = strtolower($a[1]);
                $q  = $a[2];
                $av = ($q !== '' && $q[0] === '"') ? $a[3] : (($q !== '' && $q[0] === "'") ? $a[4] : ($a[5] ?? ''));
                $av = html_entity_decode($av, ENT_QUOTES, 'UTF-8');
                if (stripos($an, 'on') === 0 || !in_array($an, $attrOk, true)) { continue; }
                if ($an === 'href'  && !preg_match('#^(https?:|mailto:|tel:|/|\#)#i', $av)) { continue; }
                if ($an === 'src'   && !preg_match('#^(https?:|/)#i', $av)) { continue; }
                if ($an === 'class' && !preg_match('/^[A-Za-z0-9 _-]{0,60}$/', $av)) { continue; }
                if (in_array($an, ['colspan','rowspan','width','height'], true) && !preg_match('/^\d{1,4}$/', $av)) { continue; }
                if ($an === 'style') { $av = cms_clean_style($av); if ($av === '') { continue; } }
                $attrs .= ' ' . $an . '="' . htmlspecialchars($av, ENT_QUOTES, 'UTF-8') . '"';
            }
        }
        if ($tag === 'a' && stripos($attrs, 'target="_blank"') !== false && stripos($attrs, ' rel=') === false) { $attrs .= ' rel="noopener"'; }
        if ($tag === 'img' && stripos($attrs, ' loading=') === false) { $attrs .= ' loading="lazy"'; }
        return in_array($tag, $selfclosing, true) ? "<$tag$attrs>" : "<$tag$attrs>";
    }, $html);

    return trim((string) $out);
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
    /* ---- Tiroir de navigation (téléphone) ---- */
    .burger{display:none;background:transparent;border:1px solid var(--line);color:var(--text);
      width:38px;height:38px;border-radius:10px;cursor:pointer;font-size:1.1rem;line-height:1;
      align-items:center;justify-content:center;flex:none}
    .burger:active{transform:scale(.94)}
    .drawer-ov{position:fixed;inset:0;background:rgba(3,8,18,.6);backdrop-filter:blur(2px);
      opacity:0;pointer-events:none;transition:opacity .22s ease;z-index:60}
    .drawer-ov.open{opacity:1;pointer-events:auto}
    .drawer{position:fixed;top:0;bottom:0;left:0;width:min(78vw,320px);z-index:61;
      background:#0d1728;border-right:1px solid var(--line);
      transform:translateX(-100%);transition:transform .26s cubic-bezier(.16,1,.3,1);
      display:flex;flex-direction:column;padding:calc(.9rem + env(safe-area-inset-top,0)) 0 1rem}
    .drawer.open{transform:none}
    .drawer .dh{display:flex;align-items:center;gap:.6rem;padding:.2rem 1.1rem 1rem;
      border-bottom:1px solid var(--line);margin-bottom:.5rem}
    .drawer .dh img{width:30px;height:30px;border-radius:8px}
    .drawer .dh .t{font-weight:700}
    .drawer a{display:flex;align-items:center;gap:.7rem;padding:.85rem 1.1rem;color:var(--text);
      text-decoration:none;font-size:.95rem;border-left:3px solid transparent}
    .drawer a:active{background:rgba(56,189,248,.1)}
    .drawer a.on{border-left-color:var(--accent);color:var(--accent);background:rgba(56,189,248,.07)}
    .drawer .sep{height:1px;background:var(--line);margin:.6rem 1.1rem}
    @media(prefers-reduced-motion:reduce){.drawer,.drawer-ov{transition:none}}

    /* ---- Web-app mobile : barre d'onglets + responsive ---- */
    .tabbar{display:none}
    @media(max-width:760px){
      header.top{padding:.7rem 1rem;top:env(safe-area-inset-top,0)}
      header.top .ttl{font-size:1rem}
      /* Le menu horizontal disparaît : il débordait latéralement et volait de la
         hauteur d'écran là où elle est le plus rare. Son contenu passe au tiroir. */
      nav.menu{display:none}
      .burger{display:flex}
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
    <button class="burger" id="burger" aria-label="Ouvrir le menu" aria-expanded="false"
            aria-controls="drawer">☰</button>
    <img src="/portal/assets/bastion-icon.svg" alt="">
    <div class="ttl"><?= e_(intranet_setting('intranet_title', 'Intranet')) ?></div>
    <div class="sp"></div>
    <button class="themebtn" id="themeBtn" aria-label="Changer de thème" title="Thème clair / sombre">🌓</button>
    <?php
    // ── L'EN-TÊTE DIT L'ÉTAT RÉEL DE LA SESSION ────────────────────────────
    // Il affichait « Mon compte » et « Se déconnecter » en toutes circonstances.
    // Or l'intranet est servi PAR la passerelle : il reste consultable sans être
    // authentifié — c'est voulu, un agent doit pouvoir joindre l'assistance même
    // sans accès Internet. Résultat, on cliquait sur « Mon compte » pour s'entendre
    // répondre « Vous n'êtes pas connecté », sans avoir jamais été prévenu.
    // Le portail SAIT si la session est ouverte ; autant le dire.
    $_u = intranet_user();
    ?>
    <?php if (!empty($_u['auth'])): ?>
      <?php if (!empty($_u['user'])): ?>
        <span class="act" style="opacity:.85;display:inline-flex;align-items:center;gap:.45rem" title="Session ouverte">
          <?php if (!empty($_u['photo'])): ?>
            <img src="/portal/photo.php?v=<?= e_($_u['photo']) ?>" alt=""
                 style="width:26px;height:26px;border-radius:50%;object-fit:cover;
                        border:1px solid rgba(56,189,248,.5)">
          <?php else: ?>👤<?php endif; ?>
          <?= e_($_u['complet']) ?>
        </span>
      <?php endif; ?>
      <a class="act" href="/portal/account.php">Mon compte</a>
      <a class="act" href="/portal/logout.php">Se déconnecter</a>
    <?php else: ?>
      <a class="act" href="/portal/fas.php">Se connecter</a>
    <?php endif; ?>
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
  <div class="drawer-ov" id="drawerOv" hidden></div>
  <nav class="drawer" id="drawer" aria-label="Navigation" hidden>
    <div class="dh">
      <img src="/portal/assets/bastion-icon.svg" alt="">
      <span class="t"><?= e_(intranet_setting('intranet_title', 'Intranet')) ?></span>
    </div>
    <a href="/portal/intranet.php" class="<?= $active === 'home' ? 'on' : '' ?>"><span>🏠</span>Accueil</a>
    <?php foreach ($menu as $p): ?>
      <a href="/portal/intranet/page.php?slug=<?= urlencode($p['slug']) ?>" class="<?= $active === $p['slug'] ? 'on' : '' ?>"><span>📄</span><?= e_($p['title']) ?></a>
    <?php endforeach; ?>
    <a href="/portal/intranet/annuaire.php" class="<?= $active === 'annuaire' ? 'on' : '' ?>"><span>👥</span>Annuaire</a>
    <a href="/portal/intranet/assistance.php" class="<?= $active === 'assistance' ? 'on' : '' ?>"><span>🛟</span>Assistance</a>
    <div class="sep"></div>
    <?php if (!empty($_u['auth'])): ?>
      <a href="/portal/account.php"><span>👤</span>Mon compte</a>
      <a href="/portal/logout.php"><span>🚪</span>Se déconnecter</a>
    <?php else: ?>
      <a href="/portal/fas.php"><span>🔑</span>Se connecter</a>
    <?php endif; ?>
  </nav>

  <nav class="tabbar">
    <a href="/portal/intranet.php" class="<?= $active === 'home' ? 'on' : '' ?>"><span class="i">🏠</span>Accueil</a>
    <a href="/portal/intranet/annuaire.php" class="<?= $active === 'annuaire' ? 'on' : '' ?>"><span class="i">👥</span>Annuaire</a>
    <a href="/portal/intranet/assistance.php" class="<?= $active === 'assistance' ? 'on' : '' ?>"><span class="i">🛟</span>Aide</a>
    <?php if (!empty($_u['auth'])): ?>
      <a href="/portal/account.php"><span class="i">👤</span>Compte</a>
    <?php else: ?>
      <a href="/portal/fas.php"><span class="i">🔑</span>Connexion</a>
    <?php endif; ?>
  </nav>
  <main>
    <?php if (empty($_u['auth'])): ?>
      <!-- Ni alarmiste ni muet : l'intranet fonctionne, c'est l'accès Internet qui
           manque. On dit lequel des deux, et où aller pour l'obtenir. -->
      <div style="display:flex;align-items:center;gap:.7rem;background:#2a2412;border:1px solid #a16207;
                  border-radius:12px;padding:.7rem .9rem;margin-bottom:1.1rem;font-size:.88rem">
        <span style="font-size:1.2rem">🔒</span>
        <div style="flex:1;line-height:1.4">
          Vous consultez l'intranet <strong>sans être identifié</strong> : l'annuaire, l'assistance
          et la documentation restent accessibles, mais l'accès à Internet est fermé.
        </div>
        <a href="/portal/fas.php" style="flex:none;background:var(--accent);color:#052536;font-weight:600;
           padding:.45rem .9rem;border-radius:9px;white-space:nowrap">S'identifier</a>
      </div>
    <?php endif; ?>
    <div id="pwaBanner" style="display:none;align-items:center;gap:.7rem;background:#152a45;border:1px solid var(--accent);border-radius:12px;padding:.7rem .9rem;margin-bottom:1.1rem">
      <img src="/portal/assets/icon-192.png" alt="" style="width:34px;height:34px;border-radius:8px;flex:none">
      <div style="flex:1;font-size:.85rem;line-height:1.35"><strong style="color:#fff">Installer l'application</strong><br><span class="muted" id="pwaHint">Accédez à l'intranet en un geste depuis votre écran d'accueil.</span></div>
      <a id="pwaCa" href="/portal/ca.crt.php" style="display:none;flex:none;background:var(--accent);color:#052536;font-weight:600;padding:.45rem .8rem;border-radius:9px;font-size:.85rem;white-space:nowrap;text-decoration:none">Installer le certificat</a>
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
// Le service worker ne s'enregistre QUE sur une origine sûre. Le portail présente un
// certificat de l'autorité Bastion : tant qu'un appareil ne la reconnaît pas, le
// navigateur affiche « Non sécurisé », REFUSE le service worker, et l'installation
// devient impossible. On retient donc l'échec au lieu de l'avaler — la bannière s'en
// sert pour expliquer ce qui manque.
window.__pwaSecure = (window.isSecureContext === true);
if ('serviceWorker' in navigator && window.__pwaSecure) {
  navigator.serviceWorker.register('/portal/sw.js').catch(function () { window.__pwaSecure = false; });
}
(function(){
  var b=document.getElementById('pwaBanner'); if(!b)return;
  var standalone=window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone;
  if(standalone||localStorage.getItem('pwaDismiss')==='1')return;
  var deferred=null, install=document.getElementById('pwaInstall'), close=document.getElementById('pwaClose'), hint=document.getElementById('pwaHint');
  window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();deferred=e;b.style.display='flex';});
  install.addEventListener('click',function(){if(deferred){deferred.prompt();deferred.userChoice.finally(function(){b.style.display='none';deferred=null;});}});
  close.addEventListener('click',function(){b.style.display='none';localStorage.setItem('pwaDismiss','1');});
  if(/iphone|ipad|ipod/i.test(navigator.userAgent)&&!standalone){install.style.display='none';hint.textContent='Appuyez sur Partager puis « Sur l\'écran d\'accueil ».';b.style.display='flex';}
  // ── DIRE CE QUI MANQUE, PLUTÔT QUE DE SE TAIRE ────────────────────────────
  // Sans origine sûre, « beforeinstallprompt » ne se déclenche JAMAIS : la bannière
  // ne s'affichait pas, et l'agent n'avait aucun moyen de comprendre pourquoi son
  // téléphone refusait d'installer l'application. On l'affiche donc précisément dans
  // ce cas, avec la marche à suivre et le certificat à portée de doigt.
  if (!window.__pwaSecure) {
    var ios2 = /iphone|ipad|ipod/i.test(navigator.userAgent);
    install.style.display = 'none';
    var ca = document.getElementById('pwaCa'); if (ca) { ca.style.display = 'inline-block'; }
    hint.innerHTML = "Votre appareil ne reconnaît pas encore la passerelle : installez le certificat "
      + "Bastion, puis rouvrez cette page — l'installation deviendra possible."
      + (ios2 ? " Sur iPhone : R\u00e9glages \u2192 G\u00e9n\u00e9ral \u2192 VPN et gestion des appareils, "
              + "puis R\u00e9glages \u2192 G\u00e9n\u00e9ral \u2192 Informations \u2192 Confiance certificats." : "");
    b.style.display = 'flex';
  }
})();
(function () {
  var b = document.getElementById('burger'), d = document.getElementById('drawer'),
      ov = document.getElementById('drawerOv');
  if (!b || !d || !ov) { return; }
  // « hidden » est retiré à l'ouverture et remis à la fermeture : un tiroir hors
  // écran mais présent dans le document reste atteignable au clavier et lu par les
  // lecteurs d'écran — l'utilisateur tabule alors dans un menu qu'il ne voit pas.
  function ouvrir() {
    d.hidden = false; ov.hidden = false;
    requestAnimationFrame(function () { d.classList.add('open'); ov.classList.add('open'); });
    b.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }
  function fermer() {
    d.classList.remove('open'); ov.classList.remove('open');
    b.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    setTimeout(function () { d.hidden = true; ov.hidden = true; }, 280);
  }
  b.addEventListener('click', function () {
    d.classList.contains('open') ? fermer() : ouvrir();
  });
  ov.addEventListener('click', fermer);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && d.classList.contains('open')) { fermer(); b.focus(); }
  });
  // Retour par le bouton « Précédent » : le navigateur restaure la page telle
  // quelle, tiroir ouvert compris, et le défilement du corps resterait bloqué.
  window.addEventListener('pageshow', function () {
    if (d.classList.contains('open')) { fermer(); }
  });
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
