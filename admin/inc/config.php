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

/** Purge le cache des clients : à appeler après une action qui change leur état. */
function nds_clients_flush(): void { @unlink('/dev/shm/pf-nds-all.cache'); }

function nds_clients(): array {
    // Cache court : « ndsctl json » prend de 0,65 à 1,7 s selon le nombre de clients connectés,
    // et il est interrogé par presque toutes les pages — c'était le principal coût d'affichage
    // de la console. Purgé par nds_clients_flush() après une déconnexion forcée.
    $f = '/dev/shm/pf-nds-all.cache';
    if (is_file($f) && (time() - filemtime($f)) < 30) {
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
 * Débit instantané de l'interface WAN, en octets par seconde.
 *
 * Un débit ne se lit nulle part : le noyau ne publie que des COMPTEURS cumulés. Il faut
 * donc deux échantillons et le temps écoulé entre eux. Le précédent est gardé en tmpfs.
 *
 * SENS : « descendant » = rx sur le WAN, c'est-à-dire ce qui ARRIVE d'Internet, donc ce
 * que les postes téléchargent. « montant » = tx, ce qui part vers Internet. Le sens est
 * bien celui de l'utilisateur, pas celui de l'interface.
 *
 * Ce que compte le WAN inclut le trafic PROPRE de la passerelle (mises à jour apt,
 * signatures antivirus) : c'est voulu — l'administrateur veut savoir ce qui occupe sa
 * ligne, quelle qu'en soit l'origine.
 *
 * @return array{down:int,up:int,if:string}
 */
function sys_net_rate(): array {
    // L'interface WAN vient de la configuration : la coder en dur casserait sur tout
    // site dont le nommage diffère.
    static $if = null;
    if ($if === null) {
        $if = 'enp0s3';
        foreach (@file('/etc/proxyfibre/net.env') ?: [] as $l) {
            if (preg_match('/^WAN_IF="?([A-Za-z0-9._@:-]+)"?/', $l, $m)) { $if = $m[1]; break; }
        }
    }
    $base = "/sys/class/net/{$if}/statistics/";
    if (!is_readable($base . 'rx_bytes')) { return ['down' => 0, 'up' => 0, 'if' => $if]; }

    $rx  = (int) trim((string) @file_get_contents($base . 'rx_bytes'));
    $tx  = (int) trim((string) @file_get_contents($base . 'tx_bytes'));
    $now = microtime(true);
    $f   = '/dev/shm/pf-net-' . preg_replace('/[^a-z0-9]/i', '', $if) . '.sample';

    $down = 0; $up = 0;
    $prev = (string) @file_get_contents($f);
    if ($prev !== '') {
        [$pts, $prx, $ptx, $pdown, $pup] = array_pad(explode(' ', trim($prev)), 5, '0');
        $dt = $now - (float) $pts;
        if ($dt >= 0.5) {
            // Un compteur qui DIMINUE signifie que l'interface a été réinitialisée :
            // le delta serait négatif et donnerait un débit absurde. On repart de zéro.
            $down = ($rx >= (int) $prx) ? (int) round(($rx - (int) $prx) / $dt) : 0;
            $up   = ($tx >= (int) $ptx) ? (int) round(($tx - (int) $ptx) / $dt) : 0;
        } else {
            // Deux onglets ouverts sur la console interrogent à quelques millisecondes
            // d'écart : le Δt frôle zéro et la division explose. On rejoue la dernière
            // valeur ET — surtout — on NE RÉÉCRIT PAS l'échantillon : sinon chaque
            // appel remettrait la base à zéro, aucun Δt exploitable ne s'accumulerait
            // jamais, et le débit resterait bloqué à 0.
            return ['down' => (int) $pdown, 'up' => (int) $pup, 'if' => $if];
        }
    }
    // Le débit est calculé sur le temps RÉELLEMENT écoulé, jamais sur l'intervalle
    // supposé du sondage : celui-ci dérive (ndsctl prend ~1,7 s, l'onglet peut être
    // en arrière-plan et le navigateur ralentit alors ses minuteurs).
    // 0644 comme les autres caches de /dev/shm, et NON 0666 : un fichier inscriptible par
    // tous laisserait n'importe quel compte local injecter un débit fantaisiste dans la
    // console. L'enjeu est cosmétique, mais un fichier world-writable n'a aucune raison
    // d'exister. Seul www-data l'écrit en service ; un fichier laissé par un essai en
    // root empêcherait l'écriture, d'où le nettoyage à l'installation (deploy.sh).
    @file_put_contents($f, "{$now} {$rx} {$tx} {$down} {$up}");
    return ['down' => $down, 'up' => $up, 'if' => $if];
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
    // Anomalies de sécurité détectées et NON acquittées (nouvel appareil LAN, membres admin
    // AD, GPO hors console). Elles rejoignent le canal d'alerte existant : courriel du
    // watchdog + bandeau du tableau de bord. La détection/écriture est faite par le scanner
    // « proxyfibre-anomaly » (minuterie) ; ici on ne fait que LIRE (aucun effet de bord).
    try {
        foreach (pf_db()->query("SELECT severity, detail FROM pf_anomaly WHERE acknowledged=0 ORDER BY ts DESC LIMIT 25") as $a) {
            $out[] = ['lvl' => ($a['severity'] === 'danger' ? 'danger' : 'warn'),
                      'txt' => 'Anomalie détectée — ' . $a['detail'],
                      'act' => 'Sécurité', 'url' => 'securite.php'];
        }
    } catch (Throwable $e) {}
    usort($out, fn($a, $b) => ($a['lvl'] === 'danger' ? 0 : 1) <=> ($b['lvl'] === 'danger' ? 0 : 1));
    return $out;
}
