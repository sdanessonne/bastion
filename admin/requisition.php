<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Réquisitions judiciaires & administratives.
 * Rassemble toute la traçabilité légale détenue par la passerelle sur une cible
 * (agent/matricule, adresse IP, adresse MAC, domaine, ou période) et produit :
 *   1) un dossier VISUEL à l'écran ;
 *   2) un dossier PDF horodaté, SIGNÉ électroniquement (CMS/PKCS#7 détaché émis par
 *      l'AC Bastion) et livré en archive ZIP avec les éléments de vérification.
 * Chaque extraction est elle-même journalisée (table pf_requisitions).
 */
require_once __DIR__ . '/inc/auth.php';
$db = pf_db();

// ── Utilitaires de collecte ──────────────────────────────────────────────────
function req_norm_dt(string $v, string $def): string
{
    $v = trim($v);
    if ($v === '') { return $def; }
    $v = str_replace('T', ' ', $v);
    if (strlen($v) === 16) { $v .= ':00'; }
    return $v;
}
/** Compte + récupère (borné) les lignes d'une table filtrée. */
function req_q(PDO $db, string $table, string $where, string $order, array $params, int $cap, ?int &$total): array
{
    $c = $db->prepare("SELECT COUNT(*) FROM $table WHERE $where");
    $c->execute($params); $total = (int) $c->fetchColumn();
    $s = $db->prepare("SELECT * FROM $table WHERE $where $order LIMIT " . (int) $cap);
    $s->execute($params);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
/** Identité des agents concernés (jointure profil + commissariat). */
function req_identity(PDO $db, array $usernames): array
{
    $out = []; $seen = [];
    foreach ($usernames as $u) {
        $u = (string) $u;
        if ($u === '' || stripos($u, 'non authentif') !== false || isset($seen[$u])) { continue; }
        $seen[$u] = true;
        $base = preg_replace('/^admin-/', '', $u);
        $st = $db->prepare('SELECT p.nom,p.prenom,p.service,c.name AS comm,c.cpn
            FROM pf_user_profile p
            LEFT JOIN pf_user_site s ON s.username=p.username
            LEFT JOIN pf_commissariats c ON c.id=s.commissariat_id
            WHERE p.username IN (?,?) LIMIT 1');
        $st->execute([$u, $base]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out[] = [
            'username' => $u, 'matricule' => $base,
            'nom' => $r['nom'] ?? '', 'prenom' => $r['prenom'] ?? '',
            'service' => $r['service'] ?? '', 'commissariat' => $r['comm'] ?? '', 'cpn' => $r['cpn'] ?? '',
        ];
    }
    return $out;
}
/**
 * État du scellement des journaux sur la période requise.
 *
 * Ne porte QUE sur les journées écoulées : le jour en cours n'est pas encore scellé
 * (des enregistrements s'y ajoutent), et l'annoncer comme non scellé sans le dire
 * laisserait croire à une anomalie.
 *
 * @return array{nb:int,total:int,verdict:string,note:string}
 */
function req_seal_status(PDO $db, string $from, string $to): array
{
    require_once __DIR__ . '/inc/logseal.php';
    try { seal_schema($db); } catch (Throwable $e) { }

    $d1 = date('Y-m-d', strtotime($from));
    $d2 = min(date('Y-m-d', strtotime($to)), date('Y-m-d', strtotime('-1 day')));
    if ($d2 < $d1) {
        return ['nb' => 0, 'total' => 0, 'verdict' => 'Sans objet',
                'note' => "La période demandée porte sur la journée en cours : le scellement quotidien n'intervient qu'après la clôture du jour."];
    }
    $total = (int) ((strtotime($d2) - strtotime($d1)) / 86400) + 1;

    $st = $db->prepare("SELECT * FROM pf_log_seal WHERE day BETWEEN ? AND ? ORDER BY day");
    $st->execute([$d1, $d2]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $ok = true; $alt = [];
    foreach ($rows as $r) {
        $calc = seal_digest_for_day($db, $r['day']);
        $good = hash_equals($r['digest'], $calc['digest'])
             && hash_equals($r['seal'], seal_compute($r['day'], $r['digest'], $r['prev_seal']))
             && seal_verify_signature($r['seal'], $r['signature']);
        if (!$good) { $ok = false; $alt[] = $r['day']; }
    }

    $nb = count($rows);
    if ($nb === 0) {
        return ['nb' => 0, 'total' => $total, 'verdict' => 'AUCUN SCELLÉ',
                'note' => "Aucune journée de la période n'est scellée : l'intégrité des journaux ne peut pas être attestée pour cet intervalle."];
    }
    if (!$ok) {
        return ['nb' => $nb, 'total' => $total, 'verdict' => 'ALTÉRATION DÉTECTÉE',
                'note' => "Les journaux des journées suivantes ne correspondent plus à leur scellé d'origine : "
                        . implode(', ', $alt) . ". Leur contenu a été modifié après enregistrement."];
    }
    $note = "Chaque journée est scellée par une empreinte SHA-256 chaînée à celle de la veille et signée par l'Autorité de "
          . "certification Bastion. Le contrôle confirme que les journaux de la période n'ont pas été modifiés depuis leur "
          . "enregistrement : toute suppression ou altération d'une ligne rompt l'empreinte du jour, et la resigner exigerait "
          . "la clé privée de l'Autorité.";
    if ($nb < $total) {
        $note .= ' ' . ($total - $nb) . " journée(s) de la période ne sont pas scellées (passerelle hors service, ou antérieures "
               . "à la mise en place du scellement) : l'intégrité n'est pas attestée pour celles-là.";
    }
    return ['nb' => $nb, 'total' => $total,
            'verdict' => $nb === $total ? 'Intègre — aucune altération' : 'Intègre sur les journées scellées', 'note' => $note];
}

function req_collect(PDO $db, string $type, string $val, string $from, string $to): array
{
    $S = []; $W = []; $sc = 0; $wc = 0; $capS = 3000; $capW = 10000; $bt = [$from, $to];
    $ph = fn($a) => implode(',', array_fill(0, count($a), '?'));
    if ($type === 'agent') {
        $cand = [$val];
        if (preg_match('/^\d{6,8}$/', $val)) { $cand[] = 'admin-' . $val; }
        $cand = array_values(array_unique($cand)); $p = $ph($cand);
        $S = req_q($db, 'pf_connlog', "username IN ($p) AND ts BETWEEN ? AND ?", 'ORDER BY ts', array_merge($cand, $bt), $capS, $sc);
        $W = req_q($db, 'pf_weblog',  "username IN ($p) AND ts BETWEEN ? AND ?", 'ORDER BY ts', array_merge($cand, $bt), $capW, $wc);
    } elseif ($type === 'ip') {
        $S = req_q($db, 'pf_connlog', 'ip=? AND ts BETWEEN ? AND ?',        'ORDER BY ts', [$val, $from, $to], $capS, $sc);
        $W = req_q($db, 'pf_weblog',  'client_ip=? AND ts BETWEEN ? AND ?', 'ORDER BY ts', [$val, $from, $to], $capW, $wc);
    } elseif ($type === 'mac') {
        $S = req_q($db, 'pf_connlog', 'mac=? AND ts BETWEEN ? AND ?', 'ORDER BY ts', [$val, $from, $to], $capS, $sc);
        $ips = array_values(array_unique(array_filter(array_map(fn($r) => $r['ip'], $S))));
        if ($ips) { $p = $ph($ips); $W = req_q($db, 'pf_weblog', "client_ip IN ($p) AND ts BETWEEN ? AND ?", 'ORDER BY ts', array_merge($ips, $bt), $capW, $wc); }
    } elseif ($type === 'domaine') {
        $W = req_q($db, 'pf_weblog', 'domain LIKE ? AND ts BETWEEN ? AND ?', 'ORDER BY ts', ['%' . $val . '%', $from, $to], $capW, $wc);
        $us = array_values(array_unique(array_filter(array_map(fn($r) => $r['username'], $W), fn($u) => $u !== '' && stripos($u, 'non authentif') === false)));
        if ($us) { $p = $ph($us); $S = req_q($db, 'pf_connlog', "username IN ($p) AND ts BETWEEN ? AND ?", 'ORDER BY ts', array_merge($us, $bt), $capS, $sc); }
    } else { // periode
        $S = req_q($db, 'pf_connlog', 'ts BETWEEN ? AND ?', 'ORDER BY ts', [$from, $to], $capS, $sc);
        $W = req_q($db, 'pf_weblog',  'ts BETWEEN ? AND ?', 'ORDER BY ts', [$from, $to], $capW, $wc);
    }
    $users = array_merge(array_map(fn($r) => $r['username'], $S), array_map(fn($r) => $r['username'], $W));
    return ['sessions' => $S, 'web' => $W, 'sessCount' => $sc, 'webCount' => $wc, 'subjects' => req_identity($db, $users)];
}
function req_fmt_bytes($n): string
{
    $n = (float) $n; $u = ['o', 'Ko', 'Mo', 'Go', 'To']; $i = 0;
    while ($n >= 1024 && $i < 4) { $n /= 1024; $i++; }
    return number_format($n, $i ? 1 : 0, ',', ' ') . ' ' . $u[$i];
}
function req_fmt_dur($s): string
{
    $s = (int) $s; if ($s <= 0) { return '—'; }
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $x = $s % 60;
    return ($h ? $h . 'h ' : '') . ($m ? $m . 'm ' : '') . $x . 's';
}
$TYPE_LABEL = ['agent' => 'Agent (matricule / identifiant)', 'ip' => 'Adresse IP', 'mac' => 'Adresse MAC (matériel)', 'domaine' => 'Domaine consulté', 'periode' => 'Période seule (toute activité)'];

// ── Paramètres de la requête ─────────────────────────────────────────────────
$type   = $_POST['type']   ?? $_GET['type']   ?? 'agent';
if (!isset($TYPE_LABEL[$type])) { $type = 'agent'; }
$val    = trim((string) ($_POST['val'] ?? $_GET['val'] ?? ''));
$from   = req_norm_dt((string) ($_POST['from'] ?? ''), date('Y-m-d H:i:s', time() - 30 * 86400));
$to     = req_norm_dt((string) ($_POST['to'] ?? ''), date('Y-m-d H:i:s'));
$action = $_POST['action'] ?? '';
$meta = [
    'num'       => trim((string) ($_POST['num'] ?? '')),
    'autorite'  => trim((string) ($_POST['autorite'] ?? '')),
    'cadre'     => trim((string) ($_POST['cadre'] ?? '')),
    'requerant' => trim((string) ($_POST['requerant'] ?? '')),
    'motif'     => trim((string) ($_POST['motif'] ?? '')),
];
$needVal = $type !== 'periode';
$hasQuery = ($action === 'search' || $action === 'export') && (!$needVal || $val !== '');

// ═══ EXPORT : PDF signé + ZIP (avant toute sortie HTML) ═══════════════════════
if ($action === 'export' && $hasQuery) {
    csrf_check();
    $data = req_collect($db, $type, $val, $from, $to);
    $sha  = hash('sha256', json_encode([$meta, $type, $val, $from, $to, $data['subjects'], $data['sessions'], $data['web']], JSON_UNESCAPED_UNICODE));

    require_once __DIR__ . '/lib/fpdf/fpdf.php';

    class ReqPDF extends FPDF
    {
        public array $meta = [];
        public string $realm = '';
        public function w2(string $s): string { $r = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s); return $r === false ? $s : $r; }
        public function Header(): void
        {
            $this->SetFont('Helvetica', 'B', 9); $this->SetTextColor(70, 90, 120);
            $this->Cell(120, 5, $this->w2("BASTION — Contrôle d'accès réseau"), 0, 0, 'L');
            $this->Cell(70, 5, $this->w2('Réquisition ' . ($this->meta['num'] ?: '—')), 0, 1, 'R');
            $this->SetDrawColor(180, 195, 215); $this->SetLineWidth(0.3); $this->Line(10, 17, 200, 17);
            $this->SetTextColor(0, 0, 0); $this->Ln(8);
        }
        public function Footer(): void
        {
            $this->SetY(-14); $this->SetFont('Helvetica', 'I', 7.5); $this->SetTextColor(120, 120, 120);
            $this->Cell(120, 5, $this->w2('Document confidentiel — communication réservée à l’autorité requérante'), 0, 0, 'L');
            $this->Cell(70, 5, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
        }
        public function h1(string $t): void
        {
            $this->Ln(2); $this->SetFont('Helvetica', 'B', 12); $this->SetFillColor(30, 58, 95); $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 8, '  ' . $this->w2($t), 0, 1, 'L', true); $this->SetTextColor(0, 0, 0); $this->Ln(2);
        }
        public function kv(string $k, string $v): void
        {
            $this->SetFont('Helvetica', 'B', 10); $this->Cell(52, 6, $this->w2($k), 0, 0, 'L');
            $this->SetFont('Helvetica', '', 10); $this->MultiCell(0, 6, $this->w2($v !== '' ? $v : '—'));
        }
        public function cellFit(float $w, float $h, string $txt, int $border, int $ln, string $align, bool $fill = false): void
        {
            $t = $this->w2($txt);
            while ($t !== '' && $this->GetStringWidth($t) > $w - 1.5) { $t = substr($t, 0, -1); }
            $this->Cell($w, $h, $t, $border, $ln, $align, $fill);
        }
        /** @param array<int,array{0:string,1:float}> $cols */
        public function tableHead(array $cols): void
        {
            $this->SetFont('Helvetica', 'B', 8.5); $this->SetFillColor(210, 222, 235); $this->SetDrawColor(180, 195, 215);
            foreach ($cols as $i => $c) { $this->cellFit($c[1], 6, $c[0], 1, $i === count($cols) - 1 ? 1 : 0, 'L', true); }
            $this->SetFont('Helvetica', '', 8.5);
        }
        /** @param array<int,string> $cells @param array<int,array{0:string,1:float}> $cols */
        public function tableRow(array $cells, array $cols, bool $zebra): void
        {
            if ($this->GetY() > 275) { $this->AddPage(); $this->tableHead($cols); }
            $this->SetFillColor(244, 247, 251);
            foreach ($cols as $i => $c) { $this->cellFit($c[1], 5.4, $cells[$i] ?? '', 1, $i === count($cols) - 1 ? 1 : 0, 'L', $zebra); }
        }
    }

    $pdf = new ReqPDF('P', 'mm', 'A4'); $pdf->meta = $meta; $pdf->AliasNbPages();
    $pdf->SetMargins(10, 10, 10); $pdf->SetAutoPageBreak(true, 16); $pdf->AddPage();

    // Titre
    $pdf->SetFont('Helvetica', 'B', 18); $pdf->SetTextColor(30, 58, 95);
    $pdf->Cell(0, 10, $pdf->w2('DOSSIER DE RÉQUISITION'), 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 11); $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 6, $pdf->w2('Extraction de données de traçabilité — passerelle Bastion'), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0); $pdf->Ln(4);

    $pdf->h1('Cadre de la réquisition');
    $pdf->kv('N° de réquisition', $meta['num']);
    $pdf->kv('Autorité requérante', $meta['autorite']);
    $pdf->kv('Cadre juridique', $meta['cadre']);
    $pdf->kv('Requérant (OPJ/agent)', $meta['requerant']);
    $pdf->kv('Motif', $meta['motif']);
    $pdf->kv('Établi le', date('d/m/Y à H:i:s'));
    $pdf->kv('Établi par', $_SESSION['admin'] ?? 'administrateur');

    $pdf->h1('Objet et périmètre');
    $pdf->kv('Type de cible', $TYPE_LABEL[$type]);
    if ($needVal) { $pdf->kv('Valeur recherchée', $val); }
    $pdf->kv('Période examinée', date('d/m/Y H:i', strtotime($from)) . ' → ' . date('d/m/Y H:i', strtotime($to)));
    $pdf->kv('Sessions trouvées', (string) $data['sessCount']);
    $pdf->kv('Entrées de navigation', (string) $data['webCount']);

    // ── Intégrité des journaux ───────────────────────────────────────────────
    // Ce qui distingue un extrait de base d'une pièce à valeur probante : pouvoir
    // affirmer que les journaux n'ont pas été retouchés depuis leur enregistrement.
    // Chaque journée écoulée est scellée (SHA-256), chaînée à la veille et signée
    // par l'Autorité Bastion — une suppression ou une modification rompt la chaîne.
    $pdf->h1('Intégrité des journaux sur la période');
    $seal = req_seal_status($db, $from, $to);
    $pdf->kv('Journées scellées', $seal['nb'] . ' sur ' . $seal['total'] . ' journée(s) écoulée(s)');
    $pdf->kv('Contrôle de la chaîne', $seal['verdict']);
    if ($seal['note'] !== '') {
        $pdf->SetFont('Helvetica', 'I', 8.5); $pdf->SetTextColor(90, 90, 90);
        $pdf->MultiCell(0, 4.2, $pdf->w2($seal['note']), 0, 'L');
        $pdf->SetTextColor(0, 0, 0); $pdf->Ln(1);
    }

    // Identités
    $pdf->h1('Agent(s) concerné(s)');
    if (!$data['subjects']) {
        $pdf->SetFont('Helvetica', 'I', 10); $pdf->Cell(0, 6, $pdf->w2('Aucune identité nominative rattachée aux enregistrements.'), 0, 1);
    } else {
        $cols = [['Identifiant', 34], ['Matricule', 24], ['Nom', 34], ['Prénom', 30], ['Service', 34], ['Commissariat', 34]];
        $pdf->tableHead($cols); $z = false;
        foreach ($data['subjects'] as $s) {
            $pdf->tableRow([$s['username'], $s['matricule'], $s['nom'], $s['prenom'], $s['service'], $s['commissariat']], $cols, $z); $z = !$z;
        }
    }

    // Sessions
    $pdf->h1('Sessions de connexion (portail captif)');
    if (!$data['sessions']) {
        $pdf->SetFont('Helvetica', 'I', 10); $pdf->Cell(0, 6, $pdf->w2('Aucune session sur la période.'), 0, 1);
    } else {
        $cols = [['Date / heure', 32], ['Événement', 22], ['Identifiant', 34], ['IP', 26], ['MAC', 32], ['Durée', 18], ['Volume ↓↑', 26]];
        $pdf->tableHead($cols); $z = false;
        foreach ($data['sessions'] as $r) {
            $vol = req_fmt_bytes(($r['bytes_in'] ?? 0) + ($r['bytes_out'] ?? 0));
            $pdf->tableRow([date('d/m/y H:i:s', strtotime($r['ts'])), $r['event'], $r['username'], $r['ip'], $r['mac'], req_fmt_dur($r['duration_s'] ?? 0), $vol], $cols, $z); $z = !$z;
        }
        if ($data['sessCount'] > count($data['sessions'])) {
            $pdf->SetFont('Helvetica', 'I', 8); $pdf->Cell(0, 5, $pdf->w2('… ' . count($data['sessions']) . ' sur ' . $data['sessCount'] . ' sessions (limite d’affichage).'), 0, 1);
        }
    }

    // Navigation
    $pdf->h1('Historique de navigation (domaines)');
    if (!$data['web']) {
        $pdf->SetFont('Helvetica', 'I', 10); $pdf->Cell(0, 6, $pdf->w2('Aucune entrée de navigation sur la période.'), 0, 1);
    } else {
        $cols = [['Date / heure', 32], ['Identifiant', 40], ['IP', 28], ['Domaine consulté', 90]];
        $pdf->tableHead($cols); $z = false;
        foreach ($data['web'] as $r) {
            $pdf->tableRow([date('d/m/y H:i:s', strtotime($r['ts'])), $r['username'], $r['client_ip'], $r['domain']], $cols, $z); $z = !$z;
        }
        if ($data['webCount'] > count($data['web'])) {
            $pdf->SetFont('Helvetica', 'I', 8); $pdf->Cell(0, 5, $pdf->w2('… ' . count($data['web']) . ' sur ' . $data['webCount'] . ' entrées (limite d’affichage).'), 0, 1);
        }
    }

    // Intégrité
    $pdf->h1('Intégrité et signature électronique');
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->MultiCell(0, 5, $pdf->w2(
        "Ce document est signé électroniquement par la passerelle Bastion au moyen d'un certificat "
        . "émis par l'Autorité de certification Bastion. La signature détachée (format CMS/PKCS#7, "
        . "RFC 5652) jointe à l'archive garantit l'origine du document et l'absence de toute "
        . "modification depuis son émission. La procédure de vérification est fournie dans le fichier "
        . "VERIFICATION.txt de l'archive."));
    $pdf->Ln(1);
    $pdf->kv('Empreinte des données (SHA-256)', strtoupper(implode(' ', str_split(substr($sha, 0, 32), 4))) . '…');
    $pdf->kv('Empreinte complète', $sha);
    $pdf->kv('Horodatage', date('c'));

    $tmpBase = tempnam(sys_get_temp_dir(), 'req');
    $pdfPath = $tmpBase . '.pdf'; $p7sPath = $pdfPath . '.p7s'; $zipPath = $tmpBase . '.zip';
    $pdf->Output('F', $pdfPath);

    // Signature CMS détachée (via helper root : émet le cert au 1er usage puis signe).
    $signOut = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-sign sign ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($p7sPath) . ' 2>&1'));
    $signed = (strpos($signOut, 'OK') === 0) && is_file($p7sPath);

    // Journaliser la réquisition (audit interne).
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS pf_requisitions (id INT AUTO_INCREMENT PRIMARY KEY, num VARCHAR(96), autorite VARCHAR(160), cadre VARCHAR(160),
            requerant VARCHAR(120), cible_type VARCHAR(16), cible_val VARCHAR(255), periode_from DATETIME, periode_to DATETIME, admin VARCHAR(64),
            data_sha256 CHAR(64), signed TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
        $db->prepare('INSERT INTO pf_requisitions (num,autorite,cadre,requerant,cible_type,cible_val,periode_from,periode_to,admin,data_sha256,signed)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $meta['num'], $meta['autorite'], $meta['cadre'], $meta['requerant'], $type, $needVal ? $val : '',
            $from, $to, $_SESSION['admin'] ?? '', $sha, $signed ? 1 : 0,
        ]);
    } catch (Throwable $e) {}

    $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $meta['num'] !== '' ? $meta['num'] : ('sans-numero-' . date('Ymd-His')));
    $base = 'Dossier-requisition-' . $slug;
    $verif = "VÉRIFICATION DU DOSSIER DE RÉQUISITION BASTION\n"
        . "==============================================\n\n"
        . "Ce dossier ($base.pdf) est signé électroniquement par la passerelle Bastion.\n"
        . "La signature détachée ($base.pdf.p7s) est au format CMS/PKCS#7 (RFC 5652) et\n"
        . "garantit :\n"
        . "  - l'INTÉGRITÉ  : le PDF n'a pas été modifié depuis sa signature ;\n"
        . "  - l'AUTHENTICITÉ : il provient bien de cette passerelle Bastion.\n\n"
        . "VÉRIFICATION AVEC OPENSSL (Windows/Linux/macOS) :\n"
        . "  openssl cms -verify -binary -inform DER \\\n"
        . "    -in \"$base.pdf.p7s\" -content \"$base.pdf\" \\\n"
        . "    -CAfile AC-Bastion.crt\n\n"
        . "Résultat attendu : « CMS Verification successful ».\n"
        . "Si le résultat est « verification failure », le PDF a été altéré : ne pas exploiter.\n\n"
        . "Fichiers de l'archive :\n"
        . "  - $base.pdf            : le dossier (document officiel)\n"
        . "  - $base.pdf.p7s        : signature électronique détachée\n"
        . "  - AC-Bastion.crt       : autorité de certification (racine de confiance)\n"
        . "  - Certificat-signature.crt : certificat de signature (émis par l'AC)\n\n"
        . "Empreinte SHA-256 des données extraites :\n  $sha\n\n"
        . "Établi le " . date('d/m/Y à H:i:s') . " par " . ($_SESSION['admin'] ?? 'administrateur') . ".\n"
        . "Document confidentiel — communication réservée à l'autorité requérante.\n";

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $zip->addFile($pdfPath, $base . '.pdf');
        if ($signed) { $zip->addFile($p7sPath, $base . '.pdf.p7s'); }
        if (is_readable('/etc/proxyfibre/bastion-ca.crt')) { $zip->addFile('/etc/proxyfibre/bastion-ca.crt', 'AC-Bastion.crt'); }
        if (is_readable('/etc/proxyfibre/requisition.crt')) { $zip->addFile('/etc/proxyfibre/requisition.crt', 'Certificat-signature.crt'); }
        $zip->addFromString('VERIFICATION.txt', $verif);
        $zip->close();
    }

    if (is_file($zipPath)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $base . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-store');
        readfile($zipPath);
    } else {
        http_response_code(500); echo 'Échec de génération de l’archive.';
    }
    @unlink($pdfPath); @unlink($p7sPath); @unlink($zipPath); @unlink($tmpBase);
    exit;
}

// ═══ AFFICHAGE (formulaire + rapport visuel) ═════════════════════════════════
require_once __DIR__ . '/inc/layout.php';
$report = $hasQuery ? req_collect($db, $type, $val, $from, $to) : null;
pf_header('Réquisition judiciaire', 'requisition.php');
?>
<div class="ad-intro" style="background:linear-gradient(120deg,#3a1526,#1a1020);border:1px solid var(--line);border-radius:14px;padding:1.1rem 1.4rem;margin-bottom:1.2rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
  <span style="font-size:2rem">⚖️</span>
  <div style="flex:1;min-width:220px">
    <div style="font-size:1.15rem;font-weight:600;color:#fff">Réquisition judiciaire &amp; administrative</div>
    <div style="color:var(--muted);font-size:.9rem">Extraction de toute la traçabilité légale détenue sur une cible, en
    dossier visuel et <strong>PDF signé électroniquement</strong> (intégrité et origine vérifiables).</div>
  </div>
</div>

<section class="panel" style="margin-bottom:1.2rem">
  <div class="panel-head"><h2>🔎 Objet de la réquisition</h2></div>
  <form method="post" style="padding:1.1rem 1.2rem;display:grid;gap:.9rem">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.9rem">
      <label class="rq-l">Type de cible
        <select name="type" id="rqtype" onchange="rqToggle()">
          <?php foreach ($TYPE_LABEL as $k => $lab): ?><option value="<?= e($k) ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="rq-l" id="rqvalwrap">Valeur recherchée
        <input type="text" name="val" value="<?= e($val) ?>" placeholder="ex. 0110480, 192.168.182.41, 08:00:27:xx:xx:xx, facebook.com">
      </label>
      <label class="rq-l">Du
        <input type="datetime-local" name="from" value="<?= e(date('Y-m-d\TH:i', strtotime($from))) ?>">
      </label>
      <label class="rq-l">Au
        <input type="datetime-local" name="to" value="<?= e(date('Y-m-d\TH:i', strtotime($to))) ?>">
      </label>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.9rem;border-top:1px solid var(--line);padding-top:.9rem">
      <label class="rq-l">N° de réquisition <input type="text" name="num" value="<?= e($meta['num']) ?>" placeholder="ex. 2026/00123"></label>
      <label class="rq-l">Autorité requérante <input type="text" name="autorite" value="<?= e($meta['autorite']) ?>" placeholder="ex. TJ d'Évry — Parquet"></label>
      <label class="rq-l">Cadre juridique <input type="text" name="cadre" value="<?= e($meta['cadre']) ?>" placeholder="ex. art. 60-1 / 77-1-1 / 99-3 CPP"></label>
      <label class="rq-l">Requérant (OPJ/agent) <input type="text" name="requerant" value="<?= e($meta['requerant']) ?>" placeholder="Grade, nom, unité"></label>
    </div>
    <label class="rq-l">Motif / réquisition <input type="text" name="motif" value="<?= e($meta['motif']) ?>" placeholder="Objet de la demande (facultatif)"></label>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap">
      <button class="btn" name="action" value="search">🔎 Générer le dossier</button>
      <button class="btn" name="action" value="export" style="background:#7f1d1d;border-color:#7f1d1d"<?= $hasQuery ? '' : ' disabled' ?>>⬇️ Télécharger le dossier signé (ZIP)</button>
    </div>
    <p class="muted small" style="margin:0">L'archive contient le PDF, sa signature électronique détachée (CMS/PKCS#7),
    le certificat de l'AC Bastion et la procédure de vérification. Chaque extraction est journalisée.</p>
  </form>
</section>

<style>
  .rq-l{display:grid;gap:.3rem;font-size:.82rem;color:var(--muted)}
  .rq-l input,.rq-l select{padding:.55rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px;width:100%}
  .rq-report{background:#fff;color:#111;border-radius:12px;padding:1.6rem 1.8rem;box-shadow:0 10px 40px rgba(0,0,0,.35)}
  .rq-report h2{color:#1e3a5f;border-bottom:2px solid #1e3a5f;padding-bottom:.3rem;margin:1.4rem 0 .6rem;font-size:1.05rem}
  .rq-report h1{color:#1e3a5f;text-align:center;margin:.2rem 0}
  .rq-report .sub{text-align:center;color:#666;margin-bottom:1rem}
  .rq-report table{width:100%;border-collapse:collapse;font-size:.8rem;margin:.4rem 0}
  .rq-report th{background:#d2deeb;text-align:left;padding:.35rem .5rem;border:1px solid #b4c3d7}
  .rq-report td{padding:.3rem .5rem;border:1px solid #d7e0ea;vertical-align:top}
  .rq-report tr:nth-child(even) td{background:#f4f7fb}
  .rq-kv{display:grid;grid-template-columns:220px 1fr;gap:.2rem .8rem;font-size:.85rem}
  .rq-kv dt{font-weight:700}
  .rq-seal{margin-top:1.2rem;border:1px dashed #7f1d1d;border-radius:10px;padding:.8rem 1rem;background:#fdf2f2;font-size:.8rem;color:#333;word-break:break-all}
</style>

<?php if ($report !== null): ?>
<section class="panel" style="margin-bottom:1.2rem;background:transparent;border:none;box-shadow:none;padding:0">
  <div class="rq-report" id="rqprint">
    <h1>DOSSIER DE RÉQUISITION</h1>
    <div class="sub">Extraction de données de traçabilité — passerelle Bastion</div>

    <h2>Cadre de la réquisition</h2>
    <dl class="rq-kv">
      <dt>N° de réquisition</dt><dd><?= e($meta['num'] ?: '—') ?></dd>
      <dt>Autorité requérante</dt><dd><?= e($meta['autorite'] ?: '—') ?></dd>
      <dt>Cadre juridique</dt><dd><?= e($meta['cadre'] ?: '—') ?></dd>
      <dt>Requérant</dt><dd><?= e($meta['requerant'] ?: '—') ?></dd>
      <dt>Motif</dt><dd><?= e($meta['motif'] ?: '—') ?></dd>
      <dt>Établi le</dt><dd><?= e(date('d/m/Y à H:i:s')) ?> par <?= e($_SESSION['admin'] ?? 'administrateur') ?></dd>
    </dl>

    <h2>Objet et périmètre</h2>
    <dl class="rq-kv">
      <dt>Type de cible</dt><dd><?= e($TYPE_LABEL[$type]) ?></dd>
      <?php if ($needVal): ?><dt>Valeur recherchée</dt><dd><strong><?= e($val) ?></strong></dd><?php endif; ?>
      <dt>Période examinée</dt><dd><?= e(date('d/m/Y H:i', strtotime($from))) ?> → <?= e(date('d/m/Y H:i', strtotime($to))) ?></dd>
      <dt>Sessions trouvées</dt><dd><?= (int) $report['sessCount'] ?></dd>
      <dt>Entrées de navigation</dt><dd><?= (int) $report['webCount'] ?></dd>
    </dl>

    <h2>Agent(s) concerné(s) — <?= count($report['subjects']) ?></h2>
    <?php if (!$report['subjects']): ?><p><em>Aucune identité nominative rattachée.</em></p>
    <?php else: ?>
    <table><tr><th>Identifiant</th><th>Matricule</th><th>Nom</th><th>Prénom</th><th>Service</th><th>Commissariat</th></tr>
      <?php foreach ($report['subjects'] as $s): ?>
      <tr><td><?= e($s['username']) ?></td><td><?= e($s['matricule']) ?></td><td><?= e($s['nom']) ?></td><td><?= e($s['prenom']) ?></td><td><?= e($s['service']) ?></td><td><?= e($s['commissariat']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <h2>Sessions de connexion (portail captif) — <?= (int) $report['sessCount'] ?></h2>
    <?php if (!$report['sessions']): ?><p><em>Aucune session sur la période.</em></p>
    <?php else: ?>
    <table><tr><th>Date / heure</th><th>Événement</th><th>Identifiant</th><th>IP</th><th>MAC</th><th>Durée</th><th>Volume ↓↑</th></tr>
      <?php foreach ($report['sessions'] as $r): ?>
      <tr><td><?= e(date('d/m/Y H:i:s', strtotime($r['ts']))) ?></td><td><?= e($r['event']) ?></td><td><?= e($r['username']) ?></td><td><?= e($r['ip']) ?></td><td><?= e($r['mac']) ?></td><td><?= e(req_fmt_dur($r['duration_s'] ?? 0)) ?></td><td><?= e(req_fmt_bytes(($r['bytes_in'] ?? 0) + ($r['bytes_out'] ?? 0))) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if ($report['sessCount'] > count($report['sessions'])): ?><p class="muted small">… <?= count($report['sessions']) ?> sur <?= (int) $report['sessCount'] ?> affichées (le PDF est identiquement borné).</p><?php endif; ?>
    <?php endif; ?>

    <h2>Historique de navigation (domaines) — <?= (int) $report['webCount'] ?></h2>
    <?php if (!$report['web']): ?><p><em>Aucune entrée de navigation sur la période.</em></p>
    <?php else: ?>
    <table><tr><th>Date / heure</th><th>Identifiant</th><th>IP</th><th>Domaine consulté</th></tr>
      <?php foreach (array_slice($report['web'], 0, 500) as $r): ?>
      <tr><td><?= e(date('d/m/Y H:i:s', strtotime($r['ts']))) ?></td><td><?= e($r['username']) ?></td><td><?= e($r['client_ip']) ?></td><td><?= e($r['domain']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if (count($report['web']) > 500): ?><p class="muted small">… 500 sur <?= count($report['web']) ?> affichées à l'écran (le PDF contient l'ensemble borné).</p><?php endif; ?>
    <?php endif; ?>

    <div class="rq-seal">
      <strong>🔏 Intégrité &amp; signature.</strong> Le dossier téléchargé (PDF) est signé électroniquement par
      l'AC Bastion (CMS/PKCS#7 détaché) : origine et non-altération vérifiables. Empreinte SHA-256 des données à cet
      instant :<br><code><?= e(hash('sha256', json_encode([$meta, $type, $val, $from, $to, $report['subjects'], $report['sessions'], $report['web']], JSON_UNESCAPED_UNICODE))) ?></code>
    </div>
  </div>
  <div style="margin-top:.9rem;display:flex;gap:.6rem;flex-wrap:wrap">
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="val" value="<?= e($val) ?>">
      <input type="hidden" name="from" value="<?= e(date('Y-m-d\TH:i', strtotime($from))) ?>"><input type="hidden" name="to" value="<?= e(date('Y-m-d\TH:i', strtotime($to))) ?>">
      <input type="hidden" name="num" value="<?= e($meta['num']) ?>"><input type="hidden" name="autorite" value="<?= e($meta['autorite']) ?>">
      <input type="hidden" name="cadre" value="<?= e($meta['cadre']) ?>"><input type="hidden" name="requerant" value="<?= e($meta['requerant']) ?>">
      <input type="hidden" name="motif" value="<?= e($meta['motif']) ?>">
      <button class="btn" name="action" value="export" style="background:#7f1d1d;border-color:#7f1d1d">⬇️ Télécharger le dossier signé (ZIP)</button>
    </form>
    <button class="btn-sm" onclick="window.print()">🖨️ Imprimer le rapport</button>
  </div>
</section>
<?php endif; ?>

<script>
function rqToggle(){ var t=document.getElementById('rqtype').value; document.getElementById('rqvalwrap').style.display = (t==='periode')?'none':'grid'; }
rqToggle();
</script>
<?php pf_footer(); ?>
