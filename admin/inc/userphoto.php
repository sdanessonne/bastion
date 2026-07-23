<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Photos des fonctionnaires (comptes gérés dans users.php).
 *
 * Même principe de sécurité que les avatars administrateurs (inc/avatar.php) : toute image
 * reçue est RÉ-ENCODÉE (décodage des pixels puis ré-émission) — code, script ou métadonnées
 * piégées ne survivent pas. Stockage en base (table pf_user_photo), jamais sur le disque :
 * survit aux mises à jour (rsync --delete) et n'expose aucun fichier dans l'arborescence web.
 */

/** Migration idempotente : table photo par utilisateur (PNG ré-encodé + version pour le cache). */
function userphoto_migre(PDO $db): void
{
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS pf_user_photo (
            username VARCHAR(64) PRIMARY KEY,
            photo MEDIUMBLOB NOT NULL,
            v VARCHAR(16) NOT NULL,
            updated_at INT NOT NULL
        )');
    } catch (Throwable $e) { /* droits insuffisants : la fonctionnalité reste inactive */ }
}

/** Carte [username => version] de toutes les photos, pour l'affichage (img vs initiale). */
function userphoto_all_versions(PDO $db): array
{
    $out = [];
    try {
        foreach ($db->query('SELECT username, v FROM pf_user_photo') as $r) { $out[$r['username']] = $r['v']; }
    } catch (Throwable $e) { /* table pas encore créée */ }
    return $out;
}

function userphoto_supprimer(PDO $db, string $user): void
{
    try { $db->prepare('DELETE FROM pf_user_photo WHERE username = ?')->execute([$user]); }
    catch (Throwable $e) {}
}

/**
 * Valide, ré-encode et enregistre la photo d'un fonctionnaire. Rend [ok(bool), message].
 * Barrières identiques à avatar_traiter() : GD requis → upload HTTP réel → taille bornée →
 * vrai raster → garde anti-bombe (24 Mpx) → recadrage carré 256 px → stockage des octets
 * ré-encodés seulement.
 */
function userphoto_traiter(PDO $db, string $user, array $file): array
{
    if (!function_exists('imagecreatefromstring') || !function_exists('getimagesizefromstring')) {
        return [false, "Traitement d'image indisponible (php-gd manquant)."];
    }
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        return [false, ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) ? "Fichier trop volumineux." : "Aucun fichier reçu."];
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return [false, "Envoi invalide."];
    }
    $data = (string) @file_get_contents($file['tmp_name']);
    if ($data === '' || strlen($data) > 5 * 1024 * 1024) {
        return [false, "L'image doit peser entre 1 octet et 5 Mo."];
    }
    $info = @getimagesizefromstring($data);
    if ($info === false) {
        return [false, "Ce fichier n'est pas une image reconnue."];
    }
    if (!in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        return [false, "Format non accepté (JPEG, PNG, GIF ou WebP uniquement)."];
    }
    $w = (int) ($info[0] ?? 0);
    $h = (int) ($info[1] ?? 0);
    if ($w < 1 || $h < 1 || $w * $h > 24 * 1000 * 1000) {
        return [false, "Dimensions d'image invalides ou trop grandes."];
    }
    $src = @imagecreatefromstring($data);
    if (!$src) {
        return [false, "Image illisible ou corrompue."];
    }
    $side = min($w, $h);
    $sx = intdiv($w - $side, 2);
    $sy = intdiv($h - $side, 2);
    $dst = imagecreatetruecolor(256, 256);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, 256, 256, $side, $side);
    ob_start();
    imagepng($dst, null, 6);
    $png = (string) ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);
    if ($png === '') {
        return [false, "Ré-encodage impossible."];
    }
    $v = substr(sha1($png), 0, 12);
    try {
        $st = $db->prepare('INSERT INTO pf_user_photo (username, photo, v, updated_at) VALUES (?,?,?,?)
                            ON DUPLICATE KEY UPDATE photo = VALUES(photo), v = VALUES(v), updated_at = VALUES(updated_at)');
        $st->bindValue(1, $user, PDO::PARAM_STR);
        $st->bindValue(2, $png, PDO::PARAM_LOB);
        $st->bindValue(3, $v, PDO::PARAM_STR);
        $st->bindValue(4, time(), PDO::PARAM_INT);
        $st->execute();
    } catch (Throwable $e) {
        return [false, "Enregistrement en base impossible."];
    }
    return [true, "Photo enregistrée."];
}
