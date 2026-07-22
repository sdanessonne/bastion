<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — Active Directory (Samba AD DC) : fonctionnaires, ordinateurs, GPO, partages. */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';

function ad(...$args): string {
    $cmd = 'sudo /usr/local/sbin/proxyfibre-ad';
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
    return (string) shell_exec($cmd . ' 2>&1');
}
/** Lecture avec cache court (samba-tool est lent). */
function ad_cache(string $key, int $ttl, ...$args): string {
    $f = '/dev/shm/pf-ad-' . preg_replace('/[^a-z0-9]/', '', $key) . '.cache';
    if (is_file($f) && (time() - filemtime($f)) < $ttl) {
        $r = @file_get_contents($f);
        if ($r !== false) { return $r; }
    }
    $r = ad(...$args);
    if (trim($r) !== '') { @file_put_contents($f, $r); }
    return $r;
}
function ad_lines_cached(string $key, int $ttl, ...$args): array {
    return array_values(array_filter(array_map('trim', explode("\n", ad_cache($key, $ttl, ...$args))), fn($l) => $l !== ''));
}
function ad_cache_clear(): void { foreach (glob('/dev/shm/pf-ad-*.cache') ?: [] as $f) { @unlink($f); } }

$dcUp = trim((string) shell_exec('systemctl is-active samba-ad-dc 2>/dev/null')) === 'active';

// ── Domaine actuel + nom souhaité (paramétrable) ─────────────────────────────
$di = $dcUp ? ad_cache('domaininfo', 300, 'domaininfo') : '';
preg_match('/realm=(.+)/', $di, $mr);    $curRealm = trim($mr[1] ?? '') ?: 'BASTION.LOCAL';
preg_match('/workgroup=(.+)/', $di, $mw); $curWg    = trim($mw[1] ?? '') ?: 'BASTION';
$baseDN = 'DC=' . implode(',DC=', explode('.', strtolower($curRealm)));
$wantRealm = $curRealm; $wantDom = $curWg;
try { foreach (pf_db()->query("SELECT k,v FROM pf_settings WHERE k IN ('ad_realm','ad_domain')") as $r) {
    if ($r['k'] === 'ad_realm'  && $r['v'] !== '') { $wantRealm = $r['v']; }
    if ($r['k'] === 'ad_domain' && $r['v'] !== '') { $wantDom   = $r['v']; }
} } catch (Throwable $e) {}

// ── Catalogue de GPO prêtes à déployer (stratégies registre) — voir inc/gpo-catalog.php ──
$GPO_CATALOG = require __DIR__ . '/inc/gpo-catalog.php';

// ── Groupes intégrés d'Active Directory (créés automatiquement, non supprimables) et
//    descriptions en clair pour les plus utiles. Tout groupe absent de cette liste est
//    considéré comme un groupe MÉTIER créé par l'administration. ──
$GROUP_DESC = [
    'Domain Admins'        => "Administrateurs du domaine — contrôle total sur l'annuaire et tous les postes. À réserver à très peu de comptes.",
    'Enterprise Admins'    => "Administrateurs de la forêt — droits les plus élevés (au-delà d'un seul domaine).",
    'Schema Admins'        => "Peut modifier le schéma de l'annuaire — très sensible, à laisser vide en temps normal.",
    'Domain Users'         => "Tous les comptes d'agents du domaine en font partie automatiquement.",
    'Domain Computers'     => "Tous les postes joints au domaine.",
    'Domain Guests'        => "Comptes invités du domaine (accès restreint).",
    'Domain Controllers'   => "Les serveurs contrôleurs de domaine (le cœur de l'annuaire).",
    'Group Policy Creator Owners' => "Peut créer et gérer des stratégies de groupe (GPO).",
    'Administrators'       => "Administrateurs locaux du contrôleur de domaine.",
    'Account Operators'    => "Peut créer et gérer les comptes et groupes courants (hors administrateurs).",
    'Backup Operators'     => "Peut sauvegarder et restaurer les fichiers, même sans droit d'accès.",
    'Server Operators'     => "Peut administrer les serveurs (services, partages, sauvegardes).",
    'Print Operators'      => "Peut gérer les imprimantes partagées du domaine.",
    'Remote Desktop Users' => "Autorisés à se connecter aux postes en Bureau à distance.",
    'Protected Users'      => "Comptes à protection renforcée contre le vol d'identifiants.",
    'Cert Publishers'      => "Serveurs autorisés à publier des certificats dans l'annuaire.",
    'DnsAdmins'            => "Administrateurs du service DNS.",
    'DnsUpdateProxy'       => "Comptes autorisés à mettre à jour les enregistrements DNS pour d'autres.",
    'Cryptographic Operators'          => "Peut effectuer des opérations de chiffrement.",
    'Network Configuration Operators'  => "Peut modifier les paramètres réseau des postes.",
    'Distributed COM Users'            => "Peut lancer/activer des objets DCOM sur la machine.",
    'Certificate Service DCOM Access'  => "Accès DCOM au service de certificats.",
    'Event Log Readers'                => "Peut lire les journaux d'événements Windows.",
    'Replicator'                       => "Réservé à la réplication de fichiers (rôle système).",
    'Terminal Server License Servers'  => "Serveurs de licences Bureau à distance.",
    'Pre-Windows 2000 Compatible Access' => "Compatibilité avec d'anciens systèmes (rôle hérité).",
    'RAS and IAS Servers'              => "Serveurs d'accès distant / RADIUS autorisés à lire des propriétés de comptes.",
    'Windows Authorization Access Group' => "Accès à certaines propriétés d'autorisation des comptes.",
    'Read-only Domain Controllers'     => "Contrôleurs de domaine en lecture seule (sites distants).",
    'Enterprise Read-only Domain Controllers' => "Contrôleurs en lecture seule à l'échelle de la forêt.",
    'Cloneable Domain Controllers'     => "Contrôleurs de domaine pouvant être clonés (virtualisation).",
    'Denied RODC Password Replication Group'  => "Comptes dont le mot de passe n'est jamais mis en cache sur un contrôleur en lecture seule.",
    'Allowed RODC Password Replication Group' => "Comptes dont le mot de passe peut être mis en cache sur un contrôleur en lecture seule.",
    'Key Admins'           => "Peut gérer les attributs de clés (Windows Hello, BitLocker).",
    'Enterprise Key Admins'=> "Idem, à l'échelle de la forêt.",
    'Guests'               => "Groupe invité local (accès minimal).",
    'Users'                => "Utilisateurs standard locaux du contrôleur.",
];
// Ensemble des noms intégrés (ceux ci-dessus + quelques autres purement techniques).
$BUILTIN_GROUPS = array_fill_keys(array_keys($GROUP_DESC), true) + array_fill_keys([
    'IIS_IUSRS', 'Incoming Forest Trust Builders', 'Storage Replica Administrators',
    'Access Control Assistance Operators', 'Remote Management Users', 'Hyper-V Administrators',
    'RDS Remote Access Servers', 'RDS Endpoint Servers', 'RDS Management Servers',
    'Performance Monitor Users', 'Performance Log Users',
], true);

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $out = '';
    if ($do === 'domain_save') {
        $rr = strtoupper(preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_POST['realm'] ?? '')));
        $dd = strtoupper(substr(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_POST['domain'] ?? '')), 0, 15));
        if ($rr === '' || strpos($rr, '.') === false) {
            $out = 'ERROR: nom de domaine invalide (ex. POLICE.LOCAL).';
        } else {
            if ($dd === '') { $dd = strtok($rr, '.'); }
            $up = pf_db()->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
            $up->execute(['ad_realm', $rr]); $up->execute(['ad_domain', $dd]);
            $wantRealm = $rr; $wantDom = $dd;
            $out = "Nom de domaine enregistré : $rr (NetBIOS $dd). Utilisez « Recréer le domaine » pour l'appliquer.";
        }
    } elseif ($do === 'reprovision') {
        $out = ad('reprovision');
        $out = trim($out) !== '' ? 'Recréation du domaine lancée en arrière-plan (~2 min). Rechargez la page ensuite.' : $out;
    } elseif ($do === 'gpo_desc') {
        $guid = strtoupper(preg_replace('/[^0-9A-Fa-f{}-]/', '', (string) ($_POST['guid'] ?? '')));
        $desc = trim((string) ($_POST['desc'] ?? ''));
        if ($guid !== '') {
            pf_db()->exec('CREATE TABLE IF NOT EXISTS pf_gpo_desc (guid VARCHAR(64) PRIMARY KEY, description TEXT)');
            pf_db()->prepare('INSERT INTO pf_gpo_desc (guid,description) VALUES (?,?) ON DUPLICATE KEY UPDATE description=VALUES(description)')->execute([$guid, $desc]);
            $out = 'Description enregistrée.';
        }
    } elseif ($do === 'computer_desc') {
        $name = strtoupper(preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($_POST['name'] ?? '')));
        $desc = trim((string) ($_POST['desc'] ?? ''));
        if ($name !== '') {
            pf_db()->exec('CREATE TABLE IF NOT EXISTS pf_computer_desc (name VARCHAR(64) PRIMARY KEY, description TEXT)');
            pf_db()->prepare('INSERT INTO pf_computer_desc (name,description) VALUES (?,?) ON DUPLICATE KEY UPDATE description=VALUES(description)')->execute([$name, $desc]);
            $out = 'Description du poste enregistrée.';
        }
    } elseif ($do === 'drive_add') {
        pf_db()->exec('CREATE TABLE IF NOT EXISTS pf_drives (id INT AUTO_INCREMENT PRIMARY KEY, letter CHAR(1), path VARCHAR(255), label VARCHAR(96), added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
        $letter = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($_POST['letter'] ?? '')), 0, 1));
        $path   = trim((string) ($_POST['path'] ?? ''));
        $label  = trim((string) ($_POST['label'] ?? ''));
        if ($letter === '' || substr($path, 0, 2) !== '\\\\') {   // doit commencer par \\ (UNC)
            $out = 'ERROR: lettre requise et chemin UNC (\\serveur\partage).';
        } else {
            pf_db()->prepare('INSERT INTO pf_drives (letter,path,label) VALUES (?,?,?)')->execute([$letter, $path, $label]);
            $out = "Lecteur {$letter}: ajouté. Cliquez « Déployer » pour appliquer.";
        }
    } elseif ($do === 'drive_del') {
        pf_db()->prepare('DELETE FROM pf_drives WHERE id=?')->execute([(int) ($_POST['id'] ?? 0)]);
        $out = 'Lecteur retiré.';
    } elseif ($dcUp) {
    switch ($do) {
        case 'user_create':
            $u = trim((string) ($_POST['username'] ?? ''));
            $p = (string) ($_POST['password'] ?? '');
            $out = ad('user', 'create', $u, $p);
            if (!empty($_POST['groupname'])) { ad('group', 'addmembers', (string) $_POST['groupname'], $u); }
            break;
        case 'user_delete':     $out = ad('user', 'delete', (string) ($_POST['name'] ?? '')); break;
        case 'user_setpw':      $out = ad('user', 'setpassword', (string) ($_POST['name'] ?? ''), (string) ($_POST['password'] ?? '')); break;
        case 'computer_delete': $out = ad('computer', 'delete', (string) ($_POST['name'] ?? '')); break;
        case 'group_add':       $out = ad('group', 'add', (string) ($_POST['name'] ?? '')); break;
        case 'group_del':
            $gn = (string) ($_POST['name'] ?? '');
            if (isset($BUILTIN_GROUPS[$gn])) { $out = 'ERROR: groupe système Windows non supprimable.'; }
            else { $out = ad('group', 'delete', $gn); }
            break;
        case 'ou_create':
            $n = preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($_POST['name'] ?? ''));
            $out = ad('ou', 'create', 'OU=' . $n . ',' . $baseDN);
            break;
        case 'gpo_create':   $out = ad('gpo', 'create', (string) ($_POST['name'] ?? '')); break;
        case 'kms_auto':     $out = ad('gpo', 'activation', '192.168.182.1'); break;
        case 'sysvol_reset': $out = ad('gpo', 'sysvolreset', ''); break;
        case 'gpo_cert':
            // Déploie le certificat racine Bastion dans le magasin de confiance des postes.
            $ca = '/etc/proxyfibre/bastion-ca.crt';
            if (!is_readable($ca) || !preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', (string) file_get_contents($ca), $m)) {
                $out = 'ERROR: certificat CA Bastion introuvable.';
            } else {
                $der  = base64_decode(preg_replace('/\s+/', '', $m[1]));
                $thumb = strtoupper(sha1($der));
                $blob = pack('V', 0x20) . pack('V', 1) . pack('V', strlen($der)) . $der;   // magasin CryptoAPI : propID cert + DER
                $pol = [[
                    'keyname'   => 'Software\\Policies\\Microsoft\\SystemCertificates\\Root\\Certificates\\' . $thumb,
                    'valuename' => 'Blob', 'class' => 'MACHINE', 'type' => 'REG_BINARY',
                    'data'      => array_values(unpack('C*', $blob)),
                ]];
                $tmp = tempnam(sys_get_temp_dir(), 'cert');
                file_put_contents($tmp, json_encode($pol));
                $out = ad('gpo', 'deploy', 'Bastion — Certificat racine (confiance HTTPS)', $tmp);
                @unlink($tmp);
            }
            break;
        case 'gpo_deploy':
            $key = (string) ($_POST['tpl'] ?? '');
            if (isset($GPO_CATALOG[$key])) {
                $c = $GPO_CATALOG[$key];
                $tmp = tempnam(sys_get_temp_dir(), 'gpo');
                file_put_contents($tmp, json_encode($c['policies'], JSON_UNESCAPED_UNICODE));
                $out = ad('gpo', 'deploy', 'Bastion — ' . $c['title'], $tmp);
                @unlink($tmp);
            } else { $out = 'ERROR: modèle inconnu.'; }
            break;
        case 'share_create': $out = ad('share', 'create', (string) ($_POST['name'] ?? '')); break;
        case 'drives_deploy':
            $rows = pf_db()->query('SELECT letter,path,label FROM pf_drives ORDER BY letter')->fetchAll();
            if (!$rows) { $out = 'ERROR: aucun lecteur à déployer.'; break; }
            $json = array_map(fn($r) => ['letter' => $r['letter'], 'path' => $r['path'], 'label' => $r['label']], $rows);
            $tmp = tempnam(sys_get_temp_dir(), 'drv');
            file_put_contents($tmp, json_encode($json, JSON_UNESCAPED_UNICODE));
            $out = ad('gpo', 'drives', $tmp);
            @unlink($tmp);
            break;
        case 'wallpaper_deploy':
            // Fond d'écran imposé : upload d'une image → GPO « Bastion — Fond d'écran ».
            $style = preg_replace('/[^0-9a-z]/', '', (string) ($_POST['style'] ?? '10'));
            if (!in_array($style, ['10', '6', '2', '0', '22', 'tile'], true)) { $style = '10'; }
            $f = $_FILES['image'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $out = 'ERROR: image requise (JPG, PNG ou BMP).';
            } elseif ($f['size'] > 15 * 1024 * 1024) {
                $out = 'ERROR: image trop volumineuse (max 15 Mo).';
            } else {
                $info = @getimagesize($f['tmp_name']);
                $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_BMP => 'bmp'];
                if (!$info || !isset($allowed[$info[2]])) {
                    $out = 'ERROR: format non supporté (JPG, PNG ou BMP attendu).';
                } else {
                    $ext = $allowed[$info[2]];
                    $tmp = tempnam(sys_get_temp_dir(), 'wp') . '.' . $ext;
                    if (@move_uploaded_file($f['tmp_name'], $tmp)) {
                        $out = ad('gpo', 'wallpaper', $tmp, $style);
                        $ok = strpos($out, "fond d'ecran deploye") !== false;
                        if ($ok) {
                            // Aperçu web + réglages persistés (best-effort).
                            $media = __DIR__ . '/media';
                            if (!is_dir($media)) { @mkdir($media, 0755, true); }
                            foreach (glob($media . '/wallpaper.*') ?: [] as $old) { @unlink($old); }
                            @copy($tmp, $media . '/wallpaper.' . $ext);
                            @chmod($media . '/wallpaper.' . $ext, 0644);
                            $up = pf_db()->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
                            $up->execute(['wallpaper_ext', $ext]);
                            $up->execute(['wallpaper_style', $style]);
                            $up->execute(['wallpaper_ts', (string) time()]);
                            $out = "Fond d'écran déployé sur le domaine. Il s'appliquera à la prochaine ouverture de session (ou après « gpupdate /force »).";
                        }
                        @unlink($tmp);
                    } else { $out = "ERROR: réception de l'image impossible."; }
                }
            }
            break;
    }
    }
    ad_cache_clear();
    $err = preg_match('/refuse|ERROR|Failed|Traceback|invalide|usage:/i', $out);
    $flash = [trim($out) !== '' ? trim($out) : 'Opération effectuée.', $err ? 'err' : 'ok'];
}

// ── Lectures (mises en cache) ────────────────────────────────────────────────
$users = $computers = $groups = $ous = $gpos = $shares = [];
if ($dcUp) {
    // Cache froid → rafraîchir les 6 listes EN PARALLÈLE en un seul appel (~1,5 s au lieu de ~9 s).
    $wf = '/dev/shm/pf-ad-users.cache';
    if (!is_file($wf) || (time() - filemtime($wf)) > 20) { shell_exec('sudo /usr/local/sbin/proxyfibre-ad warm 2>/dev/null'); }
    $users     = ad_lines_cached('users', 20, 'user', 'list');
    $computers = ad_lines_cached('computers', 20, 'computer', 'list');
    $groups    = ad_lines_cached('groups', 30, 'group', 'list');
    $ous       = ad_lines_cached('ous', 30, 'ou', 'list');
    $blk = [];
    foreach (explode("\n", ad_cache('gpos', 20, 'gpo', 'list')) as $l) {
        if (trim($l) === '') { if (!empty($blk['name'])) { $gpos[] = $blk; } $blk = []; continue; }
        if (preg_match('/^GPO\s*:\s*(\{[0-9A-Fa-f-]+\})/', $l, $m)) { $blk['guid'] = $m[1]; }
        if (preg_match('/display name\s*:\s*(.+)/i', $l, $m)) { $blk['name'] = trim($m[1]); }
    }
    if (!empty($blk['name'])) { $gpos[] = $blk; }
    foreach (explode("\n", ad_cache('shares', 30, 'share', 'list')) as $l) {
        if (preg_match('/^\[([^\]]+)\]/', trim($l), $m)) { $shares[] = $m[1]; }
    }
}
$sys = ['Administrator', 'Guest', 'krbtgt'];
$humanUsers = array_values(array_filter($users, fn($u) => !in_array($u, $sys, true) && stripos($u, 'dns-') !== 0));

// Descriptions des GPO : intégrées (GPO par défaut) + personnalisées (table pf_gpo_desc).
$GPO_BUILTIN = [
    '{31B2F340-016D-11D2-945F-00C04FB984F9}' => "Stratégie appliquée à TOUT le domaine. Contient les règles par défaut : politique de mot de passe (longueur minimale, complexité, durée de validité), verrouillage des comptes après échecs, et paramètres Kerberos. C'est ici que se règlent les exigences de mot de passe imposées à tous les comptes.",
    '{6AC1786C-016F-11D2-945F-00C04FB984F9}' => "Stratégie appliquée aux CONTRÔLEURS DE DOMAINE (les serveurs qui hébergent l'annuaire). Définit leurs droits d'utilisateur et paramètres de sécurité (audit, attribution de privilèges). À ne modifier qu'avec précaution.",
];
// Modèles de description prêts à l'emploi (insérables dans le champ note).
$GPO_TPL = [
    'Blocage des clés USB'        => "Empêche l'utilisation des périphériques de stockage USB sur les postes (protection des données sensibles).",
    "Fond d'écran imposé"         => "Impose le fond d'écran officiel du service et empêche sa modification par l'utilisateur.",
    'Verrouillage de session'     => "Verrouille automatiquement la session après 10 minutes d'inactivité.",
    'Mot de passe complexe'       => "Exige des mots de passe complexes (12 caractères min., majuscules, chiffres, symboles), renouvelés tous les 90 jours.",
    'Restriction panneau config.' => "Restreint l'accès au panneau de configuration et aux paramètres système pour les utilisateurs standard.",
    'Pare-feu Windows imposé'     => "Active et verrouille le pare-feu Windows sur tous les postes du domaine.",
    'Lecteur réseau (partage)'    => "Connecte automatiquement un lecteur réseau vers un dossier partagé à l'ouverture de session.",
];
$gpoDesc = [];
if ($dcUp) {
    try {
        pf_db()->exec('CREATE TABLE IF NOT EXISTS pf_gpo_desc (guid VARCHAR(64) PRIMARY KEY, description TEXT)');
        foreach (pf_db()->query('SELECT guid,description FROM pf_gpo_desc') as $r) { $gpoDesc[strtoupper($r['guid'])] = $r['description']; }
    } catch (Throwable $e) {}
}

// Ordinateurs : description perso (pf_computer_desc) + dernier fonctionnaire connecté (audit d'auth).
$computerDesc = [];
$lastByWs = [];
if ($dcUp) {
    try {
        pf_db()->exec('CREATE TABLE IF NOT EXISTS pf_computer_desc (name VARCHAR(64) PRIMARY KEY, description TEXT)');
        foreach (pf_db()->query('SELECT name,description FROM pf_computer_desc') as $r) { $computerDesc[strtoupper($r['name'])] = $r['description']; }
    } catch (Throwable $e) {}
    foreach (explode("\n", ad_cache('authlog', 20, 'authlog')) as $l) {
        $p = explode("\t", $l);
        if (count($p) < 4) { continue; }
        $ws = strtoupper(trim($p[0], "\\ "));
        $user = preg_replace('/@.*$/', '', $p[1]);
        if (substr($user, -1) === '$') { continue; }   // ignorer les comptes machine (ex. W-91$)
        if ($ws === '' && preg_match('/ipv4:([0-9.]+)/', $p[2], $m)) { $ws = 'IP:' . $m[1]; }
        if ($ws !== '') { $lastByWs[$ws] = ['user' => $user, 'ts' => substr($p[3], 0, 16)]; } // dernière ligne = plus récent
    }
}

// Noms des agents (pour l'arborescence de l'annuaire).
$agentNames = [];
if ($dcUp) {
    try { foreach (pf_db()->query('SELECT username,nom,prenom FROM pf_user_profile') as $r) {
        $n = trim(((string) ($r['nom'] ?? '')) . ' ' . ((string) ($r['prenom'] ?? '')));
        if ($n !== '') { $agentNames[(string) $r['username']] = $n; }
    } } catch (Throwable $e) {}
}

pf_header('Active Directory', 'ad.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<?php if (!$dcUp): ?>
  <div class="flash err">Le contrôleur de domaine (Samba AD DC) n'est pas actif. Lancez le provisioning
  <code>provisioning/setup-ad.sh</code> sur la passerelle, puis rechargez cette page.</div>
<?php else: ?>
<style>
  .ad-intro{background:linear-gradient(120deg,#1e3a5f,#152238);border:1px solid var(--line);border-radius:14px;
    padding:1.1rem 1.4rem;margin-bottom:1.2rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
  .ad-intro .dom{font-size:1.15rem;font-weight:600;color:#fff}
  .ad-intro .desc{color:var(--muted);font-size:.9rem;flex:1;min-width:220px}
  .ad-sec{margin-bottom:1.4rem}
  .ad-sec .lead{color:var(--muted);font-size:.86rem;margin:.2rem 0 0}
  .ad-help{font-size:.78rem;color:var(--muted)}
  .ad-inline{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
  .ad-inline input{flex:1;min-width:180px;padding:.55rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px}
  .chips{display:flex;flex-wrap:wrap;gap:.4rem}
  .chip{background:var(--bg);border:1px solid var(--line);border-radius:20px;padding:.25rem .7rem;font-size:.82rem;color:var(--text)}
</style>

<div class="ad-intro">
  <span style="font-size:2rem">🗄️</span>
  <div>
    <div class="dom">Domaine <?= e($curRealm) ?></div>
    <div class="desc">Annuaire central des <strong>agents</strong> et des <strong>postes</strong> : ouverture de
    session Windows, dossiers partagés et stratégies (GPO).</div>
  </div>
  <span class="badge on">Contrôleur actif</span>
</div>

<section class="cards">
  <div class="kpi"><div class="kpi-val"><?= count($humanUsers) ?></div><div class="kpi-lbl">Fonctionnaires</div></div>
  <div class="kpi"><div class="kpi-val"><?= count($computers) ?></div><div class="kpi-lbl">Ordinateurs</div></div>
  <div class="kpi"><div class="kpi-val"><?= count($gpos) ?></div><div class="kpi-lbl">Stratégies (GPO)</div></div>
  <div class="kpi"><div class="kpi-val"><?= count($shares) ?></div><div class="kpi-lbl">Dossiers partagés</div></div>
</section>

<!-- ARBORESCENCE DE L'ANNUAIRE -->
<style>
  .adtree{font-size:.9rem;overflow-x:auto}
  .tree, .tree ul{list-style:none;margin:0;padding:0}
  .tree ul{margin-left:1.2rem;padding-left:1rem;border-left:1px solid var(--line)}
  .tree li{position:relative;padding:.18rem 0}
  .tree ul>li::before{content:"";position:absolute;left:-1rem;top:1rem;width:.8rem;height:1px;background:var(--line)}
  .tree .node{display:inline-flex;align-items:center;gap:.4rem;padding:.15rem .5rem;border-radius:6px}
  .tree .node small{color:var(--muted);font-weight:400}
  .tree .node.root{font-weight:700;color:#fff;background:linear-gradient(120deg,#1e3a5f,#152238);border:1px solid var(--line)}
  .tree details>summary{cursor:pointer;list-style:none;display:inline-flex;align-items:center;gap:.4rem;
    padding:.15rem .5rem;border-radius:6px;font-weight:600}
  .tree details>summary::-webkit-details-marker{display:none}
  .tree details>summary::before{content:"▸";color:var(--muted);transition:transform .2s;font-size:.8rem}
  .tree details[open]>summary::before{transform:rotate(90deg)}
  .tree details>summary:hover{background:var(--panel2)}
  .tree .leaf-muted{color:var(--muted)}
</style>
<section class="ad-sec panel">
  <div class="panel-head"><h2>🌳 Arborescence de l'annuaire</h2>
    <div style="display:flex;gap:.5rem">
      <button type="button" class="btn-sm" onclick="document.querySelectorAll('.tree details').forEach(d=>d.open=true)">Tout déplier</button>
      <button type="button" class="btn-sm" onclick="document.querySelectorAll('.tree details').forEach(d=>d.open=false)">Tout replier</button>
    </div>
  </div>
  <div class="adtree" style="padding:1.1rem 1.4rem">
    <ul class="tree">
      <li>
        <span class="node root">🏛️ <?= e($curRealm) ?> <small style="color:#9fb3d1"><?= e($baseDN) ?></small></span>
        <ul>
          <li><details open>
            <summary>👥 Comptes utilisateurs (<?= count($humanUsers) ?>)</summary>
            <ul>
              <?php if (!$humanUsers): ?><li class="leaf-muted">Aucun compte.</li>
              <?php else: foreach ($humanUsers as $u): ?>
                <li><span class="node">👤 <strong><?= e($u) ?></strong><?php if (!empty($agentNames[$u])): ?> <small>— <?= e($agentNames[$u]) ?></small><?php endif; ?></span></li>
              <?php endforeach; endif; ?>
            </ul>
          </details></li>
          <li><details open>
            <summary>💻 Ordinateurs (<?= count($computers) ?>)</summary>
            <ul>
              <?php if (!$computers): ?><li class="leaf-muted">Aucun poste joint.</li>
              <?php else: foreach ($computers as $c): $cn = rtrim($c, '$'); $wu = $lastByWs[strtoupper($cn)] ?? null;
                    $cd = $computerDesc[strtoupper($cn)] ?? ''; ?>
                <li><span class="node">💻 <strong><?= e($cn) ?></strong>
                  <?php if ($cd !== ''): ?> <small>— <?= e($cd) ?></small><?php endif; ?>
                  <?php if ($wu): ?> <small>· 👤 <?= e($wu['user']) ?></small><?php endif; ?></span></li>
              <?php endforeach; endif; ?>
            </ul>
          </details></li>
          <li><details>
            <summary>👪 Groupes (<?= count($groups) ?>)</summary>
            <ul>
              <?php foreach ($groups as $g): ?><li><span class="node">🏷️ <?= e($g) ?></span></li><?php endforeach; ?>
            </ul>
          </details></li>
          <li><details>
            <summary>🗂️ Unités d'organisation (<?= count($ous) ?>)</summary>
            <ul>
              <?php if (!$ous): ?><li class="leaf-muted">Aucune OU personnalisée.</li>
              <?php else: foreach ($ous as $o): ?><li><span class="node">🗂️ <?= e($o) ?></span></li><?php endforeach; endif; ?>
            </ul>
          </details></li>
        </ul>
      </li>
    </ul>
  </div>
</section>

<!-- 0. NOM DE DOMAINE -->
<section class="ad-sec panel">
  <div class="panel-head"><h2>⚙️ Nom de domaine</h2></div>
  <div style="padding:1rem 1.2rem">
    <p class="lead" style="margin:0 0 .9rem">Domaine actuel : <strong><?= e($curRealm) ?></strong>
      (NetBIOS <?= e($curWg) ?>, DN <code><?= e($baseDN) ?></code>).</p>
    <form method="post" class="ad-inline" style="margin-bottom:.5rem">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="domain_save">
      <input type="text" name="realm" value="<?= e($wantRealm) ?>" placeholder="ex. POLICE.LOCAL" style="text-transform:uppercase">
      <input type="text" name="domain" value="<?= e($wantDom) ?>" placeholder="NetBIOS" style="max-width:160px;text-transform:uppercase">
      <button class="btn-sm">Enregistrer le nom</button>
    </form>
    <?php if (strcasecmp($wantRealm, $curRealm) !== 0): ?>
      <div class="flash err" style="margin:.7rem 0">⚠️ Nom souhaité (<strong><?= e($wantRealm) ?></strong>) différent du domaine
      actuel. Pour l'appliquer il faut <strong>recréer le domaine</strong> : cela <strong>efface tous les comptes,
      ordinateurs, GPO et partages</strong>.</div>
    <?php endif; ?>
    <form method="post" onsubmit="return confirm('ATTENTION — Recréer le domaine « <?= e($wantRealm) ?> » va EFFACER tous les comptes, ordinateurs, GPO et partages existants. Continuer ?')">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="reprovision">
      <button class="btn-sm btn-danger">🗑️ Recréer le domaine avec ce nom</button>
    </form>
    <p class="ad-help" style="margin-top:.7rem">La recréation prend ~2 min (services redémarrés) ; rechargez la page ensuite.
    Les postes déjà joints devront être re-joints au nouveau domaine.</p>
  </div>
</section>

<!-- 0bis. ACTIVATION KMS -->
<section class="ad-sec panel">
  <div class="panel-head"><h2>🔑 Activation Windows / Office (KMS)</h2>
    <span class="badge <?= trim((string) shell_exec('systemctl is-active proxyfibre-kms 2>/dev/null')) === 'active' ? 'on' : 'off' ?>">
      <?= trim((string) shell_exec('systemctl is-active proxyfibre-kms 2>/dev/null')) === 'active' ? 'Serveur KMS actif' : 'Inactif' ?></span></div>
  <div style="padding:1rem 1.2rem">
    <p class="lead" style="margin:0 0 .7rem">Les postes du domaine activent Windows et Office automatiquement
    contre la passerelle. Sur un poste, en <strong>Invite de commandes (administrateur)</strong> :</p>
    <pre style="margin:0;padding:.9rem 1rem;background:#0b1120;color:#cbd5e1;border:1px solid var(--line);border-radius:10px;font-size:.82rem;overflow:auto">Windows :  slmgr /skms 192.168.182.1:1688
           slmgr /ato

Office  :  cd "C:\Program Files\Microsoft Office\Office16"
           cscript ospp.vbs /sethst:192.168.182.1
           cscript ospp.vbs /act</pre>
    <p class="ad-help" style="margin-top:.6rem">Utiliser les clés KMS génériques (GVLK) de Microsoft.
    L'activation se renouvelle seule (180 jours). Serveur : <code>vlmcsd</code> sur le port 1688.</p>
    <?php $kmsGpo = in_array('Bastion — Activation Windows/Office', array_map(fn($g) => $g['name'] ?? '', $gpos), true); ?>
    <div style="border:1px solid var(--line);border-radius:12px;background:linear-gradient(120deg,#14324f,#152238);padding:1rem 1.2rem;margin-top:1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
      <span style="font-size:1.8rem">⚡</span>
      <div style="flex:1;min-width:240px">
        <div style="font-weight:600">Activation automatique à la jonction au domaine</div>
        <div class="ad-help" style="margin:.2rem 0 0">Déploie une GPO (script de démarrage) + les enregistrements DNS
        d'auto-découverte <code>_vlmcs</code> : <strong>Windows et Office s'activent seuls</strong> contre le KMS Bastion
        dès qu'un poste est sur le domaine. Garde-fou : les postes déjà activés (OEM/numérique) ne sont pas touchés.</div>
      </div>
      <?php if ($kmsGpo): ?><span class="badge on">✓ Activé</span>
      <?php else: ?>
      <form method="post" onsubmit="return confirm('Activer automatiquement Windows/Office sur tous les postes du domaine (KMS) ?')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="kms_auto">
        <button class="btn">⚡ Activer automatiquement</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- 1. FONCTIONNAIRES → page fusionnée -->
<section class="ad-sec panel">
  <div class="panel-head"><h2>👮 Fonctionnaires (<?= count($humanUsers) ?>)</h2>
    <a class="btn-sm" href="/users.php">Gérer les utilisateurs &amp; droits →</a></div>
  <p class="lead" style="padding:.2rem 1.2rem 1rem">La création et la gestion des comptes (accès Internet
  <strong>et</strong> compte domaine, avec les droits d'administration) se font désormais dans l'onglet
  <strong><a href="/users.php" style="color:var(--accent)">Utilisateurs &amp; droits</a></strong> — un seul écran pour
  tout le cycle de vie d'un agent.</p>
</section>

<!-- 2. ORDINATEURS -->
<section class="ad-sec panel">
  <div class="panel-head"><h2>💻 Ordinateurs du domaine (<?= count($computers) ?>)</h2></div>
  <p class="lead" style="padding:0 1.2rem;margin:.7rem 0"><strong>Cliquez sur un poste</strong> pour voir le dernier
  fonctionnaire connecté et ajouter une description. Joindre un poste : DNS sur <code><?= e(explode('.', $baseDN)[0] === 'DC=bastion' ? '192.168.182.2' : '192.168.182.2') ?></code>,
  domaine <code><?= e($curRealm) ?></code>, identifiants <code>Administrator</code>.</p>
  <div style="padding:0 1.2rem 1.2rem">
    <?php if (!$computers): ?><span class="muted">Aucun poste joint pour le moment.</span>
    <?php else: foreach ($computers as $c):
        $cn = rtrim($c, '$');                       // nom sans le $ final du compte machine
        $wu = $lastByWs[strtoupper($cn)] ?? null;
        $cd = $computerDesc[strtoupper($cn)] ?? ''; ?>
      <details class="gpo-item">
        <summary>💻 <?= e($cn) ?><?php if ($wu): ?> <span class="muted" style="font-weight:400">— 👤 <?= e($wu['user']) ?></span><?php endif; ?></summary>
        <div class="gpo-body">
          <p class="expl">👤 <strong>Dernier fonctionnaire connecté :</strong>
            <?php if ($wu): ?><?= e($wu['user']) ?> <span class="muted small">(<?= e($wu['ts']) ?>)</span>
            <?php else: ?><span class="muted">aucune ouverture de session enregistrée</span><?php endif; ?></p>
          <?php if ($cd !== ''): ?><p class="expl"><strong>Description :</strong> <?= nl2br(e($cd)) ?></p><?php endif; ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="computer_desc">
            <input type="hidden" name="name" value="<?= e($cn) ?>">
            <textarea name="desc" rows="2" placeholder="Description du poste (localisation, service, usage…)"><?= e($cd) ?></textarea>
            <button class="btn-sm">Enregistrer la description</button>
          </form>
          <form method="post" style="margin-top:.5rem" onsubmit="return confirm('Retirer <?= e($cn) ?> du domaine ?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="computer_delete">
            <input type="hidden" name="name" value="<?= e($cn) ?>"><button class="btn-sm btn-danger">Retirer du domaine</button></form>
        </div>
      </details>
    <?php endforeach; endif; ?>
  </div>
</section>

<!-- 3. DOSSIERS PARTAGÉS -->
<section class="ad-sec panel">
  <div class="panel-head"><h2>📁 Dossiers partagés (<?= count($shares) ?>)</h2></div>
  <p class="lead" style="padding:0 1.2rem;margin:.7rem 0">Dossiers réseau accessibles depuis les postes via
  <code>\\192.168.182.2\NomDuPartage</code>. Les fichiers déposés sont analysés par l'antivirus.</p>
  <div style="padding:0 1.2rem 1.2rem">
    <form method="post" class="ad-inline" style="margin-bottom:.9rem">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="share_create">
      <input type="text" name="name" required placeholder="Nom du nouveau partage (ex. Brigade)">
      <button class="btn">Créer le partage</button>
    </form>
    <div class="chips">
      <?php if (!$shares): ?><span class="muted">Aucun partage.</span>
      <?php else: foreach ($shares as $sh): ?><span class="chip">📁 <?= e($sh) ?></span><?php endforeach; endif; ?>
    </div>
  </div>
</section>

<!-- 3bis. LECTEURS RÉSEAU (GPO Drive Maps) -->
<?php
$drives = [];
try {
    pf_db()->exec('CREATE TABLE IF NOT EXISTS pf_drives (id INT AUTO_INCREMENT PRIMARY KEY, letter CHAR(1), path VARCHAR(255), label VARCHAR(96), added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    $drives = pf_db()->query('SELECT * FROM pf_drives ORDER BY letter')->fetchAll();
} catch (Throwable $e) {}
$drivesGpo = in_array('Bastion — Lecteurs réseau', array_map(fn($g) => $g['name'] ?? '', $gpos), true);
?>
<section class="ad-sec panel">
  <div class="panel-head"><h2>💽 Lecteurs réseau (<?= count($drives) ?>)</h2>
    <form method="post" style="margin:0" onsubmit="this.querySelector('button').textContent='Déploiement…';this.querySelector('button').disabled=true">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="drives_deploy">
      <button class="btn"<?= $drives ? '' : ' disabled' ?>>🚀 Déployer sur les postes</button>
    </form>
  </div>
  <p class="lead" style="padding:0 1.2rem;margin:.7rem 0">Connecte automatiquement des lecteurs réseau à l'ouverture
  de session des agents (par GPO). Les chemins pointent vers les partages, ex.
  <code>\\<?= e($curRealm) ?>\Commun</code>.</p>
  <div class="ad-help" style="margin:0 1.2rem .8rem;padding:.7rem .9rem;background:rgba(56,189,248,.06);border-radius:8px">
    <strong>Un poste affiche « Windows a tenté en vain de lire gpt.ini » ?</strong>
    Les permissions du SYSVOL sont désynchronisées (fréquent après création de GPO sur Samba).
    <form method="post" style="margin:.5rem 0 0" onsubmit="this.querySelector('button').textContent='Réparation…';this.querySelector('button').disabled=true">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="sysvol_reset">
      <button class="btn-sm">🔧 Réparer les permissions SYSVOL</button>
    </form>
  </div>
  <div style="padding:0 1.2rem 1.2rem">
    <table class="grid-table" style="margin-bottom:.9rem">
      <thead><tr><th style="width:70px">Lettre</th><th>Chemin réseau</th><th>Étiquette</th><th></th></tr></thead>
      <tbody>
        <?php if (!$drives): ?><tr><td colspan="4" class="muted center">Aucun lecteur. Ajoutez-en un ci-dessous.</td></tr>
        <?php else: foreach ($drives as $dr): ?>
          <tr>
            <td><strong><?= e($dr['letter']) ?>:</strong></td>
            <td class="mono"><?= e($dr['path']) ?></td>
            <td><?= e($dr['label']) ?: '<span class="muted">—</span>' ?></td>
            <td class="row-actions">
              <form method="post" style="display:inline" onsubmit="return confirm('Retirer ce lecteur ?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="drive_del">
                <input type="hidden" name="id" value="<?= (int) $dr['id'] ?>"><button class="btn-sm btn-danger">Suppr.</button></form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    <form method="post" class="ad-inline">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="drive_add">
      <select name="letter" style="max-width:90px;padding:.55rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
        <?php foreach (str_split('ZYXWVUTSPNMLKJHGF') as $L): ?><option><?= $L ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="path" required placeholder="\\<?= e($curRealm) ?>\Commun" list="sharelist" style="min-width:220px">
      <datalist id="sharelist"><?php foreach ($shares as $sh): ?><option value="\\<?= e($curRealm) ?>\<?= e($sh) ?>"><?php endforeach; ?></datalist>
      <input type="text" name="label" placeholder="Étiquette (ex. Commun)" style="max-width:200px">
      <button class="btn-sm">+ Ajouter</button>
    </form>
    <?php if ($drivesGpo): ?><p class="ad-help" style="margin-top:.6rem"><span class="badge on">✓ GPO déployée</span>
      Les lecteurs se montent à l'<strong>ouverture de session</strong> des agents (après <code>gpupdate</code> + reconnexion).</p><?php endif; ?>
  </div>
</section>

<!-- 3ter. FOND D'ÉCRAN (GPO Desktop Wallpaper) -->
<?php
$wpGpo   = in_array("Bastion — Fond d'écran", array_map(fn($g) => $g['name'] ?? '', $gpos), true);
$wpExt = $wpStyle = ''; $wpTs = 0;
try {
    foreach (pf_db()->query("SELECT k,v FROM pf_settings WHERE k IN ('wallpaper_ext','wallpaper_style','wallpaper_ts')") as $r) {
        if ($r['k'] === 'wallpaper_ext') { $wpExt = $r['v']; }
        if ($r['k'] === 'wallpaper_style') { $wpStyle = $r['v']; }
        if ($r['k'] === 'wallpaper_ts') { $wpTs = (int) $r['v']; }
    }
} catch (Throwable $e) {}
$wpPreview = ($wpExt && is_file(__DIR__ . '/media/wallpaper.' . $wpExt)) ? ('media/wallpaper.' . $wpExt . '?t=' . $wpTs) : '';
$wpStyleLabels = ['10' => 'Remplir', '6' => 'Ajuster', '2' => 'Étirer', '0' => 'Centrer', '22' => 'Étendre', 'tile' => 'Mosaïque'];
?>
<section class="ad-sec panel">
  <div class="panel-head"><h2>🖼️ Fond d'écran des postes</h2>
    <?php if ($wpGpo): ?><span class="badge on">✓ GPO déployée</span><?php endif; ?>
  </div>
  <p class="lead" style="padding:0 1.2rem;margin:.7rem 0">Impose le même fond d'écran à <strong>tous les postes du
  domaine</strong> (par GPO). L'image est hébergée sur le contrôleur et appliquée à l'ouverture de session.</p>
  <div style="padding:0 1.2rem 1.2rem;display:flex;gap:1.4rem;flex-wrap:wrap;align-items:flex-start">
    <div style="flex:0 0 auto">
      <div style="width:240px;height:135px;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--bg);display:flex;align-items:center;justify-content:center">
        <?php if ($wpPreview): ?><img src="<?= e($wpPreview) ?>" alt="Fond d'écran actuel" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?><span class="muted small" style="text-align:center;padding:.5rem">Aucun fond d'écran<br>imposé pour l'instant</span><?php endif; ?>
      </div>
      <?php if ($wpPreview): ?><p class="muted small" style="margin:.4rem 0 0;text-align:center">Ajustement : <strong><?= e($wpStyleLabels[$wpStyle] ?? $wpStyle) ?></strong><?php if ($wpTs): ?> · <?= e(date('d/m/Y H:i', $wpTs)) ?><?php endif; ?></p><?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data" style="flex:1;min-width:280px;display:grid;gap:.8rem;max-width:460px"
          onsubmit="this.querySelector('button').textContent='Déploiement…';this.querySelector('button').disabled=true">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="wallpaper_deploy">
      <label style="display:grid;gap:.3rem;font-size:.85rem;color:var(--muted)">Image (JPG, PNG ou BMP — max 15 Mo)
        <input type="file" name="image" accept="image/jpeg,image/png,image/bmp" required></label>
      <label style="display:grid;gap:.3rem;font-size:.85rem;color:var(--muted)">Ajustement
        <select name="style" style="padding:.5rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
          <?php foreach ($wpStyleLabels as $v => $lab): ?>
            <option value="<?= e($v) ?>"<?= ((string) $wpStyle === (string) $v || (!$wpStyle && $v === '10')) ? ' selected' : '' ?>><?= e($lab) ?></option>
          <?php endforeach; ?>
        </select></label>
      <div><button class="btn"<?= $dcUp ? '' : ' disabled' ?>>🖼️ Appliquer sur les postes</button></div>
      <p class="ad-help" style="margin:0">S'applique à la prochaine <strong>ouverture de session</strong> des agents
      (ou après <code>gpupdate /force</code> + reconnexion). Remplace le fond précédent.</p>
    </form>
  </div>
</section>

<!-- 4. GPO -->
<style>
  .gpo-item{border:1px solid var(--line);border-radius:10px;margin-bottom:.5rem;background:var(--bg);overflow:hidden}
  .gpo-item>summary{cursor:pointer;padding:.7rem 1rem;font-weight:500;list-style:none;display:flex;align-items:center;gap:.5rem}
  .gpo-item>summary::-webkit-details-marker{display:none}
  .gpo-item>summary::before{content:"▸";color:var(--muted);transition:transform .2s}
  .gpo-item[open]>summary::before{transform:rotate(90deg)}
  .gpo-body{padding:.2rem 1rem 1rem 1.8rem;border-top:1px solid var(--line)}
  .gpo-body .expl{color:#cbd5e1;line-height:1.6;margin:.6rem 0}
  .gpo-body textarea{width:100%;padding:.55rem;background:var(--card);color:var(--text);border:1px solid var(--line);border-radius:8px;font-size:.88rem;margin-bottom:.5rem}
  /* Liste « pro » des GPO existantes */
  .gpo-card{border:1px solid var(--line);border-radius:12px;margin-bottom:.55rem;background:var(--bg);overflow:hidden;transition:border-color .15s ease}
  .gpo-card:hover{border-color:#3a4d68}
  .gpo-card>summary{cursor:pointer;list-style:none;padding:.85rem 1rem;display:flex;gap:.85rem;align-items:flex-start}
  .gpo-card>summary::-webkit-details-marker{display:none}
  .gpo-ic{font-size:1.55rem;line-height:1;flex:none;margin-top:.05rem;width:2.4rem;height:2.4rem;display:flex;align-items:center;justify-content:center;background:var(--card);border:1px solid var(--line);border-radius:10px}
  .gpo-main{flex:1;min-width:0}
  .gpo-nm{font-weight:600;color:var(--text);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;line-height:1.3}
  .gpo-dsc{color:var(--muted);font-size:.85rem;line-height:1.5;margin-top:.25rem}
  .gpo-chev{color:var(--muted);flex:none;transition:transform .2s;margin-top:.35rem;font-size:.9rem}
  .gpo-card[open] .gpo-chev{transform:rotate(90deg)}
  .gpo-pill{font-size:.66rem;font-weight:600;letter-spacing:.02em;padding:.12rem .55rem;border-radius:20px;white-space:nowrap;border:1px solid transparent}
  .gpo-pill.src-bastion{background:rgba(56,189,248,.14);color:#7dd3fc;border-color:rgba(56,189,248,.3)}
  .gpo-pill.src-default{background:rgba(74,222,128,.14);color:#86efac;border-color:rgba(74,222,128,.3)}
  .gpo-pill.src-ext{background:var(--card);color:var(--muted);border-color:var(--line)}
  .gpo-pill.scope{background:var(--card);color:var(--muted);border-color:var(--line)}
  .gpo-card .gpo-body{margin-left:0}
  .gpo-cat{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:.8rem}
  .cat-card{border:1px solid var(--line);border-radius:12px;background:var(--bg);padding:.9rem 1rem;display:flex;flex-direction:column;gap:.4rem;
    transition:transform .15s ease,border-color .15s ease}
  .cat-card:hover{transform:translateY(-2px);border-color:var(--accent)}
  .cat-h{display:flex;align-items:center;gap:.5rem;font-size:.92rem}
  .cat-ico{font-size:1.3rem}
  .cat-scope{align-self:flex-start;font-size:.68rem;padding:.1rem .5rem;border-radius:20px;background:var(--panel2);color:var(--muted)}
  .cat-d{color:var(--muted);font-size:.82rem;line-height:1.5;margin:.1rem 0 .3rem;flex:1}
</style>
<section class="ad-sec panel">
  <div class="panel-head"><h2>📋 Stratégies de groupe (GPO) (<?= count($gpos) ?>)</h2></div>
  <p class="lead" style="padding:0 1.2rem;margin:.7rem 0"><strong>Cliquez sur une GPO</strong> pour savoir ce qu'elle
  fait. Les GPO appliquent automatiquement des règles aux postes (sécurité, mot de passe, restrictions…) ;
  elles s'éditent en détail depuis la console « Gestion des stratégies de groupe » d'un poste Windows.</p>
  <div style="padding:0 1.2rem 1.2rem">
    <!-- Certificat racine Bastion : confiance HTTPS automatique -->
    <?php $certGpo = in_array('Bastion — Certificat racine (confiance HTTPS)', array_map(fn($g) => $g['name'] ?? '', $gpos), true); ?>
    <div style="border:1px solid var(--line);border-radius:12px;background:linear-gradient(120deg,#14324f,#152238);padding:1rem 1.2rem;margin-bottom:1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
      <span style="font-size:1.8rem">🔐</span>
      <div style="flex:1;min-width:240px">
        <div style="font-weight:600">Certificat racine Bastion — confiance HTTPS des postes</div>
        <div class="ad-help" style="margin:.2rem 0 0">Déploie l'autorité Bastion dans le magasin « Autorités racines de confiance »
        de <strong>tous les postes du domaine</strong> (par GPO, dès qu'ils rejoignent le réseau AD). Les pages Bastion
        (console, portail, <strong>page de blocage</strong>) s'affichent alors sans avertissement de certificat.</div>
      </div>
      <?php if ($certGpo): ?><span class="badge on">✓ Déployé</span>
      <?php else: ?>
      <form method="post" onsubmit="return confirm('Déployer le certificat racine Bastion sur tous les postes du domaine ?')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="gpo_cert">
        <button class="btn">🔐 Déployer le certificat</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Catalogue prêt à déployer -->
    <h3 style="font-size:.95rem;margin:.2rem 0 .3rem">📚 Catalogue de stratégies — déploiement en un clic (<?= count($GPO_CATALOG) ?>)</h3>
    <p class="ad-help" style="margin:0 0 .8rem">Chaque modèle crée la GPO, la configure et la <strong>lie au domaine</strong>.
    Elle s'applique aux postes à leur prochaine actualisation (<code>gpupdate /force</code> ou redémarrage).</p>
    <?php
      $deployedNames = array_map(fn($g) => $g['name'] ?? '', $gpos);
      $gpoByCat = [];
      foreach ($GPO_CATALOG as $k => $c) { $gpoByCat[$c['cat'] ?? 'Divers'][$k] = $c; }
    ?>
    <input type="search" id="gposearch" placeholder="🔎 Filtrer les stratégies…" style="width:100%;max-width:420px;margin:0 0 .6rem;padding:.5rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
    <?php foreach ($gpoByCat as $cat => $items): ?>
      <h4 class="gpo-cat-h" style="margin:.9rem 0 .3rem;font-size:.88rem;color:var(--muted)"><?= e($cat) ?> <span style="font-weight:400">(<?= count($items) ?>)</span></h4>
      <div class="gpo-cat">
        <?php foreach ($items as $k => $c): $isDep = in_array('Bastion — ' . $c['title'], $deployedNames, true); ?>
          <div class="cat-card" data-search="<?= e(strtolower($c['title'] . ' ' . $c['desc'] . ' ' . $cat)) ?>">
            <div class="cat-h"><span class="cat-ico"><?= $c['icon'] ?></span><strong><?= e($c['title']) ?></strong></div>
            <span class="cat-scope"><?= $c['scope'] === 'Ordinateur' ? '💻 Ordinateur' : '👤 Utilisateur' ?></span>
            <p class="cat-d"><?= e($c['desc']) ?></p>
            <?php if ($isDep): ?>
              <span class="badge on">✓ Déployée</span>
            <?php else: ?>
              <form method="post" onsubmit="return confirm('Déployer « <?= e($c['title']) ?> » sur tout le domaine ?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="gpo_deploy">
                <input type="hidden" name="tpl" value="<?= e($k) ?>">
                <button class="btn-sm">⬇ Déployer</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <script>
    (function(){
      var q=document.getElementById('gposearch'); if(!q) return;
      q.addEventListener('input',function(){
        var v=this.value.trim().toLowerCase();
        document.querySelectorAll('.gpo-cat-h').forEach(function(h){
          var grid=h.nextElementSibling, shown=0;
          grid.querySelectorAll('.cat-card').forEach(function(card){
            var ok=!v||(card.getAttribute('data-search')||'').indexOf(v)>=0;
            card.style.display=ok?'':'none'; if(ok) shown++;
          });
          h.style.display=shown?'':'none';
        });
      });
    })();
    </script>

    <h3 style="font-size:.95rem;margin:1.4rem 0 .3rem">GPO déployées sur le domaine (<?= count($gpos) ?>)</h3>
    <p class="ad-help" style="margin:0 0 .8rem">Toutes les stratégies actuellement liées au domaine. Chaque carte indique
    sa <strong>portée</strong> (💻 ordinateur ou 👤 utilisateur) et son rôle. Cliquez une carte pour voir son identifiant
    et y ajouter une note.</p>
    <?php
      // Associer chaque GPO déployée à une icône / description / portée (catalogue + GPO Bastion spéciales).
      $gpoMeta = [];
      foreach ($GPO_CATALOG as $c) { $gpoMeta['Bastion — ' . $c['title']] = ['icon' => $c['icon'], 'desc' => $c['desc'], 'scope' => $c['scope']]; }
      $gpoMeta += [
          'Bastion — Applications' => ['icon'=>'🏪','scope'=>'Ordinateur','desc'=>"Installe automatiquement et en silence les applications du store sur les postes, au démarrage."],
          'Bastion — Activation Windows/Office' => ['icon'=>'🔑','scope'=>'Ordinateur','desc'=>"Active Windows et Office via le serveur KMS de la passerelle (postes non déjà activés)."],
          'Bastion — Certificat racine (confiance HTTPS)' => ['icon'=>'🔏','scope'=>'Ordinateur','desc'=>"Déploie l'autorité de certification Bastion dans le magasin de confiance des postes (HTTPS interne sans avertissement)."],
          "Bastion — Fond d'écran" => ['icon'=>'🖼️','scope'=>'Utilisateur','desc'=>"Impose le fond d'écran officiel à l'ouverture de session des agents."],
          'Bastion — Lecteurs réseau' => ['icon'=>'💽','scope'=>'Utilisateur','desc'=>"Connecte automatiquement les lecteurs réseau (partages) à l'ouverture de session."],
      ];
    ?>
    <form method="post" class="ad-inline" style="margin-bottom:1rem">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="gpo_create">
      <input type="text" name="name" required placeholder="Créer une GPO vide (ex. Sécurité-Postes)">
      <button class="btn">＋ Créer la GPO</button>
    </form>
    <?php if (!$gpos): ?><span class="muted">Aucune GPO.</span>
    <?php else: foreach ($gpos as $g):
        $guid = strtoupper($g['guid'] ?? '');
        $name = $g['name'] ?? '';
        $builtin = $GPO_BUILTIN[$guid] ?? '';
        $note = $gpoDesc[$guid] ?? '';
        $meta = $gpoMeta[$name] ?? null;
        $isBastion = strpos($name, 'Bastion —') === 0;
        $icon  = $meta['icon'] ?? ($builtin ? '🏛️' : '📋');
        $scope = $meta['scope'] ?? '';
        $desc  = $meta['desc'] ?? $builtin ?? '';
        if ($desc === '' && $note !== '') { $desc = $note; }
        ?>
      <details class="gpo-card">
        <summary>
          <span class="gpo-ic"><?= $icon ?></span>
          <span class="gpo-main">
            <span class="gpo-nm"><?= e($name) ?>
              <?php if ($builtin): ?><span class="gpo-pill src-default">Windows par défaut</span>
              <?php elseif ($isBastion): ?><span class="gpo-pill src-bastion">Bastion</span>
              <?php else: ?><span class="gpo-pill src-ext">Personnalisée</span><?php endif; ?>
              <?php if ($scope === 'Ordinateur'): ?><span class="gpo-pill scope">💻 Ordinateur</span>
              <?php elseif ($scope === 'Utilisateur'): ?><span class="gpo-pill scope">👤 Utilisateur</span><?php endif; ?>
            </span>
            <span class="gpo-dsc"><?= $desc !== '' ? e($desc) : '<em>Aucune description — ajoutez-en une ci-dessous.</em>' ?></span>
          </span>
          <span class="gpo-chev">▸</span>
        </summary>
        <div class="gpo-body">
          <?php if ($note !== '' && $note !== $desc): ?><p class="expl"><strong>Note de l'administrateur :</strong><br><?= nl2br(e($note)) ?></p><?php endif; ?>
          <p class="ad-help">Identifiant : <code><?= e($g['guid'] ?? '—') ?></code> · Lien : <strong>domaine</strong></p>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="gpo_desc">
            <input type="hidden" name="guid" value="<?= e($g['guid'] ?? '') ?>">
            <select onchange="if(this.value){var t=this.closest('form').querySelector('textarea');t.value=this.value;t.focus();}this.selectedIndex=0"
              style="margin-bottom:.5rem;padding:.4rem;background:var(--card);color:var(--text);border:1px solid var(--line);border-radius:8px;font-size:.82rem">
              <option value="">— Insérer un modèle de note —</option>
              <?php foreach ($GPO_TPL as $lab => $txt): ?><option value="<?= e($txt) ?>"><?= e($lab) ?></option><?php endforeach; ?>
            </select>
            <textarea name="desc" rows="2" placeholder="Note libre de l'administrateur pour cette GPO…"><?= e($note) ?></textarea>
            <button class="btn-sm">Enregistrer la note</button>
          </form>
        </div>
      </details>
    <?php endforeach; endif; ?>
  </div>
</section>

<!-- 5. GROUPES & OU -->
<?php
  // Séparer les groupes métier (créés par l'administration) des groupes système Windows.
  $customGroups = $sysGroups = [];
  foreach ($groups as $g) { if (isset($BUILTIN_GROUPS[$g])) { $sysGroups[] = $g; } else { $customGroups[] = $g; } }
  sort($customGroups); sort($sysGroups);
  // OU : « Domain Controllers » est intégrée, le reste est personnalisé.
  $customOus = array_values(array_filter($ous, fn($o) => stripos($o, 'Domain Controllers') === false));
?>
<style>
  .grp-grid{display:flex;flex-wrap:wrap;gap:.5rem}
  .grp-chip{display:inline-flex;align-items:center;gap:.5rem;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:.45rem .7rem;font-size:.86rem}
  .grp-chip.biz{border-color:rgba(56,189,248,.35);background:rgba(56,189,248,.08)}
  .grp-chip .ic{font-size:1rem;line-height:1}
  .grp-chip .x{border:none;background:transparent;color:var(--muted);cursor:pointer;font-size:1rem;line-height:1;padding:0 0 0 .2rem}
  .grp-chip .x:hover{color:var(--danger)}
  .sys-toggle{margin-top:1rem;border:1px solid var(--line);border-radius:12px;background:var(--bg);overflow:hidden}
  .sys-toggle>summary{cursor:pointer;list-style:none;padding:.75rem 1rem;font-weight:600;font-size:.9rem;display:flex;align-items:center;gap:.5rem;color:var(--muted)}
  .sys-toggle>summary::-webkit-details-marker{display:none}
  .sys-toggle>summary::before{content:"▸";transition:transform .2s}
  .sys-toggle[open]>summary::before{transform:rotate(90deg)}
  .sys-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:.5rem;padding:.4rem 1rem 1rem}
  .sys-card{border:1px solid var(--line);border-radius:8px;background:var(--card);padding:.55rem .75rem}
  .sys-card .n{font-weight:600;font-size:.83rem}
  .sys-card .d{color:var(--muted);font-size:.76rem;line-height:1.4;margin-top:.15rem}
</style>
<section class="ad-sec panel">
  <div class="panel-head"><h2>🏷️ Groupes &amp; unités d'organisation</h2></div>
  <p class="lead" style="padding:0 1.2rem;margin:.7rem 0"><strong>Groupes</strong> : ensembles d'agents partageant
  des droits (ex. accès à un partage). <strong>Unités d'organisation (OU)</strong> : classement des comptes/postes
  par service ou site, pour y appliquer des GPO.</p>
  <div class="split" style="padding:0 1.2rem 1.2rem">
    <!-- Colonne GROUPES -->
    <div>
      <h3 style="font-size:.9rem;margin:.2rem 0 .5rem">👮 Vos groupes métier (<?= count($customGroups) ?>)</h3>
      <form method="post" class="ad-inline" style="margin-bottom:.7rem">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="group_add">
        <input type="text" name="name" required placeholder="Ex. Brigade-VTT, Accueil, Commissariat-Evry…">
        <button class="btn-sm">＋ Groupe</button>
      </form>
      <?php if (!$customGroups): ?>
        <p class="muted small" style="margin:.2rem 0">Aucun groupe métier pour l'instant. Créez-en pour rassembler des
        agents par service, brigade ou commissariat, puis donnez-leur des droits (partages, GPO).</p>
      <?php else: ?>
      <div class="grp-grid">
        <?php foreach ($customGroups as $g): ?>
          <span class="grp-chip biz"><span class="ic">🏷️</span><?= e($g) ?>
            <form method="post" style="display:contents" onsubmit="return confirm('Supprimer le groupe « <?= e($g) ?> » ?')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="group_del">
              <input type="hidden" name="name" value="<?= e($g) ?>"><button class="x" title="Supprimer">✕</button>
            </form>
          </span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <details class="sys-toggle">
        <summary>⚙️ Groupes système Windows (<?= count($sysGroups) ?>)</summary>
        <div class="sys-grid">
          <?php foreach ($sysGroups as $g): ?>
            <div class="sys-card">
              <div class="n"><?= e($g) ?></div>
              <div class="d"><?= e($GROUP_DESC[$g] ?? "Groupe intégré d'Active Directory (rôle technique de l'annuaire).") ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
    </div>

    <!-- Colonne OU -->
    <div>
      <h3 style="font-size:.9rem;margin:.2rem 0 .5rem">🗂️ Unités d'organisation (<?= count($customOus) ?>)</h3>
      <form method="post" class="ad-inline" style="margin-bottom:.7rem">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="ou_create">
        <input type="text" name="name" required placeholder="Ex. Commissariat-Evry, Brigade-Nuit…">
        <button class="btn-sm">＋ OU</button>
      </form>
      <?php if (!$customOus): ?>
        <p class="muted small" style="margin:.2rem 0">Aucune unité d'organisation personnalisée. Une OU regroupe des
        comptes/postes (par service ou commissariat) pour leur appliquer des GPO spécifiques.</p>
      <?php else: ?>
      <div class="grp-grid">
        <?php foreach ($customOus as $o): ?><span class="grp-chip"><span class="ic">🗂️</span><?= e($o) ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <p class="muted small" style="margin-top:.9rem">L'OU intégrée <code>Domain Controllers</code> (contrôleurs de
      domaine) est gérée automatiquement et n'est pas listée ici.</p>
    </div>
  </div>
</section>
<?php endif; ?>
<?php pf_footer(); ?>
