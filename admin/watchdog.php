<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — surveillance hors console.
 *
 * Lancé par proxyfibre-watchdog.timer (toutes les minutes), JAMAIS par le web.
 *
 * Le bandeau d'alertes du tableau de bord ne sert à rien si personne ne regarde
 * l'écran — or une panne du portail captif à 2 h du matin laisse le réseau coupé
 * (repli) jusqu'au lendemain matin. Ce script réutilise EXACTEMENT la logique
 * sys_alerts() de la console, l'historise et prévient par courriel.
 *
 * N'écrit QUE sur CHANGEMENT d'état : une panne de trois jours produit une ligne
 * d'ouverture et une de clôture, pas 4320 lignes identiques.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Réservé à la ligne de commande.\n"); }

require_once __DIR__ . '/inc/config.php';

$db = pf_db();

// ── Historique des alertes ───────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS pf_alerts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    sig         VARCHAR(64)  NOT NULL,           -- empreinte de l'anomalie (dédoublonnage)
    lvl         ENUM('danger','warn') NOT NULL,
    txt         VARCHAR(500) NOT NULL,
    opened_at   DATETIME     NOT NULL,
    closed_at   DATETIME     NULL,               -- NULL = toujours en cours
    notified    TINYINT(1)   NOT NULL DEFAULT 0,
    KEY k_open (closed_at, sig),
    KEY k_when (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$now     = date('Y-m-d H:i:s');
$current = [];
foreach (sys_alerts() as $a) { $current[substr(sha1($a['txt']), 0, 32)] = $a; }

// Anomalies déjà ouvertes en base.
$open = [];
foreach ($db->query("SELECT id, sig, lvl, txt FROM pf_alerts WHERE closed_at IS NULL") as $r) {
    $open[$r['sig']] = $r;
}

$nouvelles = [];
foreach ($current as $sig => $a) {
    if (isset($open[$sig])) { continue; }                       // déjà connue : on se tait
    $st = $db->prepare("INSERT INTO pf_alerts (sig, lvl, txt, opened_at) VALUES (?,?,?,?)");
    $st->execute([$sig, $a['lvl'], $a['txt'], $now]);
    $nouvelles[] = $a;
}

$closes = [];
foreach ($open as $sig => $r) {
    if (isset($current[$sig])) { continue; }                    // toujours en panne
    $db->prepare("UPDATE pf_alerts SET closed_at = ? WHERE id = ?")->execute([$now, $r['id']]);
    $closes[] = $r;
}

require_once __DIR__ . '/inc/mailer.php';

// ── Notification ─────────────────────────────────────────────────────────────
/**
 * Envoi par sendmail si un agent de transport est présent.
 *
 * Une passerelle de commissariat n'a pas forcément de MTA : sans lui, on ne
 * PERD RIEN — l'alerte reste en base (console) et part dans syslog, que la
 * supervision du site peut collecter. On ne fait donc pas de l'envoi un échec.
 */
function wd_settings(PDO $db): array {
    $s = [];
    try { foreach ($db->query("SELECT k,v FROM pf_settings WHERE k LIKE 'alert\\_%'") as $r) { $s[$r['k']] = $r['v']; } }
    catch (Throwable $e) {}
    return $s;
}
/**
 * Notification mise en forme, dont l'ÉCHEC EST CONSIGNÉ.
 *
 * Le modèle (pf_mail_notif) rend « false » en cas de problème. Laisser ce retour
 * de côté reproduirait exactement le défaut corrigé plus tôt : une adresse
 * enregistrée, un administrateur qui se croit prévenu, et rien qui part — y
 * compris le jour où le portail tombe et où le repli coupe Internet.
 *
 * L'échec va donc dans le journal système, au même endroit que l'alerte :
 * « journalctl -t bastion-watchdog » le montre en une commande, et une
 * supervision de site le collecte comme le reste.
 */
function wd_notif(string $to, string $niveau, string $titre,
                  string $constat, array $faits = [], string $suite = ''): bool {
    if (!is_executable('/usr/sbin/sendmail')) {
        shell_exec('logger -t bastion-watchdog -p daemon.err '
            . escapeshellarg('COURRIEL NON ENVOYE a ' . $to . ' : aucun agent de messagerie installe'));
        return false;
    }
    $ok = pf_mail_notif($to, $niveau, $titre, $constat, $faits, $suite);
    if (!$ok) {
        shell_exec('logger -t bastion-watchdog -p daemon.err '
            . escapeshellarg('COURRIEL NON ENVOYE a ' . $to . ' : le relais a refuse le message'));
    }
    return $ok;
}

$S    = wd_settings($db);
$dest = trim((string) ($S['alert_email'] ?? ''));
$host = trim((string) shell_exec('hostname 2>/dev/null')) ?: 'bastion';

foreach ($nouvelles as $a) {
    $tag = $a['lvl'] === 'danger' ? 'ALERTE' : 'avertissement';
    // syslog systématique : traçable même sans courriel, et collectable à distance.
    shell_exec('logger -t bastion-watchdog -p ' . ($a['lvl'] === 'danger' ? 'daemon.err' : 'daemon.warning')
        . ' ' . escapeshellarg($tag . ' : ' . $a['txt']));
    if ($dest !== '') {
        // Le titre reprend le CONSTAT, pas une formule générique : « anomalie
        // détectée » ne dit rien dans une liste de messages sur téléphone, alors
        // que « portail captif à l'arrêt » se comprend sans ouvrir.
        $court = mb_substr($a['txt'], 0, 90, 'UTF-8');
        if (mb_strlen($a['txt'], 'UTF-8') > 90) { $court = rtrim($court) . '…'; }
        wd_notif($dest, $a['lvl'] === 'danger' ? 'danger' : 'warn', $court,
            $a['txt'],
            ['Passerelle' => $host, 'Détectée le' => date('d/m/Y à H:i:s'),
             'Gravité'    => $a['lvl'] === 'danger' ? 'Alerte — action attendue'
                                                    : 'Avertissement — à surveiller'],
            'Ouvrez la console d\'administration, rubrique Surveiller. '
            . 'Cette anomalie y figure avec le détail et les actions possibles.');
    }
}
foreach ($closes as $r) {
    shell_exec('logger -t bastion-watchdog -p daemon.notice ' . escapeshellarg('retour a la normale : ' . $r['txt']));
    if ($dest !== '') {
        // La clôture est envoyée aussi : sans elle, on reste avec la dernière
        // alerte en tête et l'on intervient sur un incident déjà terminé.
        $court = mb_substr($r['txt'], 0, 90, 'UTF-8');
        if (mb_strlen($r['txt'], 'UTF-8') > 90) { $court = rtrim($court) . '…'; }
        wd_notif($dest, 'ok', 'Résolu : ' . $court,
            "L'anomalie signalée précédemment n'est plus constatée.",
            ['Passerelle' => $host, 'Anomalie' => $r['txt'],
             'Résolue le' => date('d/m/Y à H:i:s')]);
    }
}

// Purge : un an d'historique suffit largement pour un journal d'exploitation.
$db->exec("DELETE FROM pf_alerts WHERE closed_at IS NOT NULL AND closed_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)");

printf("%d anomalie(s) en cours, %d nouvelle(s), %d close(s)%s\n",
    count($current), count($nouvelles), count($closes),
    $dest !== '' ? ", courriel -> {$dest}" : ", pas d'adresse de notification configurée");
