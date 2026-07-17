<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — API de gestion interrogée par le serveur central (« Bastion Central »).
 * Authentification par token (pf_settings.api_token). Réponses JSON.
 * Servie sur le vhost admin : https://<passerelle>:8443/api.php?action=…&token=…
 */
require_once __DIR__ . '/inc/config.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Bastion-Version: ' . BASTION_VERSION);

function jout($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$db = pf_db();

// ── Authentification par token ───────────────────────────────────────────────
$expected = '';
try { $expected = (string) $db->query("SELECT v FROM pf_settings WHERE k='api_token'")->fetchColumn(); }
catch (Throwable $e) { /* pas de token → refus */ }
$given = '';
$h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/Bearer\s+(\S+)/i', $h, $m)) { $given = $m[1]; }
if ($given === '') { $given = (string) ($_POST['token'] ?? $_GET['token'] ?? ''); }

// Jeton DÉDIÉ aux stations blanches, distinct de celui d'administration.
// Une station est un poste en LIBRE ACCÈS, physiquement exposé : quiconque l'ouvre lit
// sa configuration. Lui confier le jeton d'administration donnerait la main sur les
// comptes, le filtrage et le PXE de la passerelle. Ce jeton-là n'ouvre QUE le dépôt de
// résultats d'analyse, et rien d'autre.
$expectStation = '';
try { $expectStation = (string) $db->query("SELECT v FROM pf_settings WHERE k='station_token'")->fetchColumn(); }
catch (Throwable $e) { }

$estAdmin   = $expected !== '' && $given !== '' && hash_equals($expected, $given);
$estStation = $expectStation !== '' && $given !== '' && hash_equals($expectStation, $given);
if (!$estAdmin && !$estStation) { jout(['error' => 'unauthorized'], 401); }

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

// Une station ne peut appeler QUE ces deux actions. Sans ce garde-fou, le jeton « limité »
// ouvrirait toute l'API : la limitation ne serait qu'une intention.
$actionsStation = ['station.report', 'station.clamdb'];
if ($estStation && !$estAdmin && !in_array($action, $actionsStation, true)) { jout(['error' => 'forbidden'], 403); }
$active = fn(string $u) => trim((string) shell_exec('systemctl is-active ' . escapeshellarg($u) . ' 2>/dev/null'));

switch ($action) {
    /**
     * Base virale ClamAV servie aux stations blanches.
     *
     * La passerelle fait déjà tourner freshclam : elle a la base à jour, en permanence.
     * Les stations viennent la chercher ici, sur le LAN. Une station de commissariat n'a
     * souvent AUCUN accès Internet ; lui demander de joindre les miroirs ClamAV la
     * laisserait travailler avec une base figée au jour de son installation.
     *
     * Sans « file » : l'inventaire. Avec « file » : le fichier lui-même.
     */
    case 'station.clamdb': {
        $dir = '/var/lib/clamav';
        // Liste BLANCHE de noms. Le paramètre vient du réseau : sans cela, « file=../../
        // etc/shadow » servirait n'importe quel fichier lisible par Apache. On ne filtre
        // pas les caractères dangereux, on n'accepte que des noms connus — c'est la seule
        // approche qui ne se fait pas contourner.
        $permis = ['main.cvd', 'main.cld', 'daily.cvd', 'daily.cld', 'bytecode.cvd', 'bytecode.cld'];
        $demande = (string) ($_GET['file'] ?? '');

        if ($demande === '') {
            $liste = [];
            foreach ($permis as $f) {
                $p = $dir . '/' . $f;
                if (!is_file($p) || !is_readable($p)) { continue; }
                $liste[] = [
                    'nom'    => $f,
                    'taille' => (int) filesize($p),
                    'date'   => (int) filemtime($p),
                    // L'empreinte permet à la station de ne retélécharger que ce qui a
                    // changé — main.cvd pèse ~170 Mo et ne bouge que quelques fois par an,
                    // là où daily change plusieurs fois par jour.
                    'sha256' => hash_file('sha256', $p),
                ];
            }
            jout(['ok' => true, 'base' => $liste, 'date_base' => $liste ? max(array_column($liste, 'date')) : 0]);
        }

        if (!in_array($demande, $permis, true)) { jout(['error' => 'fichier inconnu'], 404); }
        $p = $dir . '/' . $demande;
        if (!is_file($p) || !is_readable($p)) { jout(['error' => 'fichier absent'], 404); }

        // Sortie binaire : on remplace l'en-tête JSON posé en tête de fichier.
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($p));
        header('Content-Disposition: attachment; filename="' . $demande . '"');
        header('X-Bastion-Sha256: ' . hash_file('sha256', $p));
        // readfile() diffuse sans charger les 170 Mo en mémoire d'un coup. Le tampon de
        // sortie de PHP, lui, les garderait : on le vide d'abord.
        while (ob_get_level() > 0) { ob_end_clean(); }
        readfile($p);
        exit;
    }

    // Dépôt d'un résultat d'analyse par une station blanche.
    case 'station.report': {
        $poste   = substr(trim((string) ($_POST['poste'] ?? '')), 0, 64);
        $support = substr(trim((string) ($_POST['support'] ?? '')), 0, 200);
        $op      = substr(trim((string) ($_POST['operateur'] ?? '')), 0, 64);
        $nb      = max(0, (int) ($_POST['menaces'] ?? 0));
        $fic     = max(0, (int) ($_POST['fichiers'] ?? 0));
        $detail  = substr(trim((string) ($_POST['detail'] ?? '')), 0, 4000);
        $abouti  = ($_POST['abouti'] ?? '1') === '1';
        if ($poste === '') { jout(['error' => 'poste manquant'], 400); }

        // On réutilise pf_avscan, déjà en place pour les analyses de la passerelle :
        // les deux origines se lisent au même endroit. « launched_by » distingue la
        // station de l'administrateur.
        $st = $db->prepare('INSERT INTO pf_avscan (path, scanned, infected, detail, launched_by)
                            VALUES (?,?,?,?,?)');
        $st->execute([
            'Station ' . $poste . ' — ' . ($support ?: 'support inconnu'),
            $fic,
            $abouti ? $nb : -1,   // -1 = analyse NON aboutie : ni saine, ni infectée.
            ($abouti ? '' : "ANALYSE NON ABOUTIE
") . $detail,
            'station:' . ($op ?: 'inconnu'),
        ]);
        jout(['ok' => true, 'id' => (int) $db->lastInsertId()]);
    }

    case 'status':
        $svc = [];
        foreach (['opennds', 'freeradius', 'mariadb', 'apache2', 'dnsmasq', 'chrony', 'proxyfibre-weblog'] as $u) {
            $svc[$u] = $active($u) === 'active' ? 'ok' : ($active($u) === 'failed' ? 'failed' : 'down');
        }
        $wg = trim((string) shell_exec('systemctl show -p Result --value proxyfibre-walledgarden 2>/dev/null'));
        $svc['proxyfibre-walledgarden'] = ($wg === 'success' || $wg === '') ? 'ok' : 'failed';
        $clients = nds_clients(); $auth = 0;
        foreach ($clients as $c) { if (($c['state'] ?? '') === 'Authenticated') { $auth++; } }
        $set = [];
        try { foreach ($db->query('SELECT k,v FROM pf_settings') as $r) { $set[$r['k']] = $r['v']; } } catch (Throwable $e) {}
        jout([
            'ok'            => true,
            'host'          => php_uname('n'),
            'version'       => BASTION_VERSION,
            'time'          => date('c'),
            'uptime'        => trim((string) shell_exec('uptime -p 2>/dev/null')),
            'load'          => trim((string) shell_exec("cat /proc/loadavg 2>/dev/null | awk '{print $1,$2,$3}'")),
            'services'      => $svc,
            'services_ok'   => count(array_filter($svc, fn($s) => $s === 'ok')),
            'services_total'=> count($svc),
            'sessions'      => $auth,
            'clients'       => count($clients),
            'users'         => (int) $db->query('SELECT COUNT(DISTINCT username) FROM radcheck')->fetchColumn(),
            'blocklist'     => (int) $db->query('SELECT COUNT(*) FROM pf_blocklist')->fetchColumn(),
            'adblock'       => ($set['adblock_enabled'] ?? '0') === '1',
            'adblock_count' => (int) ($set['adblock_count'] ?? 0),
        ]);

    case 'service':
        $svc = (string) ($_POST['svc'] ?? '');
        $do  = (string) ($_POST['do'] ?? '');
        if (!in_array($do, ['start', 'stop', 'restart', 'reload'], true)) { jout(['error' => 'action invalide'], 400); }
        $out = shell_exec('sudo /usr/local/sbin/proxyfibre-service ' . escapeshellarg($do) . ' ' . escapeshellarg($svc) . ' 2>&1');
        jout(['ok' => true, 'result' => trim((string) $out)]);

    case 'filter.list':
        $rows = $db->query('SELECT domain,category FROM pf_blocklist ORDER BY domain')->fetchAll();
        jout(['ok' => true, 'count' => count($rows), 'domains' => $rows]);

    case 'filter.add':
        $cat = trim((string) ($_POST['category'] ?? 'central')) ?: 'central';
        $st  = $db->prepare('INSERT IGNORE INTO pf_blocklist (domain,category,added_by) VALUES (?,?,?)');
        $n = 0;
        foreach (preg_split('/\s+/', (string) ($_POST['domains'] ?? '')) as $d) {
            $d = strtolower(trim($d));
            $d = preg_replace(['#^https?://#', '#/.*$#', '#^www\.#'], '', $d);
            if (preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $d)) { $st->execute([$d, $cat, 'central']); $n += $st->rowCount(); }
        }
        shell_exec('sudo /usr/local/sbin/proxyfibre-apply-filter 2>/dev/null');
        jout(['ok' => true, 'added' => $n]);

    case 'users.list':
        $rows = $db->query('SELECT c.username,
            (SELECT groupname FROM radusergroup g WHERE g.username=c.username LIMIT 1) grp
            FROM radcheck c WHERE c.attribute="Cleartext-Password" ORDER BY c.username')->fetchAll();
        jout(['ok' => true, 'count' => count($rows), 'users' => $rows]);

    case 'users.add':
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        $g = trim((string) ($_POST['groupname'] ?? ''));
        if ($u === '' || $p === '') { jout(['error' => 'username + password requis'], 400); }
        $db->prepare('DELETE FROM radcheck WHERE username=? AND attribute="Cleartext-Password"')->execute([$u]);
        $db->prepare('INSERT INTO radcheck (username,attribute,op,value) VALUES (?,"Cleartext-Password",":=",?)')->execute([$u, $p]);
        $db->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$u]);
        if ($g !== '') { $db->prepare('INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)')->execute([$u, $g]); }
        jout(['ok' => true, 'user' => $u]);

    case 'pxe.get':
        $s = [];
        foreach ($db->query("SELECT k,v FROM pf_settings WHERE k LIKE 'pxe\\_%'") as $r) { $s[$r['k']] = $r['v']; }
        jout(['ok' => true, 'pxe' => $s]);

    case 'pxe.set':
        $up = $db->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
        $allowed = ['pxe_menu_title', 'pxe_timeout', 'pxe_default', 'pxe_protected',
                    'pxe_debian_enabled', 'pxe_ubuntu_enabled', 'pxe_windows_enabled', 'pxe_local_enabled', 'pxe_shell_enabled'];
        $n = 0;
        foreach ($allowed as $k) {
            if (isset($_POST[$k])) { $up->execute([$k, trim(str_replace(["\r", "\n"], ' ', (string) $_POST[$k]))]); $n++; }
        }
        jout(['ok' => true, 'updated' => $n]);

    case 'ad.status': {
        $dc = trim((string) shell_exec('systemctl is-active samba-ad-dc 2>/dev/null')) === 'active';
        if (!$dc) { jout(['ok' => true, 'dc' => false]); }
        $ad = fn(...$a) => (string) shell_exec('sudo /usr/local/sbin/proxyfibre-ad '
            . implode(' ', array_map('escapeshellarg', $a)) . ' 2>&1');
        $lines = fn($s) => array_values(array_filter(array_map('trim', explode("\n", $s)), fn($l) => $l !== ''));
        $users = $lines($ad('user', 'list'));
        $sysu  = ['Administrator', 'Guest', 'krbtgt'];
        $fonc  = array_values(array_filter($users, fn($u) => !in_array($u, $sysu, true) && stripos($u, 'dns-') !== 0));
        $gpos = [];
        foreach (explode("\n", $ad('gpo', 'list')) as $l) { if (preg_match('/display name\s*:\s*(.+)/i', $l, $m)) { $gpos[] = trim($m[1]); } }
        $shares = [];
        foreach (explode("\n", $ad('share', 'list')) as $l) { if (preg_match('/^\[([^\]]+)\]/', trim($l), $m)) { $shares[] = $m[1]; } }
        jout([
            'ok' => true, 'dc' => true,
            'fonctionnaires' => count($fonc),
            'ordinateurs'    => count($lines($ad('computer', 'list'))),
            'gpo'            => count($gpos),
            'partages'       => count($shares),
        ]);
    }

    case 'ad.user.add': {
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        $g = trim((string) ($_POST['groupname'] ?? ''));
        if ($u === '' || $p === '') { jout(['error' => 'username + password requis'], 400); }
        $out = shell_exec('sudo /usr/local/sbin/proxyfibre-ad user create ' . escapeshellarg($u) . ' ' . escapeshellarg($p) . ' 2>&1');
        if ($g !== '') { shell_exec('sudo /usr/local/sbin/proxyfibre-ad group addmembers ' . escapeshellarg($g) . ' ' . escapeshellarg($u) . ' 2>&1'); }
        $err = preg_match('/ERROR|Failed|refuse|Traceback/i', (string) $out);
        jout($err ? ['error' => trim((string) $out)] : ['ok' => true, 'user' => $u], $err ? 400 : 200);
    }

    case 'ad.user.list': {
        $out = (string) shell_exec('sudo /usr/local/sbin/proxyfibre-ad user list 2>&1');
        $users = array_values(array_filter(array_map('trim', explode("\n", $out)), fn($l) => $l !== ''));
        jout(['ok' => true, 'users' => $users]);
    }

    default:
        jout(['error' => 'action inconnue',
              'actions' => ['status', 'service', 'filter.list', 'filter.add', 'users.list', 'users.add',
                            'pxe.get', 'pxe.set', 'ad.status', 'ad.user.add', 'ad.user.list']], 400);
}
