<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — photo de l'agent, servie au portail.
 *
 * ── UNE SEULE RÈGLE, ET ELLE EST STRICTE ────────────────────────────────────
 * Cette page ne sert QUE la photo de l'agent qui la demande, déduite de SA
 * session OpenNDS. Aucun paramètre « ?u= » n'est accepté.
 *
 * La console d'administration, elle, expose bien un « user-photo.php?u=… » —
 * mais elle est protégée par une authentification d'administrateur. Le portail
 * ne l'est pas : il est joignable par n'importe quel poste du réseau interne, y
 * compris un visiteur. Reprendre le même mécanisme ici transformerait la
 * passerelle en trombinoscope ouvert du commissariat, interrogeable matricule
 * par matricule. Sur un service de police, ce n'est pas une commodité qu'on
 * accorde : c'est une fuite qu'on refuse.
 */
require_once __DIR__ . '/https_guard.php';
require_once __DIR__ . '/nds.php';
require_once __DIR__ . '/intranet/_common.php';

$u = intranet_user();
if (empty($u['auth']) || $u['user'] === '') { http_response_code(403); exit; }

$db = intranet_db();
if ($db === null) { http_response_code(503); exit; }

try {
    $st = $db->prepare('SELECT photo, v FROM pf_user_photo WHERE username = ? LIMIT 1');
    $st->execute([$u['user']]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $r = null; }

if (!$r || empty($r['photo'])) { http_response_code(404); exit; }

$bin = $r['photo'];
// Type déduit du CONTENU, jamais d'une extension ou d'un champ déclaré : la photo
// vient d'un téléversement, et un type annoncé par l'appelant se falsifie.
$mime = 'image/jpeg';
if (strncmp($bin, "\x89PNG", 4) === 0)      { $mime = 'image/png'; }
elseif (strncmp($bin, 'GIF8', 4) === 0)     { $mime = 'image/gif'; }
elseif (strncmp($bin, 'RIFF', 4) === 0)     { $mime = 'image/webp'; }

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bin));
// Cache privé : la photo d'un agent ne doit pas être conservée par un
// intermédiaire, et « v » change quand elle est remplacée.
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo $bin;
