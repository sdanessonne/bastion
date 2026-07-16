<?php
/**
 * Bastion Central — configuration partagée (registre des sites, client API, helpers).
 * Machine dédiée départementale qui supervise et pilote les passerelles de site.
 * Identifiants de base lus hors webroot (central.env, sinon admin.env pour la démo).
 */
declare(strict_types=1);

const CENTRAL_VERSION = '1.0';

session_name('PFCENTRAL');
session_start();

function pf_env(string $file): array {
    $out = [];
    if (is_readable($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            if (preg_match('/^(\w+)="?([^"]*)"?$/', $l, $m)) { $out[$m[1]] = $m[2]; }
        }
    }
    return $out;
}
$PF = pf_env('/etc/proxyfibre/central.env');
if (!$PF) { $PF = pf_env('/etc/proxyfibre/admin.env'); }

function pf_db(): PDO {
    static $pdo = null;
    global $PF;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=localhost;dbname=%s;charset=utf8mb4', $PF['DB_NAME'] ?? 'radius');
        $pdo = new PDO($dsn, $PF['DB_USER'] ?? 'radius', $PF['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE IF NOT EXISTS pf_central_sites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            commissariat VARCHAR(120) DEFAULT NULL,
            base_url VARCHAR(255) NOT NULL,
            token VARCHAR(255) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )');
    }
    return $pdo;
}

function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '_')) { http_response_code(400); exit('Requête invalide (CSRF).'); }
}

/**
 * Appelle l'API d'une passerelle de site. $post=null → GET. Retourne un tableau
 * décodé (avec 'error' si injoignable / réponse invalide). Certificats auto-signés
 * acceptés (réseau interne).
 */
function capi(array $site, string $action, ?array $post = null, int $timeout = 6): array {
    $url = rtrim((string) $site['base_url'], '/') . '/api.php?action=' . rawurlencode($action)
         . '&token=' . rawurlencode((string) $site['token']);
    $opts = [
        'http' => ['timeout' => $timeout, 'ignore_errors' => true, 'method' => 'GET'],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ];
    if ($post !== null) {
        $post['token'] = $site['token'];
        $opts['http']['method']  = 'POST';
        $opts['http']['header']  = "Content-Type: application/x-www-form-urlencoded\r\n";
        $opts['http']['content'] = http_build_query($post);
    }
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    if ($raw === false) { return ['error' => 'injoignable']; }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : ['error' => 'réponse invalide'];
}

function sites_all(bool $onlyEnabled = false): array {
    $sql = 'SELECT * FROM pf_central_sites' . ($onlyEnabled ? ' WHERE enabled=1' : '') . ' ORDER BY commissariat, name';
    return pf_db()->query($sql)->fetchAll();
}
