<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Fiche de connexion Wi-Fi, en PDF.
 *
 * À afficher près du poste d'accueil ou à remettre à un agent : nom du réseau,
 * phrase secrète, et un QR que les téléphones savent lire pour se connecter sans
 * rien saisir. Le QR suit le format « WIFI: » reconnu par Android et iOS.
 *
 * CE DOCUMENT CONTIENT LA PHRASE SECRÈTE EN CLAIR. C'est son objet — une fiche
 * qu'on affiche —, mais cela vaut d'être écrit sur le document lui-même, et la
 * génération est tracée au journal d'audit comme n'importe quelle divulgation.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/audit.php';

$etat = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-wifi state 2>/dev/null'), true) ?: [];
$ssid = (string) ($etat['ssid'] ?? '');
if ($ssid === '') { http_response_code(404); exit('Aucun point d\'accès configuré.'); }

$ouvert = !empty($etat['ouvert']);
$psk    = '';
if (!$ouvert) {
    try {
        $st = pf_db()->prepare("SELECT v FROM pf_settings WHERE k='wifi_psk' LIMIT 1");
        $st->execute();
        $psk = (string) ($st->fetchColumn() ?: '');
    } catch (Throwable $e) { $psk = ''; }
}

/**
 * Charge utile du QR, au format « WIFI: » que lisent Android et iOS.
 * Les caractères « \ ; , : " » DOIVENT être échappés : une phrase contenant un
 * point-virgule couperait la chaîne en deux et le téléphone lirait un mot de passe
 * tronqué — sans jamais dire pourquoi la connexion échoue.
 */
$echap = static fn(string $s): string => addcslashes($s, '\\;,:"');
$charge = $ouvert
    ? 'WIFI:T:nopass;S:' . $echap($ssid) . ';;'
    : 'WIFI:T:WPA;S:' . $echap($ssid) . ';P:' . $echap($psk) . ';;';

// QR par « qrencode », déjà utilisé pour le badge agent. On passe la charge utile
// par l'entrée standard : elle contient la phrase secrète, qui n'a donc rien à faire
// sur une ligne de commande visible dans « ps ».
$qrPng = '';
$p = @proc_open('qrencode -o - -t PNG -m 1 -s 8 -l M',
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (is_resource($p)) {
    fwrite($pipes[0], $charge); fclose($pipes[0]);
    $qrPng = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
}

require_once __DIR__ . '/lib/fpdf/fpdf.php';

$w2 = static function (string $s): string {
    $r = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    return $r === false ? $s : $r;
};

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// ── Bandeau ─────────────────────────────────────────────────────────────────
$pdf->SetFillColor(15, 32, 55);
$pdf->Rect(0, 0, 210, 42, 'F');
$logo = __DIR__ . '/assets/bastion-logo.png';
if (is_file($logo)) { $pdf->Image($logo, 18, 9, 24, 24, 'PNG'); }
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Helvetica', 'B', 22);
$pdf->SetXY(48, 12);
$pdf->Cell(0, 9, $w2('BASTION'), 0, 2, 'L');
$pdf->SetFont('Helvetica', '', 11);
$pdf->SetTextColor(150, 190, 230);
$pdf->Cell(0, 7, $w2('Accès au réseau sans fil'), 0, 0, 'L');

// ── Nom du réseau ───────────────────────────────────────────────────────────
$pdf->SetTextColor(60, 60, 60);
$pdf->SetFont('Helvetica', '', 10);
$pdf->SetXY(18, 56);
$pdf->Cell(0, 6, $w2('NOM DU RÉSEAU (SSID)'), 0, 2, 'L');
$pdf->SetFont('Helvetica', 'B', 20);
$pdf->SetTextColor(15, 32, 55);
$pdf->Cell(0, 12, $w2($ssid), 0, 2, 'L');

// ── Phrase secrète, ou mention « ouvert » ───────────────────────────────────
$pdf->Ln(4);
$pdf->SetFont('Helvetica', '', 10);
$pdf->SetTextColor(60, 60, 60);
if ($ouvert) {
    $pdf->Cell(0, 6, $w2('PHRASE SECRÈTE'), 0, 2, 'L');
    $pdf->SetFont('Helvetica', 'B', 15);
    $pdf->SetTextColor(180, 100, 0);
    $pdf->Cell(0, 10, $w2('Aucune — réseau ouvert'), 0, 2, 'L');
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell(105, 5, $w2(
        "Le réseau est accessible sans mot de passe. Les échanges ne sont pas chiffrés "
        . "par le Wi-Fi : n'y saisissez d'informations sensibles que sur des sites en HTTPS."), 0, 'L');
} else {
    $pdf->Cell(0, 6, $w2('PHRASE SECRÈTE'), 0, 2, 'L');
    // Courier : le 0 se distingue du O, le 1 du l. Une fiche se recopie à la main.
    $pdf->SetFont('Courier', 'B', 17);
    $pdf->SetTextColor(15, 32, 55);
    $pdf->Cell(0, 11, $w2($psk), 0, 2, 'L');
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell(105, 5, $w2('Respectez les majuscules et les tirets.'), 0, 'L');
}

// ── QR ──────────────────────────────────────────────────────────────────────
if ($qrPng !== '') {
    $tmp = tempnam(sys_get_temp_dir(), 'pfqr') . '.png';
    file_put_contents($tmp, $qrPng);
    $pdf->Image($tmp, 132, 56, 60, 60, 'PNG');
    @unlink($tmp);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->SetXY(132, 117);
    $pdf->MultiCell(60, 4.5, $w2("Scannez avec l'appareil photo\nde votre téléphone"), 0, 'C');
}

// ── Marche à suivre ─────────────────────────────────────────────────────────
$pdf->SetXY(18, 140);
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->SetTextColor(15, 32, 55);
$pdf->Cell(0, 8, $w2('Se connecter'), 0, 2, 'L');
$pdf->SetFont('Helvetica', '', 10.5);
$pdf->SetTextColor(60, 60, 60);
$etapes = [
    "Choisissez le réseau « $ssid » dans la liste des réseaux sans fil."
        . ($ouvert ? '' : ' Saisissez la phrase secrète ci-dessus.'),
    "Une page d'identification s'ouvre automatiquement. Si ce n'est pas le cas, "
        . "ouvrez votre navigateur : elle apparaîtra à la première visite.",
    "Identifiez-vous avec votre compte. La connexion à Internet s'ouvre alors, et "
        . "votre navigation est journalisée conformément à la réglementation.",
];
$n = 1;
foreach ($etapes as $e) {
    $y = $pdf->GetY();
    $pdf->SetFont('Helvetica', 'B', 10.5);
    $pdf->SetXY(18, $y);
    $pdf->Cell(7, 6, $n++ . '.', 0, 0, 'L');
    $pdf->SetFont('Helvetica', '', 10.5);
    $pdf->SetXY(25, $y);
    $pdf->MultiCell(167, 5.5, $w2($e), 0, 'L');
    $pdf->Ln(1.5);
}

// ── Avertissement + pied de page ────────────────────────────────────────────
$pdf->SetY(-46);
$pdf->SetDrawColor(200, 200, 200);
$pdf->SetFillColor(250, 245, 230);
$pdf->Rect(18, $pdf->GetY(), 174, 20, 'DF');
$pdf->SetXY(22, $pdf->GetY() + 3);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetTextColor(140, 90, 0);
$pdf->Cell(0, 5, $w2($ouvert ? 'Réseau ouvert' : 'Document à ne pas laisser sans surveillance'), 0, 2, 'L');
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(110, 90, 60);
$pdf->MultiCell(166, 4.5, $w2($ouvert
    ? "Ce réseau ne demande aucun mot de passe. L'identification de chaque agent se fait "
      . "sur le portail, et chaque accès est journalisé."
    : "Cette fiche porte la phrase secrète du réseau en clair. Affichez-la dans un local "
      . "contrôlé, et renouvelez la phrase depuis la console si elle a circulé."), 0, 'L');

$pdf->SetY(-16);
$pdf->SetFont('Helvetica', 'I', 8);
$pdf->SetTextColor(140, 140, 140);
$pdf->Cell(0, 5, $w2('Bastion — fiche établie le ' . date('d/m/Y à H\hi')
    . ' · canal ' . (int) ($etat['canal'] ?? 0)), 0, 0, 'C');

// Deux modes de remise. « attachment » force le téléchargement ; un aperçu dans une
// fenêtre de la console a besoin de « inline », sinon le navigateur téléchargerait le
// fichier au lieu de l'afficher dans le cadre.
$apercu = isset($_GET['apercu']);
audit('wifi.fiche', ($apercu ? 'aperçu' : 'téléchargement') . ' de la fiche de connexion'
    . ($ouvert ? ' (réseau ouvert)' : ' (avec phrase secrète)'));

$nom = 'Bastion-WiFi-' . preg_replace('/[^A-Za-z0-9_-]/', '', $ssid) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($apercu ? 'inline' : 'attachment') . '; filename="' . $nom . '"');
// Le document porte la phrase secrète : il ne doit rester ni dans le cache du
// navigateur ni dans celui d'un intermédiaire.
header('Cache-Control: private, no-store, max-age=0');
$pdf->Output($apercu ? 'I' : 'D', $nom);
