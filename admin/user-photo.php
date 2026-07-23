<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Sert la photo d'un fonctionnaire (table pf_user_photo), pour l'affichage dans la console.
 * Sortie binaire : ce fichier n'inclut PAS le gabarit HTML. Réservé aux administrateurs
 * authentifiés (inc/auth.php) — un trombinoscope interne, rien de plus à révéler entre admins.
 */
require_once __DIR__ . '/inc/auth.php';

$u = preg_replace('/[^A-Za-z0-9._@-]/', '', (string) ($_GET['u'] ?? ''));

$row = null;
try {
    $st = pf_db()->prepare('SELECT photo, v FROM pf_user_photo WHERE username = ?');
    $st->execute([$u]);
    $row = $st->fetch();
} catch (Throwable $e) { $row = null; }   // table pas encore créée : aucune photo

if (!$row || ($row['photo'] ?? null) === null) { http_response_code(404); exit; }

$v = (string) ($row['v'] ?? '');
// Type STRICT + « nosniff » : l'image (déjà ré-encodée en PNG) ne doit jamais être interprétée
// autrement. Défense en profondeur, au cas où un polyglotte résiduel passerait.
header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
header('Cache-Control: private, max-age=3600');
if ($v !== '') { header('ETag: "' . $v . '"'); }

$inm = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), '"');
if ($v !== '' && $inm === $v) { http_response_code(304); exit; }

echo $row['photo'];
