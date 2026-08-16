<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Utilisateurs & droits (fusion portail Internet + Active Directory).
 * Un compte = accès Internet (RADIUS) et/ou compte domaine (AD), avec des droits de
 * gestion : administrateur de la console et/ou administrateur du domaine.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/userphoto.php';
require_once __DIR__ . '/inc/audit.php';
$db = pf_db();

require_once __DIR__ . '/inc/adcache.php';
$dcUp = trim((string) shell_exec('systemctl is-active samba-ad-dc 2>/dev/null')) === 'active';

/**
 * Format d'identifiant : matricule à 7 chiffres (ex. 0110480) pour un agent,
 * ou « admin-<matricule> » (ex. admin-0110480) pour un administrateur.
 * Le compte « admin » intégré reste toléré.
 */
function pf_valid_id(string $u): bool {
    return $u === 'admin' || (bool) preg_match('/^(admin-)?\d{7}$/', $u);
}
const PF_ID_HINT = 'Identifiant attendu : matricule à 7 chiffres (ex. 0110480) ou « admin-0110480 » pour un administrateur.';

/**
 * Complexité minimale d'un mot de passe de COMPTE DOMAINE ou d'ADMINISTRATEUR DE CONSOLE.
 *
 * Rien ne la vérifiait ici : côté domaine, samba-tool refusait la création et son message
 * partait dans un coin du bandeau ; côté console, RIEN ne refusait quoi que ce soit — on
 * pouvait donner à un administrateur de Bastion un mot de passe d'un caractère, et la
 * console l'acceptait sans un mot. On vérifie donc AVANT d'écrire.
 *
 * Ce seuil est un plancher, pas la règle du domaine : la stratégie par défaut de l'AD peut
 * exiger davantage (longueur, symboles). Le message le dit, pour que l'exploitant ne conclue
 * pas d'un refus du domaine que la console lui a menti.
 *
 * @return string|null  ce qui manque, ou null si le mot de passe convient.
 */
function pf_mdp_faible(string $p): ?string {
    if (mb_strlen($p) < 8)               { return 'au moins 8 caractères'; }
    if (!preg_match('/\p{Lu}/u', $p))    { return 'au moins une majuscule'; }
    if (!preg_match('/\d/', $p))         { return 'au moins un chiffre'; }
    return null;
}

// Compte de l'administrateur connecté : sert de garde-fou contre l'auto-exclusion.
$moi = (string) ($_SESSION['admin'] ?? '');

/**
 * Tout ce qui interdit d'enregistrer la fiche, en clair. Tableau vide = on peut écrire.
 *
 * POURQUOI VÉRIFIER EN AMONT. Le contrôle était dispersé au fil des écritures : un mot de
 * passe manquant affichait bien « requis », mais les lignes suivantes s'exécutaient quand
 * même — le groupe du portail effacé, le compte domaine créé à moitié. On repartait d'une
 * fiche refusée ET d'un compte pourtant modifié, sans savoir lequel des deux croire. Un
 * seul refus annule désormais l'enregistrement entier.
 */
function pf_refus_save(PDO $db, string $u, bool $dcUp, string $moi, bool $existsPortal, bool $existsAd): array {
    $portal  = isset($_POST['portal']);
    $adAcct  = isset($_POST['ad_account']) && $dcUp;
    $isAdmin = isset($_POST['role_admin']);
    $p       = (string) ($_POST['password'] ?? '');
    $existsAdmin = (bool) $db->query('SELECT 1 FROM pf_admins WHERE username=' . $db->quote($u))->fetchColumn();
    $r = [];

    // ── LE FORMAT NE S'IMPOSE QU'À LA CRÉATION ───────────────────────────────
    // Les comptes hérités (« dupont.jean ») sont antérieurs à la règle du matricule, et le
    // contrôle s'appliquait aussi à leur MODIFICATION. Le champ identifiant étant en lecture
    // seule en édition, la console renvoyait « identifiant invalide » sur une valeur que
    // l'exploitant n'avait aucun moyen de corriger : ces comptes n'étaient plus modifiables
    // du tout — ni date de fin, ni commissariat, ni photo — seulement supprimables.
    $existe = $existsPortal || $existsAd || $existsAdmin
           || (bool) $db->query('SELECT 1 FROM radcheck WHERE username=' . $db->quote($u))->fetchColumn();
    if (!$existe && !pf_valid_id($u)) { $r[] = 'Identifiant invalide. ' . PF_ID_HINT; }

    // Demander un accès sans fournir de mot de passe ne crée rien du tout : on le refuse
    // au lieu d'enregistrer une fiche qui semble complète et ne donne accès à rien.
    if ($portal  && $p === '' && !$existsPortal) { $r[] = "Mot de passe requis pour créer l'accès Internet."; }
    if ($adAcct  && $p === '' && !$existsAd)     { $r[] = 'Mot de passe requis pour créer le compte domaine.'; }
    if ($isAdmin && $p === '' && !$existsAdmin)  { $r[] = 'Mot de passe requis pour créer cet administrateur de console.'; }

    if ($p !== '' && ($adAcct || $isAdmin) && ($manque = pf_mdp_faible($p)) !== null) {
        $r[] = 'Mot de passe trop simple pour un compte domaine ou un administrateur de console : il faut '
             . $manque . '. La stratégie du domaine peut en exiger davantage.';
    }

    // ── DÉCOCHER, C'EST DÉTRUIRE ─────────────────────────────────────────────
    // Décocher « Compte domaine » ne suspend pas le compte : il le SUPPRIME de l'annuaire.
    // Recréé ensuite, il porte un SID neuf — et Windows ouvre alors un profil NEUF : bureau
    // vide, documents de l'ancien profil hors de portée de l'agent. Un clic malencontreux
    // suivi d'« Enregistrer » suffisait, sans un mot. Il faut maintenant cocher la case qui
    // le dit ; la case n'apparaît que si la suppression est réellement en jeu.
    if (!$portal && $existsPortal && !isset($_POST['confirm_del_portal'])) {
        $r[] = 'Décocher « Accès Internet » SUPPRIME le compte du portail : confirmez-le dans la fiche.';
    }
    if (!$adAcct && $existsAd && !isset($_POST['confirm_del_ad'])) {
        $r[] = 'Décocher « Compte domaine » SUPPRIME le compte Active Directory : confirmez-le dans la fiche.';
    }

    // ── AUTO-EXCLUSION ───────────────────────────────────────────────────────
    // Se retirer son propre droit console ne se voit qu'à la déconnexion suivante, quand
    // il est trop tard pour revenir en arrière.
    if ($u !== '' && $u === $moi && !$isAdmin) {
        $r[] = "Vous ne pouvez pas retirer votre propre droit d'administration : vous perdriez l'accès à la console "
             . 'à la déconnexion. Demandez-le à un autre administrateur.';
    }
    return $r;
}

/**
 * Ce qui interdit une action EN MASSE. null = on peut l'appliquer.
 *
 * La fiche individuelle ne laisse plus désigner un groupe inexistant — le champ libre y a
 * été remplacé par une liste déroulante, justement parce qu'une faute de frappe rattachait
 * l'agent à un groupe fantôme : plus de quota, plus d'horaires, plus de tunnel, et rien
 * pour l'annoncer. La barre d'action en masse, elle, était restée en saisie libre : la même
 * faute y reclassait CINQUANTE agents d'un coup, tout aussi silencieusement. L'argument est
 * donc vérifié avant que le premier compte ne soit touché — un refus ne laisse pas de
 * traitement à moitié fait.
 */
function pf_refus_masse(string $op, string $arg, bool $dcUp): ?string {
    if ($op === 'setgroup' && $arg !== '') {
        $connus = [];
        try { $connus = pf_db()->query('SELECT groupname FROM pf_groups')->fetchAll(PDO::FETCH_COLUMN) ?: []; }
        catch (Throwable $e) { return null; }   // table illisible : on ne bloque pas sur une panne de lecture
        if (!in_array($arg, $connus, true)) {
            return 'Groupe portail « ' . $arg . " » inconnu : aucun compte n'a été touché. "
                 . "Créez-le d'abord dans « Groupes & quotas », ou choisissez-en un existant.";
        }
    }
    if ($op === 'addadgroup' && $arg !== '' && $dcUp) {
        $connus = [];
        try { $connus = ad_lines_cached('groups', 0, 'group', 'list'); } catch (Throwable $e) { return null; }
        // Liste vide = le contrôleur n'a rien répondu ; on ne transforme pas une panne de
        // lecture en refus, sans quoi l'action deviendrait impossible pendant l'incident.
        if ($connus && !in_array($arg, $connus, true)) {
            return 'Groupe AD « ' . $arg . " » inconnu : aucun compte n'a été touché.";
        }
    }
    if ($op === 'setpassword' && ($manque = pf_mdp_faible($arg)) !== null) {
        return 'Mot de passe trop simple : il faut ' . $manque . ". Aucun compte n'a été touché.";
    }
    return null;
}

// ── Commissariats (groupes d'appartenance) + affectation par compte ──────────
$sites = [];        // id => ['name','cpn']
foreach ($db->query('SELECT id,name,cpn FROM pf_commissariats ORDER BY cpn,name') as $r) {
    $sites[(int) $r['id']] = ['name' => (string) $r['name'], 'cpn' => (string) $r['cpn']];
}
$siteByName = [];   // nom minuscule => id (pour l'import CSV et les actions en masse)
foreach ($sites as $id => $s) { $siteByName[mb_strtolower($s['name'])] = $id; }
$userSite = [];     // username => id du commissariat
foreach ($db->query('SELECT username,commissariat_id FROM pf_user_site') as $r) {
    $userSite[(string) $r['username']] = (int) $r['commissariat_id'];
}
$profiles = [];     // username => ['nom','prenom','service'] (identité du fonctionnaire)
foreach ($db->query('SELECT username,nom,prenom,service FROM pf_user_profile') as $r) {
    $profiles[(string) $r['username']] = ['nom' => (string) $r['nom'], 'prenom' => (string) $r['prenom'], 'service' => (string) $r['service']];
}
// Photos des fonctionnaires (stockées en base, ré-encodées ; servies par user-photo.php).
userphoto_migre($db);
$photoV = userphoto_all_versions($db);   // username => version de la photo (absent = pas de photo)

// Désactivation programmée : date de fin d'accès par compte (fin de mission, mutation).
try { $db->exec('CREATE TABLE IF NOT EXISTS pf_user_expiry (username VARCHAR(64) PRIMARY KEY, expires_at DATE, applied TINYINT(1) DEFAULT 0, set_by VARCHAR(64), set_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)'); } catch (Throwable $e) {}
$expiry = [];   // username => 'YYYY-MM-DD'
try { foreach ($db->query('SELECT username,expires_at FROM pf_user_expiry WHERE expires_at IS NOT NULL') as $r) { $expiry[(string) $r['username']] = (string) $r['expires_at']; } } catch (Throwable $e) {}

// Niveaux d'accès des administrateurs console (garde-fou : « admin » toujours complet — cf. inc/auth.php).
try { $db->exec("ALTER TABLE pf_admins ADD COLUMN IF NOT EXISTS role VARCHAR(16) DEFAULT 'full'"); } catch (Throwable $e) {}
$adminRole = [];   // username => full|comptes|lecture
try { foreach ($db->query('SELECT username,role FROM pf_admins') as $r) { $adminRole[(string) $r['username']] = (string) ($r['role'] ?: 'full'); } } catch (Throwable $e) {}
function pf_set_profile(PDO $db, string $u, string $nom, string $prenom, string $service): void {
    if ($nom === '' && $prenom === '' && $service === '') {
        $db->prepare('DELETE FROM pf_user_profile WHERE username=?')->execute([$u]);
    } else {
        $db->prepare('INSERT INTO pf_user_profile (username,nom,prenom,service) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE nom=VALUES(nom), prenom=VALUES(prenom), service=VALUES(service)')->execute([$u, $nom, $prenom, $service]);
    }
    // Report de l'identité dans l'ANNUAIRE : sans « displayName », Windows affiche le matricule
    // sur l'écran de session et dans le menu Démarrer. L'agent doit voir son nom, pas 0110480.
    ad('user', 'identity', $u, $prenom, $nom, $service);
}
function pf_set_site(PDO $db, array $sites, string $u, int $sid): void {
    if ($sid > 0 && isset($sites[$sid])) {
        $db->prepare('INSERT INTO pf_user_site (username,commissariat_id) VALUES (?,?) ON DUPLICATE KEY UPDATE commissariat_id=VALUES(commissariat_id)')->execute([$u, $sid]);
    } else {
        $db->prepare('DELETE FROM pf_user_site WHERE username=?')->execute([$u]);
    }
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Toute action de cette page peut modifier l'annuaire (création, suppression, groupes,
    // mot de passe) : on purge le cache de lecture pour que les autres pages voient l'état réel.
    ad_cache_clear();
    $action = $_POST['action'] ?? '';
    $u = trim((string) ($_POST['username'] ?? ''));
    $u = preg_replace('/[^A-Za-z0-9._@-]/', '', $u);

    // L'état réel du compte se lit UNE fois : « ad_lines » interroge le contrôleur de
    // domaine, et le relire dans la validation puis dans les écritures doublait l'appel.
    $refus = [];
    $existsPortal = $existsAd = false;
    if ($action === 'save' && $u !== '') {
        $existsPortal = (bool) $db->query('SELECT 1 FROM radcheck WHERE username=' . $db->quote($u) . ' AND attribute="Cleartext-Password"')->fetchColumn();
        $existsAd     = $dcUp && in_array($u, ad_lines('user', 'list'), true);
        $refus        = pf_refus_save($db, $u, $dcUp, $moi, $existsPortal, $existsAd);
    }

    if ($refus) {
        $flash = [implode(' ', $refus), 'err'];
    } elseif ($action === 'save' && $u !== '') {
        $p        = (string) ($_POST['password'] ?? '');
        $portal   = isset($_POST['portal']);
        $adAcct   = isset($_POST['ad_account']) && $dcUp;
        $pgroup   = trim((string) ($_POST['pgroup'] ?? ''));
        $adgroup  = trim((string) ($_POST['adgroup'] ?? ''));
        $isAdmin  = isset($_POST['role_admin']);       // administrateur console
        $isDomAdm = isset($_POST['role_domadmin']);    // administrateur domaine
        $detruit  = [];                                // ce que cet enregistrement a supprimé (pour l'audit)
        $msgs = [];

        // ── Accès Internet (portail RADIUS) ──
        if ($portal) {
            if ($p !== '') {
                $db->prepare('DELETE FROM radcheck WHERE username=? AND attribute="Cleartext-Password"')->execute([$u]);
                $db->prepare('INSERT INTO radcheck (username,attribute,op,value) VALUES (?,"Cleartext-Password",":=",?)')->execute([$u, $p]);
            }
            $db->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$u]);
            if ($pgroup !== '') { $db->prepare('INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)')->execute([$u, $pgroup]); }
        } else {
            $db->prepare('DELETE FROM radcheck WHERE username=?')->execute([$u]);
            $db->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$u]);
            if ($existsPortal) { $detruit[] = 'portail'; }
        }

        // ── Compte domaine (AD) ──
        if ($adAcct) {
            if (!$existsAd) {
                $out = ad('user', 'create', $u, $p);
                if (preg_match('/ERROR|Failed|password/i', $out)) { $msgs[] = 'AD : ' . trim($out); }
            } elseif ($p !== '') {
                ad('user', 'setpassword', $u, $p);
            }
            if ($adgroup !== '') { ad('group', 'addmembers', $adgroup, $u); }
        } elseif ($existsAd) {
            ad('user', 'delete', $u);
            $isDomAdm = false;
            $detruit[] = 'domaine';
        }

        // ── Droit : administrateur de la console ──
        if ($isAdmin) {
            if ($p !== '') {
                $h = password_hash($p, PASSWORD_DEFAULT);
                $db->prepare('INSERT INTO pf_admins (username,password_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)')->execute([$u, $h]);
            }
            // Niveau d'accès (rôle). JAMAIS pour « admin » : il reste complet quoi qu'il arrive.
            $lvl = in_array($_POST['admin_level'] ?? '', ['full', 'comptes', 'lecture'], true) ? (string) $_POST['admin_level'] : 'full';
            if ($u !== 'admin') { try { $db->prepare('UPDATE pf_admins SET role=? WHERE username=?')->execute([$lvl, $u]); } catch (Throwable $e) {} }
        } else {
            if ($u !== 'admin') { $db->prepare('DELETE FROM pf_admins WHERE username=?')->execute([$u]); }
        }

        // ── Droit : administrateur du domaine ──
        // Le retrait n'est tenté que si le compte EXISTE dans l'annuaire : sans ce test,
        // chaque enregistrement d'un agent sans compte domaine lançait un samba-tool pour
        // le sortir d'un groupe où il n'a jamais été — une seconde d'attente pour rien.
        if ($dcUp && ($adAcct || $existsAd)) {
            if ($isDomAdm && $adAcct) { ad('group', 'addmembers', 'Domain Admins', $u); }
            elseif (!$isDomAdm)       { ad('group', 'removemembers', 'Domain Admins', $u); }
        }

        // ── Commissariat (groupe d'appartenance) ──
        pf_set_site($db, $sites, $u, (int) ($_POST['commissariat'] ?? 0));
        $userSite[$u] = (int) ($_POST['commissariat'] ?? 0);

        // ── Identité du fonctionnaire (nom / prénom / service) ──
        pf_set_profile($db, $u, trim((string) ($_POST['nom'] ?? '')), trim((string) ($_POST['prenom'] ?? '')), trim((string) ($_POST['service'] ?? '')));

        // ── Photo du fonctionnaire (optionnelle) ──
        if (isset($_POST['photo_remove'])) {
            userphoto_supprimer($db, $u);
        } elseif (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            [$pok, $pmsg] = userphoto_traiter($db, $u, $_FILES['photo']);
            if (!$pok) { $msgs[] = 'Photo : ' . $pmsg; }
        }

        // ── Date de fin d'accès (désactivation programmée le jour venu, par la minuterie) ──
        $exp = trim((string) ($_POST['expires_at'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) {
            $db->prepare('INSERT INTO pf_user_expiry (username,expires_at,applied,set_by) VALUES (?,?,0,?)
                          ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at), applied=0, set_by=VALUES(set_by)')
               ->execute([$u, $exp, $_SESSION['admin'] ?? '']);
        } else {
            $db->prepare('DELETE FROM pf_user_expiry WHERE username=?')->execute([$u]);
        }

        // Ce qui a été SUPPRIMÉ est annoncé dans le bandeau, pas seulement subi : « enregistré »
        // tout court laissait croire à une simple mise à jour alors qu'un compte venait de
        // disparaître de l'annuaire.
        $quoi = $detruit ? ' Accès supprimé : ' . implode(' et ', $detruit) . '.' : '';
        $flash = ['Compte « ' . $u . ' » enregistré.' . $quoi . ($msgs ? ' ' . implode(' ', $msgs) : ''), $msgs ? 'err' : 'ok'];
        audit('users.save', $u . ($detruit ? ' (suppression ' . implode('+', $detruit) . ')' : ''));
    }

    // Se supprimer soi-même réussit sans rien signaler : la page se recharge, et l'accès
    // n'est perdu qu'à la déconnexion suivante — quand il n'y a plus de quoi revenir.
    if ($action === 'delete' && $u !== '' && $u === $moi) {
        $flash = ['Vous ne pouvez pas supprimer votre propre compte. Demandez-le à un autre administrateur.', 'err'];
    } elseif ($action === 'delete' && $u !== '') {
        $db->prepare('DELETE FROM radcheck WHERE username=?')->execute([$u]);
        $db->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$u]);
        if ($u !== 'admin') { $db->prepare('DELETE FROM pf_admins WHERE username=?')->execute([$u]); }
        $db->prepare('DELETE FROM pf_user_site WHERE username=?')->execute([$u]);
        $db->prepare('DELETE FROM pf_user_profile WHERE username=?')->execute([$u]);
        userphoto_supprimer($db, $u);
        $db->prepare('DELETE FROM pf_user_expiry WHERE username=?')->execute([$u]);
        if ($dcUp && in_array($u, ad_lines('user', 'list'), true)) { ad('user', 'delete', $u); }
        // Déconnecter du portail si en ligne
        foreach (nds_clients() as $mac => $c) {
            if (!empty($c['custom']) && ($d = base64_decode($c['custom'], true)) && strpos($d, 'user=' . $u) !== false) {
                shell_exec('sudo /usr/bin/ndsctl deauth ' . escapeshellarg($mac) . ' 2>/dev/null');
            }
        }
        $flash = ['Compte « ' . $u . ' » supprimé (portail + domaine + droits).', 'ok'];
        audit('users.delete', $u);
    }

    // ── Rattrapage : reporter dans l'annuaire l'identité de TOUS les comptes ──
    // Les comptes créés avant que la console ne renseigne « displayName » affichent encore
    // leur matricule sur les postes. Ce bouton les met tous à jour d'un coup.
    if ($action === 'sync_identity' && $dcUp) {
        $n = $np = 0; $adList = ad_lines('user', 'list');
        foreach ($profiles as $pu => $pr) {
            if (!in_array($pu, $adList, true)) { continue; }
            if ($pr['nom'] !== '' || $pr['prenom'] !== '') {
                $r = ad('user', 'identity', $pu, $pr['prenom'], $pr['nom'], $pr['service']);
                if (stripos($r, 'ERROR') === false) { $n++; }
            }
        }
        // Photos déjà enregistrées dans la console : on les publie aussi dans l'annuaire.
        try {
            $st = $db->query('SELECT username, photo FROM pf_user_photo');
            foreach ($st as $row) {
                $pu = (string) $row['username'];
                if (!in_array($pu, $adList, true)) { continue; }
                userphoto_publier_ad($pu, (string) $row['photo']);
                $np++;
            }
        } catch (Throwable $e) {}
        audit('users.sync_identity', $n . ' identites, ' . $np . ' photos');
        $flash = [($n + $np) > 0
            ? "$n identité(s) et $np photo(s) publiées dans l'annuaire. Effet à la prochaine ouverture de session."
            : "Aucun compte à mettre à jour (renseignez d'abord nom et prénom).", ($n + $np) > 0 ? 'ok' : 'err'];
    }

    // ── Actions en masse sur une sélection ──────────────────────────────────
    $refusMasse = null;
    if ($action === 'bulk') {
        $op   = (string) ($_POST['bulk_op'] ?? '');
        $sel  = array_values(array_filter(array_map(
            fn($x) => preg_replace('/[^A-Za-z0-9._@-]/', '', (string) $x),
            (array) ($_POST['sel'] ?? [])), fn($x) => $x !== ''));
        $arg  = trim((string) ($_POST['bulk_arg'] ?? ''));
        $refusMasse = pf_refus_masse($op, $arg, $dcUp);
    }
    if ($action === 'bulk' && $refusMasse !== null) {
        $flash = [$refusMasse, 'err'];
    } elseif ($action === 'bulk') {
        $n = 0; $skip = 0;
        $adList = $dcUp ? ad_lines('user', 'list') : [];
        foreach ($sel as $su) {
            if ($op === 'delete') {
                // « admin » est intouchable, et l'on ne se supprime pas soi-même : la barre
                // de masse permettait d'emporter son propre compte au milieu de la sélection.
                if ($su === 'admin' || $su === $moi) { $skip++; continue; }
                $db->prepare('DELETE FROM radcheck WHERE username=?')->execute([$su]);
                $db->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$su]);
                $db->prepare('DELETE FROM pf_admins WHERE username=?')->execute([$su]);
                $db->prepare('DELETE FROM pf_user_site WHERE username=?')->execute([$su]);
                $db->prepare('DELETE FROM pf_user_profile WHERE username=?')->execute([$su]);
                userphoto_supprimer($db, $su);
                $db->prepare('DELETE FROM pf_user_expiry WHERE username=?')->execute([$su]);
                if ($dcUp && in_array($su, $adList, true)) { ad('user', 'delete', $su); }
                foreach (nds_clients() as $mac => $c) {
                    if (!empty($c['custom']) && ($d = base64_decode($c['custom'], true)) && strpos($d, 'user=' . $su) !== false) {
                        shell_exec('sudo /usr/bin/ndsctl deauth ' . escapeshellarg($mac) . ' 2>/dev/null');
                    }
                }
                $n++;
            } elseif ($op === 'setgroup') {
                $db->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$su]);
                if ($arg !== '') { $db->prepare('INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)')->execute([$su, $arg]); }
                $n++;
            } elseif ($op === 'setpassword' && $arg !== '') {
                // Un compte sans accès portail NI compte domaine ne reçoit rien : il était
                // pourtant compté comme traité. « 12 mots de passe réinitialisés » pour huit
                // changements réels, c'est la pire des réponses — on croit l'agent servi.
                $fait = false;
                if ((bool) $db->query('SELECT 1 FROM radcheck WHERE username=' . $db->quote($su) . ' AND attribute="Cleartext-Password"')->fetchColumn()) {
                    $db->prepare('UPDATE radcheck SET value=? WHERE username=? AND attribute="Cleartext-Password"')->execute([$arg, $su]);
                    $fait = true;
                }
                if ($dcUp && in_array($su, $adList, true)) { ad('user', 'setpassword', $su, $arg); $fait = true; }
                $fait ? $n++ : $skip++;
            } elseif ($op === 'addadgroup' && $arg !== '' && $dcUp) {
                if (in_array($su, $adList, true)) { ad('group', 'addmembers', $arg, $su); $n++; } else { $skip++; }
            } elseif ($op === 'setsite') {
                $sid = $arg === '' ? 0 : (int) ($siteByName[mb_strtolower($arg)] ?? 0);
                if ($arg !== '' && $sid === 0) { $skip++; continue; }
                pf_set_site($db, $sites, $su, $sid);
                $n++;
            }
        }
        $labels = ['delete'=>'supprimé·s', 'setgroup'=>'reclassé·s', 'setpassword'=>'mot de passe réinitialisé', 'addadgroup'=>'ajouté·s au groupe AD', 'setsite'=>'affecté·s au commissariat'];
        // Un compte ignoré l'est toujours pour une raison précise : la taire obligeait à
        // recompter les lignes à la main pour deviner lesquelles n'avaient pas bougé.
        $pourquoi = ['delete'=>'compte « admin » ou le vôtre', 'setpassword'=>'ni accès Internet ni compte domaine',
                     'addadgroup'=>'pas de compte domaine', 'setsite'=>'nom de commissariat inconnu'];
        $flash = [$n . ' compte(s) ' . ($labels[$op] ?? 'traité·s')
                . ($skip ? " — {$skip} ignoré·s (" . ($pourquoi[$op] ?? 'non applicable') . ')' : '') . '.', $n ? 'ok' : 'err'];
        // Une suppression en masse ne laissait AUCUNE trace dans le journal d'audit : la page
        // « Audit » montrait les suppressions une à une et ignorait celles de cinquante comptes.
        audit('users.bulk.' . $op, $n . ' compte(s)' . ($arg !== '' && $op !== 'setpassword' ? ' → ' . $arg : '')
            . ($skip ? ", {$skip} ignoré(s)" : ''));
    }

    // ── Import CSV en masse ─────────────────────────────────────────────────
    if ($action === 'import') {
        $raw = (string) ($_POST['csv'] ?? '');
        if (isset($_FILES['csvfile']) && is_uploaded_file($_FILES['csvfile']['tmp_name'] ?? '')) {
            $raw = (string) file_get_contents($_FILES['csvfile']['tmp_name']);
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $created = 0; $updated = 0; $adOk = 0; $errs = []; $line = 0;
        $adList = $dcUp ? ad_lines('user', 'list') : [];
        foreach (explode("\n", $raw) as $row) {
            $line++;
            $row = trim($row);
            if ($row === '' || $row[0] === '#') { continue; }
            $sep = (strpos($row, ';') !== false) ? ';' : ((strpos($row, "\t") !== false) ? "\t" : ',');
            $col = array_map('trim', explode($sep, $row));
            $iu = preg_replace('/[^A-Za-z0-9._@-]/', '', $col[0] ?? '');
            if ($iu === '') { continue; }
            if (strcasecmp($iu, 'identifiant') === 0 || strcasecmp($iu, 'username') === 0) { continue; } // en-tête
            if (!pf_valid_id($iu)) { $errs[] = "ligne {$line} ({$iu}) : identifiant invalide (matricule 7 chiffres ou admin-…)"; continue; }
            $ip  = $col[1] ?? '';
            $ipg = $col[2] ?? '';
            $iad = in_array(strtolower($col[3] ?? ''), ['1','oui','yes','o','x','true'], true);
            $existed  = (bool) $db->query('SELECT 1 FROM radcheck WHERE username=' . $db->quote($iu) . ' AND attribute="Cleartext-Password"')->fetchColumn();
            $adExists = $dcUp && in_array($iu, $adList, true);

            // ── MOT DE PASSE ABSENT : toléré pour un compte DÉJÀ existant ─────────
            // L'export ne contient jamais les mots de passe (les promener en clair serait une
            // fuite). Pour qu'un ré-import ne vide pas pour autant les comptes, un mot de passe
            // absent met simplement à jour identité, groupe et commissariat d'un compte qui
            // existe — sans y toucher. Il reste refusé pour un compte NEUF : on ne crée pas un
            // accès sans mot de passe.
            if ($ip === '' && !$existed && !$adExists) {
                $errs[] = "ligne {$line} ({$iu}) : mot de passe manquant (compte nouveau)";
                continue;
            }
            // Un compte domaine demandé avec un mot de passe trop simple échouait APRÈS la
            // création de l'accès portail : la ligne comptait pour un succès ET pour une
            // erreur, et l'agent se retrouvait avec Internet mais sans ouverture de session.
            // On écarte la ligne entière, avant la première écriture.
            if ($ip !== '' && $iad && $dcUp && ($manque = pf_mdp_faible($ip)) !== null) {
                $errs[] = "ligne {$line} ({$iu}) : mot de passe trop simple pour un compte domaine (il faut {$manque})";
                continue;
            }
            // Portail : mot de passe réécrit seulement s'il est fourni.
            if ($ip !== '') {
                $db->prepare('DELETE FROM radcheck WHERE username=? AND attribute="Cleartext-Password"')->execute([$iu]);
                $db->prepare('INSERT INTO radcheck (username,attribute,op,value) VALUES (?,"Cleartext-Password",":=",?)')->execute([$iu, $ip]);
            }
            // Groupe portail : uniquement si le compte a (ou vient d'obtenir) un accès portail —
            // on ne fabrique pas une appartenance de groupe pour un compte qui n'a pas de portail.
            if ($ip !== '' || $existed) {
                $db->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$iu]);
                if ($ipg !== '') { $db->prepare('INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)')->execute([$iu, $ipg]); }
            }
            ($existed || $adExists) ? $updated++ : $created++;
            // Commissariat (colonne 5 facultative, par nom)
            $isite = trim($col[4] ?? '');
            if ($isite !== '') { pf_set_site($db, $sites, $iu, (int) ($siteByName[mb_strtolower($isite)] ?? 0)); }
            // Identité (colonnes 6/7/8 facultatives : nom, prénom, service)
            $inom = trim($col[5] ?? ''); $iprenom = trim($col[6] ?? ''); $iservice = trim($col[7] ?? '');
            if ($inom !== '' || $iprenom !== '' || $iservice !== '') { pf_set_profile($db, $iu, $inom, $iprenom, $iservice); }
            // Domaine
            if ($iad && $dcUp) {
                if ($adExists) {
                    if ($ip !== '') { ad('user', 'setpassword', $iu, $ip); }
                    $adOk++;
                } elseif ($ip !== '') {
                    $out = ad('user', 'create', $iu, $ip);
                    if (preg_match('/ERROR|Failed|password/i', $out)) { $errs[] = "ligne {$line} ({$iu}) AD : " . trim($out); }
                    else { $adOk++; $adList[] = $iu; }
                } else {
                    // « domaine = oui » sur une ligne sans mot de passe et sans compte AD : on ne
                    // peut pas créer le compte domaine, on le dit plutôt que de l'ignorer.
                    $errs[] = "ligne {$line} ({$iu}) : compte domaine à créer mais mot de passe absent";
                }
            }
        }
        $sum = "Import : {$created} créé·s, {$updated} mis à jour" . ($dcUp ? ", {$adOk} compte(s) domaine" : '') . '.';
        if ($errs) { $sum .= ' ⚠ ' . count($errs) . ' erreur(s) : ' . implode(' | ', array_slice($errs, 0, 5)); }
        $flash = [$sum, $errs ? 'err' : 'ok'];
        audit('users.import', "{$created} créé(s), {$updated} mis à jour" . ($errs ? ', ' . count($errs) . ' en erreur' : ''));
    }

    // ── Gestion des commissariats (liste des groupes) ────────────────────────
    if ($action === 'site_add') {
        $name = trim((string) ($_POST['site_name'] ?? ''));
        $cpn  = trim((string) ($_POST['site_cpn'] ?? ''));
        if ($name === '') { $flash = ['Nom de commissariat requis.', 'err']; }
        else {
            $db->prepare('INSERT INTO pf_commissariats (name,cpn) VALUES (?,?) ON DUPLICATE KEY UPDATE cpn=VALUES(cpn)')->execute([$name, $cpn]);
            $flash = ['Commissariat « ' . $name . ' » enregistré.', 'ok'];
            audit('users.site_add', $name);
        }
    }
    if ($action === 'site_del') {
        $sid = (int) ($_POST['site_id'] ?? 0);
        if ($sid > 0) {
            $nom = (string) ($sites[$sid]['name'] ?? $sid);
            $rattaches = (int) $db->query('SELECT COUNT(*) FROM pf_user_site WHERE commissariat_id=' . $sid)->fetchColumn();
            $db->prepare('DELETE FROM pf_commissariats WHERE id=?')->execute([$sid]);
            $db->prepare('DELETE FROM pf_user_site WHERE commissariat_id=?')->execute([$sid]);
            // Le nombre d'agents détachés est annoncé : la confirmation prévient qu'il y en
            // aura, jamais combien — et un commissariat effacé par erreur détache en silence.
            $flash = ['Commissariat « ' . $nom . ' » supprimé.'
                    . ($rattaches ? " {$rattaches} agent(s) détaché(s) : réaffectez-les." : ''), 'ok'];
            audit('users.site_del', $nom . ($rattaches ? " ({$rattaches} agents détachés)" : ''));
        }
    }
    // Seconde purge, APRÈS les écritures : l'affichage qui suit relit donc l'annuaire réel
    // (une lecture faite en cours de traitement aurait pu remettre en cache l'état d'avant).
    ad_cache_clear();
}

// Rechargement des commissariats après une modification de la liste.
if (in_array($_POST['action'] ?? '', ['site_add', 'site_del'], true)) {
    $sites = []; $siteByName = [];
    foreach ($db->query('SELECT id,name,cpn FROM pf_commissariats ORDER BY cpn,name') as $r) {
        $sites[(int) $r['id']] = ['name' => (string) $r['name'], 'cpn' => (string) $r['cpn']];
    }
    foreach ($sites as $id => $s) { $siteByName[mb_strtolower($s['name'])] = $id; }
}

// ── Collecte de tous les comptes (union portail + AD) ────────────────────────
$portalG = [];
foreach ($db->query('SELECT c.username, (SELECT groupname FROM radusergroup g WHERE g.username=c.username LIMIT 1) grp
    FROM radcheck c WHERE c.attribute="Cleartext-Password"') as $r) { $portalG[$r['username']] = (string) $r['grp']; }

$adUsers = [];
if ($dcUp) {
    $sys = ['Administrator', 'Guest', 'krbtgt'];
    foreach (ad_lines('user', 'list') as $x) { if (!in_array($x, $sys, true) && stripos($x, 'dns-') !== 0) { $adUsers[$x] = true; } }
}
$consoleAdmins = [];
foreach ($db->query('SELECT username FROM pf_admins') as $r) { $consoleAdmins[$r['username']] = true; }
$domainAdmins = [];
if ($dcUp) { foreach (ad_lines('group', 'listmembers', 'Domain Admins') as $x) { $domainAdmins[$x] = true; } }

$all = array_unique(array_merge(array_keys($portalG), array_keys($adUsers), array_keys($consoleAdmins)));
sort($all, SORT_NATURAL | SORT_FLAG_CASE);

// Clients en ligne
$online = [];
foreach (nds_clients() as $c) {
    if (($c['state'] ?? '') === 'Authenticated' && !empty($c['custom'])
        && ($d = base64_decode($c['custom'], true)) && preg_match('/user=([^,]+)/', $d, $m)) { $online[$m[1]] = true; }
}

// Préchargement édition
$edit = ['username'=>'', 'portal'=>true, 'ad'=>false, 'pgroup'=>'', 'adgroup'=>'', 'admin'=>false, 'domadmin'=>false, 'site'=>0, 'nom'=>'', 'prenom'=>'', 'service'=>'', 'is_new'=>true];
if (isset($_GET['edit'])) {
    $eu = preg_replace('/[^A-Za-z0-9._@-]/', '', (string) $_GET['edit']);
    if ($eu !== '') {
        $edit = [
            'username' => $eu,
            'portal'   => isset($portalG[$eu]),
            'ad'       => isset($adUsers[$eu]),
            'pgroup'   => $portalG[$eu] ?? '',
            'adgroup'  => '',
            'admin'    => isset($consoleAdmins[$eu]),
            'domadmin' => isset($domainAdmins[$eu]),
            'site'     => $userSite[$eu] ?? 0,
            'nom'      => $profiles[$eu]['nom'] ?? '',
            'prenom'   => $profiles[$eu]['prenom'] ?? '',
            'service'  => $profiles[$eu]['service'] ?? '',
            'is_new'   => false,
        ];
    }
}

// Ouvrir la modale au chargement : soit ?edit=, soit réouverture après erreur de validation.
$openModal = isset($_GET['edit']) && $edit['username'] !== '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save' && $flash && ($flash[1] ?? '') === 'err') {
    $su = preg_replace('/[^A-Za-z0-9._@-]/', '', (string) ($_POST['username'] ?? ''));
    $edit = [
        'username' => $su,
        'portal'   => isset($_POST['portal']),
        'ad'       => isset($_POST['ad_account']),
        'pgroup'   => trim((string) ($_POST['pgroup'] ?? '')),
        'adgroup'  => trim((string) ($_POST['adgroup'] ?? '')),
        'admin'    => isset($_POST['role_admin']),
        'domadmin' => isset($_POST['role_domadmin']),
        'site'     => (int) ($_POST['commissariat'] ?? 0),
        'nom'      => trim((string) ($_POST['nom'] ?? '')),
        'prenom'   => trim((string) ($_POST['prenom'] ?? '')),
        'service'  => trim((string) ($_POST['service'] ?? '')),
        'is_new'   => !in_array($su, $all, true),
    ];
    $openModal = true;
}

// ── Export CSV du parc de comptes ────────────────────────────────────────────
// Symétrique de l'import : mêmes colonnes, même séparateur. Sert à tenir un état des
// comptes (dossier, contrôle hiérarchique) ou à repartir d'une base existante.
// LE MOT DE PASSE N'EST JAMAIS EXPORTÉ — la colonne reste vide. Un fichier de comptes qui
// promène les mots de passe en clair est une fuite qui se recopie de machine en machine ;
// et l'import sait désormais mettre à jour un compte existant sans mot de passe, donc le
// fichier reste ré-importable tel quel (il ne recrée pas les comptes neufs, faute de secret).
// Émis AVANT tout HTML (pf_header n'a pas encore été appelé), puis exit.
if (isset($_GET['export'])) {
    audit('users.export', count($all) . ' compte(s)');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="comptes-bastion-' . date('Y-m-d') . '.csv"');
    $fo = fopen('php://output', 'w');
    fwrite($fo, "\xEF\xBB\xBF");   // BOM UTF-8 : Excel affiche alors les accents correctement
    // Séparateur « ; » (Excel FR), guillemet « " », et ÉCHAPPEMENT VIDE : les trois arguments
    // sont fournis exprès. PHP 8.4 émet sinon un « Deprecated: the $escape parameter must be
    // provided » — un avertissement qui, ici, s'écrirait EN PLEIN MILIEU du CSV téléchargé si
    // display_errors est actif. L'échappement vide donne en prime un CSV conforme (RFC 4180 :
    // les guillemets internes sont doublés, pas préfixés d'antislash).
    $csv = function ($row) use ($fo) { fputcsv($fo, $row, ';', '"', ''); };
    $csv(['identifiant', 'motdepasse', 'groupe_portail', 'domaine', 'commissariat', 'nom', 'prenom', 'service']);
    foreach ($all as $name) {
        $sid = $userSite[$name] ?? 0;
        $csv([
            $name,
            '',                                              // jamais le mot de passe
            $portalG[$name] ?? '',
            isset($adUsers[$name]) ? 'oui' : 'non',
            ($sid && isset($sites[$sid])) ? $sites[$sid]['name'] : '',
            $profiles[$name]['nom'] ?? '',
            $profiles[$name]['prenom'] ?? '',
            $profiles[$name]['service'] ?? '',
        ]);
    }
    fclose($fo);
    exit;
}

pf_header('Utilisateurs & droits', 'users.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<style>
  .u-form label.field{display:grid;gap:.3rem;margin-bottom:.8rem;font-size:.82rem;color:var(--muted)}
  .u-form input[type=text],.u-form input[type=password]{padding:.6rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px}
  .u-block{border:1px solid var(--line);border-radius:10px;padding:.85rem 1rem;margin-bottom:.8rem}
  .u-block .hd{font-weight:600;color:var(--text);margin-bottom:.6rem}
  /* Case + libellé : la case ne rétrécit pas, le texte occupe le reste sans se casser mot à mot */
  .u-chk{display:flex;align-items:flex-start;gap:.55rem;color:var(--text);font-size:.88rem;line-height:1.35;cursor:pointer}
  .u-chk input[type=checkbox]{flex:0 0 auto;width:16px;height:16px;margin:.12rem 0 0;accent-color:var(--accent,#38bdf8);cursor:pointer}
  .u-chk .txt{flex:1 1 auto}
  .u-chk .txt small{display:block;color:var(--muted);font-size:.76rem;margin-top:.1rem;font-weight:400}
  .u-block.disabled{opacity:.55}
  .u-block.disabled .u-chk{cursor:not-allowed}

  /* Bandeau de suppression : n'apparaît que si l'enregistrement va DÉTRUIRE quelque chose.
     Rouge, dans le bloc concerné, et portant sa propre case à cocher — c'est cette case que
     le serveur exige (cf. pf_refus_save). Une simple fenêtre « êtes-vous sûr ? » se claque
     par réflexe ; ici il faut viser la case, et son texte dit ce qui disparaît. */
  .u-danger{margin-top:.7rem;padding:.6rem .75rem;border-radius:8px;
            background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.45)}
  .u-danger .u-chk{color:#f87171;font-weight:600}
  .u-danger .u-chk small{color:#f87171;opacity:.85;font-weight:400}
  .u-danger input[type=checkbox]{accent-color:#f87171}

  /* ── LA FICHE TENAIT SUR DEUX ÉCRANS ─────────────────────────────────────
     Cinq blocs empilés dans une fenêtre de 520 px : il fallait faire défiler
     pour atteindre le compte domaine et les rôles, et l'on enregistrait sans
     avoir vu le bas. Sur un écran large, la place existe — on l'utilise.

     Mise en COLONNES et non en onglets : un onglet masque des champs, et l'on
     valide alors une fiche dont on n'a pas vu la moitié. Ici tout reste visible
     et tout reste soumis ; c'est une mise en page, pas un découpage.

     « break-inside:avoid » est indispensable : sans lui un bloc se couperait au
     milieu, la case à cocher se retrouvant dans une colonne et son champ dans
     l'autre. */
  @media (min-width:1000px){
    #usermodal .modal{max-width:940px}
    #usermodal .u-form{column-count:2;column-gap:1.1rem}
    #usermodal .u-form > *{break-inside:avoid;-webkit-column-break-inside:avoid;page-break-inside:avoid}
    /* Les champs simples d'en-tête (identifiant, mot de passe, date) gardent
       leur marge basse : en colonnes, elle sert de séparation verticale. */
    #usermodal .u-form label.field{margin-bottom:.85rem}
  }
  .roles .u-chk+.u-chk{margin-top:.7rem}
  .rbadge{display:inline-block;font-size:.68rem;padding:.1rem .5rem;border-radius:20px;margin-left:.25rem}
  .r-portal{background:rgba(56,189,248,.15);color:#38bdf8} .r-ad{background:rgba(74,222,128,.15);color:#4ade80}
  .r-adm{background:rgba(234,179,8,.18);color:#eab308} .r-dom{background:rgba(248,113,113,.18);color:#f87171}
  .r-site{background:rgba(168,139,250,.18);color:#a78bfa}
</style>
<?php
// ── Groupes existants (portail et annuaire) ──────────────────────────────────
// Lus ICI, une fois : la fiche individuelle s'en sert pour ses listes déroulantes, et la
// barre d'action en masse pour ses suggestions. La liste AD vient du CACHE — cette page se
// charge à chaque consultation, et interroger le contrôleur à chaque fois la rendrait lente
// pour rien.
$pfGroupes = [];
try { $pfGroupes = $db->query('SELECT groupname FROM pf_groups ORDER BY groupname')->fetchAll(PDO::FETCH_COLUMN) ?: []; }
catch (Throwable $e) { $pfGroupes = []; }
$pfAdGroupes = [];
if ($dcUp) {
    try { $pfAdGroupes = ad_lines_cached('groups', 0, 'group', 'list'); } catch (Throwable $e) { $pfAdGroupes = []; }
}
$pfAdGroupes = array_values(array_filter((array) $pfAdGroupes, fn($g) => trim((string) $g) !== ''));
sort($pfAdGroupes, SORT_NATURAL | SORT_FLAG_CASE);

// Options du sélecteur de commissariat, groupées par CPN.
$siteOptions = function (int $sel) use ($sites) {
    $out = '<option value="0">— Aucun —</option>';
    $cur = null;
    foreach ($sites as $id => $s) {
        if ($s['cpn'] !== $cur) { if ($cur !== null) { $out .= '</optgroup>'; } $out .= '<optgroup label="' . e($s['cpn']) . '">'; $cur = $s['cpn']; }
        $out .= '<option value="' . $id . '"' . ($sel === $id ? ' selected' : '') . '>' . e($s['name']) . '</option>';
    }
    if ($cur !== null) { $out .= '</optgroup>'; }
    return $out;
};
?>
<section class="panel">
  <div class="panel-head"><h2>Comptes (<?= count($all) ?>)</h2>
    <div style="display:flex;gap:.6rem">
      <?php if ($dcUp): ?>
        <?php /* « display:flex » sur le FORMULAIRE, pas seulement « margin:0 » : le
                 conteneur au-dessus est une flexbox, donc ses enfants DIRECTS s'étirent
                 à la hauteur du plus grand. Les deux autres boutons en profitent ; celui-ci,
                 enveloppé dans un formulaire, gardait sa hauteur naturelle et paraissait
                 plus petit. Le formulaire devient flex à son tour pour transmettre
                 l'étirement à son bouton. */ ?>
        <form method="post" style="margin:0;display:flex" title="Reporte nom et prénom dans l'annuaire, pour que les agents voient leur identité sur les postes au lieu de leur matricule">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="sync_identity">
          <button class="btn-sm">🪪 Publier les identités sur les postes</button>
        </form>
      <?php endif; ?>
      <?php /* Lien direct (GET) et non bouton JS : l'export est une simple lecture, et un
               lien laisse le navigateur nommer et enregistrer le fichier normalement. */ ?>
      <a class="btn-sm" href="?export=1" title="Télécharger tous les comptes au format CSV (sans les mots de passe)">⬇️ Exporter (CSV)</a>
      <button type="button" class="btn-sm" id="managesites">🏢 Commissariats</button>
      <button type="button" class="btn" id="newuser">➕ Nouvel utilisateur</button>
    </div>
  </div>
  <form method="post" id="bulkform">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="bulk">
    <div class="bulkbar" id="bulkbar">
      <span class="muted small"><b id="selcount">0</b> sélectionné(s)</span>
      <select name="bulk_op" id="bulkop">
        <option value="setgroup">Changer le groupe (portail)</option>
        <option value="setsite">Affecter à un commissariat</option>
        <option value="setpassword">Réinitialiser le mot de passe</option>
        <?php if ($dcUp): ?><option value="addadgroup">Ajouter à un groupe AD</option><?php endif; ?>
        <option value="delete">Supprimer les comptes</option>
      </select>
      <?php /* Le champ reste libre — un mot de passe s'y saisit — mais il propose les noms
               qui existent, et le serveur refuse les autres en bloc (cf. pf_refus_masse).
               La saisie libre non vérifiée reclassait cinquante agents dans un groupe
               fantôme : sans quota, sans horaires, et sans un mot pour le dire. */ ?>
      <input type="text" name="bulk_arg" id="bulkarg" placeholder="groupe / mot de passe" list="sitelist">
      <datalist id="sitelist"><?php foreach ($sites as $s): ?><option value="<?= e($s['name']) ?>"><?php endforeach; ?></datalist>
      <datalist id="pgrouplist"><?php foreach ($pfGroupes as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist>
      <datalist id="adgrouplist"><?php foreach ($pfAdGroupes as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist>
      <button class="btn-sm" id="bulkgo">Appliquer</button>
    </div>
  </form>
  <div class="toolbar">
    <input type="text" id="filt-q" placeholder="🔎 Filtrer : matricule, nom, service…" style="min-width:240px;flex:1">
    <select id="filt-site">
      <option value="0">Tous les commissariats</option>
      <?php foreach ($sites as $id => $s): ?><option value="<?= $id ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
    </select>
    <select id="filt-type">
      <option value="">Tous les accès / droits</option>
      <option value="portal">🌐 Accès Internet</option>
      <option value="ad">🗄️ Compte domaine</option>
      <option value="adm">Admin console</option>
      <option value="dom">Admin domaine</option>
      <option value="none">Sans accès ni droit</option>
    </select>
    <span class="muted small ml-auto" id="filt-count"></span>
  </div>
  <div class="table-wrap">
  <table class="grid-table">
    <?php /* Colonnes triables : l'en-tête porte « data-sort » (la clé de tri lue sur chaque
             ligne). Un clic trie, un second inverse. « Accès / droits » n'est pas triable —
             une ligne peut cumuler plusieurs badges, un ordre n'aurait pas de sens. */ ?>
    <thead><tr><th style="width:1%"><input type="checkbox" id="selall" title="Tout sélectionner"></th>
      <th class="th-sort" data-sort="id">Identifiant</th><th>Accès / droits</th>
      <th class="th-sort" data-sort="sitename">Commissariat</th>
      <th class="th-sort" data-sort="online">État</th><th></th></tr></thead>
    <tbody>
    <?php if (!$all): ?><tr><td colspan="6" class="muted center">Aucun compte.</td></tr>
    <?php else: foreach ($all as $name): $sid = $userSite[$name] ?? 0;
      $rtypes = [];
      if (isset($portalG[$name]))      { $rtypes[] = 'portal'; }
      if (isset($adUsers[$name]))      { $rtypes[] = 'ad'; }
      if (isset($consoleAdmins[$name])){ $rtypes[] = 'adm'; }
      if (isset($domainAdmins[$name])) { $rtypes[] = 'dom'; }
      $rsearch = mb_strtolower($name . ' ' . ($profiles[$name]['nom'] ?? '') . ' ' . ($profiles[$name]['prenom'] ?? '')
               . ' ' . ($profiles[$name]['service'] ?? '') . ' ' . ($sites[$sid]['name'] ?? ''));
    ?>
      <tr class="urow" data-f="<?= e($rsearch) ?>" data-site="<?= $sid ?>" data-types="<?= e(implode(' ', $rtypes)) ?>"
          data-id="<?= e(mb_strtolower($name)) ?>" data-sitename="<?= e(mb_strtolower($sites[$sid]['name'] ?? '')) ?>" data-online="<?= !empty($online[$name]) ? 1 : 0 ?>">
        <td><input type="checkbox" class="selrow" name="sel[]" form="bulkform" value="<?= e($name) ?>"<?= $name === 'admin' ? ' data-admin="1"' : '' ?><?= $name === $moi ? ' data-self="1"' : '' ?>></td>
        <td>
          <div style="display:flex;align-items:center;gap:.6rem">
            <?php if (!empty($photoV[$name])): ?><img src="user-photo.php?u=<?= e($name) ?>&amp;v=<?= e($photoV[$name]) ?>" alt="" style="width:34px;height:34px;border-radius:9px;object-fit:cover;flex:none;border:1px solid var(--line)">
            <?php else: ?><span style="width:34px;height:34px;border-radius:9px;flex:none;border:1px solid var(--line);background:var(--bg);display:inline-flex;align-items:center;justify-content:center;color:var(--muted);font-size:.95rem">👤</span><?php endif; ?>
            <div>
              <strong><?= e($name) ?></strong>
              <?php $pn = trim(($profiles[$name]['nom'] ?? '') . ' ' . ($profiles[$name]['prenom'] ?? '')); ?>
              <?php if ($pn !== ''): ?><br><span class="muted svc-meta"><?= e($pn) ?><?php if (($profiles[$name]['service'] ?? '') !== ''): ?> · <?= e($profiles[$name]['service']) ?><?php endif; ?></span>
              <?php elseif (isset($portalG[$name]) && $portalG[$name] !== ''): ?><br><span class="muted svc-meta"><?= e($portalG[$name]) ?></span><?php endif; ?>
            </div>
          </div></td>
        <td>
          <?php if (isset($portalG[$name])): ?><span class="rbadge r-portal">🌐 Internet</span><?php endif; ?>
          <?php if (isset($adUsers[$name])): ?><span class="rbadge r-ad">🗄️ Domaine</span><?php endif; ?>
          <?php if (isset($consoleAdmins[$name])): ?><span class="rbadge r-adm">Admin console</span><?php endif; ?>
          <?php if (isset($domainAdmins[$name])): ?><span class="rbadge r-dom">Admin domaine</span><?php endif; ?>
        </td>
        <td><?php if ($sid && isset($sites[$sid])): ?><span class="rbadge r-site">🏢 <?= e($sites[$sid]['name']) ?></span><?php else: ?><span class="muted small">—</span><?php endif; ?></td>
        <td><?php if (!empty($online[$name])): ?><span class="badge on">En ligne</span><?php else: ?><span class="badge off">Hors ligne</span><?php endif; ?>
          <?php if (!empty($expiry[$name])): $et = strtotime((string) $expiry[$name]); $expd = $et < time(); ?>
            <br><span class="rbadge" style="font-size:.62rem;background:<?= $expd ? 'rgba(248,113,113,.18)' : 'rgba(234,179,8,.18)' ?>;color:<?= $expd ? '#f87171' : '#eab308' ?>" title="Désactivation programmée">⏳ <?= $expd ? 'expiré' : 'expire le ' . e(date('d/m/Y', $et)) ?></span>
          <?php endif; ?></td>
        <td class="row-actions">
          <button type="button" class="btn-sm edit-user"
            data-u="<?= e($name) ?>" data-portal="<?= isset($portalG[$name]) ? 1 : 0 ?>"
            data-ad="<?= isset($adUsers[$name]) ? 1 : 0 ?>" data-pgroup="<?= e($portalG[$name] ?? '') ?>"
            data-admin="<?= isset($consoleAdmins[$name]) ? 1 : 0 ?>" data-dom="<?= isset($domainAdmins[$name]) ? 1 : 0 ?>" data-site="<?= $sid ?>"
            data-nom="<?= e($profiles[$name]['nom'] ?? '') ?>" data-prenom="<?= e($profiles[$name]['prenom'] ?? '') ?>" data-service="<?= e($profiles[$name]['service'] ?? '') ?>"
            data-photov="<?= e($photoV[$name] ?? '') ?>" data-expiry="<?= e($expiry[$name] ?? '') ?>" data-role="<?= e($adminRole[$name] ?? 'full') ?>">Modifier</button>
          <?php if ($name !== 'admin'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Supprimer « <?= e($name) ?> » (portail + domaine + droits) ?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete">
            <input type="hidden" name="username" value="<?= e($name) ?>"><button class="btn-sm btn-danger">Supprimer</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
      <tr id="filt-empty" style="display:none"><td colspan="6" class="muted center">Aucun compte ne correspond aux filtres.</td></tr>
    </tbody>
  </table>
  </div>
  <p class="muted small" style="padding:0 1.2rem 1rem">🌐 Internet = accès au portail captif · 🗄️ Domaine =
  ouverture de session Windows · <span style="color:#eab308">Admin console</span> = gère Bastion ·
  <span style="color:#f87171">Admin domaine</span> = gère l'Active Directory.</p>
</section>
<style>
  .th-sort{cursor:pointer;user-select:none;white-space:nowrap}
  .th-sort:hover{color:var(--text)}
  .th-sort::after{content:"↕";opacity:.35;margin-left:.3rem;font-size:.8em}
  .th-sort.asc::after{content:"↑";opacity:.9}
  .th-sort.desc::after{content:"↓";opacity:.9}
</style>
<script>
(function(){
  var q=document.getElementById('filt-q'), fs=document.getElementById('filt-site'),
      ft=document.getElementById('filt-type'), cnt=document.getElementById('filt-count'),
      empty=document.getElementById('filt-empty'),
      rows=[].slice.call(document.querySelectorAll('tr.urow'));
  if(!q) return;

  // Les filtres SURVIVENT à un enregistrement. Chaque action (créer, modifier, supprimer)
  // recharge la page en POST ; sans mémoire, on retrouvait la liste entière et il fallait
  // refiltrer à la main pour revenir là où l'on était. On les garde dans sessionStorage
  // (le temps de l'onglet seulement — ce ne sont pas des préférences durables).
  var KEY='pf_users_filt';
  try{ var s=JSON.parse(sessionStorage.getItem(KEY)||'{}');
       if(s.q!=null) q.value=s.q; if(s.site!=null) fs.value=s.site; if(s.type!=null) ft.value=s.type; }catch(e){}
  function remember(){ try{ sessionStorage.setItem(KEY, JSON.stringify({q:q.value,site:fs.value,type:ft.value})); }catch(e){} }

  function apply(){
    var term=q.value.trim().toLowerCase(), site=fs.value, type=ft.value, shown=0;
    rows.forEach(function(r){
      var ok=true;
      if(term && r.dataset.f.indexOf(term)===-1) ok=false;
      if(ok && site!=='0' && r.dataset.site!==site) ok=false;
      if(ok && type){
        var t=r.dataset.types||'';
        if(type==='none') ok = (t==='');
        else ok = (' '+t+' ').indexOf(' '+type+' ')!==-1;
      }
      r.style.display=ok?'':'none';
      if(ok){ shown++; }
      else {
        /* SORTIR DE L'ÉCRAN, C'EST SORTIR DE LA SÉLECTION.
           Une ligne masquée gardait sa case cochée : on filtrait sur un commissariat, on
           cochait « tout sélectionner », on supprimait — et l'on emportait des comptes
           jamais affichés, choisis par un filtre précédent. Rien ne les montrait, ni avant
           ni après : le bandeau annonçait seulement un nombre plus grand que prévu. */
        var cb=r.querySelector('.selrow');
        if(cb && cb.checked){ cb.checked=false; cb.dispatchEvent(new Event('change',{bubbles:true})); }
      }
    });
    empty.style.display=shown?'none':'';
    cnt.textContent=shown+' / '+rows.length+' compte(s)';
    remember();
  }
  q.addEventListener('input',apply); fs.addEventListener('change',apply); ft.addEventListener('change',apply);

  // ── Tri des colonnes ─────────────────────────────────────────────────────
  // Réordonne les lignes DANS le tableau (pas une copie) : le filtrage, la sélection et les
  // formulaires par ligne continuent de fonctionner sur les mêmes éléments. Comparaison
  // « naturelle » pour que 0110480 se range avant 0224891 sans surprise.
  var tbody=rows.length?rows[0].parentNode:null, coll;
  try{ coll=new Intl.Collator(undefined,{numeric:true,sensitivity:'base'}); }catch(e){ coll=null; }
  function cmp(a,b){ return coll?coll.compare(a,b):(a<b?-1:a>b?1:0); }
  function sortBy(th){
    if(!tbody) return;   // aucun compte : rien à trier
    var keCol=th.getAttribute('data-sort'), dir=th.classList.contains('asc')?'desc':'asc';
    [].forEach.call(document.querySelectorAll('.th-sort'),function(h){h.classList.remove('asc','desc');});
    th.classList.add(dir);
    var sorted=rows.slice().sort(function(x,y){
      var vx=x.dataset[keCol]||'', vy=y.dataset[keCol]||'', r=cmp(vx,vy);
      return dir==='asc'?r:-r;
    });
    sorted.forEach(function(r){ tbody.appendChild(r); });   // filt-empty reste en dernier ci-dessous
    if(empty) tbody.appendChild(empty);
  }
  [].forEach.call(document.querySelectorAll('.th-sort'),function(th){
    th.addEventListener('click',function(){ sortBy(th); });
  });

  apply();
})();
</script>

<!-- ════ Modale création / modification ════ -->
<div class="modal-ov" id="usermodal">
  <div class="modal" role="dialog" aria-modal="true">
    <div class="modal-head"><h2 id="modaltitle">Nouvel utilisateur</h2>
      <button type="button" class="modal-x" data-close aria-label="Fermer">&times;</button></div>
    <form method="post" enctype="multipart/form-data" class="u-form modal-body" id="userform">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <label class="field">Identifiant
        <input type="text" name="username" id="f_username" required placeholder="0110480"
               pattern="(admin-)?[0-9]{7}" title="<?= e(PF_ID_HINT) ?>"
               inputmode="text" autocomplete="off">
        <span class="muted small">Matricule à 7 chiffres (ex. <code>0110480</code>) ou <code>admin-0110480</code> pour un administrateur.</span></label>
      <label class="field">Mot de passe <span class="muted small" id="f_pwdhint"></span>
        <input type="text" name="password" id="f_password" value="" placeholder="respecter la complexité si compte domaine"></label>
      <label class="field">Date de fin d'accès <span class="muted small">(optionnel — désactivation automatique le jour venu)</span>
        <input type="date" name="expires_at" id="f_expires"></label>

      <div class="u-block">
        <div class="hd">👮 Identité du fonctionnaire</div>
        <!-- Un même agent a souvent DEUX comptes : « 0110480 » pour lui, « admin-0110480 »
             pour administrer la console. La fiche d'identité n'existe que sur le premier.
             Ouvrir le second et le trouver vide donne l'impression que les informations ont
             disparu — c'est le signalement qui a motivé cette note. On dit donc où elles sont
             plutôt que de laisser des champs vides parler à notre place. -->
        <p id="f_jumeau" hidden class="muted small"
           style="margin:0 0 .6rem;padding:.5rem .7rem;border-radius:8px;
                  background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.3)"></p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
          <label class="field" style="margin:0">Nom<input type="text" name="nom" id="f_nom" placeholder="DUPONT"></label>
          <label class="field" style="margin:0">Prénom<input type="text" name="prenom" id="f_prenom" placeholder="Jean"></label>
        </div>
        <label class="field" style="margin:.6rem 0 0">Service<input type="text" name="service" id="f_service" placeholder="ex. Police secours, PJ, BAC…"></label>
        <div style="display:flex;align-items:center;gap:1rem;margin-top:.7rem">
          <img id="f_photo_img" alt="" style="width:64px;height:64px;border-radius:12px;object-fit:cover;border:1px solid var(--line);background:var(--bg);display:none">
          <span id="f_photo_ph" style="width:64px;height:64px;border-radius:12px;border:1px solid var(--line);background:var(--bg);display:inline-flex;align-items:center;justify-content:center;font-size:1.7rem;color:var(--muted)">👤</span>
          <div style="flex:1;min-width:0">
            <label class="field" style="margin:0">📷 Photo <span class="muted small">(JPEG, PNG, GIF ou WebP — recadrée en carré, max 5 Mo)</span>
              <input type="file" name="photo" id="f_photo" accept="image/jpeg,image/png,image/gif,image/webp"></label>
            <label class="u-chk" id="f_photo_rm_l" style="margin-top:.4rem;display:none"><input type="checkbox" name="photo_remove" id="f_photo_rm"><span class="txt">Retirer la photo actuelle</span></label>
          </div>
        </div>
      </div>

      <div class="u-block">
        <label class="u-chk"><input type="checkbox" name="portal" id="f_portal">
          <span class="txt">🌐 Accès Internet <small>Connexion au portail captif</small></span></label>
        <div class="u-danger" id="f_del_portal" hidden>
          <label class="u-chk"><input type="checkbox" name="confirm_del_portal">
            <span class="txt">Oui, supprimer l'accès Internet de ce compte
            <small>Le compte disparaît du portail : mot de passe et groupe effacés, session en cours coupée.
            Il faudra le recréer et redonner un mot de passe à l'agent.</small></span></label>
        </div>
        <?php
        // ── LISTE DÉROULANTE, ET NON SAISIE LIBRE ────────────────────────────
        // Le champ était libre : une faute de frappe créait une appartenance à un
        // groupe INEXISTANT. L'agent perdait alors la politique attendue — quotas,
        // horaires, sortie par tunnel — et rien ne le signalait, ni à la création
        // ni ensuite. On ne peut plus désigner que des groupes qui existent.
        // ($pfGroupes est lu plus haut, une seule fois pour la page.)
        ?>
        <label class="field" style="margin:.7rem 0 0">Groupe (quotas / horaires)
          <select name="pgroup" id="f_pgroup">
            <option value="">— aucun (accès sans politique de groupe) —</option>
            <?php foreach ($pfGroupes as $g): ?>
              <option value="<?= e($g) ?>"><?= e($g) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$pfGroupes): ?>
            <span class="muted small">Aucun groupe défini — créez-en un dans
            <a href="/groups.php" style="color:var(--accent)">Groupes &amp; quotas</a>.</span>
          <?php endif; ?>
        </label>
      </div>

      <div class="u-block<?= $dcUp ? '' : ' disabled' ?>">
        <label class="u-chk"><input type="checkbox" name="ad_account" id="f_ad" <?= $dcUp ? '' : 'disabled' ?>>
          <span class="txt">🗄️ Compte domaine <small>Ouverture de session Windows (Active Directory)</small></span></label>
        <div class="u-danger" id="f_del_ad" hidden>
          <label class="u-chk"><input type="checkbox" name="confirm_del_ad">
            <span class="txt">Oui, supprimer le compte Active Directory
            <small>Ce n'est pas une désactivation : le compte est effacé de l'annuaire. Recréé plus tard, il
            portera un identifiant de sécurité (SID) neuf — Windows ouvrira donc un profil NEUF sur les postes :
            bureau vide, documents de l'ancien profil hors de portée de l'agent. Pour un départ temporaire,
            posez plutôt une <strong>date de fin d'accès</strong> en haut de la fiche.</small></span></label>
        </div>
        <label class="field" style="margin:.7rem 0 0">Groupe AD <span class="muted small">(optionnel)</span>
          <?php
          // Même raison que pour le groupe du portail : une faute de frappe créait
          // une adhésion à un groupe AD inexistant. Ici la conséquence était même
          // muette côté console — samba-tool refusait, et l'agent restait sans les
          // droits qu'on croyait lui avoir donnés.
          //
          // La liste vient du CACHE de l'annuaire (ad_lines_cached), jamais d'un
          // appel direct : cette page se charge à chaque consultation de compte, et
          // interroger le contrôleur à chaque fois la rendrait lente pour rien.
          // ($pfAdGroupes est lu plus haut, une seule fois pour la page.)
          ?>
          <select name="adgroup" id="f_adgroup" <?= $dcUp ? '' : 'disabled' ?>>
            <!-- « Ne pas ajouter » et non « aucun » : ce champ AJOUTE une adhésion,
                 il n'en retire jamais. Écrire « aucun » laisserait croire qu'on
                 sort l'agent de ses groupes en enregistrant — ce qui ne se produit
                 pas, et se découvrirait trop tard. -->
            <option value="">— ne pas ajouter à un groupe —</option>
            <?php foreach ($pfAdGroupes as $g): ?>
              <option value="<?= e($g) ?>"><?= e($g) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($dcUp && !$pfAdGroupes): ?>
            <span class="muted small">Liste indisponible — le contrôleur de domaine n'a rien répondu.
            Vérifiez-le dans <a href="/ad.php" style="color:var(--accent)">Active Directory</a>.</span>
          <?php endif; ?>
        </label>
        <?php if (!$dcUp): ?><p class="muted small" style="margin:.5rem 0 0">Contrôleur de domaine inactif.</p><?php endif; ?>
      </div>

      <div class="u-block">
        <label class="field" style="margin:0">🏢 Commissariat <span class="muted small">(groupe d'appartenance)</span>
          <select name="commissariat" id="f_site" style="padding:.6rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
            <?= $siteOptions(0) ?>
          </select></label>
      </div>

      <div class="u-block roles">
        <div class="hd">🔑 Droits de gestion</div>
        <label class="u-chk"><input type="checkbox" name="role_admin" id="f_admin">
          <span class="txt">Administrateur de la console <strong>Bastion</strong><small>Accès à cette interface d'administration</small></span></label>
        <label class="field" id="f_level_wrap" style="margin:.3rem 0 .3rem 1.8rem">Niveau d'accès de la console
          <select name="admin_level" id="f_level" style="padding:.5rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
            <option value="full">Complet — tout gérer</option>
            <option value="comptes">Comptes &amp; agents seulement</option>
            <option value="lecture">Lecture seule — consultation (aucune modification)</option>
          </select>
          <span class="muted small">Le compte « admin » reste toujours complet.</span></label>
        <label class="u-chk"><input type="checkbox" name="role_domadmin" id="f_dom" <?= $dcUp ? '' : 'disabled' ?>>
          <span class="txt">Administrateur du <strong>domaine</strong><small>Membre de « Domain Admins » (gère l'Active Directory)</small></span></label>
      </div>

      <div class="form-actions" style="justify-content:flex-end">
        <button type="button" class="btn-sm" data-close>Annuler</button>
        <button class="btn">Enregistrer</button>
      </div>
      <p class="muted small" style="margin:.6rem 0 0">Un mot de passe pour compte domaine ou administrateur de console
      doit faire au moins 8 caractères, avec une majuscule et un chiffre : sinon la fiche est refusée et
      <strong>rien n'est enregistré</strong>. La stratégie du domaine peut en exiger davantage.</p>
    </form>
  </div>
</div>

<script>
(function(){
  var ov=document.getElementById('usermodal'), f=document.getElementById('userform'),
      title=document.getElementById('modaltitle'), hint=document.getElementById('f_pwdhint'),
      uName=document.getElementById('f_username');
  function set(id,v){document.getElementById(id).checked=!!v;}
  function open(){ov.classList.add('open');}
  function close(){ov.classList.remove('open');}
  var PF_PROFILS = <?= json_encode(array_keys($profiles), JSON_UNESCAPED_UNICODE) ?>;
  function fill(d,isNew){
    title.textContent=isNew?'Nouvel utilisateur':'Modifier « '+d.u+' »';
    uName.value=d.u||''; uName.readOnly=!isNew;
    hint.textContent=isNew?'':'(laisser vide = inchangé)';
    document.getElementById('f_password').value='';
    document.getElementById('f_expires').value=d.expires||'';
    // Un compte peut porter un groupe qui n'existe plus (groupe supprimé depuis).
    // Poser « .value » sur une valeur absente de la liste laisserait le champ sur la
    // PREMIÈRE option — donc « aucun » — et le premier enregistrement reclasserait
    // l'agent sans que personne ne l'ait demandé. On ajoute donc l'option manquante,
    // nommée pour ce qu'elle est.
    (function(){
      var sel=document.getElementById('f_pgroup'), v=d.pgroup||'';
      if (v && !Array.prototype.some.call(sel.options, function(o){ return o.value===v; })) {
        var o=document.createElement('option');
        o.value=v; o.textContent=v+' — groupe introuvable';
        sel.appendChild(o);
      }
      sel.value=v;
    })();
    document.getElementById('f_adgroup').value='';
    document.getElementById('f_site').value=String(d.site||0);
    document.getElementById('f_nom').value=d.nom||'';
    document.getElementById('f_prenom').value=d.prenom||'';
    document.getElementById('f_service').value=d.service||'';
    // Compte d'administration sans fiche : dire OU elle se trouve.
    (function(){
      var n=document.getElementById('f_jumeau');
      if(!n) return;
      n.hidden=true;
      if(isNew || (d.nom||'') || (d.prenom||'') || (d.service||'')) return;
      var u=d.u||'', jum = u.indexOf('admin-')===0 ? u.slice(6) : 'admin-'+u;
      if(PF_PROFILS.indexOf(jum)===-1) return;
      n.innerHTML='Ce compte n’a pas de fiche d’identité. Celle de l’agent est portée par '
                + '<strong>'+jum.replace(/[<>&"]/g,'')+'</strong> — un même agent a souvent deux comptes : '
                + 'le sien, et celui avec lequel il administre la console.';
      n.hidden=false;
    })();
    // Photo : aperçu de l'existante en édition ; sinon pastille neutre. Champ fichier remis à zéro.
    var pimg=document.getElementById('f_photo_img'), pph=document.getElementById('f_photo_ph'), prml=document.getElementById('f_photo_rm_l');
    document.getElementById('f_photo').value=''; document.getElementById('f_photo_rm').checked=false;
    if(!isNew && d.photov){ pimg.src='user-photo.php?u='+encodeURIComponent(d.u)+'&v='+encodeURIComponent(d.photov);
      pimg.style.display=''; pph.style.display='none'; prml.style.display=''; }
    else { pimg.removeAttribute('src'); pimg.style.display='none'; pph.style.display=''; prml.style.display='none'; }
    set('f_portal', isNew?true:d.portal);
    set('f_ad', d.ad); set('f_admin', d.admin); set('f_dom', d.dom);
    // Ce que le compte possède DÉJÀ. La destruction se juge là-dessus, jamais sur l'état
    // courant des cases : après un refus la fiche se rouvre AVEC la case décochée, et se
    // fier aux cases ferait disparaître l'avertissement au moment où il sert.
    HAD.portal = isNew ? false : !!d.had_portal;
    HAD.ad     = isNew ? false : !!d.had_ad;
    dangers();
    document.getElementById('f_level').value=d.role||'full';
    var lw=document.getElementById('f_level_wrap'); if(lw) lw.style.display=document.getElementById('f_admin').checked?'':'none';
  }
  // Avertissement de suppression : visible seulement quand l'enregistrement détruirait
  // vraiment quelque chose. Sa case est celle qu'exige le serveur (cf. pf_refus_save).
  var HAD={portal:false, ad:false};
  function dangers(){
    [['f_portal','f_del_portal','portal'], ['f_ad','f_del_ad','ad']].forEach(function(t){
      var cb=document.getElementById(t[0]), box=document.getElementById(t[1]);
      if(!cb||!box) return;
      var perte = HAD[t[2]] && !cb.checked;
      box.hidden = !perte;
      // Rendue invisible, la confirmation est aussi décochée : sans quoi elle resterait
      // armée après un revirement, prête à valider une destruction qu'on ne demande plus.
      if(!perte){ var c=box.querySelector('input[type=checkbox]'); if(c){ c.checked=false; } }
    });
  }
  document.getElementById('f_portal').addEventListener('change', dangers);
  document.getElementById('f_ad').addEventListener('change', dangers);
  document.getElementById('f_admin').addEventListener('change',function(){
    var lw=document.getElementById('f_level_wrap'); if(lw) lw.style.display=this.checked?'':'none';
  });
  document.getElementById('newuser').addEventListener('click',function(){
    fill({u:'',portal:1,ad:0,pgroup:'',admin:0,dom:0,site:0,nom:'',prenom:'',service:'',photov:'',expires:'',role:'full',
          had_portal:0,had_ad:0}, true); open();
    setTimeout(function(){uName.focus();},60);
  });
  [].forEach.call(document.querySelectorAll('.edit-user'),function(b){
    b.addEventListener('click',function(){
      fill({u:b.dataset.u, portal:b.dataset.portal==='1', ad:b.dataset.ad==='1',
            had_portal:b.dataset.portal==='1', had_ad:b.dataset.ad==='1',
            pgroup:b.dataset.pgroup, admin:b.dataset.admin==='1', dom:b.dataset.dom==='1', site:b.dataset.site,
            nom:b.dataset.nom, prenom:b.dataset.prenom, service:b.dataset.service, photov:b.dataset.photov, expires:b.dataset.expiry, role:b.dataset.role}, false);
      open();
    });
  });
  [].forEach.call(ov.querySelectorAll('[data-close]'),function(x){x.addEventListener('click',close);});
  ov.addEventListener('click',function(e){if(e.target===ov)close();});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});

  // Modale de gestion des commissariats (liaison paresseuse : le DOM de la modale
  // est défini plus bas dans la page, donc on le résout au moment du clic).
  var msBtn=document.getElementById('managesites');
  if(msBtn){
    msBtn.addEventListener('click',function(){
      var sov=document.getElementById('sitemodal'); if(!sov) return;
      sov.classList.add('open');
      if(!sov._bound){ sov._bound=1;
        [].forEach.call(sov.querySelectorAll('[data-close]'),function(x){x.addEventListener('click',function(){sov.classList.remove('open');});});
        sov.addEventListener('click',function(e){if(e.target===sov)sov.classList.remove('open');});
        document.addEventListener('keydown',function(e){if(e.key==='Escape')sov.classList.remove('open');});
      }
    });
  }

  <?php if ($openModal): ?>
  <?php /* « had_portal » / « had_ad » décrivent l'ÉTAT EN BASE, pas les cases soumises :
           après un refus, la fiche se rouvre avec la case décochée, et c'est justement là
           que l'avertissement de suppression doit reparaître. */ ?>
  fill(<?= json_encode(['u'=>$edit['username'],'portal'=>$edit['portal'],'ad'=>$edit['ad'],
        'had_portal'=>isset($portalG[$edit['username']]), 'had_ad'=>isset($adUsers[$edit['username']]),
        'pgroup'=>$edit['pgroup'],'admin'=>$edit['admin'],'dom'=>$edit['domadmin'],'site'=>$edit['site'],
        'nom'=>$edit['nom'],'prenom'=>$edit['prenom'],'service'=>$edit['service'],
        'photov'=>($photoV[$edit['username']] ?? ''), 'expires'=>($expiry[$edit['username']] ?? ''),
        'role'=>($adminRole[$edit['username']] ?? 'full')]) ?>, <?= $edit['is_new'] ? 'true' : 'false' ?>);
  open();
  <?php endif; ?>
})();
</script>

<!-- ════ Modale gestion des commissariats ════ -->
<div class="modal-ov" id="sitemodal">
  <div class="modal" role="dialog" aria-modal="true">
    <div class="modal-head"><h2>🏢 Commissariats</h2>
      <button type="button" class="modal-x" data-close aria-label="Fermer">&times;</button></div>
    <div class="modal-body">
      <p class="muted small" style="margin-top:0">Groupes d'appartenance des agents (circonscriptions et postes de
        l'Essonne). Vous pouvez en ajouter ou en retirer.</p>
      <div class="table-wrap" style="max-height:46vh;overflow-y:auto;border:1px solid var(--line);border-radius:10px">
        <table class="grid-table" style="font-size:.85rem">
          <thead><tr><th>Commissariat</th><th>Circonscription (CPN)</th><th></th></tr></thead>
          <tbody>
          <?php if (!$sites): ?><tr><td colspan="3" class="muted center">Aucun commissariat.</td></tr>
          <?php else: foreach ($sites as $id => $s): ?>
            <tr>
              <td><strong><?= e($s['name']) ?></strong></td>
              <td class="muted"><?= e($s['cpn']) ?></td>
              <td class="row-actions">
                <form method="post" style="display:inline" onsubmit="return confirm('Supprimer « <?= e($s['name']) ?> » ? Les agents rattachés seront détachés.')">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="site_del">
                  <input type="hidden" name="site_id" value="<?= $id ?>"><button class="btn-sm btn-danger">Suppr.</button></form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <form method="post" style="margin-top:1rem;display:grid;gap:.6rem">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="site_add">
        <div class="hd" style="font-weight:600">➕ Ajouter un commissariat</div>
        <input type="text" name="site_name" required placeholder="Nom (ex. Palaiseau)"
               style="padding:.55rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
        <input type="text" name="site_cpn" placeholder="Circonscription (ex. CPN Massy-Palaiseau)"
               style="padding:.55rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
        <div style="text-align:right"><button class="btn">Ajouter</button></div>
      </form>
    </div>
  </div>
</div>

<section class="panel" style="margin-top:1rem">
  <div class="panel-head"><h2>📥 Import CSV en masse</h2></div>
  <form method="post" enctype="multipart/form-data" style="padding:1.2rem">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="import">
    <p class="muted small" style="margin:0 0 .6rem">Une ligne par agent. Colonnes (séparateur <code>;</code>, <code>,</code> ou tabulation) :
      <br><code>identifiant ; motdepasse ; groupe_portail ; domaine(oui/non) ; commissariat ; nom ; prénom ; service</code>
      <br>L'identifiant est un <strong>matricule à 7 chiffres</strong> (ex. <code>0110480</code>) ou <code>admin-0110480</code>.
      Les colonnes 3 à 8 sont facultatives (le commissariat est reconnu par son nom). Une ligne commençant par <code>#</code> ou l'en-tête « identifiant » est ignorée.
      Les comptes existants sont mis à jour (mot de passe + groupe).
      <br><strong>Mot de passe vide</strong> : accepté pour un compte qui <em>existe déjà</em> — on met alors à jour son
      identité, son groupe et son commissariat sans toucher au mot de passe. C'est ce qui rend le fichier d'<strong>export</strong>
      (⬇️ en haut) ré-importable tel quel : il ne contient jamais les mots de passe. Pour un compte <em>neuf</em>, le mot
      de passe reste obligatoire.
      <br>Une ligne avec <code>domaine = oui</code> exige un mot de passe d'au moins 8 caractères, avec majuscule et chiffre :
      sinon la ligne est <strong>écartée entière</strong> et signalée — elle ne crée pas non plus l'accès Internet.</p>
    <!-- L'exemple portait des identifiants « dupont.jean », hérités d'avant la règle du
         matricule : recopié tel quel, il était rejeté ligne par ligne pour identifiant
         invalide. Un exemple faux dans le mode d'emploi coûte un appel à l'assistance. -->
    <textarea name="csv" rows="6" placeholder="0110480 ; Motdepasse1 ; default ; oui ; Palaiseau ; DUPONT ; Jean ; Police secours&#10;0224891 ; Secret2024A ; cadres ; oui ; Massy ; MARTIN ; Claire ; PJ&#10;admin-0110480 ; Passage01X ; ; non"
      style="width:100%;padding:.7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px;font-family:monospace;font-size:.85rem"></textarea>
    <div style="display:flex;align-items:center;gap:1rem;margin-top:.7rem;flex-wrap:wrap">
      <label class="muted small">…ou fichier .csv : <input type="file" name="csvfile" accept=".csv,text/csv,text/plain"></label>
      <button class="btn">Importer</button>
    </div>
  </form>
</section>

<style>
  .bulkbar{display:none;align-items:center;gap:.6rem;flex-wrap:wrap;padding:.6rem 1.2rem;background:var(--bg);border-bottom:1px solid var(--line)}
  .bulkbar.show{display:flex}
  .bulkbar select,.bulkbar input[type=text]{padding:.4rem .5rem;background:var(--card,var(--bg));color:var(--text);border:1px solid var(--line);border-radius:6px;font-size:.85rem}
</style>
<script>
(function(){
  var bar=document.getElementById('bulkbar'), cnt=document.getElementById('selcount'),
      all=document.getElementById('selall'), rows=[].slice.call(document.querySelectorAll('.selrow')),
      op=document.getElementById('bulkop'), arg=document.getElementById('bulkarg');
  // Une ligne masquée par le filtre n'est pas sélectionnable : « tout sélectionner » ne
  // portait pas sur ce qui est affiché mais sur la table entière, et l'on validait alors
  // une action en masse sur des comptes hors de vue.
  function visibles(){
    return rows.filter(function(r){
      var tr=r.closest('tr');
      return tr && tr.style.display!=='none';
    });
  }
  function refresh(){
    var sel=rows.filter(function(r){return r.checked;}), vis=visibles();
    cnt.textContent=sel.length;
    bar.classList.toggle('show', sel.length>0);
    // La case d'en-tête reflète l'état réel : cochée seulement si TOUT le visible l'est,
    // grisée en position intermédiaire. Sans cela elle restait cochée après un filtrage
    // qui venait pourtant de vider la sélection.
    all.checked = vis.length>0 && sel.length>=vis.length;
    all.indeterminate = sel.length>0 && sel.length<vis.length;
  }
  function argPlaceholder(){
    var v=op.value;
    arg.style.display=(v==='delete')?'none':'';
    arg.placeholder=(v==='setgroup')?'nom du groupe portail':(v==='setpassword')?'nouveau mot de passe (8+ car., majuscule, chiffre)':(v==='addadgroup')?'nom du groupe AD':(v==='setsite')?'nom du commissariat (vide = retirer)':'';
    // Chaque opération propose SES noms existants ; sans liste, la saisie libre inventait
    // des groupes que rien ne rejetait.
    var listes={setsite:'sitelist', setgroup:'pgrouplist', addadgroup:'adgrouplist'};
    if(listes[v]){arg.setAttribute('list',listes[v]);}else{arg.removeAttribute('list');}
  }
  all.addEventListener('change',function(){var c=all.checked;visibles().forEach(function(r){r.checked=c;});refresh();});
  rows.forEach(function(r){r.addEventListener('change',refresh);});
  op.addEventListener('change',argPlaceholder);
  document.getElementById('bulkgo').addEventListener('click',function(e){
    var sel=rows.filter(function(r){return r.checked;});
    if(!sel.length){e.preventDefault();return;}
    if(op.value==='delete'){
      /* La confirmation annonçait un NOMBRE. Un nombre ne se vérifie pas : « supprimer 23
         compte(s) » se valide aussi vite qu'on l'a lu, et l'erreur ne se découvre qu'au
         moment où un agent ne peut plus ouvrir sa session. On énumère donc les matricules,
         et l'on nomme ceux que le serveur refusera de toucher. */
      var noms=sel.map(function(r){return r.value;}),
          gardes=sel.filter(function(r){return r.dataset.admin||r.dataset.self;})
                    .map(function(r){return r.value;}),
          apercu=noms.slice(0,12).join(', ')+(noms.length>12?' … (+'+(noms.length-12)+')':''),
          txt='Supprimer '+noms.length+' compte(s) — portail, domaine et droits :\n\n'+apercu+'\n\n'
             +'Le compte domaine est EFFACÉ de l\'annuaire : un profil Windows neuf sera créé si vous le recréez.';
      if(gardes.length){ txt+='\n\nIgnoré(s) : '+gardes.join(', ')+' (compte « admin » ou le vôtre).'; }
      if(!confirm(txt)){e.preventDefault();return;}
    }
    if((op.value==='setgroup'||op.value==='setpassword'||op.value==='addadgroup') && !arg.value.trim()){
      e.preventDefault();arg.focus();return;
    }
  });
  argPlaceholder();refresh();
})();
</script>
<?php pf_footer(); ?>
