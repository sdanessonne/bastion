<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Sert la photo de profil de l'administrateur connecté (ou d'un autre, ?u=).
 * Sortie binaire : ce fichier n'inclut PAS le gabarit HTML.
 */
require_once __DIR__ . '/inc/auth.php';   // impose une session admin ; redirige sinon
$db = pf_db();

// Par défaut, sa propre photo. « ?u= » permet d'afficher celle d'un autre administrateur
// (liste des comptes) : tous les spectateurs ici sont déjà des administrateurs
// authentifiés, il n'y a donc rien de plus à révéler qu'un trombinoscope interne.
$cible = (string) ($_GET['u'] ?? $_SESSION['admin'] ?? '');

$png = null; $v = '';
try {
    $st = $db->prepare('SELECT avatar, avatar_v FROM pf_admins WHERE username = ?');
    $st->execute([$cible]);
    $r = $st->fetch();
    if ($r && $r['avatar'] !== null) { $png = $r['avatar']; $v = (string) ($r['avatar_v'] ?? ''); }
} catch (Throwable $e) { /* colonne pas encore créée : pas d'avatar */ }

if ($png === null) { http_response_code(404); exit; }

// Même ré-encodée, l'image est servie en type STRICT + « nosniff » : le navigateur ne doit
// jamais l'interpréter comme autre chose qu'une image. Défense en profondeur, au cas où un
// polyglotte résiduel passerait — il ne pourrait pas être exécuté comme du HTML/JS.
header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
header('Cache-Control: private, max-age=3600');
if ($v !== '') { header('ETag: "' . $v . '"'); }

// Cache conditionnel : si le navigateur a déjà cette version, on ne renvoie pas les octets.
$inm = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), '"');
if ($v !== '' && $inm === $v) { http_response_code(304); exit; }

echo $png;
