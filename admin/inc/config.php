<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — configuration partagée (connexion base, helpers).
 * Les identifiants de base sont lus hors webroot dans /etc/proxyfibre/admin.env.
 */
declare(strict_types=1);

const BASTION_VERSION = '1.0';

// Aligne l'horloge PHP sur le fuseau du système (= celui de MariaDB), pour que les
// dates affichées et les filtres de période concordent avec les enregistrements.
$__tz = @trim((string) @file_get_contents('/etc/timezone'));
date_default_timezone_set($__tz !== '' ? $__tz : 'Europe/Paris');

session_name('PFADMIN');
session_start();

// ── Chargement des paramètres (hors racine web) ──────────────────────────────
function pf_env(string $file): array {
    $out = [];
    if (is_readable($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            if (preg_match('/^(\w+)="?([^"]*)"?$/', $l, $m)) { $out[$m[1]] = $m[2]; }
        }
    }
    return $out;
}
$PF = pf_env('/etc/proxyfibre/admin.env');

// ── Connexion base (PDO MariaDB) ─────────────────────────────────────────────
function pf_db(): PDO {
    static $pdo = null;
    global $PF;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=localhost;dbname=%s;charset=utf8mb4', $PF['DB_NAME'] ?? 'radius');
        $pdo = new PDO($dsn, $PF['DB_USER'] ?? 'radius', $PF['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '_')) {
        http_response_code(400);
        exit('Requête invalide (CSRF).');
    }
}

/** Interroge OpenNDS et renvoie la liste des clients (indexée par MAC). */
/**
 * Décodage d'une sortie « ndsctl json ».
 *
 * Sert aussi à distinguer un ÉCHEC d'un résultat vide, ce qui n'est pas anodin :
 *   - aucun client connecté  → « {} »                              (JSON valide)
 *   - appel refusé           → « ndsctl thread is busy, ... »      (PAS du JSON)
 * Le second cas doit être traité comme une panne, pas comme « zéro client ».
 *
 * @return array|null null = l'appel a échoué ; sinon la liste (éventuellement vide).
 */
function nds_decode(string $raw): ?array {
    if (trim($raw) === '') { return null; }
    // ndsctl json peut contenir des caractères de contrôle bruts → retirés avant décodage.
    $d = json_decode(preg_replace('/[[:cntrl:]]/', '', $raw), true);
    if (!is_array($d)) { return null; }
    return is_array($d['clients'] ?? null) ? $d['clients'] : [];
}

function nds_clients(): array {
    // Cache court (8 s) : ndsctl json prend ~1,7 s quand des clients sont connectés.
    $f = '/dev/shm/pf-nds-all.cache';
    if (is_file($f) && (time() - filemtime($f)) < 8) {
        $d = nds_decode((string) @file_get_contents($f));
        if ($d !== null) { return $d; }
    }
    $raw = (string) shell_exec('sudo /usr/bin/ndsctl json 2>/dev/null');
    $d   = nds_decode($raw);
    if ($d !== null) { @file_put_contents($f, $raw); return $d; }

    // ÉCHEC. ndsctl se sérialise sur un verrou interne et refuse tout appel concurrent
    // (« ndsctl thread is busy ») — MESURÉ : 4 refus sur 5 appels simultanés, ce qui
    // arrive dès que la page, les métriques et les sessions l'interrogent ensemble.
    // Sans ce repli, la console annonçait alors 0 client connecté : une valeur FAUSSE.
    // Mieux vaut la dernière valeur connue, même un peu périmée.
    if (is_file($f)) {
        $d = nds_decode((string) @file_get_contents($f));
        if ($d !== null) { return $d; }
    }
    return [];
}

function fmtBytes($n): string {
    $n = (float) $n; $u = ['o','Ko','Mo','Go','To']; $i = 0;
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return number_format($n, $i ? 1 : 0, ',', ' ') . ' ' . $u[$i];
}
function fmtDuration(int $s): string {
    if ($s < 0) { $s = 0; }
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60);
    return $h > 0 ? sprintf('%dh %02dmin', $h, $m) : sprintf('%dmin', max(1, $m));
}

// ── Ressources système (processeur / RAM / disques) ──────────────────────────
function sys_cpu_pct(): int {
    $a = @file('/proc/stat'); if (!$a) { return 0; }
    $s1 = array_map('intval', preg_split('/\s+/', trim($a[0])));
    usleep(150000);
    $b = @file('/proc/stat');
    $s2 = array_map('intval', preg_split('/\s+/', trim($b[0])));
    $idle1 = ($s1[4] ?? 0) + ($s1[5] ?? 0);
    $idle2 = ($s2[4] ?? 0) + ($s2[5] ?? 0);
    $tot1 = array_sum(array_slice($s1, 1));
    $tot2 = array_sum(array_slice($s2, 1));
    $dt = $tot2 - $tot1; $di = $idle2 - $idle1;
    return $dt > 0 ? max(0, min(100, (int) round(100 * ($dt - $di) / $dt))) : 0;
}
function sys_mem(): array {
    $m = [];
    foreach (@file('/proc/meminfo') ?: [] as $l) { if (preg_match('/^(\w+):\s+(\d+)/', $l, $x)) { $m[$x[1]] = (int) $x[2]; } }
    $total = $m['MemTotal'] ?? 0;
    $avail = $m['MemAvailable'] ?? ($m['MemFree'] ?? 0);
    $used  = max(0, $total - $avail);
    return ['total' => $total * 1024, 'used' => $used * 1024, 'pct' => $total ? (int) round(100 * $used / $total) : 0];
}
function sys_disk(string $path): ?array {
    if (!is_dir($path)) { return null; }
    $t = @disk_total_space($path); $f = @disk_free_space($path);
    if (!$t) { return null; }
    $u = $t - $f;
    return ['total' => $t, 'used' => $u, 'free' => $f, 'pct' => (int) round(100 * $u / $t)];
}

/**
 * État de plusieurs unités systemd en UN SEUL appel.
 *
 * `systemctl is-active a b c` écrit exactement une ligne par unité, dans l'ordre
 * (une unité inexistante donne « inactive ») — bien plus rapide que N appels, et le
 * tableau de bord interroge une dizaine de services à chaque chargement.
 *
 * @param  string[] $units Noms d'unités systemd.
 * @return array<string,string> unité => active|inactive|failed|activating…
 */
function sys_units_active(array $units): array {
    if (!$units) { return []; }
    $out   = (string) shell_exec('systemctl is-active '
        . implode(' ', array_map('escapeshellarg', $units)) . ' 2>/dev/null');
    $lines = preg_split('/\R/', trim($out)) ?: [];
    $res   = [];
    foreach (array_values($units) as $i => $u) { $res[$u] = $lines[$i] ?? 'inconnu'; }
    return $res;
}

/**
 * Anomalies à porter à la connaissance de l'administrateur, les plus graves d'abord.
 *
 * Vécu sur ce produit : OpenNDS s'est arrêté sans bruit (deux IP détectées sur son
 * interface) et le LAN a eu un accès Internet DIRECT, sans authentification ni
 * filtrage, sans que rien ne le signale. D'où le niveau « danger » sur le portail
 * captif : c'est une faille de sécurité ouverte, pas une simple panne de service.
 * De même, l'arrêt du journal de navigation crée un TROU dans la journalisation
 * légale — obligation réglementaire pour cet équipement.
 *
 * @return array<int,array{lvl:string,txt:string,act:string,url:string}>
 */
function sys_alerts(): array {
    $CRIT = [
        'opennds'           => ['danger', "Le portail captif est à l'arrêt : les postes du réseau peuvent atteindre Internet sans authentification ni filtrage.", 'services.php'],
        'mariadb'           => ['danger', "Base de données arrêtée : comptes, journaux et réglages sont inaccessibles.", 'services.php'],
        'proxyfibre-weblog' => ['danger', "Journal de navigation arrêté : la journalisation légale est interrompue (trou dans la traçabilité).", 'services.php'],
        'freeradius'        => ['warn',   "Service d'authentification arrêté : plus aucune connexion utilisateur ne peut être validée.", 'services.php'],
        'dnsmasq'           => ['warn',   "DHCP/DNS arrêté : les postes ne reçoivent plus d'adresse et ne résolvent plus les noms.", 'services.php'],
        'samba-ad-dc'       => ['warn',   "Active Directory arrêté : ouverture de session et stratégies de groupe indisponibles.", 'ad.php'],
    ];
    $out   = [];
    $state = sys_units_active(array_keys($CRIT));
    foreach ($CRIT as $unit => [$lvl, $txt, $url]) {
        if (($state[$unit] ?? '') !== 'active') {
            $out[] = ['lvl' => $lvl, 'txt' => $txt, 'act' => 'Voir', 'url' => $url];
        }
    }
    foreach ([['/', 'système'], ['/srv/pxe', 'de données']] as [$path, $nom]) {
        $d = sys_disk($path);
        if ($d && $d['pct'] >= 90) {
            $out[] = ['lvl' => $d['pct'] >= 95 ? 'danger' : 'warn',
                      'txt' => "Disque {$nom} rempli à {$d['pct']} % — il reste " . fmtBytes($d['free']) . '.',
                      'act' => 'Système', 'url' => 'systeme.php'];
        }
    }
    usort($out, fn($a, $b) => ($a['lvl'] === 'danger' ? 0 : 1) <=> ($b['lvl'] === 'danger' ? 0 : 1));
    return $out;
}
