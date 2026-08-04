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

$estAdmin = $expected !== '' && $given !== '' && hash_equals($expected, $given);

// Jeton station : soit le jeton PARTAGÉ historique (pf_settings.station_token), soit un jeton
// PAR STATION (table pf_station_tokens) — révocable un par un depuis la console. Toutes les
// comparaisons restent à temps constant (hash_equals) : jamais d'égalité SQL sur le secret.
$estStation = false;
$stationTokId = null;   // id du jeton par-station reconnu, pour tracer sa dernière activité
if ($given !== '') {
    if ($expectStation !== '' && hash_equals($expectStation, $given)) {
        $estStation = true;
    } else {
        try {
            foreach ($db->query('SELECT id,token FROM pf_station_tokens WHERE revoked=0') as $r) {
                if (hash_equals((string) $r['token'], $given)) { $estStation = true; $stationTokId = (int) $r['id']; break; }
            }
        } catch (Throwable $e) { /* table pas encore créée : seul le jeton partagé s'applique */ }
    }
}
// Jeton POSTE : porté par le script d'inventaire déployé par stratégie. Il n'ouvre QUE la
// remontée d'inventaire (voir $actionsPoste), rien d'autre.
// Limite assumée, à connaître : ce script vit dans le SYSVOL, lisible par tout compte du
// domaine — le jeton n'est donc pas un secret fort. L'inventaire est par nature DÉCLARATIF
// (un poste décrit lui-même son matériel) : c'est une donnée d'exploitation, jamais une preuve.
// L'adresse d'origine est enregistrée avec chaque remontée pour pouvoir recouper.
$expectPoste = '';
try { $expectPoste = (string) $db->query("SELECT v FROM pf_settings WHERE k='inventory_token'")->fetchColumn(); }
catch (Throwable $e) { }
$estPoste = $expectPoste !== '' && $given !== '' && hash_equals($expectPoste, $given);

// ── POINT D'ENTRÉE DU RÉSEAU DES POSTES ──────────────────────────────────────
// La console est volontairement interdite depuis le réseau des postes (le vhost 8443
// porte « Require not ip 192.168.182.0/24 »). Les postes doivent pourtant atteindre
// CE fichier — sans quoi l'inventaire et la vignette de l'écran de connexion ne
// remontent jamais, ce qui a été le cas jusqu'ici, en silence.
//
// Il est donc publié aussi sur le port du portail (2443), déjà joignable par les
// postes. Apache marque alors la requête, et sur ce chemin SEULES les actions à
// jeton sont servies : la branche « administrateur connecté » est refusée, même si
// un cookie de session valide accompagnait l'appel — les cookies ne distinguent pas
// les ports, un administrateur connecté à la console en enverrait un sans le savoir.
$viaPostes = ($_SERVER['BASTION_API_POSTE'] ?? '') === '1';
if ($viaPostes) { $estAdmin = false; }

if (!$estAdmin && !$estStation && !$estPoste) { jout(['error' => 'unauthorized'], 401); }

// Traçabilité : un jeton par-station note sa dernière activité (quand, IP, poste). La console
// montre ainsi quel ordinateur se sert de quel jeton — et repère un jeton dormant ou compromis.
if ($stationTokId) {
    try {
        $poste = substr(trim((string) ($_POST['poste'] ?? $_GET['poste'] ?? '')), 0, 96);
        $db->prepare("UPDATE pf_station_tokens SET last_seen=NOW(), last_ip=?, last_poste=COALESCE(NULLIF(?,''),last_poste) WHERE id=?")
           ->execute([(string) ($_SERVER['REMOTE_ADDR'] ?? ''), $poste, $stationTokId]);
    } catch (Throwable $e) {}
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

// Une station ne peut appeler QUE ces actions. Sans ce garde-fou, le jeton « limité »
// ouvrirait toute l'API : la limitation ne serait qu'une intention.
$actionsStation = ['station.report', 'station.clamdb', 'station.auth', 'station.bitlocker'];
if ($estStation && !$estAdmin && !in_array($action, $actionsStation, true)) { jout(['error' => 'forbidden'], 403); }
// Même principe pour le jeton POSTE : il n'ouvre QUE la remontée d'inventaire.
$actionsPoste = ['poste.inventaire', 'poste.photo'];
if ($estPoste && !$estAdmin && !in_array($action, $actionsPoste, true)) { jout(['error' => 'forbidden'], 403); }
$active = fn(string $u) => trim((string) shell_exec('systemctl is-active ' . escapeshellarg($u) . ' 2>/dev/null'));

switch ($action) {
    /**
     * Déverrouillage de la fermeture d'une station blanche.
     *
     * ── LE 2FA EST OBLIGATOIRE ICI, ET CE N'EST PAS NÉGOCIABLE ─────────────────
     * La console impose une authentification à deux facteurs aux comptes qui l'ont
     * activée. Si cette action s'en dispensait, elle deviendrait le maillon faible : il
     * suffirait d'une station et d'un mot de passe volé pour contourner le second facteur
     * de toute la console. Une porte dérobée n'a pas besoin d'être voulue pour en être une.
     *
     * ── CE QUE CETTE ACTION N'EST PAS ──────────────────────────────────────────
     * Elle n'ouvre PAS de session d'administration. Elle répond « oui » ou « non » à une
     * seule question : « ces identifiants permettent-ils de fermer la station ? ». Aucun
     * jeton, aucun cookie, rien à rejouer.
     */
    case 'station.auth': {
        require_once __DIR__ . '/inc/totp.php';

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $u  = substr(trim((string) ($_POST['user'] ?? '')), 0, 64);
        $p  = (string) ($_POST['pass'] ?? '');
        $c  = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
        $poste = substr(trim((string) ($_POST['poste'] ?? '')), 0, 64);

        try {
            $db->exec('CREATE TABLE IF NOT EXISTS pf_station_auth (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, ts DATETIME NOT NULL,
                ip VARCHAR(45) NOT NULL DEFAULT \'\', poste VARCHAR(64) NOT NULL DEFAULT \'\',
                username VARCHAR(64) NOT NULL DEFAULT \'\', ok TINYINT(1) NOT NULL DEFAULT 0,
                motif VARCHAR(64) NOT NULL DEFAULT \'\', INDEX(ts), INDEX(ip))');
        } catch (Throwable $e) {}

        if ($u === '' || $p === '') { jout(['error' => 'Identifiant et mot de passe requis.'], 400); }

        // ── LIMITATION DES TENTATIVES ──────────────────────────────────────────────
        // La console de connexion, elle, n'en a AUCUNE — ce n'est pas une raison pour
        // ouvrir une seconde porte sans serrure. Deux pièges, tous deux relevés par un
        // audit adversarial et corrigés ici :
        //
        // 1) TOCTOU. Compter les échecs PUIS enregistrer le nouveau n'est pas atomique :
        //    entre les deux se glisse le bcrypt (~370 ms). Une rafale concurrente lit
        //    toutes « compte < seuil » avant que le premier échec ne soit écrit, et franchit
        //    le plafond en masse. On sérialise donc « compter + réserver » sous un verrou
        //    nommé, et l'on RÉSERVE la tentative (ligne ok=0) AVANT le bcrypt — ainsi la
        //    tentative suivante, sous le même verrou, la voit déjà.
        //
        // 2) Plafond par IP seul. $ip = REMOTE_ADDR : sur un LAN, l'attaquant fait tourner
        //    ses adresses sources et repart d'un compteur neuf à chaque fois. On plafonne
        //    donc AUSSI par compte cible (username), indépendant de l'IP.
        //
        // Le verrou n'est tenu que le temps du comptage et de la réservation (quelques
        // millisecondes) ; le bcrypt coûteux se fait hors verrou, sans goulot pour l'usage
        // légitime. La connexion PDO n'étant pas persistante, le verrou est de toute façon
        // relâché à la fin du script, même sur un exit imprévu.
        $reserveId = null;
        try {
            $lk = $db->prepare('SELECT GET_LOCK(?, 5)');
            $lk->execute(['pf_station_auth']);
            if ((int) $lk->fetchColumn() !== 1) {
                jout(['error' => 'Service occupé, réessayez dans un instant.'], 503);
            }

            $st = $db->prepare('SELECT
                COALESCE(SUM(username = ?), 0) AS par_compte,
                COALESCE(SUM(ip = ?), 0)       AS par_ip
                FROM pf_station_auth
                WHERE ok = 0 AND ts > NOW() - INTERVAL 15 MINUTE');
            $st->execute([$u, $ip]);
            $r = $st->fetch();
            if ((int) $r['par_compte'] >= 10 || (int) $r['par_ip'] >= 5) {
                // Tracé avec ok=2 (ni succès, ni échec de mot de passe) : un blocage NE
                // DOIT PAS compter comme un échec, sinon marteler la porte prolongerait
                // indéfiniment la fenêtre de 15 minutes.
                $db->prepare('INSERT INTO pf_station_auth (ts,ip,poste,username,ok,motif) VALUES (NOW(),?,?,?,2,?)')
                   ->execute([$ip, $poste, $u, 'trop de tentatives']);
                $db->prepare('SELECT RELEASE_LOCK(?)')->execute(['pf_station_auth']);
                jout(['error' => 'Trop de tentatives. Réessayez dans 15 minutes.'], 429);
            }

            // Réservation : la tentative COMPTE dès maintenant, avant le bcrypt. C'est ce
            // qui ferme le TOCTOU. Elle sera finalisée en succès (ok=1) ou laissée en
            // échec (ok=0) selon le résultat.
            $db->prepare('INSERT INTO pf_station_auth (ts,ip,poste,username,ok,motif) VALUES (NOW(),?,?,?,0,?)')
               ->execute([$ip, $poste, $u, 'en cours']);
            $reserveId = (int) $db->lastInsertId();
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute(['pf_station_auth']);
        } catch (Throwable $e) {
            // La limite s'appuie sur la table du journal : si on ne peut ni compter ni
            // tracer, on REFUSE plutôt que d'ouvrir sans garde-fou. Le poste reste
            // utilisable (analyse des clés) et le bouton Éteindre demeure.
            try { $db->prepare('SELECT RELEASE_LOCK(?)')->execute(['pf_station_auth']); } catch (Throwable $e2) {}
            jout(['error' => 'Journal d\'authentification indisponible : fermeture impossible pour le moment.'], 503);
        }

        // Finalise la ligne réservée : succès (ok=1) ou motif d'échec (la ligne reste ok=0
        // et continue donc de compter dans le plafond). On met à jour, on n'insère pas :
        // une seule ligne par tentative, celle qui a servi au comptage.
        $trace = function (bool $ok, string $motif) use ($db, $reserveId) {
            try {
                if ($reserveId) {
                    $db->prepare('UPDATE pf_station_auth SET ok=?, motif=? WHERE id=?')
                       ->execute([$ok ? 1 : 0, $motif, $reserveId]);
                }
            } catch (Throwable $e) {}
        };

        $row = null;
        try {
            $st = $db->prepare('SELECT password_hash, totp_secret, totp_enabled FROM pf_admins WHERE username = ?');
            $st->execute([$u]);
            $row = $st->fetch();
        } catch (Throwable $e) {}

        // ── ÉNUMÉRATION DES COMPTES ────────────────────────────────────────────────
        // Compte inconnu : on vérifie quand même le mot de passe, contre un LEURRE. Sans
        // cela, la réponse revient bien plus vite pour un identifiant qui n'existe pas, et
        // l'on énumère les comptes au chronomètre sans jamais deviner un mot de passe.
        //
        // Le leurre est un VRAI hash pris dans la table, et pas une constante écrite ici :
        // MESURÉ, un hash en coût 10 face aux hash en coût 12 que produit PHP aujourd'hui
        // donnait 81 ms contre 372 ms — 78 % d'écart, soit exactement la fuite qu'on
        // prétendait boucher. Un hash de la base a forcément le même coût que les autres,
        // quelle que soit la version de PHP qui les a créés.
        $hash = $row['password_hash'] ?? null;
        if ($hash === null) {
            try { $hash = (string) $db->query('SELECT password_hash FROM pf_admins LIMIT 1')->fetchColumn(); }
            catch (Throwable $e) { $hash = ''; }
        }
        // password_verify sur une chaîne vide rend false sans calculer : sans compte en
        // base il n'y a de toute façon rien à énumérer.
        $bon = $hash !== '' && $hash !== false && password_verify($p, $hash) && !empty($row);

        if (!$bon) {
            // Message IDENTIQUE pour un identifiant inconnu et un mot de passe faux :
            // distinguer les deux revient à confirmer l'existence d'un compte.
            $trace(false, 'identifiants refusés');
            jout(['error' => 'Identifiant ou mot de passe incorrect.'], 401);
        }

        if (!empty($row['totp_enabled'])) {
            $sec = (string) ($row['totp_secret'] ?? '');
            if ($c === '') {
                // On le dit APRÈS avoir validé le mot de passe : annoncer « ce compte a un
                // 2FA » avant renseignerait un attaquant qui n'a pas le mot de passe.
                $trace(false, 'code 2FA manquant');
                jout(['error' => 'Ce compte exige un code à deux facteurs.', 'totp' => true], 401);
            }
            if ($sec === '' || !totp_verify($sec, $c)) {
                $trace(false, 'code 2FA refusé');
                jout(['error' => 'Code à deux facteurs incorrect.', 'totp' => true], 401);
            }
        }

        $trace(true, 'fermeture autorisée');
        jout(['ok' => true, 'user' => $u]);
    }

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
        // POST accepté : les stations passent par là pour que leur jeton reste hors de
        // l'URL — une chaîne de requête finit dans les journaux d'accès d'Apache.
        $demande = (string) ($_POST['file'] ?? $_GET['file'] ?? '');

        if ($demande === '') {
            $liste = [];
            $bloques = [];
            foreach ($permis as $f) {
                $p = $dir . '/' . $f;
                if (!is_file($p)) { continue; }
                // Un fichier présent mais illisible n'est PAS un fichier absent : c'est un
                // problème de droits sur /var/lib/clamav, et il se règle en une commande.
                // Les confondre enverrait l'exploitant réinstaller ClamAV pour rien.
                if (!is_readable($p)) { $bloques[] = $f; continue; }
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
            jout([
                'ok'         => true,
                'base'       => $liste,
                'date_base'  => $liste ? max(array_column($liste, 'date')) : 0,
                'illisibles' => $bloques,
                'motif'      => $liste ? null : ($bloques
                    ? 'Base présente mais illisible par le serveur web : vérifiez les droits sur ' . $dir
                    : 'Aucune base ClamAV sur la passerelle : lancez provisioning/setup-antivirus.sh'),
            ]);
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

    case 'station.bitlocker': {
        // Escrow de la clé de récupération BitLocker d'une clé USB préparée par la station.
        // La station est hors domaine : elle ne peut PAS sauvegarder dans l'AD comme les postes,
        // on centralise donc la clé de récupération ici, visible dans la console (Antivirus).
        $poste  = substr(trim((string) ($_POST['poste'] ?? '')), 0, 64);
        $op     = substr(trim((string) ($_POST['operateur'] ?? '')), 0, 64);
        $volume = substr(trim((string) ($_POST['volume'] ?? '')), 0, 96);
        $rec    = substr(trim((string) ($_POST['recovery'] ?? '')), 0, 80);
        if ($rec === '') { jout(['error' => 'cle de recuperation manquante'], 400); }
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS pf_usb_keys (
                id INT AUTO_INCREMENT PRIMARY KEY, ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                poste VARCHAR(64), operateur VARCHAR(64), volume VARCHAR(96),
                recovery VARCHAR(80), ip VARCHAR(45))');
            $db->prepare('INSERT INTO pf_usb_keys (poste,operateur,volume,recovery,ip) VALUES (?,?,?,?,?)')
               ->execute([$poste, $op, $volume, $rec, (string) ($_SERVER['REMOTE_ADDR'] ?? '')]);
        } catch (Throwable $e) { jout(['error' => 'stockage impossible'], 500); }
        jout(['ok' => true]);
    }

    case 'poste.photo': {
        // Photo d'un agent, pour que le poste la pose comme image de compte Windows.
        // Renvoie l'image telle quelle (PNG) ; 404 si l'agent n'en a pas.
        $u = preg_replace('/[^A-Za-z0-9._@-]/', '', (string) ($_GET['user'] ?? ''));
        if ($u === '') { jout(['error' => 'utilisateur requis'], 400); }
        try {
            // « v » d'abord : au démarrage d'un poste, la stratégie interroge cette action
            // une fois PAR PROFIL. Répondre 304 quand rien n'a changé évite de renvoyer
            // 100 Ko à chaque fois, à un moment où le poste a mieux à faire.
            $st = $db->prepare('SELECT v FROM pf_user_photo WHERE username=?');
            $st->execute([$u]);
            $ver = (string) $st->fetchColumn();
        } catch (Throwable $e) { $ver = ''; }
        if ($ver === '') { jout(['error' => 'aucune photo'], 404); }
        $etag = '"' . $ver . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=0, must-revalidate');
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        try {
            $st = $db->prepare('SELECT photo FROM pf_user_photo WHERE username=?');
            $st->execute([$u]);
            $img = $st->fetchColumn();
        } catch (Throwable $e) { $img = false; }
        if ($img === false || $img === null || $img === '') { jout(['error' => 'aucune photo'], 404); }
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen((string) $img));
        echo $img;
        exit;
    }

    case 'poste.inventaire': {
        // Remontée d'inventaire par un poste du domaine (script d'ouverture de session).
        // Le corps est du JSON ; on ne conserve QUE des champs connus, bornés en longueur.
        $raw = file_get_contents('php://input');
        if ($raw === false || strlen($raw) > 512 * 1024) { jout(['error' => 'corps invalide'], 400); }
        $d = json_decode((string) $raw, true);
        if (!is_array($d)) { jout(['error' => 'json invalide'], 400); }
        $s = fn($k, $max = 190) => substr(trim((string) ($d[$k] ?? '')), 0, $max);
        $i = fn($k) => (int) ($d[$k] ?? 0);
        $poste = strtoupper($s('poste', 64));
        if ($poste === '' || !preg_match('/^[A-Z0-9._-]{1,64}$/', $poste)) { jout(['error' => 'nom de poste invalide'], 400); }
        // Les logiciels sont stockés à part, en JSON, et plafonnés.
        $apps = [];
        if (isset($d['logiciels']) && is_array($d['logiciels'])) {
            foreach (array_slice($d['logiciels'], 0, 400) as $a) {
                if (!is_array($a)) { continue; }
                $n = substr(trim((string) ($a['n'] ?? '')), 0, 160);
                if ($n !== '') { $apps[] = ['n' => $n, 'v' => substr(trim((string) ($a['v'] ?? '')), 0, 40)]; }
            }
        }
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS pf_inventaire (
                poste VARCHAR(64) PRIMARY KEY, vu_le TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                utilisateur VARCHAR(64), domaine VARCHAR(96),
                os_nom VARCHAR(190), os_version VARCHAR(40), os_build VARCHAR(20), os_sku INT, os_install DATE NULL,
                fabricant VARCHAR(96), modele VARCHAR(96), serie VARCHAR(96), bios VARCHAR(190), type_machine INT,
                processeur VARCHAR(190), coeurs INT, memoire_mo INT,
                disque_go INT, libre_go INT, disque_mdl VARCHAR(190),
                ip VARCHAR(45), mac VARCHAR(20), secureboot INT, ip_source VARCHAR(45),
                logiciels MEDIUMTEXT,
                horloge_ecart INT NULL, apps_ok INT DEFAULT 0, apps_log TEXT,
                activation VARCHAR(24), activation_det VARCHAR(190), navigateur VARCHAR(64))');
            // Migration des inventaires créés avant l'ajout du diagnostic embarqué.
            foreach (['horloge_ecart INT NULL', 'apps_ok INT DEFAULT 0', 'apps_log TEXT',
                      'activation VARCHAR(24)', 'activation_det VARCHAR(190)',
                      'navigateur VARCHAR(64)'] as $col) {
                try { $db->exec('ALTER TABLE pf_inventaire ADD COLUMN ' . $col); } catch (Throwable $e) {}
            }
            $inst = $s('os_install', 10); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inst)) { $inst = null; }
            $db->prepare('INSERT INTO pf_inventaire
                (poste,utilisateur,domaine,os_nom,os_version,os_build,os_sku,os_install,fabricant,modele,serie,bios,
                 type_machine,processeur,coeurs,memoire_mo,disque_go,libre_go,disque_mdl,ip,mac,secureboot,ip_source,logiciels,
                 horloge_ecart,apps_ok,apps_log,activation,activation_det,navigateur)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE vu_le=NOW(), utilisateur=VALUES(utilisateur), domaine=VALUES(domaine),
                 os_nom=VALUES(os_nom), os_version=VALUES(os_version), os_build=VALUES(os_build), os_sku=VALUES(os_sku),
                 os_install=VALUES(os_install), fabricant=VALUES(fabricant), modele=VALUES(modele), serie=VALUES(serie),
                 bios=VALUES(bios), type_machine=VALUES(type_machine), processeur=VALUES(processeur), coeurs=VALUES(coeurs),
                 memoire_mo=VALUES(memoire_mo), disque_go=VALUES(disque_go), libre_go=VALUES(libre_go),
                 disque_mdl=VALUES(disque_mdl), ip=VALUES(ip), mac=VALUES(mac), secureboot=VALUES(secureboot),
                 ip_source=VALUES(ip_source), logiciels=VALUES(logiciels),
                 horloge_ecart=VALUES(horloge_ecart), apps_ok=VALUES(apps_ok), apps_log=VALUES(apps_log),
                 activation=VALUES(activation), activation_det=VALUES(activation_det),
                 navigateur=VALUES(navigateur)')
               ->execute([$poste, $s('utilisateur', 64), $s('domaine', 96), $s('os_nom'), $s('os_version', 40),
                          $s('os_build', 20), $i('os_sku'), $inst, $s('fabricant', 96), $s('modele', 96),
                          $s('serie', 96), $s('bios'), $i('type'), $s('processeur'), $i('coeurs'), $i('memoire_mo'),
                          $i('disque_go'), $i('libre_go'), $s('disque_mdl'), $s('ip', 45), $s('mac', 20),
                          $i('secureboot'), (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                          json_encode($apps, JSON_UNESCAPED_UNICODE),
                          isset($d['horloge_ecart']) && $d['horloge_ecart'] !== null ? (int) $d['horloge_ecart'] : null,
                          $i('apps_ok'), substr((string) ($d['apps_log'] ?? ''), 0, 8000),
                          $s('activation', 24), $s('activation_det', 190), $s('navigateur', 64)]);
        } catch (Throwable $e) { jout(['error' => 'stockage impossible'], 500); }
        jout(['ok' => true, 'poste' => $poste, 'logiciels' => count($apps)]);
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
