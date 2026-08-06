<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Résolution des sources du store d'applications.
 *
 * Le catalogue contenait deux familles d'URL qui ne pouvaient pas tenir dans le temps :
 *
 *  1. des versions FIGÉES (« LibreOffice_24.8.4_Win_x86-64.msi »). L'éditeur retire les
 *     anciennes versions de « stable/ » : l'URL devient un 404 quelques mois plus tard.
 *     Constaté le 2026-08-05 sur LibreOffice — la panne signalée par l'utilisateur.
 *
 *  2. des liens « …/releases/latest » vers GitHub. Ceux-là sont PIRE qu'un 404 : GitHub
 *     répond 200 avec une PAGE HTML de 210 Ko. curl réussit, le fichier fait plus de
 *     10 Ko, le store l'enregistre comme installeur — et chaque poste tente d'exécuter
 *     une page web. Aucune erreur nulle part. 25 entrées du catalogue étaient dans ce cas.
 *
 * D'où ce module : une URL du catalogue peut désormais être une SOURCE À RÉSOUDRE
 * (« github:proprio/depot », « libreoffice: »), et la résolution rend une URL de
 * fichier réel, choisie au moment du clic. Ce qui suit la version de l'éditeur ne peut
 * plus pourrir.
 */

/**
 * Appelle curl sans jamais faire passer l'URL par le shell.
 *
 * L'URL est écrite dans un fichier d'options « curl -K ». Raison : sous Windows,
 * escapeshellarg() remplace « % » par une espace pour empêcher l'expansion de variables,
 * ce qui transforme « KeePass%202.x » en « KeePass 202.x » et fait rejeter l'URL. Le
 * projet s'est déjà fait prendre trois fois par une histoire de guillemets (redirection
 * sous sudo, « \b » avalé par echo, et celle-ci) : on supprime la classe de bogue plutôt
 * que de la contourner une fois de plus.
 *
 * $options est une LISTE de paires [nom, valeur] — pas un tableau associatif : curl
 * accepte plusieurs fois la même option (« header »), ce qu'une clé unique interdirait.
 * Une valeur à true écrit l'option seule (drapeau).
 *
 * Rend ['rc'=>code de sortie curl, 'sortie'=>texte reçu ou message d'erreur].
 */
function app_src_curl(array $options): array
{
    $conf = tempnam(sys_get_temp_dir(), 'bastion-curl');
    if ($conf === false) {
        return ['rc' => 1, 'sortie' => 'impossible de créer le fichier d\'options curl'];
    }
    $lignes = [];
    foreach ($options as [$nom, $val]) {
        // Guillemets et contre-obliques échappés : la syntaxe du fichier -K les interprète.
        $lignes[] = $val === true ? $nom
                  : $nom . ' = "' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $val) . '"';
    }
    file_put_contents($conf, implode("\n", $lignes) . "\n");

    $o = []; $rc = 1;
    exec('curl -K ' . escapeshellarg($conf) . ' 2>&1', $o, $rc);
    @unlink($conf);
    return ['rc' => $rc, 'sortie' => trim(implode("\n", $o))];
}

/** Options communes à tous les appels du store. */
function app_src_opts(string $url, bool $strict = true, string $maxTime = '25'): array
{
    $o = [['url', $url], ['silent', true], ['show-error', true], ['location', true],
          ['max-time', $maxTime], ['user-agent', 'Bastion-Store/1.0']];
    if ($strict) {
        $o[] = ['fail', true];
    }
    return $o;
}

/** Récupère une URL en texte. Rend null en cas d'échec (le motif est rendu à l'appelant). */
function app_src_get(string $url, array $entetes = [], bool $strict = true): ?string
{
    $opt = app_src_opts($url, $strict);
    foreach ($entetes as $e) {
        $opt[] = ['header', $e];
    }
    $r = app_src_curl($opt);
    return $r['sortie'] !== '' ? $r['sortie'] : null;
}

/**
 * Télécharge une URL vers un fichier. Rend ['rc'=>…, 'sortie'=>…].
 * Passe par le même fichier d'options : l'URL ne traverse aucun shell.
 */
function app_src_telecharger(string $url, string $dest, string $maxTime = '400'): array
{
    $opt = app_src_opts($url, true, $maxTime);
    $opt[] = ['output', $dest];
    $opt[] = ['write-out', '%{http_code}'];
    return app_src_curl($opt);
}

/**
 * Note un nom de fichier d'archive de publication : est-ce l'installeur Windows 64 bits ?
 * Rend -1 pour « à écarter », sinon un score (plus c'est haut, mieux c'est).
 *
 * On écarte explicitement plutôt que de « prendre le premier » : une publication contient
 * couramment des sources, des symboles de débogage, des versions macOS/Linux, ARM et
 * portables. Prendre le premier venu, c'est déployer un .dmg sur un poste Windows.
 */
function app_src_note(string $nom, bool $veutMsi): int
{
    $n = strtolower($nom);

    // Écarts durs : rien de tout cela ne s'installe en silence sur un poste Windows x64.
    $rebuts = ['linux', 'macos', 'darwin', 'osx', '.dmg', '.deb', '.rpm', '.appimage', '.tar',
               '.zip', '.sha256', '.sha512', '.asc', '.sig', '.txt', '.json', '.xml', '.blockmap',
               'arm64', 'aarch64', 'armv7', 'symbols', 'dsym', 'debug', 'sources', '-src',
               'portable', 'x86.exe', 'win32', 'ia32', 'i686'];
    foreach ($rebuts as $r) {
        if (strpos($n, $r) !== false) {
            return -1;
        }
    }
    $estMsi = substr($n, -4) === '.msi';
    $estExe = substr($n, -4) === '.exe';
    if (!$estMsi && !$estExe) {
        return -1;
    }

    $s = 0;
    // L'extension doit correspondre à ce que le catalogue déclare : les arguments
    // silencieux ne sont pas les mêmes (« /qn » pour un MSI, « /S » pour un EXE).
    // Un MSI installé avec « /S » ne fait rien, sans le dire.
    if ($veutMsi === $estMsi) {
        $s += 100;
    }
    foreach (['x64' => 30, 'win64' => 30, 'amd64' => 30, 'x86_64' => 30, 'win' => 10] as $m => $p) {
        if (strpos($n, $m) !== false) { $s += $p; break; }
    }
    foreach (['setup', 'install'] as $m) {
        if (strpos($n, $m) !== false) { $s += 15; break; }
    }
    return $s;
}

/** Dernière publication GitHub : rend l'URL de l'installeur Windows, ou une erreur parlante. */
function app_src_github(string $depot, bool $veutMsi): array
{
    // Sans « -f » : on veut LIRE le corps de la réponse même sur une erreur HTTP. Avec -f,
    // un 403 de dépassement de quota devenait un échec muet, rapporté comme « GitHub
    // injoignable » — l'administrateur cherchait un problème de réseau qui n'existait pas.
    // Cache d'une heure. L'API GitHub n'accorde que 60 requêtes par heure et par adresse
    // sans authentification ; le script de vérification du catalogue en consomme 24 d'un
    // coup, et deux passages suffisaient à tout bloquer. Un contrôle qu'on ne peut lancer
    // que deux fois par heure finit par ne plus être lancé du tout.
    $cache = sys_get_temp_dir() . '/bastion-gh-' . md5($depot) . '.json';
    $j = (is_file($cache) && time() - filemtime($cache) < 3600)
        ? (string) file_get_contents($cache)
        : null;
    if ($j === null) {
        $j = app_src_get('https://api.github.com/repos/' . $depot . '/releases/latest',
                         ['Accept: application/vnd.github+json'], false);
        // On ne met en cache qu'une réponse EXPLOITABLE : garder un message de quota
        // pendant une heure reviendrait à se bloquer soi-même.
        if ($j !== null && strpos($j, '"assets"') !== false) {
            @file_put_contents($cache, $j);
        }
    }
    if ($j === null) {
        return ['err' => 'GitHub injoignable pour « ' . $depot . ' » (réseau ou DNS).'];
    }
    $d = json_decode($j, true);
    if (is_array($d) && isset($d['message']) && stripos((string) $d['message'], 'rate limit') !== false) {
        return ['err' => 'Quota de l\'API GitHub atteint (60 requêtes par heure et par adresse). '
                       . 'Réessayez dans un moment, ou déposez l\'installeur à la main.'];
    }
    if (!is_array($d) || empty($d['assets']) || !is_array($d['assets'])) {
        $m = is_array($d) && isset($d['message']) ? (string) $d['message'] : 'réponse inattendue';
        return ['err' => 'Aucune publication exploitable sur « ' . $depot . ' » (' . $m . ').'];
    }

    $best = null; $bestS = 0; $vus = [];
    foreach ($d['assets'] as $a) {
        $nom = (string) ($a['name'] ?? '');
        $s = app_src_note($nom, $veutMsi);
        if ($nom !== '') { $vus[] = $nom; }
        if ($s > $bestS) { $bestS = $s; $best = $a; }
    }
    if ($best === null) {
        // On DIT ce qu'on a vu : sans cela, « rien trouvé » n'aide personne à corriger.
        $ex = implode(', ', array_slice($vus, 0, 4));
        return ['err' => 'Aucun installeur Windows 64 bits dans la dernière publication de « '
                       . $depot . ' ». Fichiers proposés : ' . ($ex ?: 'aucun') . '.'];
    }
    return [
        'url'     => (string) $best['browser_download_url'],
        'version' => (string) ($d['tag_name'] ?? ''),
        // Signalé, pas caché : l'extension obtenue ne correspond pas aux arguments déclarés.
        'avert'   => $bestS < 100 ? 'L\'installeur trouvé (' . $best['name'] . ') n\'est pas du type attendu ; '
                                  . 'vérifiez les arguments d\'installation silencieuse.' : '',
    ];
}

/**
 * Éditeur publiant dans un index de répertoires numérotés par version
 * (LibreOffice, Tor…). On lit l'index, on retient la version la plus haute, et on
 * fabrique l'URL du fichier. Spécification : « index:<index>|<gabarit> », où le gabarit
 * contient {v} et est relatif à l'index.
 */
function app_src_index(string $spec): array
{
    [$base, $gabarit] = array_pad(explode('|', $spec, 2), 2, '');
    if ($base === '' || $gabarit === '' || strpos($gabarit, '{v}') === false) {
        return ['err' => 'Source « index: » mal formée (il faut « index:<url>|<gabarit avec {v}> »).'];
    }
    $h = app_src_get($base);
    if ($h === null) {
        return ['err' => 'Index de l\'éditeur injoignable (' . $base . ').'];
    }
    if (!preg_match_all('~>([0-9]+(?:\.[0-9]+){1,3})/~', $h, $m)) {
        return ['err' => 'Aucune version lisible dans l\'index (' . $base . ').'];
    }
    $vs = array_values(array_unique($m[1]));
    usort($vs, 'version_compare');
    $v = (string) end($vs);
    return ['url' => $base . str_replace('{v}', $v, $gabarit), 'version' => $v, 'avert' => ''];
}

/**
 * Index à DEUX niveaux : un répertoire par version, puis un sous-répertoire par système,
 * et un nom de fichier qu'on ne peut pas deviner (KDE : « 26.04/windows/kdenlive-26.04.2.exe »).
 * On lit les deux index et on choisit le fichier au score, en départageant par la version
 * la plus haute — sans quoi on récupérerait la première publication du cycle, pas la dernière.
 * Spécification : « dirscan:<index>|<sous-chemin> ».
 */
function app_src_dirscan(string $spec, bool $veutMsi): array
{
    [$base, $sous] = array_pad(explode('|', $spec, 2), 2, '');
    if ($base === '') {
        return ['err' => 'Source « dirscan: » mal formée.'];
    }
    $h = app_src_get($base);
    if ($h === null) {
        return ['err' => 'Index de l\'éditeur injoignable (' . $base . ').'];
    }
    if (!preg_match_all('~>([0-9]+(?:\.[0-9]+){1,3})/~', $h, $m)) {
        return ['err' => 'Aucune version lisible dans l\'index (' . $base . ').'];
    }
    $vs = array_values(array_unique($m[1]));
    usort($vs, 'version_compare');
    $v = (string) end($vs);

    $rep = $base . $v . '/' . ($sous !== '' ? rtrim($sous, '/') . '/' : '');
    $l = app_src_get($rep);
    if ($l === null) {
        return ['err' => 'Répertoire de version injoignable (' . $rep . ').'];
    }
    if (!preg_match_all('~"([^"/]+\.(?:exe|msi))"~i', $l, $f)) {
        return ['err' => 'Aucun installeur listé dans ' . $rep . '.'];
    }

    $best = null; $bestS = 0; $bestV = '0';
    foreach (array_unique($f[1]) as $nom) {
        $s = app_src_note($nom, $veutMsi);
        if ($s <= 0) {
            continue;
        }
        preg_match('~([0-9]+(?:\.[0-9]+){1,3})~', $nom, $mv);
        $nv = $mv[1] ?? '0';
        // Score d'abord, version ensuite : un installeur du bon type mais plus ancien
        // reste préférable à un fichier du mauvais type plus récent.
        if ($s > $bestS || ($s === $bestS && version_compare($nv, $bestV, '>'))) {
            $bestS = $s; $bestV = $nv; $best = $nom;
        }
    }
    if ($best === null) {
        return ['err' => 'Aucun installeur Windows 64 bits dans ' . $rep . '.'];
    }
    return ['url' => $rep . $best, 'version' => $bestV, 'avert' => ''];
}

/**
 * Application empaquetée avec electron-builder : la version du jour est publiée dans un
 * manifeste « latest.yml » à côté des fichiers. On la lit et on fabrique le nom du fichier.
 *
 * Attention : la clé « path » du manifeste ne désigne PAS forcément la version 64 bits
 * (chez Signal elle pointe sur l'ARM64). On ne prend donc que le numéro de version, et
 * le gabarit dit explicitement quelle architecture on veut.
 * Spécification : « electronyml:<base>|<gabarit avec {v}> ».
 */
function app_src_electronyml(string $spec): array
{
    [$base, $gabarit] = array_pad(explode('|', $spec, 2), 2, '');
    if ($base === '' || strpos($gabarit, '{v}') === false) {
        return ['err' => 'Source « electronyml: » mal formée (gabarit avec {v} attendu).'];
    }
    $y = app_src_get($base . 'latest.yml');
    if ($y === null) {
        return ['err' => 'Manifeste latest.yml injoignable (' . $base . ').'];
    }
    if (!preg_match('~^version:\s*([0-9][^\s]*)~mi', $y, $m)) {
        return ['err' => 'Aucune version lisible dans ' . $base . 'latest.yml.'];
    }
    return ['url' => $base . str_replace('{v}', $m[1], $gabarit), 'version' => $m[1], 'avert' => ''];
}

/**
 * Projet hébergé sur SourceForge.
 *
 * On n'utilise PAS « files/latest/download » : ce raccourci rend le fichier le plus
 * récent du projet, qui n'est pas forcément un installeur. Pour KeePass il rendait
 * « KeePass-2.61.1.zip » — une archive, que la GPO ne sait pas installer, et que rien
 * ne signalait. On lit donc le flux RSS des fichiers et on choisit un vrai installeur.
 * Spécification : « sourceforge:<projet>[|<chemin>] ».
 */
function app_src_sourceforge(string $spec, bool $veutMsi): array
{
    [$projet, $chemin] = array_pad(explode('|', $spec, 2), 2, '/');
    // Espaces encodés en « + » et non en « %20 » : sous un shell Windows, escapeshellarg
    // remplace « % » par une espace pour empêcher l'expansion de variables, ce qui rendait
    // l'URL malformée. Dans une chaîne de requête, « + » vaut espace — et passe partout.
    $rss = 'https://sourceforge.net/projects/' . rawurlencode($projet) . '/rss?path='
         . str_replace(['%2F', '%20'], ['/', '+'], rawurlencode($chemin));
    $x = app_src_get($rss);
    if ($x === null) {
        return ['err' => 'Flux SourceForge injoignable pour « ' . $projet . ' ».'];
    }
    if (!preg_match_all('~<link>([^<]+)</link>~', $x, $m)) {
        return ['err' => 'Aucun fichier listé pour « ' . $projet . ' » sur SourceForge.'];
    }

    // Le flux est trié du plus récent au plus ancien : on garde le premier meilleur score,
    // donc la version la plus récente à qualité de correspondance égale.
    $best = null; $bestS = 0; $vus = [];
    foreach ($m[1] as $lien) {
        $nom = rawurldecode(basename(rtrim(str_replace('/download', '', $lien), '/')));
        $s = app_src_note($nom, $veutMsi);
        if ($s > 0 && count($vus) < 6) { $vus[] = $nom; }
        if ($s > $bestS) { $bestS = $s; $best = ['url' => $lien, 'nom' => $nom]; }
    }
    if ($best === null) {
        return ['err' => 'Aucun installeur Windows 64 bits dans le flux SourceForge de « '
                       . $projet . ' ».'];
    }
    return [
        'url'     => $best['url'],
        'version' => '',
        'avert'   => $bestS < 100 ? 'L\'installeur trouvé (' . $best['nom'] . ') n\'est pas du type '
                                  . 'attendu ; vérifiez les arguments d\'installation silencieuse.' : '',
    ];
}

/**
 * Résout l'URL d'une entrée de catalogue.
 * Rend ['url'=>…, 'version'=>…, 'avert'=>…] ou ['err'=>…].
 */
function app_src_resoudre(array $c): array
{
    $u = (string) ($c['url'] ?? '');
    $msi = (bool) ($c['msi'] ?? false);

    // Source connue pour ne pas fournir d'installeur récupérable automatiquement
    // (page de téléchargement dynamique, archive ZIP, paquet MSIX…). On le dit au lieu
    // de proposer un bouton qui échouera.
    if (!empty($c['manuel'])) {
        return ['err' => 'cette source ne publie pas d\'installeur téléchargeable directement'
                       . (($c['manuel'] !== true) ? ' (' . $c['manuel'] . ')' : '')
                       . ' — déposez l\'installeur avec « Ajouter une application ».'];
    }
    if (strncmp($u, 'github:', 7) === 0) {
        return app_src_github(substr($u, 7), $msi);
    }
    if (strncmp($u, 'sourceforge:', 12) === 0) {
        return app_src_sourceforge(substr($u, 12), $msi);
    }
    if (strncmp($u, 'index:', 6) === 0) {
        return app_src_index(substr($u, 6));
    }
    if (strncmp($u, 'dirscan:', 8) === 0) {
        return app_src_dirscan(substr($u, 8), $msi);
    }
    if (strncmp($u, 'electronyml:', 12) === 0) {
        return app_src_electronyml(substr($u, 12));
    }
    if ($u === '') {
        return ['err' => 'Entrée de catalogue sans source.'];
    }
    return ['url' => $u, 'version' => '', 'avert' => ''];
}

/**
 * Le fichier téléchargé est-il vraiment un installeur Windows ?
 *
 * C'est le garde-fou qui manquait. Un « code de retour 0 » et « plus de 10 Ko » ne
 * prouvent rien : une page d'erreur HTML coche les deux cases. On lit donc les premiers
 * octets — un MSI est un conteneur OLE, un EXE commence par « MZ ».
 * Rend '' si le fichier est bon, sinon la raison du rejet.
 */
function app_src_verifier(string $chemin, bool $veutMsi): string
{
    $f = @fopen($chemin, 'rb');
    if ($f === false) {
        return 'fichier illisible après téléchargement';
    }
    $tete = (string) fread($f, 8);
    fclose($f);

    $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";   // conteneur OLE : MSI, et aussi .doc/.xls
    $mz  = "MZ";                                  // exécutable Windows (PE)

    if (strncmp($tete, $ole, 8) === 0) {
        return $veutMsi ? '' : 'un paquet MSI a été reçu alors qu\'un exécutable était attendu '
                             . '(les arguments d\'installation silencieuse ne conviendront pas)';
    }
    if (strncmp($tete, $mz, 2) === 0) {
        return $veutMsi ? 'un exécutable a été reçu alors qu\'un paquet MSI était attendu '
                        . '(les arguments d\'installation silencieuse ne conviendront pas)' : '';
    }
    // Le cas qui passait inaperçu : une page web enregistrée comme installeur.
    $t = ltrim(substr($tete, 0, 8));
    if (stripos($t, '<') === 0) {
        return 'une page web a été reçue à la place de l\'installeur — la source ne fournit '
             . 'pas de fichier à cette adresse';
    }
    return 'le fichier reçu n\'est ni un exécutable Windows ni un paquet MSI';
}
