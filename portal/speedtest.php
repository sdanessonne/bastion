<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — point de mesure de débit pour le test de vitesse du portail.
 *   GET  ?dl=<octets>  → renvoie <octets> octets incompressibles (téléchargement)
 *   POST (corps)       → consomme le corps et renvoie la taille reçue (téléversement)
 * Mesure le débit effectif entre le client et la passerelle (reflète les limites
 * de débit appliquées par groupe).
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $n = 0;
    $in = fopen('php://input', 'rb');
    if ($in) { while (!feof($in)) { $b = fread($in, 262144); if ($b === false) { break; } $n += strlen($b); } fclose($in); }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['received' => $n]);
    exit;
}

// Téléchargement : générer des octets incompressibles (bloc aléatoire répété).
$bytes = (int) ($_GET['dl'] ?? 0);
$bytes = max(0, min(25 * 1024 * 1024, $bytes));   // plafond 25 Mo
header('Content-Type: application/octet-stream');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Length: ' . $bytes);
header('X-Accel-Buffering: no');
$block = random_bytes(65536);
$sent = 0;
while ($sent < $bytes) {
    $len = min(65536, $bytes - $sent);
    echo $len === 65536 ? $block : substr($block, 0, $len);
    $sent += $len;
    if (($sent % (1024 * 1024)) === 0) { flush(); }
}
