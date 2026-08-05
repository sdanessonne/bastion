<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — veille de la liaison inter-sites. Lancé par cron, JAMAIS par le web.
 *
 * Deux pannes silencieuses à couvrir, une par rôle :
 *
 *   PRINCIPAL — son adresse publique change (cas courant derrière une box). Les
 *   sites continuent d'écrire à l'ancienne : leurs tunnels restent « montés » et
 *   plus rien ne passe. Personne ne s'en aperçoit avant qu'on cherche un site
 *   dans la console de flotte. On prévient donc par courriel, au CHANGEMENT.
 *
 *   SITE — même cause, vue de l'autre bout. Si le point de contact est un NOM,
 *   il suffit de le re-résoudre : le script réseau s'en charge, mais seulement
 *   quand le tunnel est réellement rompu. Si c'est une ADRESSE, rien ne peut être
 *   fait automatiquement — et c'est dit, plutôt que réessayé en boucle.
 *
 * N'écrit et n'alerte QUE sur changement : une adresse stable pendant six mois ne
 * produit pas un courriel par heure.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Réservé à la ligne de commande.\n"); }

// Chemins ABSOLUS : ce script vit dans /usr/local/sbin une fois installé, où
// « __DIR__/../../admin » ne mène nulle part. Le repli sur l'arborescence du dépôt
// permet de l'essayer sans installer.
$__base = is_dir('/var/www/admin/inc') ? '/var/www/admin/inc' : __DIR__ . '/../../admin/inc';
require_once $__base . '/config.php';
require_once $__base . '/mailer.php';

/** Trace d'un envoi manqué. Sans elle, on croirait être prévenu — c'est le défaut
 *  que ce projet a déjà corrigé une fois sur la chaîne d'alerte. */
function lv_log(string $msg, string $niveau = 'daemon.info'): void {
    shell_exec('logger -t bastion-lien -p ' . escapeshellarg($niveau) . ' ' . escapeshellarg($msg));
}

function lv_reglage(PDO $db, string $k, ?string $v = null): string {
    if ($v === null) {
        try { return (string) ($db->query('SELECT v FROM pf_settings WHERE k=' . $db->quote($k))->fetchColumn() ?: ''); }
        catch (Throwable $e) { return ''; }
    }
    try { $db->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$k, $v]); }
    catch (Throwable $e) {}
    return $v;
}

$db  = pf_db();
$etat = json_decode((string) shell_exec('/usr/local/sbin/proxyfibre-lien state 2>/dev/null'), true) ?: [];
$role = (string) ($etat['role'] ?? 'site');

// ── RÔLE SITE : reprise du point de contact ──────────────────────────────────
if ($role !== 'principal') {
    $out = trim((string) shell_exec('/usr/local/sbin/proxyfibre-lien reendpoint 2>&1'));
    // On ne journalise QUE ce qui mérite attention : « sans objet » est le cas
    // normal, et l'écrire toutes les cinq minutes noierait le reste.
    if ($out !== '' && strpos($out, 'SANS OBJET') !== 0) {
        lv_log($out, strpos($out, 'OK:') === 0 ? 'daemon.notice' : 'daemon.err');
    }
    exit(0);
}

// ── RÔLE PRINCIPAL : surveiller l'adresse publique ───────────────────────────
$wan = json_decode((string) shell_exec('/usr/local/sbin/proxyfibre-wanip state 2>/dev/null'), true) ?: [];
$ip  = (string) ($wan['direct'] ?? '');
if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    // Une mesure indisponible n'est PAS un changement d'adresse : ne rien conclure
    // vaut mieux qu'alerter à tort et user la confiance dans l'alerte.
    exit(0);
}

$connue = lv_reglage($db, 'lien_ip_publique');
if ($connue === '') { lv_reglage($db, 'lien_ip_publique', $ip); exit(0); }   // première mesure
if ($connue === $ip) { exit(0); }

lv_reglage($db, 'lien_ip_publique', $ip);
lv_log("adresse publique du concentrateur : $connue -> $ip", 'daemon.warning');

$to = lv_reglage($db, 'alert_email');
if ($to === '') { lv_log('AUCUNE ALERTE ENVOYEE : pas d\'adresse de destination configuree', 'daemon.err'); exit(0); }

$dep   = lv_reglage($db, 'lien_departement');
$port  = lv_reglage($db, 'lien_port') ?: '51820';
$sites = 0;
try { $sites = (int) $db->query('SELECT COUNT(*) FROM pf_lien_sites')->fetchColumn(); } catch (Throwable $e) {}

$titre   = 'Adresse publique du concentrateur modifiée' . ($dep !== '' ? ' — ' . $dep : '');
$constat = "L'adresse publique de ce serveur, qui sert de point de contact aux commissariats "
         . "rattachés, vient de changer. Les tunnels des sites visent encore l'ancienne : ils "
         . "restent « montés » de leur côté, mais plus aucune donnée ne circule.";
$faits = [
    'Ancienne adresse'      => $connue,
    'Nouvelle adresse'      => $ip,
    'Port du concentrateur' => $port,
    'Sites rattachés'       => (string) $sites,
];
$suite = "Communiquez « $ip:$port » aux commissariats rattachés, ou faites-leur re-résoudre leur "
       . "point de contact s'il est donné sous forme de nom. Une adresse fixe demandée à "
       . "l'opérateur supprimerait ce problème définitivement.";

if (!is_executable('/usr/sbin/sendmail')) {
    lv_log("COURRIEL NON ENVOYE a $to : aucun agent de messagerie installe", 'daemon.err');
    exit(0);
}
if (!pf_mail_notif($to, 'danger', $titre, $constat, $faits, $suite)) {
    lv_log("COURRIEL NON ENVOYE a $to : le relais a refuse le message", 'daemon.err');
}
