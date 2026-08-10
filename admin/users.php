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

    if ($action === 'save' && $u !== '' && !pf_valid_id($u)) {
        $flash = ['Identifiant invalide. ' . PF_ID_HINT, 'err'];
    } elseif ($action === 'save' && $u !== '') {
        $p        = (string) ($_POST['password'] ?? '');
        $portal   = isset($_POST['portal']);
        $adAcct   = isset($_POST['ad_account']) && $dcUp;
        $pgroup   = trim((string) ($_POST['pgroup'] ?? ''));
        $adgroup  = trim((string) ($_POST['adgroup'] ?? ''));
        $isAdmin  = isset($_POST['role_admin']);       // administrateur console
        $isDomAdm = isset($_POST['role_domadmin']);    // administrateur domaine
        $existsPortal = (bool) $db->query('SELECT 1 FROM radcheck WHERE username=' . $db->quote($u) . ' AND attribute="Cleartext-Password"')->fetchColumn();
        $existsAd     = $dcUp && in_array($u, ad_lines('user', 'list'), true);
        $msgs = [];

        // ── Accès Internet (portail RADIUS) ──
        if ($portal) {
            if ($p !== '') {
                $db->prepare('DELETE FROM radcheck WHERE username=? AND attribute="Cleartext-Password"')->execute([$u]);
                $db->prepare('INSERT INTO radcheck (username,attribute,op,value) VALUES (?,"Cleartext-Password",":=",?)')->execute([$u, $p]);
            } elseif (!$existsPortal) {
                $flash = ['Le mot de passe est requis pour créer l\'accès Internet.', 'err'];
            }
            $db->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$u]);
            if ($pgroup !== '') { $db->prepare('INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)')->execute([$u, $pgroup]); }
        } else {
            $db->prepare('DELETE FROM radcheck WHERE username=?')->execute([$u]);
            $db->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$u]);
        }

        // ── Compte domaine (AD) ──
        if ($adAcct) {
            if (!$existsAd) {
                if ($p === '') { $flash = ['Le mot de passe est requis pour créer le compte domaine.', 'err']; }
                else { $out = ad('user', 'create', $u, $p); if (preg_match('/ERROR|Failed|password/i', $out)) { $msgs[] = 'AD : ' . trim($out); } }
            } elseif ($p !== '') {
                ad('user', 'setpassword', $u, $p);
            }
            if ($adgroup !== '') { ad('group', 'addmembers', $adgroup, $u); }
        } elseif ($existsAd) {
            ad('user', 'delete', $u);
            $isDomAdm = false;
        }

        // ── Droit : administrateur de la console ──
        if ($isAdmin) {
            if ($p !== '') {
                $h = password_hash($p, PASSWORD_DEFAULT);
                $db->prepare('INSERT INTO pf_admins (username,password_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)')->execute([$u, $h]);
            } elseif (!(bool) $db->query('SELECT 1 FROM pf_admins WHERE username=' . $db->quote($u))->fetchColumn()) {
                $msgs[] = 'Droit console : mot de passe requis pour créer cet administrateur.';
            }
            // Niveau d'accès (rôle). JAMAIS pour « admin » : il reste complet quoi qu'il arrive.
            $lvl = in_array($_POST['admin_level'] ?? '', ['full', 'comptes', 'lecture'], true) ? (string) $_POST['admin_level'] : 'full';
            if ($u !== 'admin') { try { $db->prepare('UPDATE pf_admins SET role=? WHERE username=?')->execute([$lvl, $u]); } catch (Throwable $e) {} }
        } else {
            if ($u !== 'admin') { $db->prepare('DELETE FROM pf_admins WHERE username=?')->execute([$u]); }
        }

        // ── Droit : administrateur du domaine ──
        if ($dcUp) {
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

        if (!$flash) { $flash = ['Compte « ' . $u . ' » enregistré.' . ($msgs ? ' ' . implode(' ', $msgs) : ''), $msgs ? 'err' : 'ok']; }
        audit('users.save', $u);
    }

    if ($action === 'delete' && $u !== '') {
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
    if ($action === 'bulk') {
        $op   = (string) ($_POST['bulk_op'] ?? '');
        $sel  = array_values(array_filter(array_map(
            fn($x) => preg_replace('/[^A-Za-z0-9._@-]/', '', (string) $x),
            (array) ($_POST['sel'] ?? [])), fn($x) => $x !== ''));
        $arg  = trim((string) ($_POST['bulk_arg'] ?? ''));
        $n = 0; $skip = 0;
        $adList = $dcUp ? ad_lines('user', 'list') : [];
        foreach ($sel as $su) {
            if ($op === 'delete') {
                if ($su === 'admin') { $skip++; continue; }
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
                if ((bool) $db->query('SELECT 1 FROM radcheck WHERE username=' . $db->quote($su) . ' AND attribute="Cleartext-Password"')->fetchColumn()) {
                    $db->prepare('UPDATE radcheck SET value=? WHERE username=? AND attribute="Cleartext-Password"')->execute([$arg, $su]);
                }
                if ($dcUp && in_array($su, $adList, true)) { ad('user', 'setpassword', $su, $arg); }
                $n++;
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
        $flash = [$n . ' compte(s) ' . ($labels[$op] ?? 'traité·s') . ($skip ? " ({$skip} ignoré·s)" : '') . '.', $n ? 'ok' : 'err'];
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
            if ($ip === '') { $errs[] = "ligne {$line} ({$iu}) : mot de passe manquant"; continue; }
            // Portail
            $existed = (bool) $db->query('SELECT 1 FROM radcheck WHERE username=' . $db->quote($iu) . ' AND attribute="Cleartext-Password"')->fetchColumn();
            $db->prepare('DELETE FROM radcheck WHERE username=? AND attribute="Cleartext-Password"')->execute([$iu]);
            $db->prepare('INSERT INTO radcheck (username,attribute,op,value) VALUES (?,"Cleartext-Password",":=",?)')->execute([$iu, $ip]);
            $db->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$iu]);
            if ($ipg !== '') { $db->prepare('INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)')->execute([$iu, $ipg]); }
            $existed ? $updated++ : $created++;
            // Commissariat (colonne 5 facultative, par nom)
            $isite = trim($col[4] ?? '');
            if ($isite !== '') { pf_set_site($db, $sites, $iu, (int) ($siteByName[mb_strtolower($isite)] ?? 0)); }
            // Identité (colonnes 6/7/8 facultatives : nom, prénom, service)
            $inom = trim($col[5] ?? ''); $iprenom = trim($col[6] ?? ''); $iservice = trim($col[7] ?? '');
            if ($inom !== '' || $iprenom !== '' || $iservice !== '') { pf_set_profile($db, $iu, $inom, $iprenom, $iservice); }
            // Domaine
            if ($iad && $dcUp) {
                if (in_array($iu, $adList, true)) { ad('user', 'setpassword', $iu, $ip); $adOk++; }
                else {
                    $out = ad('user', 'create', $iu, $ip);
                    if (preg_match('/ERROR|Failed|password/i', $out)) { $errs[] = "ligne {$line} ({$iu}) AD : " . trim($out); }
                    else { $adOk++; $adList[] = $iu; }
                }
            }
        }
        $sum = "Import : {$created} créé·s, {$updated} mis à jour" . ($dcUp ? ", {$adOk} compte(s) domaine" : '') . '.';
        if ($errs) { $sum .= ' ⚠ ' . count($errs) . ' erreur(s) : ' . implode(' | ', array_slice($errs, 0, 5)); }
        $flash = [$sum, $errs ? 'err' : 'ok'];
    }

    // ── Gestion des commissariats (liste des groupes) ────────────────────────
    if ($action === 'site_add') {
        $name = trim((string) ($_POST['site_name'] ?? ''));
        $cpn  = trim((string) ($_POST['site_cpn'] ?? ''));
        if ($name === '') { $flash = ['Nom de commissariat requis.', 'err']; }
        else {
            $db->prepare('INSERT INTO pf_commissariats (name,cpn) VALUES (?,?) ON DUPLICATE KEY UPDATE cpn=VALUES(cpn)')->execute([$name, $cpn]);
            $flash = ['Commissariat « ' . $name . ' » enregistré.', 'ok'];
        }
    }
    if ($action === 'site_del') {
        $sid = (int) ($_POST['site_id'] ?? 0);
        if ($sid > 0) {
            $db->prepare('DELETE FROM pf_commissariats WHERE id=?')->execute([$sid]);
            $db->prepare('DELETE FROM pf_user_site WHERE commissariat_id=?')->execute([$sid]);
            $flash = ['Commissariat supprimé.', 'ok'];
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
  .roles .u-chk+.u-chk{margin-top:.7rem}
  .rbadge{display:inline-block;font-size:.68rem;padding:.1rem .5rem;border-radius:20px;margin-left:.25rem}
  .r-portal{background:rgba(56,189,248,.15);color:#38bdf8} .r-ad{background:rgba(74,222,128,.15);color:#4ade80}
  .r-adm{background:rgba(234,179,8,.18);color:#eab308} .r-dom{background:rgba(248,113,113,.18);color:#f87171}
  .r-site{background:rgba(168,139,250,.18);color:#a78bfa}
</style>
<?php
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
      <input type="text" name="bulk_arg" id="bulkarg" placeholder="groupe / mot de passe" list="sitelist">
      <datalist id="sitelist"><?php foreach ($sites as $s): ?><option value="<?= e($s['name']) ?>"><?php endforeach; ?></datalist>
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
    <thead><tr><th style="width:1%"><input type="checkbox" id="selall" title="Tout sélectionner"></th><th>Identifiant</th><th>Accès / droits</th><th>Commissariat</th><th>État</th><th></th></tr></thead>
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
      <tr class="urow" data-f="<?= e($rsearch) ?>" data-site="<?= $sid ?>" data-types="<?= e(implode(' ', $rtypes)) ?>">
        <td><input type="checkbox" class="selrow" name="sel[]" form="bulkform" value="<?= e($name) ?>"<?= $name === 'admin' ? ' data-admin="1"' : '' ?>></td>
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
<script>
(function(){
  var q=document.getElementById('filt-q'), fs=document.getElementById('filt-site'),
      ft=document.getElementById('filt-type'), cnt=document.getElementById('filt-count'),
      empty=document.getElementById('filt-empty'),
      rows=[].slice.call(document.querySelectorAll('tr.urow'));
  if(!q) return;
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
      r.style.display=ok?'':'none'; if(ok) shown++;
    });
    empty.style.display=shown?'none':'';
    cnt.textContent=shown+' / '+rows.length+' compte(s)';
  }
  q.addEventListener('input',apply); fs.addEventListener('change',apply); ft.addEventListener('change',apply);
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
        <label class="field" style="margin:.7rem 0 0">Groupe (quotas / horaires)
          <input type="text" name="pgroup" id="f_pgroup" placeholder="ex. default"></label>
      </div>

      <div class="u-block<?= $dcUp ? '' : ' disabled' ?>">
        <label class="u-chk"><input type="checkbox" name="ad_account" id="f_ad" <?= $dcUp ? '' : 'disabled' ?>>
          <span class="txt">🗄️ Compte domaine <small>Ouverture de session Windows (Active Directory)</small></span></label>
        <label class="field" style="margin:.7rem 0 0">Groupe AD <span class="muted small">(optionnel)</span>
          <input type="text" name="adgroup" id="f_adgroup" placeholder="ex. Fonctionnaires"></label>
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
      <p class="muted small" style="margin:.6rem 0 0">Un mot de passe pour compte domaine ou admin console doit être
      complexe (8+ car., majuscule, chiffre).</p>
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
    document.getElementById('f_pgroup').value=d.pgroup||'';
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
    document.getElementById('f_level').value=d.role||'full';
    var lw=document.getElementById('f_level_wrap'); if(lw) lw.style.display=document.getElementById('f_admin').checked?'':'none';
  }
  document.getElementById('f_admin').addEventListener('change',function(){
    var lw=document.getElementById('f_level_wrap'); if(lw) lw.style.display=this.checked?'':'none';
  });
  document.getElementById('newuser').addEventListener('click',function(){
    fill({u:'',portal:1,ad:0,pgroup:'',admin:0,dom:0,site:0,nom:'',prenom:'',service:'',photov:'',expires:'',role:'full'}, true); open();
    setTimeout(function(){uName.focus();},60);
  });
  [].forEach.call(document.querySelectorAll('.edit-user'),function(b){
    b.addEventListener('click',function(){
      fill({u:b.dataset.u, portal:b.dataset.portal==='1', ad:b.dataset.ad==='1',
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
  fill(<?= json_encode(['u'=>$edit['username'],'portal'=>$edit['portal'],'ad'=>$edit['ad'],
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
      Les comptes existants sont mis à jour (mot de passe + groupe).</p>
    <textarea name="csv" rows="6" placeholder="dupont.jean ; Motdepasse1 ; default ; oui&#10;martin.claire ; Secret2024! ; cadres ; oui&#10;stagiaire.ete ; Passage01 ; invites ; non"
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
  function refresh(){
    var sel=rows.filter(function(r){return r.checked;});
    cnt.textContent=sel.length;
    bar.classList.toggle('show', sel.length>0);
  }
  function argPlaceholder(){
    var v=op.value;
    arg.style.display=(v==='delete')?'none':'';
    arg.placeholder=(v==='setgroup')?'nom du groupe portail':(v==='setpassword')?'nouveau mot de passe':(v==='addadgroup')?'nom du groupe AD':(v==='setsite')?'nom du commissariat (vide = retirer)':'';
    if(v==='setsite'){arg.setAttribute('list','sitelist');}else{arg.removeAttribute('list');}
  }
  all.addEventListener('change',function(){rows.forEach(function(r){r.checked=all.checked;});refresh();});
  rows.forEach(function(r){r.addEventListener('change',refresh);});
  op.addEventListener('change',argPlaceholder);
  document.getElementById('bulkgo').addEventListener('click',function(e){
    var sel=rows.filter(function(r){return r.checked;});
    if(!sel.length){e.preventDefault();return;}
    if(op.value==='delete' && !confirm('Supprimer '+sel.length+' compte(s) (portail + domaine + droits) ?')){e.preventDefault();return;}
    if((op.value==='setgroup'||op.value==='setpassword'||op.value==='addadgroup') && !arg.value.trim()){
      e.preventDefault();arg.focus();return;
    }
  });
  argPlaceholder();refresh();
})();
</script>
<?php pf_footer(); ?>
