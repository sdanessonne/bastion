<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — métriques système en JSON (rafraîchissement live du tableau de bord). */
require_once __DIR__ . '/inc/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['admin'])) { http_response_code(401); echo '{"error":"unauthorized"}'; exit; }
// Le verrou de session PHP est tenu pendant TOUTE la requête. Or ce script interroge
// ndsctl, qui prend ~1,7 s : sans cette libération, chaque rafraîchissement bloquerait
// la navigation de l'administrateur pendant ce temps. Plus rien n'écrit la session ici.
session_write_close();

// Historique persistant (échantillonné 1/min dans pf_metrics) : [[ts,cpu,mem], …].
if (isset($_GET['history'])) {
    $n = min(1440, max(10, (int) ($_GET['history'] ?: 180)));
    $out = [];
    try { foreach (pf_db()->query("SELECT ts,cpu,mem FROM pf_metrics ORDER BY ts DESC LIMIT $n") as $r) { $out[] = [(int) $r['ts'], (int) $r['cpu'], (int) $r['mem']]; } }
    catch (Throwable $e) {}
    echo json_encode(['history' => array_reverse($out)], JSON_UNESCAPED_SLASHES);
    exit;
}

$cores = max(1, (int) trim((string) shell_exec('nproc 2>/dev/null')));
$mem   = sys_mem();
$ds    = sys_disk('/');
$dd    = sys_disk('/srv/pxe');

// Santé des services + anomalies : le tableau de bord les rafraîchit en direct,
// pour qu'un service qui tombe se voie sans recharger la page.
$SVC_VUE = ['opennds', 'freeradius', 'mariadb', 'dnsmasq', 'samba-ad-dc', 'proxyfibre-weblog', 'apache2'];

// Compteurs du haut de page. nds_clients() est mis en cache 8 s dans /dev/shm (ndsctl
// est lent) : le rafraîchissement toutes les 5 s tape donc le cache la plupart du temps.
// « down » est renvoyé en octets BRUTS, pas formaté : c'est le navigateur qui met en
// forme, sinon l'animation ne pourrait pas interpoler entre deux valeurs.
$clients = nds_clients();
$kpiAuth = 0; $kpiDown = 0;
foreach ($clients as $c) {
    if (($c['state'] ?? '') === 'Authenticated') { $kpiAuth++; }
    $kpiDown += (int) ($c['download_this_session'] ?? 0);
}

// Débit WAN instantané. Calculé à CHAQUE appel : c'est le sondage du tableau de bord
// qui fournit les échantillons successifs, il n'y a pas de démon dédié à entretenir.
$net = sys_net_rate();
// Capacité mesurée de la ligne : sert à exprimer le débit en POURCENTAGE. Lue à chaque
// appel — elle change quand l'administrateur relance une mesure, et la page ne doit pas
// avoir à être rechargée pour s'en apercevoir.
$wanCap = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-speedtest state 2>/dev/null'), true) ?: [];

echo json_encode([
    'kpi'      => ['auth' => $kpiAuth, 'seen' => count($clients), 'down' => $kpiDown],
    'net'      => ['down' => $net['down'], 'up' => $net['up'], 'if' => $net['if'],
                   'capD' => (int) ($wanCap['down'] ?? 0), 'capU' => (int) ($wanCap['up'] ?? 0),
                   'test' => !empty($wanCap['en_cours'])],
    'cpu'      => ['pct' => sys_cpu_pct(), 'detail' => $cores . ' cœur(s)'],
    'mem'      => ['pct' => $mem['pct'], 'detail' => fmtBytes($mem['used']) . ' / ' . fmtBytes($mem['total'])],
    'disksys'  => $ds ? ['pct' => $ds['pct'], 'detail' => fmtBytes($ds['used']) . ' / ' . fmtBytes($ds['total']) . ' · ' . fmtBytes($ds['free']) . ' libres'] : null,
    'diskdata' => $dd ? ['pct' => $dd['pct'], 'detail' => fmtBytes($dd['used']) . ' / ' . fmtBytes($dd['total']) . ' · ' . fmtBytes($dd['free']) . ' libres'] : null,
    'uptime'   => trim((string) shell_exec('uptime -p 2>/dev/null')),
    'services' => sys_units_active($SVC_VUE),
    'alerts'   => sys_alerts(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
