<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Sert l'aperçu du fond d'écran imposé (stocké en base, table pf_media).
 * Sortie binaire : ce fichier n'inclut PAS le gabarit HTML.
 *
 * Pourquoi en base et non en fichier : le dossier admin/ appartient à root et est
 * resynchronisé (rsync --delete) à chaque mise à jour — un fichier d'aperçu y serait
 * soit impossible à écrire pour www-data, soit effacé à la mise à jour suivante.
 */
require_once __DIR__ . '/inc/auth.php';   // impose une session admin ; redirige sinon

$row = null;
try {
    $st = pf_db()->query("SELECT mime, bytes, updated_at FROM pf_media WHERE k = 'wallpaper'");
    $row = $st ? $st->fetch() : null;
} catch (Throwable $e) { $row = null; }   // table pas encore créée : aucun aperçu

if (!$row || ($row['bytes'] ?? '') === '') { http_response_code(404); exit; }

$v = (string) ($row['updated_at'] ?? '');
// Type STRICT + « nosniff » : l'image (déjà ré-encodée en JPEG à l'envoi) ne doit jamais être
// interprétée comme autre chose qu'une image. Défense en profondeur.
header('Content-Type: ' . ($row['mime'] ?: 'image/jpeg'));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
header('Cache-Control: private, max-age=3600');
if ($v !== '') { header('ETag: "' . $v . '"'); }

// Cache conditionnel : si le navigateur a déjà cette version, on ne renvoie pas les octets.
$inm = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), '"');
if ($v !== '' && $inm === $v) { http_response_code(304); exit; }

echo $row['bytes'];
