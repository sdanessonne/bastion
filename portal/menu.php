<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — menu PXE dynamique, paramétré depuis la console admin.
 *
 * Sert le menu iPXE construit d'après les réglages (table pf_settings, clés pxe_*)
 * et gère la protection par IDENTIFIANTS ADMINISTRATEUR (validés contre pf_admins).
 * Toute la logique (login + menu) est côté serveur : boot.ipxe se contente de
 * régler l'affichage puis d'enchaîner sur ce script.
 * Servi sur le port 2080, déployé en /var/www/html/boot/menu.php.
 * Identifiants de base lus hors webroot dans /etc/proxyfibre/admin.env.
 */
header('Content-Type: text/plain; charset=utf-8');

$gw   = explode(':', $_SERVER['HTTP_HOST'] ?? '192.168.182.1:2080')[0];
$base = "http://{$gw}:2080/boot";

// ── Réglages par défaut (surchargés par pf_settings) ─────────────────────────
$S = [
    'pxe_menu_title'      => 'Bastion  -  Installation par le reseau',
    'pxe_timeout'         => '60',
    'pxe_login_timeout'   => '30',   // écran d'identification → disque local (0 = attendre)
    'pxe_default'         => 'local',
    'pxe_protected'       => '1',
    'pxe_debian_enabled'  => '1', 'pxe_debian_label'  => '[  Debian     ]  Installation reseau',
    'pxe_ubuntu_enabled'  => '1', 'pxe_ubuntu_label'  => '[  Ubuntu     ]  26.04 Desktop',
    'pxe_windows_enabled' => '1', 'pxe_windows_label' => '[  Windows 11 ]  25H2',
    'pxe_local_enabled'   => '1', 'pxe_local_label'   => 'Demarrer sur le disque local',
    'pxe_shell_enabled'   => '1', 'pxe_shell_label'   => 'Console iPXE (avance)',
    'pxe_debian_args'     => 'vga=788 --- quiet',
    'pxe_ubuntu_args'     => 'boot=casper ip=dhcp url=http://{IP}:2080/iso/ubuntu.iso ---',
];

$env = [];
foreach (@file('/etc/proxyfibre/admin.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    if (preg_match('/^(\w+)="?([^"]*)"?$/', $l, $m)) { $env[$m[1]] = $m[2]; }
}
$pdo = null;
try {
    $dsn = sprintf('mysql:host=localhost;dbname=%s;charset=utf8mb4', $env['DB_NAME'] ?? 'radius');
    $pdo = new PDO($dsn, $env['DB_USER'] ?? 'radius', $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ($pdo->query("SELECT k,v FROM pf_settings WHERE k LIKE 'pxe\\_%'") as $r) { $S[$r['k']] = $r['v']; }
} catch (Throwable $e) { /* réglages par défaut */ }

// Nettoyage anti-injection pour le texte iPXE (pas de saut de ligne).
$clean = fn($s) => trim(str_replace(["\r", "\n"], ' ', (string) $s));

// ── Protection : login géré côté serveur ─────────────────────────────────────
/**
 * Porte d'entrée avant le menu.
 *
 * On passe par un MENU iPXE plutôt que par un `login` nu, car `login` attend
 * INDÉFINIMENT : un poste amorcé en PXE sans personne devant lui resterait bloqué
 * sur la demande d'identifiants au lieu de démarrer sur son disque.
 * `choose --timeout` affiche un décompte « (30) » à droite de l'entrée sélectionnée
 * (iPXE hci/tui/menu_ui.c) et TOUTE frappe le remet à zéro : on ne risque pas d'être
 * éjecté en train de taper son mot de passe. Une fois « Ouvrir une session » choisi,
 * le formulaire n'a plus aucun délai.
 *
 * Le défaut DOIT rester « local » : à l'expiration, `choose` valide l'entrée
 * SÉLECTIONNÉE — mettre « login » par défaut lancerait le login sur expiration.
 *
 * PAS D'APOSTROPHE dans les libellés : iPXE traite la quote comme un guillemet
 * ouvrant et avalerait la fin de la ligne. (D'où « Ouvrir une session » et non
 * « S'identifier ».)
 *
 * @param int $timeout Délai en secondes avant démarrage disque ; 0 = attendre.
 */
function login_prompt(string $base, int $timeout, string $msg = ''): void {
    echo "#!ipxe\n";
    if ($msg !== '') { echo "echo {$msg}\nsleep 2\n"; }
    echo ":gate\n";
    echo "menu Bastion  -  Installation par le reseau\n";
    echo "item --gap    Acces reserve aux administrateurs\n";
    echo "item login    Ouvrir une session administrateur\n";
    echo "item local    Demarrer sur le disque local\n";
    $opt = ($timeout > 0) ? " --timeout " . ($timeout * 1000) : '';
    echo "choose --default local{$opt} target && goto \${target} || goto local\n\n";
    echo ":login\n";
    echo "login\n";
    echo "params\n";
    echo "param username \${username}\n";
    echo "param password \${password}\n";
    echo "chain --replace {$base}/menu.php##params || shell\n\n";
    echo ":local\n";
    echo "sanboot --no-describe --drive 0x80 || exit\n";
    exit;
}

// Aperçu depuis la console admin : seul le serveur lui-même (localhost) peut
// demander un rendu sans authentification (?preview=1).
$isPreview = (($_GET['preview'] ?? '') === '1')
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

if (($S['pxe_protected'] ?? '1') === '1' && !$isPreview) {
    $lto = max(0, min(3600, (int) ($S['pxe_login_timeout'] ?? 30)));
    $attempt = isset($_POST['password']) || isset($_GET['password'])
            || isset($_POST['username']) || isset($_GET['username']);
    if (!$attempt) { login_prompt($base, $lto); }        // premier passage → demande login
    $user = trim((string) ($_POST['username'] ?? $_GET['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? $_GET['password'] ?? '');
    $ok = false;
    if ($pdo && $user !== '' && $pass !== '') {
        try {
            $st = $pdo->prepare('SELECT password_hash FROM pf_admins WHERE username = ?');
            $st->execute([$user]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && password_verify($pass, $row['password_hash'])) { $ok = true; }
        } catch (Throwable $e) { $ok = false; }
    }
    if (!$ok) { login_prompt($base, $lto, '  Identifiants administrateur incorrects.'); }
}

// ── Construction du menu ─────────────────────────────────────────────────────
$title   = $clean($S['pxe_menu_title']) ?: 'Bastion';
$timeout = max(0, (int) ($S['pxe_timeout'] ?? 60)) * 1000;
$default = preg_replace('/[^a-z]/', '', strtolower((string) ($S['pxe_default'] ?? 'local'))) ?: 'local';

$osEntries  = ['debian', 'ubuntu', 'windows'];  // systèmes installables
$sysEntries = ['local', 'shell'];               // actions locales
$allKeys    = array_merge($osEntries, $sysEntries);

$defLabel = [
    'debian'  => '[  Debian     ]  Installation reseau',
    'ubuntu'  => '[  Ubuntu     ]  26.04 Desktop',
    'windows' => '[  Windows 11 ]  25H2',
    'local'   => 'Demarrer sur le disque local',
    'shell'   => 'Console iPXE (avance)',
];
$enabled = fn(string $k) => ($S["pxe_{$k}_enabled"] ?? '1') === '1';
$label   = fn(string $k) => $clean($S["pxe_{$k}_label"] ?? $defLabel[$k]) ?: $defLabel[$k];

// Le défaut doit être une entrée valide et activée, sinon repli sur le disque local.
if (!in_array($default, $allKeys, true) || !$enabled($default)) {
    $default = $enabled('local') ? 'local' : 'menu';
}

echo "#!ipxe\n:menu\n";
echo "menu {$title}\n";
echo "item --gap        Choisissez le systeme a installer :\n";
foreach ($osEntries as $k) { if ($enabled($k)) { echo "item {$k}       " . $label($k) . "\n"; } }
echo "item --gap\n";
foreach ($sysEntries as $k) { if ($enabled($k)) { echo "item {$k}       " . $label($k) . "\n"; } }
echo "choose --default {$default} --timeout {$timeout} target && goto \${target} || goto {$default}\n\n";

if ($enabled('debian')) {
    $args = str_replace('{IP}', $gw, $clean($S['pxe_debian_args'] ?? ''));
    echo ":debian\nkernel {$base}/debian/linux\ninitrd {$base}/debian/initrd.gz\nimgargs linux {$args}\nboot || goto menu\n\n";
}
if ($enabled('ubuntu')) {
    $args = str_replace('{IP}', $gw, $clean($S['pxe_ubuntu_args'] ?? ''));
    echo ":ubuntu\nkernel {$base}/ubuntu/vmlinuz\ninitrd {$base}/ubuntu/initrd\nimgargs vmlinuz {$args}\nboot || goto menu\n\n";
}
if ($enabled('windows')) {
    echo ":windows\nkernel {$base}/wimboot\n";
    echo "initrd {$base}/win11/bootmgr   bootmgr\ninitrd {$base}/win11/BCD       BCD\n";
    echo "initrd {$base}/win11/boot.sdi  boot.sdi\ninitrd {$base}/win11/boot.wim  boot.wim\nboot || goto menu\n\n";
}
// Les cibles locales sont toujours définies (servent aussi de repli).
echo ":local\nsanboot --no-describe --drive 0x80 || exit\n\n";
echo ":shell\nshell\n";
