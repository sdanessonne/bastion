<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Recherche fonctionnaire.
 * Recherche par matricule / nom / prénom / commissariat / service, fiche complète
 * (identité, comptes, postes de connexion) et historique de navigation daté.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();

function ad(...$args): string {
    $cmd = 'sudo /usr/local/sbin/proxyfibre-ad';
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
    return (string) shell_exec($cmd . ' 2>&1');
}
function ad_lines(...$args): array {
    return array_values(array_filter(array_map('trim', explode("\n", ad(...$args))), fn($l) => $l !== ''));
}
$dcUp = trim((string) shell_exec('systemctl is-active samba-ad-dc 2>/dev/null')) === 'active';

// ── Référentiels ─────────────────────────────────────────────────────────────
$sites = [];
foreach ($db->query('SELECT id,name,cpn FROM pf_commissariats ORDER BY cpn,name') as $r) {
    $sites[(int) $r['id']] = ['name' => (string) $r['name'], 'cpn' => (string) $r['cpn']];
}

// Annuaire des fonctionnaires (portail + profil + commissariat).
$agents = [];
$sql = 'SELECT u.username, pr.nom, pr.prenom, pr.service, st.commissariat_id
        FROM (SELECT username FROM radcheck WHERE attribute="Cleartext-Password"
              UNION SELECT username FROM pf_user_profile
              UNION SELECT username FROM pf_user_site) u
        LEFT JOIN pf_user_profile pr ON pr.username=u.username
        LEFT JOIN pf_user_site st ON st.username=u.username';
foreach ($db->query($sql) as $r) {
    $agents[(string) $r['username']] = [
        'username' => (string) $r['username'],
        'nom'      => (string) ($r['nom'] ?? ''),
        'prenom'   => (string) ($r['prenom'] ?? ''),
        'service'  => (string) ($r['service'] ?? ''),
        'site_id'  => (int) ($r['commissariat_id'] ?? 0),
    ];
}
// Comptes AD sans profil (pour ne rien manquer).
if ($dcUp) {
    foreach (ad_lines('user', 'list') as $x) {
        if (!isset($agents[$x]) && !in_array($x, ['Administrator', 'Guest', 'krbtgt'], true) && stripos($x, 'dns-') !== 0) {
            $agents[$x] = ['username' => $x, 'nom' => '', 'prenom' => '', 'service' => '', 'site_id' => 0];
        }
    }
}

// ── Recherche ────────────────────────────────────────────────────────────────
$q     = trim((string) ($_GET['q'] ?? ''));
$fSite = (int) ($_GET['site'] ?? 0);
$fServ = trim((string) ($_GET['service'] ?? ''));
$results = [];
if ($q !== '' || $fSite > 0 || $fServ !== '') {
    foreach ($agents as $a) {
        if ($fSite > 0 && $a['site_id'] !== $fSite) { continue; }
        if ($fServ !== '' && mb_stripos($a['service'], $fServ) === false) { continue; }
        if ($q !== '') {
            $hay = $a['username'] . ' ' . $a['nom'] . ' ' . $a['prenom'] . ' ' . $a['service']
                 . ' ' . ($sites[$a['site_id']]['name'] ?? '') . ' ' . ($sites[$a['site_id']]['cpn'] ?? '');
            if (mb_stripos($hay, $q) === false) { continue; }
        }
        $results[] = $a;
    }
    usort($results, fn($x, $y) => strcmp($x['nom'] . $x['username'], $y['nom'] . $y['username']));
}

// ── Fiche détaillée d'un fonctionnaire ───────────────────────────────────────
$sel = preg_replace('/[^A-Za-z0-9._@-]/', '', (string) ($_GET['u'] ?? ''));
$detail = null;
if ($sel !== '' && isset($agents[$sel])) {
    $a = $agents[$sel];

    // Comptes.
    $hasPortal = (bool) $db->query('SELECT 1 FROM radcheck WHERE username=' . $db->quote($sel) . ' AND attribute="Cleartext-Password"')->fetchColumn();
    $pgroup = (string) ($db->query('SELECT groupname FROM radusergroup WHERE username=' . $db->quote($sel) . ' LIMIT 1')->fetchColumn() ?: '');
    $hasAd  = $dcUp && in_array($sel, ad_lines('user', 'list'), true);
    $isCons = (bool) $db->query('SELECT 1 FROM pf_admins WHERE username=' . $db->quote($sel))->fetchColumn();

    // En ligne maintenant (portail).
    $onlineNow = null;
    foreach (nds_clients() as $mac => $c) {
        if (($c['state'] ?? '') === 'Authenticated' && !empty($c['custom'])
            && ($d = base64_decode($c['custom'], true)) && strpos($d, 'user=' . $sel) !== false) {
            $onlineNow = ['mac' => $mac, 'ip' => $c['ip'] ?? '', 'since' => (int) ($c['session_start'] ?? 0)];
            break;
        }
    }

    // Postes de domaine (AD) : depuis le journal d'authentification.
    $adPosts = [];
    if ($dcUp) {
        foreach (ad_lines('authlog') as $l) {
            $p = explode("\t", $l);
            if (count($p) >= 4 && $p[1] === $sel && $p[0] !== '') {
                $w = $p[0];
                if (!isset($adPosts[$w]) || $adPosts[$w]['ts'] < $p[3]) { $adPosts[$w] = ['ip' => $p[2], 'ts' => $p[3]]; }
            }
        }
        arsort($adPosts);
    }

    // Sessions portail (historique connexions).
    $sessions = [];
    $st = $db->prepare('SELECT mac,ip,event,ts FROM pf_connlog WHERE username=? ORDER BY ts DESC LIMIT 15');
    $st->execute([$sel]);
    $sessions = $st->fetchAll();

    $detail = compact('a', 'hasPortal', 'pgroup', 'hasAd', 'isCons', 'onlineNow', 'adPosts', 'sessions');

    // ── Historique de navigation (filtré par date/heure + domaine) ──
    $from = (string) ($_GET['from'] ?? '');
    $to   = (string) ($_GET['to'] ?? '');
    $dom  = trim((string) ($_GET['dom'] ?? ''));
    $fromSql = $from !== '' ? str_replace('T', ' ', $from) . ':00' : date('Y-m-d H:i:s', time() - 7 * 86400);
    $toSql   = $to   !== '' ? str_replace('T', ' ', $to)   . ':59' : date('Y-m-d H:i:s');
    $params = [$sel, $fromSql, $toSql];
    $where  = 'username=? AND ts BETWEEN ? AND ?';
    if ($dom !== '') { $where .= ' AND domain LIKE ?'; $params[] = '%' . $dom . '%'; }
    $cnt = $db->prepare("SELECT COUNT(*) FROM pf_weblog WHERE $where");
    $cnt->execute($params);
    $navTotal = (int) $cnt->fetchColumn();
    $navSt = $db->prepare("SELECT ts,domain,client_ip FROM pf_weblog WHERE $where ORDER BY ts DESC LIMIT 500");
    $navSt->execute($params);
    $nav = $navSt->fetchAll();
}

$fullName = function (array $a): string {
    $n = trim($a['nom'] . ' ' . $a['prenom']);
    return $n !== '' ? $n : $a['username'];
};

// ── Exports (CSV / rapport imprimable PDF) — avant toute sortie HTML ──────────
if ($detail) {
    $a = $detail['a'];
    $siteLbl = $a['site_id'] && isset($sites[$a['site_id']])
        ? $sites[$a['site_id']]['name'] . ' (' . $sites[$a['site_id']]['cpn'] . ')' : '—';
    $periode = str_replace('T', ' ', (string) ($_GET['from'] ?? substr($fromSql, 0, 16)))
        . ' au ' . str_replace('T', ' ', (string) ($_GET['to'] ?? substr($toSql, 0, 16)));
    $accts = array_filter([
        $detail['hasPortal'] ? 'Portail Internet' . ($detail['pgroup'] !== '' ? ' (' . $detail['pgroup'] . ')' : '') : '',
        $detail['hasAd'] ? 'Compte domaine' : '',
        $detail['isCons'] ? 'Administrateur console' : '',
    ]);

    // Historique complet (sans la limite d'affichage de 500) pour l'export.
    $allSt = $db->prepare("SELECT ts,domain,client_ip FROM pf_weblog WHERE $where ORDER BY ts DESC LIMIT 50000");
    $allSt->execute($params);
    $allNav = $allSt->fetchAll();
    $stamp = date('Y-m-d_His');

    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bastion-fiche-' . $a['username'] . '-' . $stamp . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        $sep = ';';
        fputcsv($out, ['Bastion — Fiche fonctionnaire (réquisition)'], $sep);
        fputcsv($out, ['Édité le', date('d/m/Y à H:i')], $sep);
        fputcsv($out, [], $sep);
        fputcsv($out, ['Matricule', $a['username']], $sep);
        fputcsv($out, ['Nom', $a['nom']], $sep);
        fputcsv($out, ['Prénom', $a['prenom']], $sep);
        fputcsv($out, ['Service', $a['service']], $sep);
        fputcsv($out, ['Commissariat', $siteLbl], $sep);
        fputcsv($out, ['Comptes', implode(' + ', $accts) ?: '—'], $sep);
        fputcsv($out, ['Période', $periode], $sep);
        fputcsv($out, ['Nombre de pages', (string) $navTotal], $sep);
        fputcsv($out, [], $sep);
        fputcsv($out, ['Postes Windows (domaine)'], $sep);
        foreach ($detail['adPosts'] as $w => $info) { fputcsv($out, [$w, $info['ip'], str_replace('T', ' ', substr($info['ts'], 0, 19))], $sep); }
        fputcsv($out, [], $sep);
        fputcsv($out, ['Date et heure', 'Domaine visité', 'Adresse IP'], $sep);
        foreach ($allNav as $w) { fputcsv($out, [$w['ts'], $w['domain'], $w['client_ip']], $sep); }
        fclose($out);
        exit;
    }

    if (isset($_GET['print'])) {
        header('Content-Type: text/html; charset=utf-8');
        $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Fiche ' . $esc($a['username']) . '</title>';
        echo '<style>
          @page{size:A4;margin:16mm}
          *{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;color:#111;font-size:12px;line-height:1.4;margin:0}
          .doc{max-width:800px;margin:0 auto;padding:10px}
          .hd{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0b3d91;padding-bottom:10px;margin-bottom:16px}
          .hd h1{margin:0;font-size:20px;color:#0b3d91}.hd .sub{color:#555;font-size:11px}
          .mention{background:#f3f4f6;border-left:4px solid #0b3d91;padding:6px 10px;font-size:11px;margin-bottom:16px}
          h2{font-size:13px;color:#0b3d91;border-bottom:1px solid #ccc;padding-bottom:3px;margin:18px 0 8px}
          table{width:100%;border-collapse:collapse;font-size:11px}
          .id td{padding:3px 6px}.id td:first-child{color:#666;width:150px;font-weight:600}
          .grid th,.grid td{border:1px solid #ccc;padding:4px 6px;text-align:left}
          .grid th{background:#0b3d91;color:#fff;font-weight:600}
          .grid tr:nth-child(even){background:#f6f8fc}
          .foot{margin-top:24px;border-top:1px solid #ccc;padding-top:8px;font-size:10px;color:#666;display:flex;justify-content:space-between}
          @media print{.noprint{display:none}}
          .btnbar{text-align:center;margin:14px 0}
          .btnbar button{padding:8px 18px;font-size:13px;background:#0b3d91;color:#fff;border:none;border-radius:6px;cursor:pointer}
        </style></head><body><div class="doc">';
        echo '<div class="btnbar noprint"><button onclick="window.print()">🖨 Imprimer / Enregistrer en PDF</button></div>';
        echo '<div class="hd"><div><h1>Bastion</h1><div class="sub">Passerelle sécurisée — Fiche fonctionnaire</div></div>'
           . '<div class="sub" style="text-align:right">Édité le ' . $esc(date('d/m/Y à H:i')) . '<br>par ' . $esc($_SESSION['admin'] ?? '') . '</div></div>';
        echo '<div class="mention">Document établi dans le cadre d\'une réquisition judiciaire / demande administrative. '
           . 'Données de connexion et de navigation conservées conformément à la réglementation en vigueur.</div>';
        echo '<h2>Identité</h2><table class="id">'
           . '<tr><td>Matricule</td><td><b>' . $esc($a['username']) . '</b></td></tr>'
           . '<tr><td>Nom</td><td>' . $esc($a['nom'] ?: '—') . '</td></tr>'
           . '<tr><td>Prénom</td><td>' . $esc($a['prenom'] ?: '—') . '</td></tr>'
           . '<tr><td>Service</td><td>' . $esc($a['service'] ?: '—') . '</td></tr>'
           . '<tr><td>Commissariat</td><td>' . $esc($siteLbl) . '</td></tr>'
           . '<tr><td>Comptes</td><td>' . $esc(implode(' + ', $accts) ?: '—') . '</td></tr></table>';
        if ($detail['adPosts']) {
            echo '<h2>Postes de connexion (domaine Windows)</h2><table class="grid"><tr><th>Poste</th><th>IP</th><th>Dernière connexion</th></tr>';
            foreach ($detail['adPosts'] as $w => $info) { echo '<tr><td>' . $esc($w) . '</td><td>' . $esc($info['ip']) . '</td><td>' . $esc(str_replace('T', ' ', substr($info['ts'], 0, 19))) . '</td></tr>'; }
            echo '</table>';
        }
        echo '<h2>Historique de navigation — ' . $esc($periode) . ' (' . number_format($navTotal, 0, ',', ' ') . ' page·s)</h2>';
        echo '<table class="grid"><tr><th style="width:150px">Date et heure</th><th>Domaine visité</th><th style="width:120px">IP</th></tr>';
        if (!$allNav) { echo '<tr><td colspan="3" style="text-align:center;color:#888">Aucune page sur la période.</td></tr>'; }
        foreach ($allNav as $w) { echo '<tr><td>' . $esc($w['ts']) . '</td><td>' . $esc($w['domain']) . '</td><td>' . $esc($w['client_ip']) . '</td></tr>'; }
        echo '</table>';
        echo '<div class="foot"><span>Bastion — document confidentiel</span><span>' . $esc(date('d/m/Y H:i')) . '</span></div>';
        echo '</div><script>window.addEventListener("load",function(){setTimeout(function(){window.print();},400);});</script></body></html>';
        exit;
    }

    // ── Fiche d'habilitation signée numériquement (dossier individuel) ──
    if (isset($_GET['habilitation'])) {
        header('Content-Type: text/html; charset=utf-8');
        $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $isDom = $dcUp && in_array($sel, ad_lines('group', 'listmembers', 'Domain Admins'), true);
        $hab = [
            'portal' => $detail['hasPortal'] ? 1 : 0,
            'ad'     => $detail['hasAd'] ? 1 : 0,
            'adm'    => $detail['isCons'] ? 1 : 0,
            'dom'    => $isDom ? 1 : 0,
        ];
        $now  = date('Y-m-d\TH:i:s');
        $ref  = 'HAB-' . $a['username'] . '-' . date('Ymd-His');
        $emet = (string) ($_SESSION['admin'] ?? '');
        // Charge utile canonique signée (ordre figé).
        $payload = implode('|', ['BASTION-HAB', $ref, $a['username'], $a['nom'], $a['prenom'], $a['service'],
            $siteLbl, 'portal:' . $hab['portal'], 'ad:' . $hab['ad'], 'adm:' . $hab['adm'], 'dom:' . $hab['dom'], $now, $emet]);
        $hash = strtoupper(hash('sha256', $payload));
        // Signature via l'autorité locale (clé privée root).
        $tmp = tempnam(sys_get_temp_dir(), 'hab'); file_put_contents($tmp, $payload);
        $sig = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-habilitation sign < ' . escapeshellarg($tmp) . ' 2>/dev/null'));
        @unlink($tmp);
        $fp  = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-habilitation fingerprint 2>/dev/null'));
        // QR : charge + signature (auto-vérifiable).
        $qrPayload = $payload . '||' . $sig;
        $qrPng = shell_exec('printf %s ' . escapeshellarg($qrPayload) . ' | qrencode -m 1 -s 3 -o - -t PNG 2>/dev/null');
        $qr = $qrPng ? 'data:image/png;base64,' . base64_encode($qrPng) : '';
        $sigWrap = trim(chunk_split($sig, 64, "\n"));
        $li = fn($ok, $label, $extra = '') => '<tr><td class="hab-ok">' . ($ok ? '☑' : '☐') . '</td><td>' . $esc($label)
            . ($extra !== '' ? ' <span style="color:#555">' . $esc($extra) . '</span>' : '') . '</td><td style="text-align:right;font-weight:600;color:' . ($ok ? '#0a7a3f' : '#999') . '">' . ($ok ? 'ACCORDÉE' : 'non accordée') . '</td></tr>';

        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Habilitation ' . $esc($a['username']) . '</title>';
        echo '<style>
          @page{size:A4;margin:15mm}
          *{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;color:#111;font-size:12px;line-height:1.45;margin:0}
          .doc{max-width:800px;margin:0 auto;padding:10px}
          .hd{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0b3d91;padding-bottom:10px}
          .hd h1{margin:0;font-size:15px;color:#0b3d91;letter-spacing:.5px}.hd h1 small{display:block;font-size:22px;font-weight:800}
          .hd .ref{text-align:right;font-size:11px;color:#333}.hd .ref b{color:#0b3d91}
          h2{font-size:13px;color:#0b3d91;border-bottom:1px solid #ccc;padding-bottom:3px;margin:18px 0 8px}
          table{width:100%;border-collapse:collapse;font-size:12px}
          .id td{padding:3px 6px;vertical-align:top}.id td:first-child{color:#666;width:150px;font-weight:600}
          .hab th,.hab td{border:1px solid #ccc;padding:6px 8px;text-align:left}
          .hab th{background:#0b3d91;color:#fff}.hab .hab-ok{width:26px;text-align:center;font-size:15px}
          .engage{font-size:11px;background:#f6f8fc;border:1px solid #d6deef;border-radius:6px;padding:8px 10px;margin-top:8px}
          .sigs{display:flex;gap:20px;margin-top:22px}.sigs .box{flex:1;border:1px solid #bbb;border-radius:6px;padding:8px 10px;min-height:90px;font-size:11px}
          .sigs .box b{color:#0b3d91}
          .seal{margin-top:22px;border:2px solid #0b3d91;border-radius:8px;padding:12px 14px;display:flex;gap:16px}
          .seal .qr{flex:0 0 auto}.seal .qr img{width:120px;height:120px;image-rendering:pixelated}
          .seal h3{margin:0 0 6px;font-size:12px;color:#0b3d91}
          .seal .kv{font-size:10px;color:#333;word-break:break-all}.seal .kv b{color:#000}
          .seal .sig{font-family:"Cascadia Code",Consolas,monospace;font-size:8.5px;color:#444;white-space:pre-wrap;line-height:1.25;margin-top:4px;max-height:78px;overflow:hidden}
          .foot{margin-top:16px;border-top:1px solid #ccc;padding-top:8px;font-size:9.5px;color:#666;display:flex;justify-content:space-between}
          .noprint{text-align:center;margin:14px 0}.noprint button{padding:8px 18px;font-size:13px;background:#0b3d91;color:#fff;border:none;border-radius:6px;cursor:pointer}
          @media print{.noprint{display:none}}
        </style></head><body><div class="doc">';
        echo '<div class="noprint"><button onclick="window.print()">🖨 Imprimer / Enregistrer en PDF</button></div>';
        echo '<div class="hd"><div><h1>BASTION<small>FICHE D\'HABILITATION</small>Accès aux systèmes d\'information</h1></div>'
           . '<div class="ref">Réf. <b>' . $esc($ref) . '</b><br>Établie le ' . $esc(date('d/m/Y à H:i')) . '<br>par ' . $esc($emet) . '</div></div>';

        echo '<h2>Identité du fonctionnaire</h2><table class="id">'
           . '<tr><td>Matricule</td><td><b>' . $esc($a['username']) . '</b></td></tr>'
           . '<tr><td>Nom / Prénom</td><td>' . $esc(trim($a['nom'] . ' ' . $a['prenom']) ?: '—') . '</td></tr>'
           . '<tr><td>Service</td><td>' . $esc($a['service'] ?: '—') . '</td></tr>'
           . '<tr><td>Commissariat</td><td>' . $esc($siteLbl) . '</td></tr></table>';

        echo '<h2>Habilitations accordées</h2><table class="hab"><tr><th style="width:26px"></th><th>Droit / accès</th><th style="width:120px;text-align:right">Statut</th></tr>';
        echo $li($hab['portal'], 'Accès Internet (portail captif)', $detail['pgroup'] !== '' ? '— groupe ' . $detail['pgroup'] : '');
        echo $li($hab['ad'], 'Compte de domaine — ouverture de session Windows');
        echo $li($hab['adm'], 'Administrateur de la console d\'administration Bastion');
        echo $li($hab['dom'], 'Administrateur du domaine Active Directory');
        echo '</table>';
        echo '<div class="engage">Le fonctionnaire reconnaît avoir pris connaissance de la charte d\'utilisation des '
           . 'moyens informatiques et s\'engage à un usage professionnel et confidentiel des accès qui lui sont attribués. '
           . 'Toute activité est journalisée conformément à la réglementation en vigueur.</div>';

        echo '<div class="sigs"><div class="box"><b>Le fonctionnaire</b><br>' . $esc(trim($a['nom'] . ' ' . $a['prenom']))
           . '<br><span style="color:#888">Date et signature :</span></div>'
           . '<div class="box"><b>Le responsable hiérarchique</b><br><span style="color:#888">Nom, date et signature :</span></div></div>';

        echo '<div class="seal">';
        if ($qr) { echo '<div class="qr"><img src="' . $esc($qr) . '" alt="QR vérification"></div>'; }
        echo '<div style="flex:1"><h3>🔏 Cachet électronique — signature numérique</h3>'
           . '<div class="kv"><b>Autorité :</b> Bastion — Autorité d\'Habilitation (RSA-2048 / SHA-256)</div>'
           . '<div class="kv"><b>Empreinte du certificat :</b> ' . $esc($fp) . '</div>'
           . '<div class="kv"><b>Empreinte du document (SHA-256) :</b> ' . $esc($hash) . '</div>'
           . '<div class="kv"><b>Horodatage :</b> ' . $esc(date('d/m/Y H:i:s')) . ' (' . $esc(date_default_timezone_get()) . ')</div>'
           . '<div class="kv"><b>Signature :</b></div><div class="sig">' . $esc($sigWrap) . '</div>'
           . '</div></div>';
        echo '<div class="foot"><span>Document authentifiable — vérification par le certificat Bastion. Toute altération invalide la signature.</span><span>' . $esc($ref) . '</span></div>';
        echo '</div><script>window.addEventListener("load",function(){setTimeout(function(){window.print();},500);});</script></body></html>';
        exit;
    }
}

// ── Vérification d'un cachet électronique (page dédiée) ───────────────────────
if (isset($_GET['verifier'])) {
    $vres = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seal'])) {
        csrf_check();
        $seal = (string) $_POST['seal'];
        $pos = strpos($seal, '||');
        if ($pos !== false) {
            $pl = substr($seal, 0, $pos);
            $sg = trim(substr($seal, $pos + 2));
            $tmp = tempnam(sys_get_temp_dir(), 'hv'); file_put_contents($tmp, $pl);
            $out = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-habilitation verify ' . escapeshellarg($sg) . ' < ' . escapeshellarg($tmp) . ' 2>/dev/null'));
            @unlink($tmp);
            $vres = ['ok' => $out === 'VALID', 'payload' => $pl];
        } else { $vres = ['ok' => false, 'payload' => '', 'err' => 'Format de cachet invalide (séparateur « || » absent).']; }
    }
    pf_header('Vérifier une habilitation', 'recherche.php');
    ?>
    <section class="panel" style="max-width:720px">
      <div class="panel-head"><h2>🔏 Vérifier un cachet électronique</h2></div>
      <div style="padding:1.2rem">
        <p class="muted small" style="margin-top:0">Collez le contenu du QR code d'une fiche d'habilitation
          (charge <code>||</code> signature) pour contrôler son authenticité.</p>
        <?php if ($vres): ?>
          <div class="flash <?= $vres['ok'] ? 'ok' : 'err' ?>" style="margin-bottom:1rem">
            <?= $vres['ok'] ? '✅ Signature VALIDE — document authentique et non altéré.' : '⛔ Signature INVALIDE' . (isset($vres['err']) ? ' — ' . e($vres['err']) : ' — document altéré ou non émis par cette passerelle.') ?>
          </div>
          <?php if ($vres['ok'] && $vres['payload'] !== ''): $f = explode('|', $vres['payload']); ?>
            <table class="grid-table" style="border:1px solid var(--line);border-radius:8px">
              <tr><td class="muted">Référence</td><td><strong><?= e($f[1] ?? '') ?></strong></td></tr>
              <tr><td class="muted">Matricule</td><td><?= e($f[2] ?? '') ?></td></tr>
              <tr><td class="muted">Nom / Prénom</td><td><?= e(trim(($f[3] ?? '') . ' ' . ($f[4] ?? ''))) ?></td></tr>
              <tr><td class="muted">Service</td><td><?= e($f[5] ?? '') ?></td></tr>
              <tr><td class="muted">Commissariat</td><td><?= e($f[6] ?? '') ?></td></tr>
              <tr><td class="muted">Émise le</td><td><?= e(str_replace('T', ' ', $f[11] ?? '')) ?> par <?= e($f[12] ?? '') ?></td></tr>
            </table>
          <?php endif; ?>
        <?php endif; ?>
        <form method="post" action="/recherche.php?verifier=1">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <textarea name="seal" rows="6" placeholder="BASTION-HAB|…||signature…" style="width:100%;padding:.7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px;font-family:monospace;font-size:.82rem"><?= e($_POST['seal'] ?? '') ?></textarea>
          <div style="margin-top:.8rem"><button class="btn">Vérifier</button>
            <a class="btn-sm" href="/recherche.php" style="margin-left:.4rem">← Retour</a></div>
        </form>
      </div>
    </section>
    <?php
    pf_footer();
    exit;
}

pf_header('Recherche fonctionnaire', 'recherche.php');
?>
<style>
  .search-bar{display:flex;gap:.7rem;flex-wrap:wrap;align-items:end;padding:1.2rem}
  .search-bar .fld{display:grid;gap:.3rem;font-size:.78rem;color:var(--muted)}
  .search-bar input,.search-bar select{padding:.6rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px;font-size:.9rem}
  .search-bar .big{min-width:280px;flex:1}
  .idcard{display:grid;grid-template-columns:auto 1fr;gap:1.2rem;align-items:center;padding:1.3rem}
  .idavatar{width:64px;height:64px;border-radius:50%;background:var(--accent2);color:#052536;display:grid;place-items:center;font-size:1.6rem;font-weight:800}
  .idmeta{display:flex;gap:1.6rem;flex-wrap:wrap;margin-top:.5rem;font-size:.9rem}
  .idmeta b{color:var(--muted);font-weight:500;display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.03em}
  .rbadge{display:inline-block;font-size:.72rem;padding:.15rem .55rem;border-radius:20px;margin-right:.3rem}
  .r-portal{background:rgba(56,189,248,.15);color:#38bdf8}.r-ad{background:rgba(74,222,128,.15);color:#4ade80}
  .r-adm{background:rgba(234,179,8,.18);color:#eab308}.r-site{background:rgba(168,139,250,.18);color:#a78bfa}
  .subgrid{display:grid;grid-template-columns:1fr 1fr;gap:1.4rem}@media(max-width:900px){.subgrid{grid-template-columns:1fr}}
</style>

<section class="panel">
  <div class="panel-head"><h2>🔎 Rechercher un fonctionnaire</h2></div>
  <form method="get" class="search-bar">
    <label class="fld big">Matricule, nom, prénom…
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="0110480, DUPONT, Jean…" autofocus></label>
    <label class="fld">Commissariat
      <select name="site"><option value="0">— Tous —</option>
        <?php foreach ($sites as $id => $s): ?><option value="<?= $id ?>"<?= $fSite === $id ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select></label>
    <label class="fld">Service
      <input type="text" name="service" value="<?= e($fServ) ?>" placeholder="ex. BAC"></label>
    <button class="btn">Rechercher</button>
    <?php if ($q !== '' || $fSite || $fServ !== '' || $sel !== ''): ?><a class="btn-sm" href="/recherche.php">Réinitialiser</a><?php endif; ?>
  </form>

  <?php if ($q !== '' || $fSite > 0 || $fServ !== ''): ?>
  <div class="table-wrap">
    <table class="grid-table">
      <thead><tr><th>Matricule</th><th>Nom / Prénom</th><th>Service</th><th>Commissariat</th><th></th></tr></thead>
      <tbody>
      <?php if (!$results): ?><tr><td colspan="5" class="muted center">Aucun fonctionnaire trouvé.</td></tr>
      <?php else: foreach ($results as $a): ?>
        <tr>
          <td class="mono"><strong><?= e($a['username']) ?></strong></td>
          <td><?= e(trim($a['nom'] . ' ' . $a['prenom'])) ?: '<span class="muted">—</span>' ?></td>
          <td><?= e($a['service']) ?: '<span class="muted">—</span>' ?></td>
          <td><?php if ($a['site_id'] && isset($sites[$a['site_id']])): ?><span class="rbadge r-site">🏢 <?= e($sites[$a['site_id']]['name']) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td class="row-actions"><a class="btn-sm" href="/recherche.php?u=<?= urlencode($a['username']) ?>">Voir la fiche →</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <p class="muted small" style="padding:.4rem 1.2rem 1rem"><?= count($results) ?> résultat(s).</p>
  <?php endif; ?>
</section>

<?php if ($detail): $a = $detail['a']; ?>
<section class="panel" style="margin-top:1.4rem">
  <div class="panel-head"><h2>👮 Fiche fonctionnaire</h2>
    <?php if ($detail['onlineNow']): ?><span class="badge on">En ligne</span><?php else: ?><span class="badge off">Hors ligne</span><?php endif; ?>
  </div>
  <div class="idcard">
    <div class="idavatar"><?= e(mb_strtoupper(mb_substr($a['nom'] !== '' ? $a['nom'] : $a['username'], 0, 1))) ?></div>
    <div>
      <div style="font-size:1.35rem;font-weight:700"><?= e($fullName($a)) ?></div>
      <div class="idmeta">
        <div><b>Matricule</b><?= e($a['username']) ?></div>
        <div><b>Service</b><?= e($a['service']) ?: '—' ?></div>
        <div><b>Commissariat</b><?= $a['site_id'] && isset($sites[$a['site_id']]) ? e($sites[$a['site_id']]['name']) . ' <span class="muted">(' . e($sites[$a['site_id']]['cpn']) . ')</span>' : '—' ?></div>
      </div>
      <div style="margin-top:.7rem">
        <?php if ($detail['hasPortal']): ?><span class="rbadge r-portal">🌐 Portail<?= $detail['pgroup'] !== '' ? ' · ' . e($detail['pgroup']) : '' ?></span><?php endif; ?>
        <?php if ($detail['hasAd']): ?><span class="rbadge r-ad">🗄️ Domaine</span><?php endif; ?>
        <?php if ($detail['isCons']): ?><span class="rbadge r-adm">Admin console</span><?php endif; ?>
      </div>
      <div style="margin-top:.9rem;display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn" href="/recherche.php?u=<?= urlencode($a['username']) ?>&habilitation=1" target="_blank">📋 Fiche d'habilitation (signée)</a>
        <a class="btn-sm" href="/users.php?edit=<?= urlencode($a['username']) ?>">Modifier le compte</a>
        <a class="btn-sm" href="/recherche.php?verifier=1">🔏 Vérifier un cachet</a>
      </div>
    </div>
  </div>
</section>

<section class="panel" style="margin-top:1.4rem">
  <div class="panel-head"><h2>💻 Postes de connexion</h2></div>
  <div class="subgrid" style="padding:1.2rem">
    <div>
      <h3 style="font-size:.9rem;margin:0 0 .6rem">🗄️ Sessions Windows (domaine)</h3>
      <?php if (!empty($detail['adPosts'])): ?>
      <table class="grid-table" style="font-size:.85rem;border:1px solid var(--line);border-radius:8px">
        <thead><tr><th>Poste</th><th>IP</th><th>Dernière connexion</th></tr></thead>
        <tbody><?php foreach ($detail['adPosts'] as $w => $info): ?>
          <tr><td class="mono"><strong><?= e($w) ?></strong></td><td class="mono muted"><?= e($info['ip']) ?></td><td class="muted"><?= e(str_replace('T', ' ', substr($info['ts'], 0, 19))) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table>
      <?php else: ?><p class="muted small">Aucune ouverture de session Windows enregistrée.</p><?php endif; ?>
    </div>
    <div>
      <h3 style="font-size:.9rem;margin:0 0 .6rem">🌐 Sessions portail captif</h3>
      <?php if ($detail['onlineNow']): ?>
        <p class="small" style="margin:0 0 .6rem"><span class="badge on">Connecté</span>
          IP <span class="mono"><?= e($detail['onlineNow']['ip']) ?></span> · MAC <span class="mono"><?= e($detail['onlineNow']['mac']) ?></span></p>
      <?php endif; ?>
      <?php if (!empty($detail['sessions'])): ?>
      <table class="grid-table" style="font-size:.85rem;border:1px solid var(--line);border-radius:8px">
        <thead><tr><th>Date</th><th>Événement</th><th>IP / MAC</th></tr></thead>
        <tbody><?php foreach ($detail['sessions'] as $s): ?>
          <tr><td class="muted"><?= e($s['ts']) ?></td><td><?= e($s['event']) ?></td><td class="mono muted"><?= e($s['ip']) ?><?= $s['mac'] ? ' · ' . e($s['mac']) : '' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table>
      <?php else: ?><p class="muted small">Aucune session portail enregistrée.</p><?php endif; ?>
    </div>
  </div>
</section>

<?php $qs = http_build_query(array_filter(['u' => $sel, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'dom' => $_GET['dom'] ?? ''])); ?>
<section class="panel" style="margin-top:1.4rem">
  <div class="panel-head"><h2>🌐 Historique de navigation</h2>
    <div style="display:flex;gap:.5rem;align-items:center">
      <span class="muted small"><?= number_format($navTotal, 0, ',', ' ') ?> page(s)</span>
      <a class="btn-sm" href="/recherche.php?<?= e($qs) ?>&export=csv">⤓ CSV</a>
      <a class="btn-sm" href="/recherche.php?<?= e($qs) ?>&print=1" target="_blank">🖨 PDF</a>
    </div>
  </div>
  <form method="get" class="search-bar" style="border-bottom:1px solid var(--line)">
    <input type="hidden" name="u" value="<?= e($sel) ?>">
    <label class="fld">Du<input type="datetime-local" name="from" value="<?= e($_GET['from'] ?? date('Y-m-d\TH:i', time() - 7 * 86400)) ?>"></label>
    <label class="fld">Au<input type="datetime-local" name="to" value="<?= e($_GET['to'] ?? date('Y-m-d\TH:i')) ?>"></label>
    <label class="fld">Domaine contient
      <input type="text" name="dom" value="<?= e($_GET['dom'] ?? '') ?>" placeholder="ex. facebook"></label>
    <button class="btn">Filtrer</button>
  </form>
  <div class="table-wrap">
    <table class="grid-table" style="font-size:.86rem">
      <thead><tr><th style="width:180px">Date &amp; heure</th><th>Domaine visité</th><th>Depuis (IP)</th></tr></thead>
      <tbody>
      <?php if (empty($nav)): ?><tr><td colspan="3" class="muted center">Aucune page sur cette période.</td></tr>
      <?php else: foreach ($nav as $w): ?>
        <tr><td class="muted"><?= e($w['ts']) ?></td><td><?= e($w['domain']) ?></td><td class="mono muted"><?= e($w['client_ip']) ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($navTotal > 500): ?><p class="muted small" style="padding:.4rem 1.2rem 1rem">Affichage limité aux 500 dernières pages — affinez la période ou le domaine.</p><?php endif; ?>
</section>
<?php endif; ?>
<?php pf_footer(); ?>
