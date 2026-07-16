<?php
/**
 * Bastion — échantillonneur de charge (processeur + mémoire) toutes les minutes.
 * Exécuté par cron ; enregistre dans pf_metrics et purge au-delà de 24 h.
 */
function m_cpu(): int {
    $a = @file('/proc/stat'); if (!$a) { return 0; }
    $s1 = array_map('intval', preg_split('/\s+/', trim($a[0])));
    usleep(300000);
    $b = @file('/proc/stat');
    $s2 = array_map('intval', preg_split('/\s+/', trim($b[0])));
    $i1 = ($s1[4] ?? 0) + ($s1[5] ?? 0);
    $i2 = ($s2[4] ?? 0) + ($s2[5] ?? 0);
    $t1 = array_sum(array_slice($s1, 1));
    $t2 = array_sum(array_slice($s2, 1));
    $dt = $t2 - $t1; $di = $i2 - $i1;
    return $dt > 0 ? max(0, min(100, (int) round(100 * ($dt - $di) / $dt))) : 0;
}
function m_mem(): int {
    $m = [];
    foreach (@file('/proc/meminfo') ?: [] as $l) { if (preg_match('/^(\w+):\s+(\d+)/', $l, $x)) { $m[$x[1]] = (int) $x[2]; } }
    $t = $m['MemTotal'] ?? 0;
    $av = $m['MemAvailable'] ?? ($m['MemFree'] ?? 0);
    return $t ? max(0, min(100, (int) round(100 * ($t - $av) / $t))) : 0;
}

$env = [];
foreach (@file('/etc/proxyfibre/admin.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    if (preg_match('/^(\w+)="?([^"]*)"?$/', $l, $x)) { $env[$x[1]] = $x[2]; }
}
try {
    $pdo = new PDO('mysql:host=localhost;dbname=' . ($env['DB_NAME'] ?? 'radius') . ';charset=utf8mb4',
        $env['DB_USER'] ?? 'radius', $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS pf_metrics (ts INT PRIMARY KEY, cpu TINYINT UNSIGNED, mem TINYINT UNSIGNED)');
    $pdo->prepare('REPLACE INTO pf_metrics (ts,cpu,mem) VALUES (?,?,?)')->execute([time(), m_cpu(), m_mem()]);
    $pdo->exec('DELETE FROM pf_metrics WHERE ts < ' . (time() - 86400));
} catch (Throwable $e) {
    fwrite(STDERR, 'metrics-sample: ' . $e->getMessage() . "\n");
    exit(1);
}
