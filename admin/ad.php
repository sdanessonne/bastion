<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — Active Directory (Samba AD DC) : fonctionnaires, ordinateurs, GPO, partages. */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';

require_once __DIR__ . '/inc/adcache.php';

$dcUp = trim((string) shell_exec('systemctl is-active samba-ad-dc 2>/dev/null')) === 'active';

// ── Domaine actuel + nom souhaité (paramétrable) ─────────────────────────────
$di = $dcUp ? ad_cache('domaininfo', 0, 'domaininfo') : '';
preg_match('/realm=(.+)/', $di, $mr);    $curRealm = trim($mr[1] ?? '') ?: 'BASTION.LOCAL';
preg_match('/workgroup=(.+)/', $di, $mw); $curWg    = trim($mw[1] ?? '') ?: 'BASTION';
$baseDN = 'DC=' . implode(',DC=', explode('.', strtolower($curRealm)));
// FQDN du CONTRÔLEUR DE DOMAINE : un partage ordinaire s'atteint par le nom de SERVEUR, jamais
// par le nom de domaine (\\domaine\Partage n'est pas une racine DFS → « Élément introuvable »).
$curDc = '';
if (preg_match('/dcfqdn=(\S+)/', $di, $md)) { $curDc = strtolower(trim($md[1])); }
if ($curDc === '' || $curDc[0] === '.') {
    $nb = trim((string) shell_exec("testparm -s --parameter-name='netbios name' 2>/dev/null")) ?: 'dc';
    $curDc = strtolower($nb) . '.' . strtolower($curRealm);
}
$wantRealm = $curRealm; $wantDom = $curWg;
try { foreach (pf_db()->query("SELECT k,v FROM pf_settings WHERE k IN ('ad_realm','ad_domain')") as $r) {
    if ($r['k'] === 'ad_realm'  && $r['v'] !== '') { $wantRealm = $r['v']; }
    if ($r['k'] === 'ad_domain' && $r['v'] !== '') { $wantDom   = $r['v']; }
} } catch (Throwable $e) {}

/** Schéma de la table des lecteurs réseau (idempotent, appelé avant toute lecture/écriture).
 *  group_name = groupe AD auquel le lecteur est réservé ; vide = tous les agents. */
function pf_drives_schema(): void {
    static $done = false; if ($done) { return; } $done = true;
    try {
        pf_db()->exec('CREATE TABLE IF NOT EXISTS pf_drives (id INT AUTO_INCREMENT PRIMARY KEY,
            letter CHAR(1), path VARCHAR(255), label VARCHAR(96),
            group_name VARCHAR(96) NOT NULL DEFAULT \'\', added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
        // Migration des installations existantes (la colonne peut déjà exister → on ignore).
        try { pf_db()->exec("ALTER TABLE pf_drives ADD COLUMN group_name VARCHAR(96) NOT NULL DEFAULT ''"); }
        catch (Throwable $e) { /* colonne déjà présente */ }
    } catch (Throwable $e) {}
}

// ── Catalogue de GPO prêtes à déployer (stratégies registre) — voir inc/gpo-catalog.php ──
$GPO_CATALOG = require __DIR__ . '/inc/gpo-catalog.php';

// ── Jauge d'installation GPO : point de sondage (AJAX, lecture seule) ─────────
// La console interroge cette route (?gpo_progress=<nonce>) pendant un déploiement lancé
// en arrière-plan pour animer la barre. Le nonce nomme le fichier de progression écrit
// par dir-ctl.sh. Aucun effet de bord, hormis vider le cache AD une fois le déploiement fini.
if (isset($_GET['gpo_progress'])) {
    header('Content-Type: application/json');
    $nonce = preg_replace('/[^a-f0-9]/', '', (string) $_GET['gpo_progress']);
    $pct = 0; $label = 'Préparation…'; $done = false; $ok = false; $msg = '';
    if ($nonce !== '') {
        $raw = @file_get_contents('/dev/shm/pf-gpo-' . $nonce . '.progress');
        if (is_string($raw) && $raw !== '') {
            $p = explode("\t", trim($raw));
            $pct = (int) $p[0];
            if (isset($p[1]) && $p[1] !== '') { $label = $p[1]; }
        }
        if ($pct >= 100)     { $done = true; $ok = true; }
        elseif ($pct < 0)    { $done = true; $ok = false; $pct = 100; }
        if ($done) {
            $out = trim((string) @file_get_contents(sys_get_temp_dir() . '/pf-gpo-' . $nonce . '.out'));
            $ls  = array_values(array_filter(array_map('trim', explode("\n", $out)), 'strlen'));
            $msg = $ls ? end($ls) : '';
            if ($ok && preg_match('/refuse|ERROR|Failed|Traceback|ATTENTION/i', $msg)) { $ok = false; }
            ad_cache_clear();   // la nouvelle GPO doit apparaître au rechargement
        }
    }
    echo json_encode(['pct' => max(0, min(100, $pct)), 'label' => $label, 'done' => $done, 'ok' => $ok, 'msg' => $msg]);
    exit;
}

// ── Diagnostic GPO : endpoint JSON à la demande (lecture seule) ───────────────
// Lancé par le bouton « Diagnostic » de l'onglet Stratégies. Contrôle chaque GPO Bastion
// (lien, version, ACL SYSVOL, fichiers) — trop lourd pour être joué à chaque page.
if (isset($_GET['gpo_health'])) {
    header('Content-Type: application/json');
    $raw  = ad('gpo', 'health');
    $data = json_decode($raw, true);
    echo json_encode(is_array($data)
        ? ['ok' => true, 'gpos' => $data]
        : ['ok' => false, 'error' => 'Diagnostic indisponible (contrôleur de domaine injoignable ?).']);
    exit;
}

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
        pf_drives_schema();
        $letter = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($_POST['letter'] ?? '')), 0, 1));
        $path   = trim((string) ($_POST['path'] ?? ''));
        $label  = trim((string) ($_POST['label'] ?? ''));
        if ($letter === '' || substr($path, 0, 2) !== '\\\\') {   // doit commencer par \\ (UNC)
            $out = 'ERROR: lettre requise et chemin UNC (\\serveur\partage).';
        } else {
            $grp = trim((string) ($_POST['group'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9 ._-]{0,64}$/', $grp)) { $grp = ''; }
            pf_db()->prepare('INSERT INTO pf_drives (letter,path,label,group_name) VALUES (?,?,?,?)')
                   ->execute([$letter, $path, $label, $grp]);
            $out = "Lecteur {$letter}: ajouté. Cliquez « Déployer » pour appliquer.";
        }
    } elseif ($do === 'drive_edit') {
        pf_drives_schema();
        $id     = (int) ($_POST['id'] ?? 0);
        $letter = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($_POST['letter'] ?? '')), 0, 1));
        $path   = trim((string) ($_POST['path'] ?? ''));
        $label  = trim((string) ($_POST['label'] ?? ''));
        if ($id <= 0 || $letter === '' || substr($path, 0, 2) !== '\\\\') {
            $out = 'ERROR: lettre requise et chemin UNC (\\serveur\partage).';
        } else {
            $grp = trim((string) ($_POST['group'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9 ._-]{0,64}$/', $grp)) { $grp = ''; }
            pf_db()->prepare('UPDATE pf_drives SET letter=?, path=?, label=?, group_name=? WHERE id=?')
                   ->execute([$letter, $path, $label, $grp, $id]);
            $out = "Lecteur {$letter}: modifié. Cliquez « Déployer » pour appliquer.";
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
        case 'computer_delete':
            // Retirer un poste du domaine DEPUIS LE POSTE ne supprime pas son objet dans
            // l'annuaire : c'est le comportement de Windows. On le supprime donc ici, et on
            // retire aussi sa fiche d'inventaire — sans quoi un poste mis au rebut resterait
            // affiché indéfiniment dans le parc.
            $cnm = (string) ($_POST['name'] ?? '');
            $out = ad('computer', 'delete', $cnm);
            if (stripos($out, 'ERROR') === false && stripos($out, 'Failed') === false) {
                try { pf_db()->prepare('DELETE FROM pf_inventaire WHERE poste=?')
                             ->execute([strtoupper(rtrim($cnm, '$'))]); } catch (Throwable $e) {}
                $out = "Poste « " . rtrim($cnm, '$') . " » retiré du domaine et de l'inventaire.";
            }
            break;
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
        case 'kms_auto':
            // « monter » : faire passer les postes Professionnel en Entreprise (changement
            // d'édition sans réinstallation). Volontairement OPT-IN — ce n'est pas anodin, et
            // cela suppose de détenir les droits Entreprise (contrat en volume).
            $out = ad('gpo', 'activation', '192.168.182.1', empty($_POST['monter']) ? '0' : '1');
            break;
        case 'timesync_deploy': $out = ad('gpo', 'timesync'); break;
        case 'sysvol_reset': $out = ad('gpo', 'sysvolreset', ''); break;
        case 'bitlocker_deploy':
            $blm = (string) ($_POST['bl_mode'] ?? 'tpm');
            if ($blm === 'tpmpin_common') {
                $blpin = preg_replace('/\D/', '', (string) ($_POST['bl_pin'] ?? ''));
                $out = (strlen((string) $blpin) < 6 || strlen((string) $blpin) > 20)
                    ? 'ERROR: PIN de 6 à 20 chiffres requis.'
                    : ad('bitlocker', 'deploy', 'tpmpin', (string) $blpin);
            } elseif ($blm === 'tpmpin_manual') {
                $out = ad('bitlocker', 'deploy', 'tpmpin');
            } else {
                $out = ad('bitlocker', 'deploy', 'tpm');
            }
            break;
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
            if (!isset($GPO_CATALOG[$key])) { $out = 'ERROR: modèle inconnu.'; break; }
            $gc    = $GPO_CATALOG[$key];
            $gname = 'Bastion — ' . $gc['title'];
            $tmp   = tempnam(sys_get_temp_dir(), 'gpo');
            file_put_contents($tmp, json_encode($gc['policies'], JSON_UNESCAPED_UNICODE));
            if (($_POST['ajax'] ?? '') === '1') {
                // Jauge d'installation : déploiement en ARRIÈRE-PLAN, la console suit
                // l'avancement via ?gpo_progress=<nonce>. « setsid » détache le processus
                // pour qu'il survive à la fin de la requête ; la sortie est captée dans
                // /tmp pour être relue. NE PAS supprimer $tmp ici : le script le lit puis
                // le supprime lui-même une fois les stratégies écrites.
                $nonce = bin2hex(random_bytes(8));
                $outf  = sys_get_temp_dir() . '/pf-gpo-' . $nonce . '.out';
                @unlink($outf);
                $inner = sprintf('sudo /usr/local/sbin/proxyfibre-ad gpo deploy %s %s %s',
                    escapeshellarg($gname), escapeshellarg($tmp), escapeshellarg($nonce));
                shell_exec(sprintf('setsid sh -c %s > %s 2>&1 & echo ok',
                    escapeshellarg($inner), escapeshellarg($outf)));
                audit('ad.gpo_deploy', $gname);
                header('Content-Type: application/json');
                echo json_encode(['nonce' => $nonce]);
                exit;
            }
            // Repli sans JavaScript : déploiement bloquant classique.
            $out = ad('gpo', 'deploy', $gname, $tmp);
            @unlink($tmp);
            break;
        case 'obj_move':
            // Déplacement d'un objet vers une unité d'organisation. Le type est contraint,
            // et la destination est revalidée côté script (aucun DN libre accepté).
            $ty = (string) ($_POST['type'] ?? '');
            $nm = trim((string) ($_POST['nom'] ?? ''));
            $ds = trim((string) ($_POST['dest'] ?? ''));
            if (!in_array($ty, ['user', 'computer', 'group', 'ou'], true) || $nm === '') {
                $out = 'ERROR: objet invalide.'; break;
            }
            $out = ad('move', $ty, $nm, $ds);
            if (stripos($out, 'ERROR') === false && stripos($out, 'Failed') === false) {
                $out = "« $nm » déplacé vers " . ($ds === '' ? 'la racine du domaine' : preg_replace('/^OU=/i', '', $ds)) . '.';
            }
            break;
        case 'gpo_wmi':
            // Condition d'application : la stratégie n'est appliquée que par les postes qui la
            // remplissent (édition, version, portable/fixe…). Évaluée par le poste lui-même.
            $gd = (string) ($_POST['guid'] ?? '');
            $fk = preg_replace('/[^a-z0-9]/', '', (string) ($_POST['filtre'] ?? ''));
            $out = $fk === '' ? ad('gpo', 'wmi', 'clear', $gd) : ad('gpo', 'wmi', 'set', $gd, $fk);
            if (strpos($out, 'ERROR') === false) {
                $out = $fk === '' ? 'Condition retirée : la stratégie s\'applique à tous les postes.'
                                  : 'Condition appliquée. Effet au prochain traitement des stratégies sur les postes.';
            }
            break;
        case 'gpo_unlink': $out = ad('gpo', 'unlink', (string) ($_POST['guid'] ?? '')); break;   // désactiver (délier)
        case 'gpo_link':   $out = ad('gpo', 'link',   (string) ($_POST['guid'] ?? '')); break;   // réactiver (relier)
        case 'gpo_delete': $out = ad('gpo', 'delete', (string) ($_POST['guid'] ?? '')); break;   // désinstaller (supprimer)
        case 'share_create': $out = ad('share', 'create', (string) ($_POST['name'] ?? '')); break;
        case 'share_delete': $out = ad('share', 'delete', (string) ($_POST['name'] ?? '')); break;
        case 'share_quota':
            $sn = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($_POST['name'] ?? ''));
            $mb = max(0, min(100000000, (int) ($_POST['mb'] ?? 0)));
            if ($sn !== '') {
                shell_exec('sudo /usr/local/sbin/proxyfibre-share-quota set ' . escapeshellarg($sn) . ' ' . $mb . ' 2>&1');
                if ($mb > 0) { shell_exec('sudo /usr/local/sbin/proxyfibre-share-quota enable 2>&1'); }   // active l'application (idempotent)
                shell_exec('sudo /usr/local/sbin/proxyfibre-share-quota scan 2>&1');                       // rafraîchit l'occupation
                $out = $mb > 0 ? "Quota de $mb Mo appliqué au partage « $sn » (les postes voient l'espace limité)."
                               : "Quota retiré du partage « $sn » (espace illimité).";
            } else { $out = 'ERROR: partage invalide.'; }
            break;
        case 'share_acl':
            // Droits d'un partage. « scope=all » : tous les agents (lecture seule ou écriture).
            // « scope=groups » : seuls les groupes désignés ; les autres n'ont AUCUN accès.
            $sn    = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['name'] ?? ''));
            $scope = (($_POST['scope'] ?? 'all') === 'groups') ? 'groups' : 'all';
            if ($sn === '') { $out = 'ERROR: partage invalide.'; break; }
            if ($scope === 'all') {
                $lvl = (($_POST['all_level'] ?? 'rw') === 'ro') ? 'ro' : 'rw';
                $out = ad('share', 'acl', $sn, $lvl === 'ro' ? '*' : '', '');
            } else {
                // Indexation NUMÉRIQUE : un nom de groupe n'est jamais une clé de formulaire
                // (espaces et caractères spéciaux seraient transformés par PHP).
                $names = (array) ($_POST['g_name'] ?? []); $levels = (array) ($_POST['g_level'] ?? []);
                $ro = $rw = [];
                foreach ($names as $i => $g) {
                    $g = trim((string) $g);
                    if ($g === '' || $g === 'Domain Admins') { continue; }   // admins : toujours inclus
                    if (!preg_match('/^[A-Za-z0-9 ._-]{1,64}$/', $g)) { continue; }
                    $v = (string) ($levels[$i] ?? 'none');
                    if     ($v === 'ro') { $ro[] = $g; }
                    elseif ($v === 'rw') { $rw[] = $g; }
                }
                if (!$ro && !$rw) {
                    $out = "ERROR: aucun groupe n'a d'accès — le dossier deviendrait inaccessible à tous "
                         . "les agents. Désignez au moins un groupe, ou choisissez « Tous les agents ».";
                    break;
                }
                $out = ad('share', 'acl', $sn, implode(',', $ro), implode(',', $rw));
            }
            break;
        case 'share_repair': $out = ad('share', 'repair'); break;
        case 'share_set':
            $ro = !empty($_POST['ro'])     ? '1' : '0';   // lecture seule
            $br = !empty($_POST['browse']) ? '1' : '0';   // visible dans le voisinage réseau
            $out = ad('share', 'set', (string) ($_POST['name'] ?? ''), $ro, $br);
            break;
        case 'drives_deploy':
            pf_drives_schema();
            $rows = pf_db()->query('SELECT letter,path,label,group_name FROM pf_drives ORDER BY letter')->fetchAll();
            if (!$rows) { $out = 'ERROR: aucun lecteur à déployer.'; break; }
            $json = array_map(fn($r) => ['letter' => $r['letter'], 'path' => $r['path'],
                                         'label' => $r['label'], 'group' => (string) ($r['group_name'] ?? '')], $rows);
            $tmp = tempnam(sys_get_temp_dir(), 'drv');
            file_put_contents($tmp, json_encode($json, JSON_UNESCAPED_UNICODE));
            $out = ad('gpo', 'drives', $tmp);
            @unlink($tmp);
            break;
        case 'photo_deploy':
            // Photo de l'agent en image de compte Windows, déployée par stratégie.
            // Script de DÉMARRAGE et non d'ouverture de session : poser l'image demande les
            // droits administrateur, et surtout l'écran de connexion affiche la vignette
            // AVANT la session — posée pendant la session, elle n'apparaîtrait qu'à la
            // suivante. Voir l'en-tête de services/scripts/gpo-photo.py.
            // L'adresse vient de la configuration : le poste doit joindre la passerelle sur
            // SON réseau, et une valeur en dur casserait sur tout site adressé autrement.
            $out = ad('gpo', 'photo', pf_lan_ip());
            if (strpos($out, 'photo deployee') !== false) {
                $out = "Photo de l'agent déployée par stratégie. Les postes la poseront au "
                     . "prochain démarrage, et l'écran de connexion l'affichera dès celui-ci.";
            }
            break;
        case 'logon_deploy':
            // Écran de connexion : image de fond (facultative) + titre/message + masquages.
            $cap  = trim((string) ($_POST['caption'] ?? ''));
            $txt  = trim((string) ($_POST['notice'] ?? ''));
            // Une bannière ne s'affiche QUE si le titre est renseigné : on évite une fenêtre vide.
            if ($cap === '' && $txt !== '') { $cap = 'Information'; }
            $flags = '';
            foreach (['u' => 'hide_user', 'd' => 'hide_details', 's' => 'hide_shutdown',
                      'c' => 'csp_force'] as $lt => $fld) {
                if (!empty($_POST[$fld])) { $flags .= $lt; }
            }
            $imgPath = '-';   // « - » : conserver l'image déjà déployée
            $f = $_FILES['logo'] ?? null;
            if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                if ($f['size'] > 15 * 1024 * 1024) { $out = 'ERROR: image trop volumineuse (max 15 Mo).'; break; }
                $info = @getimagesize($f['tmp_name']);
                $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_BMP => 'bmp'];
                if (!$info || !isset($allowed[$info[2]])) { $out = 'ERROR: format non supporté (JPG, PNG ou BMP).'; break; }
                $imgPath = tempnam(sys_get_temp_dir(), 'lg') . '.' . $allowed[$info[2]];
                if (!@move_uploaded_file($f['tmp_name'], $imgPath)) { $out = 'ERROR: réception de l\'image impossible.'; break; }
            }
            $out = ad('gpo', 'logon', $imgPath, $cap, $txt, $flags);
            if ($imgPath !== '-') { @unlink($imgPath); }
            if (strpos($out, 'deploye') !== false) {
                try {
                    $up = pf_db()->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
                    $up->execute(['logon_caption', $cap]);
                    $up->execute(['logon_notice', $txt]);
                    $up->execute(['logon_flags', $flags]);
                } catch (Throwable $e) {}
                $out = "Écran de connexion déployé. Effet au prochain démarrage des postes.";
            }
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
                            // Aperçu web STOCKÉ EN BASE (et non en fichier). Le dossier admin/ appartient à
                            // root et est resynchronisé (rsync --delete) à chaque mise à jour : un fichier
                            // d'aperçu y serait soit impossible à écrire pour www-data, soit effacé à la
                            // mise à jour suivante — c'est la cause du « fond d'écran qui n'apparaît pas ».
                            // GD ré-encode l'image (neutralise tout contenu piégé) et la réduit avant stockage.
                            $raw = @file_get_contents($tmp);
                            $im  = $raw ? @imagecreatefromstring($raw) : false;
                            if ($im) {
                                $w = imagesx($im); $h = imagesy($im);
                                if ($w > 640) {
                                    $nh = max(1, (int) round($h * 640 / $w));
                                    $rs = imagecreatetruecolor(640, $nh);
                                    imagecopyresampled($rs, $im, 0, 0, 0, 0, 640, $nh, $w, $h);
                                    imagedestroy($im); $im = $rs;
                                }
                                ob_start(); imagejpeg($im, null, 82); $jpg = (string) ob_get_clean(); imagedestroy($im);
                                pf_db()->exec('CREATE TABLE IF NOT EXISTS pf_media (k VARCHAR(64) PRIMARY KEY, mime VARCHAR(64) NOT NULL, bytes LONGBLOB NOT NULL, updated_at INT NOT NULL)');
                                $ins = pf_db()->prepare('INSERT INTO pf_media (k,mime,bytes,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE mime=VALUES(mime),bytes=VALUES(bytes),updated_at=VALUES(updated_at)');
                                $ins->bindValue(1, 'wallpaper');
                                $ins->bindValue(2, 'image/jpeg');
                                $ins->bindValue(3, $jpg, PDO::PARAM_LOB);
                                $ins->bindValue(4, time(), PDO::PARAM_INT);
                                $ins->execute();
                            }
                            $up = pf_db()->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
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
    audit('ad.' . ($do ?: 'action'), (string) ($_POST['name'] ?? $_POST['guid'] ?? $_POST['tpl'] ?? '') . ($err ? ' [échec]' : ''));
    $flash = [trim($out) !== '' ? trim($out) : 'Opération effectuée.', $err ? 'err' : 'ok'];
}

// ── Lectures (mises en cache) ────────────────────────────────────────────────
$users = $computers = $groups = $ous = $gpos = $shares = [];
if ($dcUp) {
    // Cache froid → rafraîchir les 6 listes EN PARALLÈLE en un seul appel (~1,5 s au lieu de ~9 s).
    $wf = '/dev/shm/pf-dir-users.cache';
    if (!is_file($wf) || (time() - filemtime($wf)) >= AD_TTL) { shell_exec('sudo /usr/local/sbin/proxyfibre-ad warm 2>/dev/null'); }
    $users     = ad_lines_cached('users', 0, 'user', 'list');
    $computers = ad_lines_cached('computers', 0, 'computer', 'list');
    $groups    = ad_lines_cached('groups', 0, 'group', 'list');
    $ous       = ad_lines_cached('ous', 0, 'ou', 'list');
    $blk = [];
    foreach (explode("\n", ad_cache('gpos', 0, 'gpo', 'list')) as $l) {
        if (trim($l) === '') { if (!empty($blk['name'])) { $gpos[] = $blk; } $blk = []; continue; }
        if (preg_match('/^GPO\s*:\s*(\{[0-9A-Fa-f-]+\})/', $l, $m)) { $blk['guid'] = $m[1]; }
        if (preg_match('/display name\s*:\s*(.+)/i', $l, $m)) { $blk['name'] = trim($m[1]); }
    }
    if (!empty($blk['name'])) { $gpos[] = $blk; }
    // Partages : on analyse le contenu brut de shares.conf (déjà en cache) pour en extraire, par
    // section, le chemin et les drapeaux — de quoi les LISTER, MODIFIER et SUPPRIMER dans l'UI.
    $curSh = null;
    foreach (explode("\n", ad_cache('shares', 0, 'share', 'list')) as $l) {
        $t = trim($l);
        if (preg_match('/^\[([^\]]+)\]/', $t, $m)) {
            if ($curSh) { $shares[] = $curSh; }
            $curSh = ['name' => $m[1], 'path' => '', 'ro' => false, 'browse' => true, 'guest' => false,
                      'comment' => '', 'vu' => '', 'wl' => ''];
        } elseif ($curSh && preg_match('/^([A-Za-z][A-Za-z ]*?)\s*=\s*(.*)$/', $t, $m)) {
            $k = strtolower(trim($m[1])); $v = trim($m[2]);
            $yes = in_array(strtolower($v), ['yes', 'true', '1'], true);
            if     ($k === 'path')       { $curSh['path'] = $v; }
            elseif ($k === 'read only')  { $curSh['ro'] = $yes; }
            elseif ($k === 'browseable' || $k === 'browsable') { $curSh['browse'] = $yes; }
            elseif ($k === 'guest ok')   { $curSh['guest'] = $yes; }
            elseif ($k === 'comment')    { $curSh['comment'] = $v; }
            elseif ($k === 'valid users'){ $curSh['vu'] = $v; }
            elseif ($k === 'write list') { $curSh['wl'] = $v; }
        }
    }
    if ($curSh) { $shares[] = $curSh; }
    // Les partages servant le déploiement PXE (/srv/pxe) sont protégés : gérés par l'installation
    // PXE, on ne les modifie/supprime pas depuis cette console (sinon PXE casse).
    // Droits : déduits de shares.conf, SEULE source de vérité (c'est ce que smbd relit).
    // Jetons acceptés : "DOM\Groupe", @"DOM\Groupe", @DOM\Groupe, +DOM\Groupe.
    $pfShGroups = function (string $list): array {
        $out = [];
        foreach (preg_split('/\s*,\s*|\s+(?=[@+"A-Za-z])/', $list, -1, PREG_SPLIT_NO_EMPTY) as $tk) {
            $tk = trim(str_replace('"', '', $tk));
            $tk = ltrim($tk, '@+&');
            $p  = strpos($tk, '\\');
            if ($p !== false) { $tk = substr($tk, $p + 1); }
            if ($tk !== '') { $out[$tk] = true; }
        }
        return $out;
    };
    foreach ($shares as &$_s) {
        $_s['pxe'] = strpos($_s['path'], '/srv/pxe') === 0;
        $vu = $pfShGroups($_s['vu']); $wl = $pfShGroups($_s['wl']);
        unset($vu['Domain Admins'], $wl['Domain Admins']);   // toujours présent, jamais un choix
        $_s['acl'] = [];
        foreach ($vu as $g => $_) { $_s['acl'][$g] = isset($wl[$g]) ? 'rw' : 'ro'; }
        foreach ($wl as $g => $_) { $_s['acl'][$g] = 'rw'; }
        $_s['mode'] = $_s['acl'] ? 'groups' : ($_s['ro'] ? 'allro' : 'allrw');
    }
    unset($_s);
}
// Quotas des partages : occupation (octets) + limite (Mo) par nom de partage.
$squota = [];
foreach (explode("\n", pf_cmd_cache('sharequota', 60, 'sudo /usr/local/sbin/proxyfibre-share-quota list 2>/dev/null')) as $l) {
    $p = explode("\t", $l);
    if (count($p) >= 4 && $p[0] !== '') { $squota[$p[0]] = ['used' => (int) $p[2], 'quota' => (int) $p[3]]; }
}
$sys = ['Administrator', 'Guest', 'krbtgt'];
$humanUsers = array_values(array_filter($users, fn($u) => !in_array($u, $sys, true) && stripos($u, 'dns-') !== 0));

// Groupes métier / système : calculés ICI (et non dans la section 5) car les droits des partages
// et le ciblage des lecteurs, plus haut dans la page, doivent proposer la liste des groupes.
/**
 * Petit formulaire « déplacer » attaché à un objet de l'arborescence.
 * La destination proposée se limite aux OU existantes (plus la racine du domaine) : on ne
 * laisse jamais saisir un emplacement libre, qui pourrait viser un conteneur système.
 */
function pf_bouton_deplacer(string $type, string $nom, array $ous): string
{
    if ($nom === '') { return ''; }
    // UN SEUL bouton par objet. Le formulaire et son selecteur d'unites d'organisation sont
    // UNIQUES pour toute la page (#moveForm) et deplaces a cote de l'objet clique. Auparavant
    // chaque objet portait son propre formulaire AVEC la liste complete des OU : sur un domaine
    // de quarante groupes, c'etait autant de copies du meme selecteur — d'ou une page
    // inutilement lourde, payee a chaque affichage.
    return '<button type="button" class="btn-sm js-move" style="font-size:.7rem;padding:.05rem .35rem;margin-left:.4rem"'
         . ' data-type="' . e($type) . '" data-nom="' . e($nom) . '"'
         . ' title="Deplacer vers une unite d\'organisation">↪</button>';
}

$customGroups = $sysGroups = [];
foreach ($groups as $g) { if (isset($BUILTIN_GROUPS[$g])) { $sysGroups[] = $g; } else { $customGroups[] = $g; } }
sort($customGroups); sort($sysGroups);

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
// GPO actuellement liées à la racine du domaine (GUID en majuscules) : sert à distinguer,
// dans la liste, une stratégie ACTIVE d'une stratégie DÉSACTIVÉE (déliée mais conservée).
$gpoLinked = $dcUp ? ad_lines_cached('gpolinks', 0, 'gpo', 'domainlinks') : [];

// Conditions d'application (filtres WMI) : libellés proposés + condition posée sur chaque GPO.
// Le poste évalue lui-même la condition au traitement des stratégies — d'où l'intérêt : une
// même stratégie peut ne concerner que les portables, que Windows 11, que telle édition…
$WMI_FILTRES = [];
foreach (ad_lines_cached('wmilist', 0, 'gpo', 'wmi', 'list') as $l) {
    $p = explode("\t", $l);
    if (count($p) >= 2) { $WMI_FILTRES[$p[0]] = $p[1]; }
}
$gpoWmi = [];   // {GUID} => clé de filtre, lu directement dans l'annuaire
foreach (ad_lines_cached('wmistatus', 0, 'gpo', 'wmi', 'status') as $l) {
    $p = explode("\t", $l);
    if (count($p) >= 2) { $gpoWmi[strtoupper($p[0])] = $p[1]; }
}

// Clés de récupération BitLocker séquestrées dans l'AD, par poste (nom en majuscules).
$bitlockerKeys = [];
if ($dcUp) {
    foreach (explode("\n", ad_cache('blkeys', 0, 'bitlocker', 'keys')) as $l) {
        $p = explode("\t", $l);
        $comp = trim($p[0] ?? '');
        if ($comp === '' || $comp === '?') { continue; }
        $bitlockerKeys[strtoupper($comp)][] = ['pw' => trim($p[1] ?? ''), 'when' => trim($p[2] ?? '')];
    }
}

// Ordinateurs : description perso (pf_computer_desc) + dernier fonctionnaire connecté (audit d'auth).
$computerDesc = [];
$lastByWs = [];
$computerDetail = [];   // nom (maj) => ['os'=>…, 'll'=>epoch dernière ouverture]
if ($dcUp) {
    try {
        pf_db()->exec('CREATE TABLE IF NOT EXISTS pf_computer_desc (name VARCHAR(64) PRIMARY KEY, description TEXT)');
        foreach (pf_db()->query('SELECT name,description FROM pf_computer_desc') as $r) { $computerDesc[strtoupper($r['name'])] = $r['description']; }
    } catch (Throwable $e) {}
    foreach (explode("\n", ad_cache('authlog', 0, 'authlog')) as $l) {
        $p = explode("\t", $l);
        if (count($p) < 4) { continue; }
        $ws = strtoupper(trim($p[0], "\\ "));
        $user = preg_replace('/@.*$/', '', $p[1]);
        if (substr($user, -1) === '$') { continue; }   // ignorer les comptes machine (ex. W-91$)
        if ($ws === '' && preg_match('/ipv4:([0-9.]+)/', $p[2], $m)) { $ws = 'IP:' . $m[1]; }
        if ($ws !== '') { $lastByWs[$ws] = ['user' => $user, 'ts' => substr($p[3], 0, 16)]; } // dernière ligne = plus récent
    }
    // Inventaire des postes : système d'exploitation + dernière ouverture de session (depuis l'AD).
    foreach (explode("\n", ad_cache('compdetail', 0, 'computer', 'detail')) as $l) {
        $p = explode("\t", $l);
        if (trim($p[0] ?? '') === '') { continue; }
        $computerDetail[strtoupper(trim($p[0]))] = ['os' => trim($p[1] ?? ''), 'll' => (int) trim($p[2] ?? '0')];
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
  .dir-intro{background:linear-gradient(120deg,#1e3a5f,#152238);border:1px solid var(--line);border-radius:14px;
    padding:1.1rem 1.4rem;margin-bottom:1.2rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
  .dir-intro .dom{font-size:1.15rem;font-weight:600;color:#fff}
  .dir-intro .desc{color:var(--muted);font-size:.9rem;flex:1;min-width:220px}
  .dir-sec{margin-bottom:1.4rem}
  .dir-sec .lead{color:var(--muted);font-size:.86rem;margin:.2rem 0 0}
  .dir-help{font-size:.78rem;color:var(--muted)}
  .dir-inline{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
  .dir-inline input{flex:1;min-width:180px;padding:.55rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px}
  .chips{display:flex;flex-wrap:wrap;gap:.4rem}
  .chip{background:var(--bg);border:1px solid var(--line);border-radius:20px;padding:.25rem .7rem;font-size:.82rem;color:var(--text)}
</style>

<div class="dir-intro">
  <span style="font-size:2rem">🗄️</span>
  <div>
    <div class="dom">Domaine <?= e($curRealm) ?></div>
    <div class="desc">Annuaire central des <strong>agents</strong> et des <strong>postes</strong> : ouverture de
    session Windows, dossiers partagés et stratégies (GPO).</div>
  </div>
  <span class="badge on">Contrôleur actif</span>
</div>

<!-- Les compteurs MÈNENT à leur section. Ils annonçaient « 5 stratégies » sans dire où
     les trouver : le contenu est derrière un onglet, et rien ne le laissait deviner. Le
     geste naturel devant un chiffre est de cliquer dessus — autant qu'il fonctionne. -->
<style>
  .kpi[data-tab]{cursor:pointer;transition:border-color .15s ease,transform .15s ease}
  .kpi[data-tab]:hover{border-color:var(--accent2);transform:translateY(-2px)}
  .kpi[data-tab]::after{content:"›";float:right;color:var(--muted);font-size:1.3rem;line-height:1}
</style>
<section class="cards">
  <div class="kpi" data-tab="comptes" title="Voir les comptes"><div class="kpi-val"><?= count($humanUsers) ?></div><div class="kpi-lbl">Fonctionnaires</div></div>
  <div class="kpi" data-tab="postes" title="Voir les postes"><div class="kpi-val"><?= count($computers) ?></div><div class="kpi-lbl">Ordinateurs</div></div>
  <div class="kpi" data-tab="gpo" title="Voir les stratégies"><div class="kpi-val"><?= count($gpos) ?></div><div class="kpi-lbl">Stratégies (GPO)</div></div>
  <div class="kpi" data-tab="partages" title="Voir les partages"><div class="kpi-val"><?= count($shares) ?></div><div class="kpi-lbl">Dossiers partagés</div></div>
</section>

<!-- Onglets : la page Active Directory est vaste — on regroupe ses sections. -->
<style>
  .dir-tabs{display:flex;gap:.3rem;flex-wrap:wrap;margin:0 0 1.4rem;border-bottom:1px solid var(--line)}
  .dir-tab{background:transparent;border:1px solid transparent;border-bottom:none;color:var(--muted);cursor:pointer;
          padding:.6rem 1.05rem;font-size:.9rem;border-radius:10px 10px 0 0;font-weight:500;white-space:nowrap}
  .dir-tab:hover{color:var(--text);background:var(--bg)}
  .dir-tab.active{color:#fff;background:var(--panel);border-color:var(--line);margin-bottom:-1px}
</style>
<nav class="dir-tabs" role="tablist" aria-label="Sections Active Directory">
  <button type="button" class="dir-tab" data-tab="ensemble">🌳 Vue d'ensemble</button>
  <button type="button" class="dir-tab" data-tab="comptes">👮 Comptes &amp; groupes</button>
  <button type="button" class="dir-tab" data-tab="postes">💻 Postes</button>
  <button type="button" class="dir-tab" data-tab="partages">📁 Partages &amp; lecteurs</button>
  <button type="button" class="dir-tab" data-tab="gpo">📋 Stratégies (GPO)</button>
</nav>
<script>
document.addEventListener('DOMContentLoaded', function () {
  // Chaque section (h2) est rattachée à un onglet par mot-clé de son titre — aucune section
  // à baliser à la main. Titre inconnu → « Vue d'ensemble » par défaut.
  var map = [
    ['Arborescence', 'ensemble'], ['Nom de domaine', 'ensemble'], ['Activation Windows', 'ensemble'],
    ['Fonctionnaires', 'comptes'], ['Groupes &', 'comptes'],
    ['Ordinateurs', 'postes'], ["Fond d'écran", 'postes'], ['Écran de connexion', 'postes'],
    ['Dossiers partagés', 'partages'], ['Lecteurs réseau', 'partages'],
    ['Stratégies de groupe', 'gpo']
  ];
  var secs = document.querySelectorAll('section.dir-sec');
  secs.forEach(function (s) {
    var h = s.querySelector('.panel-head h2'); var t = h ? h.textContent : '';
    var tab = 'ensemble';
    for (var i = 0; i < map.length; i++) { if (t.indexOf(map[i][0]) >= 0) { tab = map[i][1]; break; } }
    s.setAttribute('data-adtab', tab);
  });
  var tabs = document.querySelectorAll('.dir-tab');
  function show(name) {
    secs.forEach(function (s) { s.style.display = (s.getAttribute('data-adtab') === name) ? '' : 'none'; });
    tabs.forEach(function (b) { b.classList.toggle('active', b.dataset.tab === name); });
    try { localStorage.setItem('ad_tab', name); } catch (e) {}
  }
  tabs.forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab); }); });

  // Les compteurs du haut mènent à leur section.
  document.querySelectorAll('.kpi[data-tab]').forEach(function (k) {
    k.addEventListener('click', function () {
      show(k.dataset.tab);
      document.querySelector('.dir-tabs').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  function connu(n) { return Array.prototype.some.call(tabs, function (b) { return b.dataset.tab === n; }); }

  // Ancre : « ad.php#gpo » ouvre directement les stratégies. Permet d'écrire un lien
  // vers une section précise — dans la documentation, un courriel, un signet — sans
  // avoir à décrire un parcours de clics.
  var ancre = (location.hash || '').replace('#', '');
  var init = null; try { init = localStorage.getItem('ad_tab'); } catch (e) {}
  show(connu(ancre) ? ancre : (connu(init) ? init : 'ensemble'));
  window.addEventListener('hashchange', function () {
    var h = (location.hash || '').replace('#', '');
    if (connu(h)) { show(h); }
  });
});
</script>

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
<section class="dir-sec panel">
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
                <li><span class="node">👤 <strong><?= e($u) ?></strong><?php if (!empty($agentNames[$u])): ?> <small>— <?= e($agentNames[$u]) ?></small><?php endif; ?>
                  <?= pf_bouton_deplacer('user', $u, $ous) ?></span></li>
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
                  <?php if ($wu): ?> <small>· 👤 <?= e($wu['user']) ?></small><?php endif; ?>
                  <?= pf_bouton_deplacer('computer', $cn, $ous) ?></span></li>
              <?php endforeach; endif; ?>
            </ul>
          </details></li>
          <li><details>
            <summary>👪 Groupes (<?= count($groups) ?>)</summary>
            <ul>
              <?php foreach ($groups as $g): ?><li><span class="node">🏷️ <?= e($g) ?>
                <?= pf_bouton_deplacer('group', $g, $ous) ?></span></li><?php endforeach; ?>
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
<section class="dir-sec panel">
  <div class="panel-head"><h2>⚙️ Nom de domaine</h2></div>
  <div style="padding:1rem 1.2rem">
    <p class="lead" style="margin:0 0 .9rem">Domaine actuel : <strong><?= e($curRealm) ?></strong>
      (NetBIOS <?= e($curWg) ?>, DN <code><?= e($baseDN) ?></code>).</p>
    <form method="post" class="dir-inline" style="margin-bottom:.5rem">
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
    <p class="dir-help" style="margin-top:.7rem">La recréation prend ~2 min (services redémarrés) ; rechargez la page ensuite.
    Les postes déjà joints devront être re-joints au nouveau domaine.</p>
  </div>
</section>

<!-- 0bis. ACTIVATION KMS -->
<section class="dir-sec panel">
  <div class="panel-head"><h2>🔑 Activation Windows / Office (KMS)</h2>
    <?php $kmsUp = trim((string) shell_exec('systemctl is-active proxyfibre-kms 2>/dev/null')) === 'active'; ?>
    <span class="badge <?= $kmsUp ? 'on' : 'off' ?>">
      <?= $kmsUp ? 'Serveur KMS actif' : 'Inactif' ?></span></div>
  <div style="padding:1rem 1.2rem">
    <p class="lead" style="margin:0 0 .7rem">Les postes du domaine activent Windows et Office automatiquement
    contre la passerelle. Sur un poste, en <strong>Invite de commandes (administrateur)</strong> :</p>
    <pre style="margin:0;padding:.9rem 1rem;background:#0b1120;color:#cbd5e1;border:1px solid var(--line);border-radius:10px;font-size:.82rem;overflow:auto">Windows :  slmgr /skms 192.168.182.1:1688
           slmgr /ato

Office  :  cd "C:\Program Files\Microsoft Office\Office16"
           cscript ospp.vbs /sethst:192.168.182.1
           cscript ospp.vbs /act</pre>
    <p class="dir-help" style="margin-top:.6rem">Utiliser les clés KMS génériques (GVLK) de Microsoft.
    L'activation se renouvelle seule (180 jours). Serveur : <code>vlmcsd</code> sur le port 1688.</p>
    <?php $kmsGpo = in_array('Bastion — Activation Windows/Office', array_map(fn($g) => $g['name'] ?? '', $gpos), true); ?>
    <div style="border:1px solid var(--line);border-radius:12px;background:linear-gradient(120deg,#14324f,#152238);padding:1rem 1.2rem;margin-top:1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
      <span style="font-size:1.8rem">⚡</span>
      <div style="flex:1;min-width:240px">
        <div style="font-weight:600">Activation automatique à la jonction au domaine</div>
        <div class="dir-help" style="margin:.2rem 0 0">Déploie une GPO (script de démarrage) + les enregistrements DNS
        d'auto-découverte <code>_vlmcs</code> : <strong>Windows et Office s'activent seuls</strong> contre le KMS Bastion
        dès qu'un poste est sur le domaine. Garde-fou : les postes déjà activés (OEM/numérique) ne sont pas touchés.</div>
      </div>
      <?php if ($kmsGpo): ?><span class="badge on" style="align-self:flex-start">✓ Activé</span><?php endif; ?>
      <?php /* Le formulaire reste accessible MÊME une fois la GPO déployée : sans cela, l'option
                de montée d'édition serait inatteignable sur un domaine déjà activé. */ ?>
      <form method="post" onsubmit="return confirm('<?= $kmsGpo ? 'Redéployer' : 'Activer' ?> l\'activation Windows/Office sur tous les postes du domaine (KMS) ?')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="kms_auto">
        <label style="display:block;margin:.4rem 0;font-size:.85rem">
          <input type="checkbox" name="monter" value="1">
          Faire passer les postes <strong>Professionnel</strong> en <strong>Entreprise</strong></label>
        <button class="btn"><?= $kmsGpo ? '🔄 Redéployer' : '⚡ Activer automatiquement' ?></button>
        <p class="muted small" style="margin:.4rem 0 0;max-width:52ch">La montée d'édition ne réinstalle rien :
        elle applique une clé, puis le poste s'active sur le serveur KMS local. Elle suppose que vous
        <strong>déteniez les droits Entreprise</strong> (contrat en volume) — c'est une question de licence.</p>
      </form>
    </div>
  </div>
</section>

<!-- Recalage de l'heure des postes au démarrage -->
<section class="dir-sec panel">
  <div class="panel-head"><h2>🕒 Heure des postes du domaine</h2></div>
  <div style="padding:1rem 1.2rem">
    <?php $tsGpo = in_array('Bastion — Recaler l\'heure au démarrage', array_map(fn($g) => $g['name'] ?? '', $gpos), true); ?>
    <div style="border:1px solid var(--line);border-radius:12px;background:linear-gradient(120deg,#14324f,#152238);padding:1rem 1.2rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
      <span style="font-size:1.8rem">🕒</span>
      <div style="flex:1;min-width:240px">
        <div style="font-weight:600">Recaler l'heure au démarrage</div>
        <div class="dir-help" style="margin:.2rem 0 0">Déploie une GPO (script de démarrage) qui, à <strong>chaque démarrage</strong> d'un poste,
        pointe l'horloge sur la passerelle et <strong>force une resynchronisation</strong> (correction d'écart illimitée).
        Indispensable pour les postes en <strong>machine virtuelle</strong> dont l'horloge se décale au boot — sinon Kerberos,
        les GPO et les <strong>lecteurs réseau</strong> échouent tant que l'heure est fausse.</div>
      </div>
      <?php if ($tsGpo): ?><span class="badge on">✓ Déployée</span>
      <?php else: ?>
      <form method="post" onsubmit="return confirm('Déployer le recalage de l\'heure au démarrage sur tous les postes du domaine ?')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="timesync_deploy">
        <button class="btn">🕒 Déployer</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- 1. FONCTIONNAIRES → page fusionnée -->
<section class="dir-sec panel">
  <div class="panel-head"><h2>👮 Fonctionnaires (<?= count($humanUsers) ?>)</h2>
    <a class="btn-sm" href="/users.php">Gérer les utilisateurs &amp; droits →</a></div>
  <p class="lead" style="padding:.2rem 1.2rem 1rem">La création et la gestion des comptes (accès Internet
  <strong>et</strong> compte domaine, avec les droits d'administration) se font désormais dans l'onglet
  <strong><a href="/users.php" style="color:var(--accent)">Utilisateurs &amp; droits</a></strong> — un seul écran pour
  tout le cycle de vie d'un agent.</p>
</section>

<!-- 2. ORDINATEURS -->
<section class="dir-sec panel">
  <div class="panel-head"><h2>💻 Ordinateurs du domaine (<?= count($computers) ?>)</h2></div>
  <p class="lead" style="padding:0 1.2rem;margin:.7rem 0"><strong>Cliquez sur un poste</strong> pour voir le dernier
  fonctionnaire connecté et ajouter une description. Joindre un poste : DNS sur <code><?= e(explode('.', $baseDN)[0] === 'DC=bastion' ? '192.168.182.2' : '192.168.182.2') ?></code>,
  domaine <code><?= e($curRealm) ?></code>, identifiants <code>Administrator</code>.</p>
  <div style="padding:0 1.2rem 1.2rem">
    <?php if (!$computers): ?><span class="muted">Aucun poste joint pour le moment.</span>
    <?php else: foreach ($computers as $c):
        $cn = rtrim($c, '$');                       // nom sans le $ final du compte machine
        $wu = $lastByWs[strtoupper($cn)] ?? null;
        $cd = $computerDesc[strtoupper($cn)] ?? '';
        $dt = $computerDetail[strtoupper($cn)] ?? null; ?>
      <details class="gpo-item">
        <summary>💻 <?= e($cn) ?>
          <?php if ($dt && $dt['os'] !== ''): ?><span class="gpo-pill scope" style="font-weight:400"><?= e($dt['os']) ?></span><?php endif; ?>
          <?php if ($wu): ?> <span class="muted" style="font-weight:400">— 👤 <?= e($wu['user']) ?></span><?php endif; ?></summary>
        <div class="gpo-body">
          <?php if ($dt): ?>
          <p class="expl"><strong>Système :</strong> <?= $dt['os'] !== '' ? e($dt['os']) : '<span class="muted">inconnu</span>' ?>
            · <strong>Dernière ouverture de session :</strong>
            <?php if ($dt['ll'] > 0): $stale = $dt['ll'] < time() - 30 * 86400; ?>
              <span style="color:<?= $stale ? '#eab308' : 'inherit' ?>"><?= e(date('d/m/Y H:i', $dt['ll'])) ?><?= $stale ? ' — inactif depuis plus de 30 jours' : '' ?></span>
            <?php else: ?><span class="muted">jamais / inconnue</span><?php endif; ?></p>
          <?php endif; ?>
          <p class="expl">👤 <strong>Dernier fonctionnaire connecté :</strong>
            <?php if ($wu): ?><?= e($wu['user']) ?> <span class="muted small">(<?= e($wu['ts']) ?>)</span>
            <?php else: ?><span class="muted">aucune ouverture de session enregistrée</span><?php endif; ?></p>
          <?php if ($cd !== ''): ?><p class="expl"><strong>Description :</strong> <?= nl2br(e($cd)) ?></p><?php endif; ?>
          <?php $bk = $bitlockerKeys[strtoupper($cn)] ?? []; if ($bk): ?>
            <p class="expl" style="margin-bottom:.2rem">🔐 <strong>Clé(s) de récupération BitLocker</strong> <span class="muted small">(séquestrées dans l'AD)</span> :</p>
            <?php foreach ($bk as $k): ?>
              <div class="mono" style="user-select:all;background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:.35rem .5rem;margin:.2rem 0 .3rem;font-size:.82rem"><?= e($k['pw']) ?><?php if ($k['when']): ?> <span class="muted small">· <?= e($k['when']) ?></span><?php endif; ?></div>
            <?php endforeach; ?>
          <?php endif; ?>
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

<!-- Formulaire de déplacement UNIQUE pour toute l'arborescence : il est déplacé par le
     navigateur à côté de l'objet choisi, au lieu d'être recopié sur chacun d'eux.
     ── ATTENTION AU « hidden » ──────────────────────────────────────────────
     Il portait « hidden » ET « style="display:inline-flex" ». L'attribut hidden agit
     par la feuille de style du navigateur ([hidden]{display:none}) : un style EN LIGNE
     l'emporte toujours sur elle. Le formulaire était donc VISIBLE en permanence, et
     traînait en bas de la page — une liste déroulante et deux boutons sans contexte,
     que personne ne savait interpréter.
     La mise en page passe donc par une règle, qui ne s'applique QUE si l'élément
     n'est pas masqué. -->
<style>#moveForm:not([hidden]){display:inline-flex;gap:.3rem;align-items:center;margin-left:.5rem}</style>
<form method="post" id="moveForm" hidden>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="do" value="obj_move">
  <input type="hidden" name="type" id="moveType"><input type="hidden" name="nom" id="moveNom">
  <span class="muted small" id="moveQui"></span>
  <select name="dest" id="moveDest" style="font-size:.75rem;padding:.15rem .3rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:6px">
    <option value="">— Dossier par défaut (Users) —</option>
    <?php foreach ($ous as $o): $o = trim($o);
        if ($o === '' || stripos($o, 'Domain Controllers') !== false) { continue; } ?>
      <option value="<?= e($o) ?>"><?= e(preg_replace('/^OU=/i', '', $o)) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn-sm" style="font-size:.72rem">Déplacer</button>
  <button type="button" class="btn-sm" style="font-size:.72rem" id="moveCancel">Annuler</button>
</form>
<script>
(function () {
  var f = document.getElementById('moveForm');
  if (!f) { return; }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('.js-move');
    if (b) {
      document.getElementById('moveType').value = b.dataset.type;
      document.getElementById('moveNom').value = b.dataset.nom;
      document.getElementById('moveQui').textContent = '↪ ' + b.dataset.nom + ' vers :';
      b.parentNode.insertBefore(f, b.nextSibling);   // le formulaire vient à l'objet
      f.hidden = false;
      document.getElementById('moveDest').focus();
      return;
    }
    if (ev.target.id === 'moveCancel') { f.hidden = true; }
  });
  f.addEventListener('submit', function (ev) {
    if (!confirm('Déplacer « ' + document.getElementById('moveNom').value + ' » ?')) { ev.preventDefault(); }
  });
})();
</script>

<!-- 3. DOSSIERS PARTAGÉS -->
<section class="dir-sec panel">
  <div class="panel-head"><h2>📁 Dossiers partagés (<?= count($shares) ?>)</h2></div>
  <p class="lead" style="padding:0 1.2rem;margin:.7rem 0">Dossiers réseau accessibles depuis les postes via
  <code>\\192.168.182.2\NomDuPartage</code>. Les fichiers déposés sont analysés par l'antivirus.
  Un <strong>quota</strong> (en Mo) limite la taille d'un partage : les postes voient l'espace plafonné et
  l'écriture est refusée une fois plein — <strong>aucun fichier n'est supprimé</strong>.</p>
  <div style="padding:0 1.2rem 1.2rem">
    <table class="grid-table" style="margin-bottom:.9rem">
      <thead><tr><th>Partage</th><th>Dossier</th><th style="width:240px">Droits d'accès</th><th style="width:90px">Visible</th><th style="width:210px">Quota</th><th></th></tr></thead>
      <tbody>
        <?php if (!$shares): ?><tr><td colspan="6" class="muted center">Aucun partage. Créez-en un ci-dessous.</td></tr>
        <?php else: foreach ($shares as $sh): $csrf = e(csrf_token()); ?>
          <tr>
            <td><strong>📁 <?= e($sh['name']) ?></strong>
              <?php if ($sh['guest']): ?> <span class="badge" title="Accessible sans authentification (déploiement PXE)">invité</span><?php endif; ?>
              <?php if ($sh['comment']): ?><br><span class="muted small"><?= e($sh['comment']) ?></span><?php endif; ?></td>
            <td class="mono small"><?= e($sh['path']) ?: '<span class="muted">—</span>' ?></td>
            <td>
              <?php if ($sh['mode'] === 'allrw'): ?><span class="badge on">Tous les agents · lecture-écriture</span>
              <?php elseif ($sh['mode'] === 'allro'): ?><span class="badge">Tous les agents · lecture seule</span>
              <?php else: $i = 0; foreach ($sh['acl'] as $g => $lv):
                      if ($i++ >= 3) { echo '<span class="muted small">… +' . (count($sh['acl']) - 3) . ' autre(s)</span>'; break; } ?>
                  <span class="badge<?= $lv === 'rw' ? ' on' : '' ?>">🏷️ <?= e($g) ?> · <?= $lv === 'rw' ? 'écriture' : 'lecture' ?></span>
                <?php endforeach; ?>
                <div class="muted small">Autres agents : aucun accès</div>
              <?php endif; ?>
              <?php if (!$sh['pxe']): ?>
                <button type="button" class="btn-sm js-acl" data-share="<?= e($sh['name']) ?>" style="margin-top:.3rem">Modifier les droits</button>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($sh['browse']): ?><span class="badge on">Oui</span><?php else: ?><span class="badge">Non</span><?php endif; ?>
              <?php if (!$sh['pxe']): ?>
                <form method="post" style="display:inline;margin-left:.3rem">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="share_set">
                  <input type="hidden" name="name" value="<?= e($sh['name']) ?>">
                  <input type="hidden" name="ro" value="<?= $sh['ro'] ? '1' : '0' ?>"><input type="hidden" name="browse" value="<?= $sh['browse'] ? '0' : '1' ?>">
                  <button class="btn-sm" title="Afficher/masquer dans le voisinage réseau"><?= $sh['browse'] ? 'masquer' : 'afficher' ?></button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <?php $qz = $squota[$sh['name']] ?? ['used' => 0, 'quota' => 0];
              if ($sh['pxe']): ?><span class="muted">—</span>
              <?php else:
                if ($qz['quota'] > 0):
                  $qbytes = $qz['quota'] * 1048576; $pctq = min(100, (int) round(100 * $qz['used'] / max(1, $qbytes)));
                  $qcol = $pctq >= 90 ? '#f87171' : ($pctq >= 75 ? '#eab308' : 'var(--accent2)'); ?>
                  <div style="height:6px;border-radius:4px;background:var(--panel2);overflow:hidden;width:100%" title="<?= $pctq ?>% utilisé">
                    <div style="height:100%;width:<?= $pctq ?>%;background:<?= $qcol ?>;border-radius:4px"></div></div>
                  <span class="muted small"><?= number_format($qz['used'] / 1048576, $qz['used'] < 1048576 * 10 ? 1 : 0, ',', ' ') ?> / <?= (int) $qz['quota'] ?> Mo</span>
                <?php else: ?><span class="muted small">illimité</span><?php endif; ?>
                <form method="post" style="display:flex;gap:.25rem;margin-top:.3rem">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="share_quota">
                  <input type="hidden" name="name" value="<?= e($sh['name']) ?>">
                  <input type="number" name="mb" min="0" step="100" value="<?= (int) $qz['quota'] ?>" style="width:5.5rem" title="Quota en Mo (0 = illimité)">
                  <button class="btn-sm" title="Appliquer le quota">Mo ✓</button>
                </form>
              <?php endif; ?>
            </td>
            <td class="row-actions">
              <?php if ($sh['pxe']): ?><span class="badge" title="Partage système géré par le déploiement PXE — non modifiable ici">🔒 PXE</span>
              <?php else: ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Retirer le partage « <?= e($sh['name']) ?> » ?\n\nLe dossier et les fichiers sont CONSERVÉS — seul le partage réseau est retiré (réversible en le recréant).')">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="share_delete">
                  <input type="hidden" name="name" value="<?= e($sh['name']) ?>"><button class="btn-sm btn-danger">Suppr.</button></form>
              <?php endif; ?>
            </td>
          </tr>
          <?php if (!$sh['pxe']): ?>
          <tr class="acl-row" id="acl-<?= e($sh['name']) ?>" hidden><td colspan="6" style="background:rgba(56,189,248,.05)">
            <form method="post" onsubmit="return pfAclCheck(this,'<?= e($sh['name']) ?>','<?= e($sh['mode']) ?>')">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="share_acl">
              <input type="hidden" name="name" value="<?= e($sh['name']) ?>">
              <h4 style="margin:.2rem 0 .6rem">Qui a accès au dossier « <?= e($sh['name']) ?> » ?</h4>
              <label style="display:block;margin:.25rem 0">
                <input type="radio" name="scope" value="all"<?= $sh['mode'] !== 'groups' ? ' checked' : '' ?>>
                <strong>Tous les agents du domaine</strong></label>
              <div style="margin:.1rem 0 .6rem 1.5rem">
                <label style="margin-right:1rem"><input type="radio" name="all_level" value="rw"<?= $sh['mode'] !== 'allro' ? ' checked' : '' ?>>
                  en lecture-écriture <span class="muted small">(dépôt, modification et suppression de fichiers)</span></label>
                <label><input type="radio" name="all_level" value="ro"<?= $sh['mode'] === 'allro' ? ' checked' : '' ?>>
                  en lecture seule <span class="muted small">(consultation uniquement)</span></label>
              </div>
              <label style="display:block;margin:.25rem 0">
                <input type="radio" name="scope" value="groups"<?= $sh['mode'] === 'groups' ? ' checked' : '' ?>>
                <strong>Seulement les groupes désignés ci-dessous</strong></label>
              <table class="grid-table" style="margin:.4rem 0 .6rem 1.5rem;width:auto">
                <thead><tr><th>Groupe</th><th style="width:110px">Aucun accès</th><th style="width:110px">Lecture seule</th><th style="width:130px">Lecture-écriture</th></tr></thead>
                <tbody>
                  <tr><td>🔒 <strong>Administrateurs du domaine</strong><br>
                      <span class="muted small">Accès permanent : un dossier ne doit jamais devenir inadministrable.</span></td>
                    <td colspan="3" class="center muted small">toujours en lecture-écriture</td></tr>
                  <?php $gi = 0; foreach (array_merge(['Domain Users'], $customGroups, $sysGroups) as $g):
                          if ($g === 'Domain Admins') { continue; }
                          if ($g !== 'Domain Users' && in_array($g, $sysGroups, true) && !isset($sh['acl'][$g])) { continue; }
                          $lv = $sh['acl'][$g] ?? 'none'; $gi++; ?>
                    <tr>
                      <td><?= $g === 'Domain Users' ? '👥 <strong>Tous les agents du domaine</strong>' : '🏷️ ' . e($g) ?>
                        <input type="hidden" name="g_name[<?= $gi ?>]" value="<?= e($g) ?>"></td>
                      <td class="center"><input type="radio" name="g_level[<?= $gi ?>]" value="none"<?= $lv === 'none' ? ' checked' : '' ?>></td>
                      <td class="center"><input type="radio" name="g_level[<?= $gi ?>]" value="ro"<?= $lv === 'ro' ? ' checked' : '' ?>></td>
                      <td class="center"><input type="radio" name="g_level[<?= $gi ?>]" value="rw"<?= $lv === 'rw' ? ' checked' : '' ?>></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <button class="btn">Enregistrer les droits</button>
              <button type="button" class="btn-sm js-acl" data-share="<?= e($sh['name']) ?>">Annuler</button>
              <p class="dir-help" style="margin:.6rem 0 0">
                ⚠️ Un agent ajouté à un groupe doit <strong>fermer puis rouvrir sa session Windows</strong> pour que
                le changement prenne effet : ses groupes sont figés à l'ouverture de session.<br>
                Retirer un droit ne supprime aucun fichier ; les documents déjà déposés restent en place.
                <?php if ($sh['guest']): ?><br>Ce partage autorise l'accès anonyme : les droits par groupe ne s'y appliquent pas.<?php endif; ?>
              </p>
            </form>
          </td></tr>
          <?php endif; ?>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    <form method="post" class="dir-inline">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="share_create">
      <input type="text" name="name" required placeholder="Nom du nouveau partage (ex. Brigade)" pattern="[A-Za-z0-9_\-]+" title="Lettres, chiffres, tiret et souligné uniquement">
      <button class="btn-sm">+ Créer le partage</button>
    </form>
    <form method="post" style="margin-top:.6rem" onsubmit="return confirm('Réappliquer les permissions de base à tous les dossiers partagés ?')">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="share_repair">
      <button class="btn-sm">🔧 Réparer l'accès aux dossiers partagés</button>
      <span class="muted small">Réapplique les permissions de base aux dossiers et à leur contenu ; à utiliser si un
      partage reste inaccessible aux agents, ou si un fichier déposé par un agent n'est pas modifiable par ses collègues.
      N'affecte pas les droits par groupe.</span>
    </form>
    <script>
    document.querySelectorAll('.js-acl').forEach(function (b) {
      b.addEventListener('click', function () {
        var r = document.getElementById('acl-' + b.dataset.share);
        if (r) { r.hidden = !r.hidden; if (!r.hidden) { r.scrollIntoView({block: 'nearest'}); } }
      });
    });
    function pfAclCheck(f, nom, modeActuel) {
      var scope = f.querySelector('input[name=scope]:checked');
      if (scope && scope.value === 'groups' && modeActuel !== 'groups') {
        return confirm('Le dossier « ' + nom + ' » ne sera plus accessible qu\'aux groupes désignés.\n\n'
                     + 'Les agents hors de ces groupes perdront l\'accès. Continuer ?');
      }
      return true;
    }
    </script>
  </div>
</section>

<!-- 3bis. LECTEURS RÉSEAU (GPO Drive Maps) -->
<?php
$drives = [];
try {
    pf_drives_schema();
    $drives = pf_db()->query('SELECT * FROM pf_drives ORDER BY letter')->fetchAll();
} catch (Throwable $e) {}
$drivesGpo = in_array('Bastion — Lecteurs réseau', array_map(fn($g) => $g['name'] ?? '', $gpos), true);
?>
<section class="dir-sec panel">
  <div class="panel-head"><h2>💽 Lecteurs réseau (<?= count($drives) ?>)</h2>
    <form method="post" style="margin:0" onsubmit="this.querySelector('button').textContent='Déploiement…';this.querySelector('button').disabled=true">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="drives_deploy">
      <button class="btn"<?= $drives ? '' : ' disabled' ?>>🚀 Déployer sur les postes</button>
    </form>
  </div>
  <p class="lead" style="padding:0 1.2rem;margin:.7rem 0">Connecte automatiquement des lecteurs réseau à l'ouverture
  de session des agents (par GPO). Les chemins pointent vers les partages par le <strong>nom du serveur</strong>, ex.
  <code>\\<?= e($curDc) ?>\Commun</code> (et non <code>\\<?= e(strtolower($curRealm)) ?>\Commun</code> : un partage
  ordinaire n'est pas dans l'espace DFS du domaine → « Élément introuvable »).</p>
  <div class="dir-help" style="margin:0 1.2rem .8rem;padding:.7rem .9rem;background:rgba(56,189,248,.06);border-radius:8px">
    <strong>Un poste affiche « Windows a tenté en vain de lire gpt.ini » ?</strong>
    Les permissions du SYSVOL sont désynchronisées (fréquent après création de GPO sur Samba).
    <form method="post" style="margin:.5rem 0 0" onsubmit="this.querySelector('button').textContent='Réparation…';this.querySelector('button').disabled=true">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="sysvol_reset">
      <button class="btn-sm">🔧 Réparer les permissions SYSVOL</button>
    </form>
  </div>
  <div style="padding:0 1.2rem 1.2rem">
    <table class="grid-table" style="margin-bottom:.9rem">
      <thead><tr><th style="width:70px">Lettre</th><th>Chemin réseau</th><th>Étiquette</th><th style="width:180px">Pour qui ?</th><th style="width:160px"></th></tr></thead>
      <tbody>
        <?php if (!$drives): ?><tr><td colspan="5" class="muted center">Aucun lecteur. Ajoutez-en un ci-dessous.</td></tr>
        <?php else: foreach ($drives as $dr): ?>
          <tr>
            <td><strong><?= e($dr['letter']) ?>:</strong></td>
            <td class="mono"><?= e($dr['path']) ?></td>
            <td><?= e($dr['label']) ?: '<span class="muted">—</span>' ?></td>
            <td><?php $dg = (string) ($dr['group_name'] ?? '');
                if ($dg === ''): ?><span class="muted">Tous les agents</span>
                <?php else: ?><span class="badge">🏷️ <?= e($dg) ?></span><?php endif; ?></td>
            <td class="row-actions">
              <button type="button" class="btn-sm js-edit-drive"
                data-id="<?= (int) $dr['id'] ?>" data-letter="<?= e($dr['letter']) ?>"
                data-path="<?= e($dr['path']) ?>" data-label="<?= e($dr['label']) ?>"
                data-group="<?= e((string) ($dr['group_name'] ?? '')) ?>">Modifier</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Retirer ce lecteur ?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="drive_del">
                <input type="hidden" name="id" value="<?= (int) $dr['id'] ?>"><button class="btn-sm btn-danger">Suppr.</button></form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    <form method="post" class="dir-inline" id="driveForm">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="drive_add" id="driveDo">
      <input type="hidden" name="id" value="" id="driveId">
      <strong id="driveFormTitle" style="align-self:center;margin-right:.2rem">Nouveau&nbsp;:</strong>
      <select name="letter" id="driveLetter" style="max-width:90px;padding:.55rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
        <?php foreach (str_split('ZYXWVUTSPNMLKJHGF') as $L): ?><option><?= $L ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="path" id="drivePath" required placeholder="\\<?= e($curDc) ?>\Commun" list="sharelist" style="min-width:220px">
      <datalist id="sharelist"><?php foreach ($shares as $sh): ?><option value="\\<?= e($curDc) ?>\<?= e($sh['name']) ?>"><?php endforeach; ?></datalist>
      <input type="text" name="label" id="driveLabel" placeholder="Étiquette (ex. Commun)" style="max-width:200px">
      <select name="group" id="driveGroup" title="Réserver ce lecteur à un groupe (sinon : tous les agents)"
        style="max-width:200px;padding:.55rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
        <option value="">Tous les agents</option>
        <?php if ($customGroups): ?><optgroup label="Groupes métier">
          <?php foreach ($customGroups as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
        </optgroup><?php endif; ?>
        <?php if ($sysGroups): ?><optgroup label="Groupes système Windows">
          <?php foreach ($sysGroups as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
        </optgroup><?php endif; ?>
      </select>
      <button class="btn-sm" id="driveSubmit">+ Ajouter</button>
      <button type="button" class="btn-sm" id="driveCancel" style="display:none" onclick="pfDriveReset()">Annuler</button>
    </form>
    <?php if ($drivesGpo): ?><p class="dir-help" style="margin-top:.6rem"><span class="badge on">✓ GPO déployée</span>
      Les lecteurs se montent à l'<strong>ouverture de session</strong> des agents (après <code>gpupdate</code> + reconnexion).</p><?php endif; ?>
  </div>
</section>
<script>
(function () {
  var g = function (id) { return document.getElementById(id); };
  window.pfDriveReset = function () {
    g('driveDo').value = 'drive_add'; g('driveId').value = '';
    g('driveFormTitle').innerHTML = 'Nouveau&nbsp;:';
    g('driveSubmit').textContent = '+ Ajouter'; g('driveCancel').style.display = 'none';
    g('drivePath').value = ''; g('driveLabel').value = ''; g('driveGroup').value = '';
  };
  document.querySelectorAll('.js-edit-drive').forEach(function (b) {
    b.addEventListener('click', function () {
      g('driveDo').value = 'drive_edit'; g('driveId').value = b.dataset.id;
      g('driveLetter').value = b.dataset.letter; g('drivePath').value = b.dataset.path; g('driveLabel').value = b.dataset.label;
      g('driveGroup').value = b.dataset.group || '';
      g('driveFormTitle').textContent = 'Modifier ' + b.dataset.letter + ': ';
      g('driveSubmit').textContent = '✓ Enregistrer'; g('driveCancel').style.display = '';
      g('driveForm').scrollIntoView({ behavior: 'smooth', block: 'center' }); g('drivePath').focus();
    });
  });
})();
</script>

<!-- 3ter-bis. ÉCRAN DE CONNEXION -->
<?php
$lgGpo = in_array('Bastion — Écran de connexion', array_map(fn($g) => $g['name'] ?? '', $gpos), true);
$lgCap = $lgTxt = $lgFlags = '';
try {
    foreach (pf_db()->query("SELECT k,v FROM pf_settings WHERE k IN ('logon_caption','logon_notice','logon_flags')") as $r) {
        if ($r['k'] === 'logon_caption') { $lgCap = $r['v']; }
        if ($r['k'] === 'logon_notice')  { $lgTxt = $r['v']; }
        if ($r['k'] === 'logon_flags')   { $lgFlags = $r['v']; }
    }
} catch (Throwable $e) {}
$lgHas = fn(string $l) => strpos($lgFlags, $l) !== false;
?>
<section class="dir-sec panel">
  <div class="panel-head"><h2>🔐 Écran de connexion des postes</h2>
    <?php if ($lgGpo): ?><span class="badge on">✓ GPO déployée</span><?php endif; ?>
  </div>
  <div style="padding:0 1.2rem 1.2rem">
    <p class="lead" style="margin:.7rem 0">Personnalise l'écran affiché <strong>avant l'ouverture de session</strong> :
    image de fond, message d'accueil, et informations masquées. S'applique à tous les postes du domaine.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="logon_deploy">
      <div style="display:grid;gap:.9rem;max-width:760px">
        <label style="display:grid;gap:.3rem">
          <span><strong>Image de fond</strong> <span class="muted small">(JPG, PNG ou BMP — 1920×1080 conseillé ; laisser vide conserve l'image actuelle)</span></span>
          <input type="file" name="logo" accept="image/jpeg,image/png,image/bmp">
        </label>
        <label style="display:grid;gap:.3rem">
          <span><strong>Titre du message</strong> <span class="muted small">(vide = aucun message affiché)</span></span>
          <input type="text" name="caption" maxlength="120" value="<?= e($lgCap) ?>"
                 placeholder="Accès réservé aux personnels habilités">
        </label>
        <label style="display:grid;gap:.3rem">
          <span><strong>Message</strong></span>
          <textarea name="notice" rows="3" maxlength="900"
            placeholder="Ce système est réservé aux agents habilités. Toute activité est enregistrée et contrôlée."><?= e($lgTxt) ?></textarea>
        </label>
        <fieldset style="border:1px solid var(--line);border-radius:8px;padding:.7rem .9rem">
          <legend class="muted small" style="padding:0 .4rem">Informations à masquer</legend>
          <label style="display:block;margin:.2rem 0"><input type="checkbox" name="hide_user"<?= $lgHas('u') ? ' checked' : '' ?>>
            Ne pas afficher le <strong>dernier utilisateur connecté</strong></label>
          <label style="display:block;margin:.2rem 0"><input type="checkbox" name="hide_details"<?= $lgHas('d') ? ' checked' : '' ?>>
            Masquer les <strong>détails du compte</strong> (adresse de messagerie)</label>
          <label style="display:block;margin:.2rem 0"><input type="checkbox" name="hide_shutdown"<?= $lgHas('s') ? ' checked' : '' ?>>
            Retirer le <strong>bouton d'arrêt</strong> de l'écran de connexion</label>
        </fieldset>
        <fieldset style="border:1px solid var(--line);border-radius:8px;padding:.7rem .9rem">
          <legend class="muted small" style="padding:0 .4rem">Compatibilité de l'image</legend>
          <label style="display:block"><input type="checkbox" name="csp_force"<?= $lgHas('c') ? ' checked' : '' ?>>
            Appliquer l'image <strong>aussi sur les éditions Famille et Professionnel</strong></label>
          <p class="muted small" style="margin:.4rem 0 0">Utile tant que des postes ne sont pas en édition
          Entreprise. Contrairement aux autres réglages, celui-ci <strong>reste inscrit sur le poste</strong>
          même après retrait de la stratégie : décocher cette case efface l'inscription au prochain
          déploiement (ne supprimez donc pas la GPO avant d'avoir décoché).</p>
        </fieldset>
        <div><button class="btn">🚀 Déployer sur les postes</button></div>
      </div>
    </form>
    <?php $phGpo = in_array("Bastion — Photo de l'agent", array_map(fn($g) => $g['name'] ?? '', $gpos), true); ?>
    <div style="margin-top:1.3rem;padding-top:1.1rem;border-top:1px solid var(--line)">
      <h3 style="margin:0 0 .4rem;font-size:1rem">👤 Photo de l'agent sur l'écran de connexion
        <?php if ($phGpo): ?><span class="badge on">✓ GPO déployée</span><?php endif; ?></h3>
      <p class="lead" style="margin:.3rem 0 .7rem">Pose la photo de chaque fonctionnaire comme
      <strong>image de compte Windows</strong> : écran de connexion, menu Démarrer, Paramètres&nbsp;&gt;&nbsp;Comptes.
      La photo vient de la fiche de l'agent dans <a href="users.php">Utilisateurs&nbsp;&amp;&nbsp;droits</a>.</p>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="photo_deploy">
        <button class="btn"><?= $phGpo ? '🔄 Actualiser la stratégie' : '🚀 Déployer sur les postes' ?></button>
      </form>
      <p class="dir-help" style="margin:.8rem 0 0">
        Windows ne lit <strong>jamais</strong> la photo de l'annuaire pour l'image de compte : il faut
        l'écrire sur le poste, ce qui exige les droits administrateur. La stratégie utilise donc un
        script de <strong>démarrage</strong>, exécuté en tant que SYSTEM. C'est aussi ce qui permet à
        l'écran de connexion d'être correct <strong>dès ce démarrage</strong> — une image posée pendant
        la session n'apparaîtrait qu'à la session suivante. Un changement de photo dans la console est
        repris à l'ouverture de session suivante, sans redémarrage.
      </p>
    </div>

    <p class="dir-help" style="margin:.8rem 0 0">
      Le <strong>message</strong> et les <strong>masquages</strong> fonctionnent sur toutes les éditions de Windows.
      L'<strong>image de fond</strong> n'est appliquée nativement que par les éditions <strong>Entreprise</strong>
      et <strong>Éducation</strong> ; pour les éditions Famille et Professionnel, cochez la case de compatibilité
      ci-dessus. Effet au <strong>prochain démarrage</strong> du poste.
    </p>
  </div>
</section>

<!-- 3ter. FOND D'ÉCRAN (GPO Desktop Wallpaper) -->
<?php
$wpGpo   = in_array("Bastion — Fond d'écran", array_map(fn($g) => $g['name'] ?? '', $gpos), true);
$wpStyle = ''; $wpTs = 0; $wpHas = false;
try {
    foreach (pf_db()->query("SELECT k,v FROM pf_settings WHERE k IN ('wallpaper_style','wallpaper_ts')") as $r) {
        if ($r['k'] === 'wallpaper_style') { $wpStyle = $r['v']; }
        if ($r['k'] === 'wallpaper_ts') { $wpTs = (int) $r['v']; }
    }
    // L'aperçu vit en base (table pf_media) et est servi par wallpaper-img.php — robuste face
    // aux permissions du dossier admin/ et aux mises à jour (rsync --delete).
    $wpHas = (bool) pf_db()->query("SELECT 1 FROM pf_media WHERE k='wallpaper'")->fetchColumn();
} catch (Throwable $e) {}
$wpPreview = $wpHas ? ('wallpaper-img.php?t=' . $wpTs) : '';
$wpStyleLabels = ['10' => 'Remplir', '6' => 'Ajuster', '2' => 'Étirer', '0' => 'Centrer', '22' => 'Étendre', 'tile' => 'Mosaïque'];
?>
<section class="dir-sec panel">
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
      <p class="dir-help" style="margin:0">S'applique à la prochaine <strong>ouverture de session</strong> des agents
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
<section class="dir-sec panel">
  <div class="panel-head"><h2>📋 Stratégies de groupe (GPO) (<?= count($gpos) ?>)</h2></div>
  <p class="lead" style="padding:0 1.2rem;margin:.7rem 0"><strong>Cliquez sur une GPO</strong> pour savoir ce qu'elle
  fait. Les GPO appliquent automatiquement des règles aux postes (sécurité, mot de passe, restrictions…) ;
  elles s'éditent en détail depuis la console « Gestion des stratégies de groupe » d'un poste Windows.</p>
  <div style="padding:0 1.2rem 1.2rem">
    <!-- Diagnostic de santé des stratégies -->
    <style>
      .gpo-diag{border:1px solid var(--line);border-radius:12px;background:var(--bg);padding:1rem 1.2rem;margin-bottom:1rem}
      .gpo-diag-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
      .gpo-diag-sum{display:flex;gap:.5rem;flex-wrap:wrap;margin:.9rem 0 .2rem}
      .gpo-diag-pill{font-size:.8rem;font-weight:600;padding:.2rem .7rem;border-radius:20px;border:1px solid var(--line)}
      .gpo-diag-pill.ok{color:#4ade80;border-color:rgba(74,222,128,.4);background:rgba(74,222,128,.08)}
      .gpo-diag-pill.warn{color:#eab308;border-color:rgba(234,179,8,.4);background:rgba(234,179,8,.08)}
      .gpo-diag-pill.fail{color:#f87171;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08)}
      .gpo-diag-item{border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem;margin-top:.55rem;background:var(--panel)}
      .gpo-diag-item.fail{border-color:rgba(248,113,113,.45)} .gpo-diag-item.warn{border-color:rgba(234,179,8,.35)}
      .gpo-diag-item b{font-size:.92rem} .gpo-diag-c{font-size:.82rem;color:var(--muted);margin-top:.25rem;line-height:1.5}
      .gpo-diag-ok{color:#4ade80;font-size:.86rem;margin-top:.6rem}
    </style>
    <div class="gpo-diag" id="gpo-diag">
      <div class="gpo-diag-head">
        <div><span style="font-size:1.3rem">🩺</span> <strong>Diagnostic des stratégies</strong>
          <div class="dir-help" style="margin:.15rem 0 0">Vérifie que chaque GPO Bastion est <strong>liée</strong>, <strong>lisible par les postes</strong> (permissions SYSVOL) et <strong>complète</strong> — repère les stratégies qui ne s'appliquent pas.</div>
        </div>
        <button type="button" class="btn-sm" id="gpo-diag-run">🩺 Lancer le diagnostic</button>
      </div>
      <div id="gpo-diag-out" hidden></div>
    </div>
    <form id="pf-sysvolreset" method="post" style="display:none">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="sysvol_reset">
    </form>
    <script>
    (function(){
      var btn=document.getElementById('gpo-diag-run'), out=document.getElementById('gpo-diag-out');
      if(!btn) return;
      var ICO={ok:'✅',warn:'⚠️',fail:'⛔'}, LBL={ok:'conformes',warn:'à surveiller',fail:'à corriger'};
      function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
      btn.addEventListener('click', function(){
        btn.disabled=true; btn.textContent='Analyse en cours…'; out.hidden=false;
        out.innerHTML='<p class="muted small" style="margin:.8rem 0 0">Contrôle de chaque stratégie…</p>';
        fetch('ad.php?gpo_health=1', {headers:{'X-Requested-With':'fetch'}})
          .then(function(r){ return r.json(); })
          .then(function(j){
            btn.disabled=false; btn.textContent='🔄 Relancer le diagnostic';
            if(!j.ok){ out.innerHTML='<p class="flash err" style="margin:.8rem 0 0">'+esc(j.error||'Diagnostic indisponible.')+'</p>'; return; }
            var g=j.gpos||[], nf=0,nw=0,no=0;
            g.forEach(function(x){ if(x.worst==='fail')nf++; else if(x.worst==='warn')nw++; else no++; });
            var h='<div class="gpo-diag-sum">'+
              '<span class="gpo-diag-pill ok">'+no+' conformes</span>'+
              (nw?'<span class="gpo-diag-pill warn">'+nw+' à surveiller</span>':'')+
              (nf?'<span class="gpo-diag-pill fail">'+nf+' à corriger</span>':'')+'</div>';
            var issues=g.filter(function(x){ return x.worst!=='ok'; });
            var aclProblem=false;
            if(!issues.length){
              h+='<div class="gpo-diag-ok">✅ Les '+g.length+' stratégies Bastion sont saines : liées, lisibles par les postes et complètes.</div>';
            } else {
              issues.forEach(function(x){
                h+='<div class="gpo-diag-item '+x.worst+'"><b>'+ICO[x.worst]+' '+esc(x.name)+'</b>';
                x.checks.forEach(function(c){
                  if(c.status!=='ok'){
                    h+='<div class="gpo-diag-c">'+ICO[c.status]+' <strong>'+esc(c.label)+'</strong> — '+esc(c.detail)+'</div>';
                    if(/SYSVOL/i.test(c.label)) aclProblem=true;
                  }
                });
                h+='</div>';
              });
              if(aclProblem){
                h+='<p style="margin:.9rem 0 0"><button type="button" class="btn-sm" id="gpo-diag-fix">🔧 Réparer les permissions SYSVOL</button>'+
                   ' <span class="muted small">(réaligne les droits de lecture — ~40 s)</span></p>';
              }
            }
            out.innerHTML=h;
            var fix=document.getElementById('gpo-diag-fix');
            if(fix){ fix.addEventListener('click', function(){
              if(confirm('Réparer les permissions SYSVOL de toutes les stratégies ?\n\nOpération sûre (~40 s), à faire si des postes ne lisent pas les GPO.'))
                document.getElementById('pf-sysvolreset').submit();
            }); }
          })
          .catch(function(){ btn.disabled=false; btn.textContent='🔄 Relancer le diagnostic';
            out.innerHTML='<p class="flash err" style="margin:.8rem 0 0">Diagnostic impossible (réseau/serveur).</p>'; });
      });
    })();
    </script>

    <!-- Certificat racine Bastion : confiance HTTPS automatique -->
    <?php $certGpo = in_array('Bastion — Certificat racine (confiance HTTPS)', array_map(fn($g) => $g['name'] ?? '', $gpos), true); ?>
    <div style="border:1px solid var(--line);border-radius:12px;background:linear-gradient(120deg,#14324f,#152238);padding:1rem 1.2rem;margin-bottom:1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
      <span style="font-size:1.8rem">🔐</span>
      <div style="flex:1;min-width:240px">
        <div style="font-weight:600">Certificat racine Bastion — confiance HTTPS des postes</div>
        <div class="dir-help" style="margin:.2rem 0 0">Déploie l'autorité Bastion dans le magasin « Autorités racines de confiance »
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

    <!-- Chiffrement BitLocker -->
    <?php $blGpo = in_array('Bastion — Chiffrement BitLocker', array_map(fn($g) => $g['name'] ?? '', $gpos), true); ?>
    <style>.bl-opt{display:flex;gap:.5rem;align-items:flex-start;font-size:.9rem;cursor:pointer}.bl-opt input{margin-top:.2rem;flex:none}</style>
    <div style="border:1px solid var(--line);border-radius:12px;background:linear-gradient(120deg,#3a2f14,#231a08);padding:1rem 1.2rem;margin-bottom:1rem">
      <div style="display:flex;align-items:flex-start;gap:1rem;flex-wrap:wrap">
        <span style="font-size:1.8rem">🔐</span>
        <div style="flex:1;min-width:240px">
          <div style="font-weight:600">Chiffrement BitLocker des disques — clé dans l'Active Directory
            <?php if ($blGpo): ?><span class="badge on" style="margin-left:.4rem">✓ Déployé</span><?php endif; ?></div>
          <div class="dir-help" style="margin:.2rem 0 0">Chiffre le disque système des postes et <strong>sauvegarde la clé de
          récupération dans l'AD</strong> (visible sous chaque ordinateur ci-dessous). S'active au démarrage sur les postes
          dotés d'un <strong>TPM prêt</strong> (édition Pro/Entreprise) ; la clé est séquestrée <em>avant</em> le chiffrement.
          Les postes sans TPM ne sont pas touchés.</div>
          <div class="dir-help" style="margin:.35rem 0 0;color:#eab308">⚠️ Opération à fort impact : <strong>testez d'abord sur un poste pilote</strong>.</div>
        </div>
      </div>
      <form method="post" onsubmit="return blConfirm(this)" style="margin-top:.9rem;border-top:1px solid var(--line);padding-top:.8rem">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="bitlocker_deploy">
        <div class="dir-help" style="margin-bottom:.45rem">Mode de déverrouillage au démarrage :</div>
        <div style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:.6rem">
          <label class="bl-opt"><input type="radio" name="bl_mode" value="tpm" checked> <span><strong>TPM seul</strong> — démarrage transparent, aucun code. Recommandé pour les <strong>postes fixes</strong>.</span></label>
          <label class="bl-opt"><input type="radio" name="bl_mode" value="tpmpin_common"> <span><strong>TPM + PIN commun</strong> — un même code pour tout le parc, posé automatiquement. Un code est demandé à chaque démarrage.</span></label>
          <label class="bl-opt"><input type="radio" name="bl_mode" value="tpmpin_manual"> <span><strong>TPM + PIN par poste</strong> — la GPO impose un code ; vous le définissez, unique, sur chaque poste (le plus sûr). Recommandé pour les <strong>portables</strong>.</span></label>
        </div>
        <div id="blpinrow" style="display:none;margin-bottom:.6rem">
          <input type="text" name="bl_pin" inputmode="numeric" pattern="\d{6,20}" maxlength="20" autocomplete="off" placeholder="PIN commun — 6 à 20 chiffres"
                 style="padding:.45rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px;max-width:260px">
          <div class="dir-help" style="color:#eab308;margin-top:.25rem">⚠️ Un PIN commun est <strong>lisible dans SYSVOL</strong> par les comptes du domaine — à réserver au « frein anti-vol opportuniste ».</div>
        </div>
        <button class="btn">🔐 <?= $blGpo ? 'Redéployer / changer de mode' : 'Déployer BitLocker' ?></button>
      </form>
    </div>
    <script>
    (function(){
      var rows=document.getElementById('blpinrow');
      Array.prototype.forEach.call(document.querySelectorAll('input[name=bl_mode]'), function(r){
        r.addEventListener('change',function(){
          var v=document.querySelector('input[name=bl_mode]:checked').value;
          if(rows) rows.style.display=(v==='tpmpin_common')?'':'none';
        });
      });
    })();
    function blConfirm(f){
      var m=f.querySelector('input[name=bl_mode]:checked').value;
      if(m==='tpmpin_common'){
        var p=(f.bl_pin.value||'').trim();
        if(!/^\d{6,20}$/.test(p)){ alert('Saisissez un PIN de 6 à 20 chiffres.'); return false; }
        return confirm('Déployer BitLocker en TPM + PIN COMMUN ?\n\nUn code sera demandé à chaque démarrage. Ce PIN sera lisible dans SYSVOL. Testez d\'abord sur un poste pilote.');
      }
      if(m==='tpmpin_manual'){
        return confirm('Déployer BitLocker en TPM + PIN PAR POSTE ?\n\nLa GPO impose un code au démarrage, mais NE chiffre pas automatiquement : vous définirez le PIN sur chaque poste (procédure fournie). Testez d\'abord sur un poste pilote.');
      }
      return confirm('Déployer le chiffrement BitLocker (TPM seul) ?\n\nLes disques des postes avec TPM seront chiffrés au prochain démarrage, de façon transparente ; la clé est sauvegardée dans l\'AD au préalable. Testez d\'abord sur un poste pilote.');
    }
    </script>

    <!-- Catalogue prêt à déployer -->
    <h3 style="font-size:.95rem;margin:.2rem 0 .3rem">📚 Catalogue de stratégies — déploiement en un clic (<?= count($GPO_CATALOG) ?>)</h3>
    <p class="dir-help" style="margin:0 0 .8rem">Chaque modèle crée la GPO, la configure et la <strong>lie au domaine</strong>.
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
          <?php /* Pas d'attribut « data-search » : il recopiait la description ENTIÈRE de chaque
                   stratégie, soit un doublon de tout le catalogue dans la page. La recherche lit
                   désormais le texte déjà présent dans la carte. */ ?>
          <div class="cat-card">
            <div class="cat-h"><span class="cat-ico"><?= $c['icon'] ?></span><strong><?= e($c['title']) ?></strong></div>
            <span class="cat-scope"><?= $c['scope'] === 'Ordinateur' ? '💻 Ordinateur' : '👤 Utilisateur' ?></span>
            <p class="cat-d"><?= e($c['desc']) ?></p>
            <?php if ($isDep): ?>
              <span class="badge on">✓ Déployée</span>
            <?php else: ?>
              <button class="btn-sm js-depl" data-tpl="<?= e($k) ?>" data-title="<?= e($c['title']) ?>">⬇ Déployer</button>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <!-- Formulaire de déploiement UNIQUE : il était auparavant recopié sur chacune des
         stratégies du catalogue, soit plus de cent copies du même jeton et des mêmes champs. -->
    <form method="post" id="deplForm" class="gpo-deploy-form" hidden>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="gpo_deploy">
      <input type="hidden" name="tpl" id="deplTpl">
    </form>
    <script>
    (function(){
      var f=document.getElementById('deplForm');
      document.addEventListener('click',function(ev){
        var b=ev.target.closest('.js-depl'); if(!b||!f) return;
        f.setAttribute('data-title', b.dataset.title);   // repris par la jauge d'installation
        document.getElementById('deplTpl').value=b.dataset.tpl;
        // « requestSubmit » DÉCLENCHE l'événement submit — c'est indispensable : la confirmation
        // et la jauge d'installation y sont accrochées. « submit() », lui, ne le déclenche PAS :
        // en repli, on émet donc l'événement nous-mêmes plutôt que de court-circuiter les deux.
        if (f.requestSubmit) {
          f.requestSubmit();
        } else if (f.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))) {
          f.submit();   // personne n'a intercepté : envoi direct
        }
      });
      var q=document.getElementById('gposearch'); if(!q) return;
      q.addEventListener('input',function(){
        var v=this.value.trim().toLowerCase();
        document.querySelectorAll('.gpo-cat-h').forEach(function(h){
          var grid=h.nextElementSibling, shown=0;
          grid.querySelectorAll('.cat-card').forEach(function(card){
            // Recherche sur le texte AFFICHÉ : plus besoin de le dupliquer dans un attribut.
            var ok=!v||(card.textContent||'').toLowerCase().indexOf(v)>=0;
            card.style.display=ok?'':'none'; if(ok) shown++;
          });
          h.style.display=shown?'':'none';
        });
      });
    })();
    </script>

    <!-- Jauge d'installation d'une GPO du catalogue -->
    <style>
      .gpo-inst{position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;
        background:rgba(4,8,16,.72);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px)}
      .gpo-inst[hidden]{display:none}
      .gpo-inst-card{width:min(440px,92vw);background:var(--panel);border:1px solid var(--line);border-radius:16px;
        padding:1.7rem 1.6rem 1.35rem;text-align:center;box-shadow:0 30px 90px rgba(0,0,0,.55);
        animation:gpoInstUp .4s cubic-bezier(.16,1,.3,1)}
      @keyframes gpoInstUp{from{opacity:0;transform:translateY(16px) scale(.98)}to{opacity:1;transform:none}}
      .gpo-inst-ico{font-size:2.3rem;line-height:1}
      .gpo-inst-title{font-weight:700;font-size:1.12rem;margin:.5rem 0 .15rem;color:var(--text)}
      .gpo-inst-name{color:var(--muted);font-size:.9rem;margin-bottom:1.15rem}
      .gpo-inst-bar{height:12px;border-radius:8px;background:var(--bg);border:1px solid var(--line);overflow:hidden}
      .gpo-inst-bar>span{display:block;height:100%;width:0;border-radius:8px;
        background:linear-gradient(90deg,var(--accent2),var(--accent));transition:width .5s cubic-bezier(.4,0,.2,1)}
      .gpo-inst.ok  .gpo-inst-bar>span{background:linear-gradient(90deg,#22c55e,#4ade80)}
      .gpo-inst.err .gpo-inst-bar>span{background:linear-gradient(90deg,#ef4444,#f87171)}
      .gpo-inst-step{margin-top:.75rem;font-size:.86rem;color:var(--muted)}
      .gpo-inst-step b{color:var(--text);font-weight:600}
      .gpo-inst-note{margin-top:.5rem;font-size:.78rem;color:var(--muted);opacity:.85;line-height:1.4}
      .gpo-inst-note[hidden]{display:none}
      .gpo-inst-actions{margin-top:1.1rem}
    </style>
    <div id="gpo-inst" class="gpo-inst" hidden role="dialog" aria-live="polite" aria-label="Déploiement de la stratégie">
      <div class="gpo-inst-card">
        <div class="gpo-inst-ico">📋</div>
        <div class="gpo-inst-title">Déploiement de la stratégie</div>
        <div class="gpo-inst-name" id="gpo-inst-name">—</div>
        <div class="gpo-inst-bar"><span id="gpo-inst-fill"></span></div>
        <div class="gpo-inst-step"><b id="gpo-inst-pct">0 %</b> · <span id="gpo-inst-label">Préparation…</span></div>
        <div class="gpo-inst-note" id="gpo-inst-note" hidden>Réalignement des permissions du SYSVOL — cette étape peut durer jusqu'à une minute.</div>
        <div class="gpo-inst-actions" id="gpo-inst-actions" hidden>
          <button type="button" class="btn-sm" onclick="location.reload()">Fermer</button>
        </div>
      </div>
    </div>
    <script>
    (function(){
      var ov=document.getElementById('gpo-inst'); if(!ov) return;
      // L'overlay doit couvrir tout l'écran : on le sort vers <body>. Sinon il reste enfant
      // de la section GPO, qui — via « .page > * {animation:fadeUp both} » — garde un
      // transform identité (≠ none) et devient le bloc conteneur du position:fixed ;
      // combiné à .panel{overflow:hidden}, l'overlay serait rogné et quasi invisible.
      if (ov.parentNode !== document.body) { document.body.appendChild(ov); }
      var fill=document.getElementById('gpo-inst-fill'),  pctEl=document.getElementById('gpo-inst-pct'),
          lblEl=document.getElementById('gpo-inst-label'), nameEl=document.getElementById('gpo-inst-name'),
          acts=document.getElementById('gpo-inst-actions'), note=document.getElementById('gpo-inst-note'),
          icoEl=ov.querySelector('.gpo-inst-ico'), titleEl=ov.querySelector('.gpo-inst-title');
      var poll=null, creep=null, shownPct=0, serverPct=0, t0=0;
      var CAP=97;   // plafond de l'avance douce ; le 100 % est réservé à la vraie fin
      function paint(){ var p=Math.max(0,Math.min(100,shownPct)); fill.style.width=p+'%'; pctEl.textContent=Math.round(p)+' %'; }
      function stop(){ if(poll){clearInterval(poll);poll=null;} if(creep){clearInterval(creep);creep=null;} }
      function show(name){ ov.className='gpo-inst'; icoEl.textContent='📋'; titleEl.textContent='Déploiement de la stratégie';
        nameEl.textContent='« '+name+' »'; lblEl.textContent='Préparation…'; note.hidden=true; acts.hidden=true;
        shownPct=0; serverPct=0; paint(); ov.hidden=false; }
      function fail(msg){ stop(); ov.classList.add('err'); icoEl.textContent='⛔'; titleEl.textContent='Échec du déploiement';
        note.hidden=true; shownPct=100; paint(); lblEl.textContent=msg||'Une erreur est survenue.'; acts.hidden=false; }
      function succeed(){ stop(); ov.classList.add('ok'); icoEl.textContent='✅'; titleEl.textContent='Stratégie déployée';
        note.hidden=true; shownPct=100; paint(); lblEl.textContent='Terminé — appliquée aux postes au prochain gpupdate.';
        setTimeout(function(){ location.reload(); }, 1200); }
      window.startGpoDeploy=function(form, title){
        show(title); t0=Date.now();
        // Avance douce : la barre glisse vers CAP tant que le serveur travaille — garde la
        // jauge vivante pendant la longue réparation SYSVOL (~40 s), sans jamais atteindre 100.
        creep=setInterval(function(){ if(shownPct<CAP){ shownPct += (CAP-shownPct)*0.02; paint(); } }, 250);
        var fd=new FormData(form); fd.append('ajax','1');
        fetch(location.pathname, {method:'POST', body:fd, headers:{'X-Requested-With':'fetch'}})
          .then(function(r){ return r.json(); })
          .then(function(j){
            if(!j || !j.nonce){ fail('Lancement du déploiement impossible.'); return; }
            var nonce=j.nonce, misses=0;
            poll=setInterval(function(){
              fetch('ad.php?gpo_progress='+encodeURIComponent(nonce), {headers:{'X-Requested-With':'fetch'}})
                .then(function(r){ return r.json(); })
                .then(function(p){
                  misses=0;
                  if(typeof p.pct==='number'){ serverPct=p.pct; if(p.pct>shownPct){ shownPct=p.pct; paint(); } }
                  if(p.label) lblEl.textContent=p.label;
                  // Note rassurante pendant l'étape SYSVOL (serveur figé à 65 % le temps du réalignement).
                  note.hidden = !((Date.now()-t0)>8000 && serverPct>=60 && serverPct<100);
                  if(p.done){ if(p.ok) succeed(); else fail(p.msg||'Le déploiement a échoué.'); }
                })
                .catch(function(){ if(++misses>15) fail('Perte de contact avec la passerelle.'); });
            }, 600);
          })
          .catch(function(){ fail('Lancement du déploiement impossible (réseau).'); });
      };
      document.addEventListener('submit', function(ev){
        var f=ev.target;
        if(!f.classList || !f.classList.contains('gpo-deploy-form')) return;
        ev.preventDefault();
        var title=f.getAttribute('data-title')||'la stratégie';
        if(confirm('Déployer « '+title+' » sur tout le domaine ?')) window.startGpoDeploy(f, title);
      });
    })();
    </script>

    <h3 style="font-size:.95rem;margin:1.4rem 0 .3rem">GPO déployées sur le domaine (<?= count($gpos) ?>)</h3>
    <p class="dir-help" style="margin:0 0 .8rem">Toutes les stratégies actuellement liées au domaine. Chaque carte indique
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
    <form method="post" class="dir-inline" style="margin-bottom:1rem">
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
          <?php $linked = in_array($guid, $gpoLinked, true); ?>
          <p class="dir-help">Identifiant : <code><?= e($g['guid'] ?? '—') ?></code> · État :
            <strong style="color:<?= $linked ? '#4ade80' : '#eab308' ?>"><?= $linked ? 'active (liée au domaine)' : 'désactivée (déliée)' ?></strong></p>
          <?php if (!$builtin): ?>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.7rem">
              <?php if ($linked): ?>
                <form method="post" style="margin:0" onsubmit="return confirm('Désactiver « <?= e($name) ?> » ?\n\nLa stratégie sera déliée du domaine et cessera de s\'appliquer aux postes. Elle n\'est PAS supprimée — vous pourrez la réactiver.')">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="gpo_unlink">
                  <input type="hidden" name="guid" value="<?= e($g['guid'] ?? '') ?>"><button class="btn-sm">⏸ Désactiver</button></form>
              <?php else: ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="gpo_link">
                  <input type="hidden" name="guid" value="<?= e($g['guid'] ?? '') ?>"><button class="btn-sm">▶ Réactiver</button></form>
              <?php endif; ?>
              <form method="post" style="margin:0" onsubmit="return confirm('SUPPRIMER définitivement « <?= e($name) ?> » ?\n\nCette action est IRRÉVERSIBLE : la stratégie et ses réglages sont effacés du domaine.')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="gpo_delete">
                <input type="hidden" name="guid" value="<?= e($g['guid'] ?? '') ?>"><button class="btn-sm btn-danger">🗑 Désinstaller</button></form>
            </div>
          <?php else: ?>
            <p class="dir-help" style="margin-bottom:.7rem">🔒 Stratégie système Windows — ni désactivable ni supprimable depuis la console.</p>
          <?php endif; ?>
          <?php if (!$builtin): $curF = $gpoWmi[$guid] ?? ''; ?>
            <form method="post" style="margin-bottom:.7rem;display:flex;gap:.4rem;flex-wrap:wrap;align-items:center">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="gpo_wmi">
              <input type="hidden" name="guid" value="<?= e($g['guid'] ?? '') ?>">
              <label style="font-size:.85rem">N'appliquer qu'aux postes :
                <select name="filtre" style="padding:.4rem;background:var(--card);color:var(--text);border:1px solid var(--line);border-radius:8px;font-size:.82rem">
                  <option value="">Tous les postes (aucune condition)</option>
                  <?php foreach ($WMI_FILTRES as $k => $lab): ?>
                    <option value="<?= e($k) ?>"<?= $curF === $k ? ' selected' : '' ?>><?= e($lab) ?></option>
                  <?php endforeach; ?>
                </select></label>
              <button class="btn-sm">Appliquer</button>
              <?php if ($curF !== ''): ?><span class="badge on">🎯 <?= e($WMI_FILTRES[$curF] ?? $curF) ?></span><?php endif; ?>
            </form>
          <?php endif; ?>
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
  // (les listes $customGroups / $sysGroups sont calculées plus haut : la section « Partages »
  //  en a besoin avant celle-ci pour proposer les groupes dans les droits.)
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
<section class="dir-sec panel">
  <div class="panel-head"><h2>🏷️ Groupes &amp; unités d'organisation</h2></div>
  <p class="lead" style="padding:0 1.2rem;margin:.7rem 0"><strong>Groupes</strong> : ensembles d'agents partageant
  des droits (ex. accès à un partage). <strong>Unités d'organisation (OU)</strong> : classement des comptes/postes
  par service ou site, pour y appliquer des GPO.</p>
  <div class="split" style="padding:0 1.2rem 1.2rem">
    <!-- Colonne GROUPES -->
    <div>
      <h3 style="font-size:.9rem;margin:.2rem 0 .5rem">👮 Vos groupes métier (<?= count($customGroups) ?>)</h3>
      <form method="post" class="dir-inline" style="margin-bottom:.7rem">
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
      <form method="post" class="dir-inline" style="margin-bottom:.7rem">
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
