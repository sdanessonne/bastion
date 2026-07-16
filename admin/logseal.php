<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — scellement quotidien des journaux. Lancé par proxyfibre-logseal.timer.
 *
 * Scelle tous les jours ÉCOULÉS qui ne le sont pas encore, du plus ancien au plus
 * récent : le chaînage impose l'ordre chronologique, et un rattrapage après une
 * coupure doit reconstruire la chaîne sans trou.
 *
 * Ne scelle JAMAIS le jour en cours : des lignes s'y ajoutent encore.
 *
 * Usage : logseal.php [--verify] [--from AAAA-MM-JJ]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Réservé à la ligne de commande.\n"); }

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/logseal.php';

$db = pf_db();
seal_schema($db);

// ── Vérification seule ───────────────────────────────────────────────────────
if (in_array('--verify', $argv, true)) {
    $v = seal_verify_chain($db);
    echo $v['resume'] . "\n";
    foreach ($v['days'] as $d) {
        $bad = array_keys(array_filter([
            'empreinte' => !$d['digest_ok'], 'scelle' => !$d['seal_ok'],
            'chainage'  => !$d['chain_ok'],  'signature' => !$d['sig_ok'],
        ]));
        printf("  %s  %4d session(s) %5d visite(s)  %s\n", $d['day'], $d['nb_acct'], $d['nb_web'],
            $bad ? 'ALTERE : ' . implode(', ', $bad) : 'ok');
    }
    exit($v['ok'] ? 0 : 1);
}

// ── Quel est le premier jour à sceller ? ─────────────────────────────────────
$idx  = array_search('--from', $argv, true);
$from = ($idx !== false && isset($argv[$idx + 1])) ? $argv[$idx + 1] : null;

if ($from === null) {
    $last = $db->query("SELECT MAX(day) FROM pf_log_seal")->fetchColumn();
    if ($last) {
        $from = date('Y-m-d', strtotime($last . ' +1 day'));
    } else {
        // Premier lancement : on part du plus ancien événement journalisé, sinon d'hier.
        $d1 = $db->query("SELECT MIN(DATE(acctstoptime)) FROM radacct WHERE acctstoptime IS NOT NULL")->fetchColumn();
        $d2 = $db->query("SELECT MIN(DATE(ts)) FROM pf_weblog")->fetchColumn();
        $cand = array_values(array_filter([$d1, $d2]));
        $from = $cand ? min($cand) : date('Y-m-d', strtotime('-1 day'));
    }
}

$today = date('Y-m-d');
$n = 0;
for ($day = $from; $day < $today; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
    $st = $db->prepare("SELECT COUNT(*) FROM pf_log_seal WHERE day = ?");
    $st->execute([$day]);
    if ($st->fetchColumn()) { continue; }                    // déjà scellé

    $c    = seal_digest_for_day($db, $day);
    $prev = seal_previous($db, $day);
    $seal = seal_compute($day, $c['digest'], $prev);

    // Signature CMS détachée du scellé, via le certificat de réquisition (AC Bastion).
    // Le scellé signé est ce qui rend une altération indétectable IMPOSSIBLE sans la
    // clé privée : recalculer l'empreinte ne suffit pas, il faut re-signer.
    $sig  = null;
    $fIn  = tempnam('/tmp', 'seal_');
    $fOut = '/tmp/' . basename($fIn) . '.p7s';
    file_put_contents($fIn, $seal);
    exec('sudo /usr/local/sbin/proxyfibre-sign sign ' . escapeshellarg($fIn) . ' ' . escapeshellarg($fOut) . ' 2>&1', $o, $rc);
    if ($rc === 0 && is_file($fOut)) { $sig = file_get_contents($fOut); }
    @unlink($fIn); @unlink($fOut);

    $st = $db->prepare("INSERT INTO pf_log_seal (day, nb_acct, nb_web, digest, prev_seal, seal, signature, created_at)
                        VALUES (?,?,?,?,?,?,?,?)");
    $st->execute([$day, $c['nb_acct'], $c['nb_web'], $c['digest'], $prev, $seal, $sig, date('Y-m-d H:i:s')]);

    printf("scellé %s : %d session(s), %d visite(s), %s\n", $day, $c['nb_acct'], $c['nb_web'],
        $sig ? 'signé' : 'NON SIGNÉ (signature indisponible)');
    if (!$sig) {
        shell_exec('logger -t bastion-logseal -p daemon.err '
            . escapeshellarg("scelle du {$day} NON SIGNE : valeur probante reduite"));
    }
    $n++;
}

echo $n === 0 ? "Rien à sceller (à jour).\n" : "{$n} jour(s) scellé(s).\n";
