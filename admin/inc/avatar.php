<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Photos de profil des administrateurs.
 *
 * ── L'UPLOAD D'IMAGE EST UNE SURFACE À RISQUE ───────────────────────────────
 * Un fichier « image » venu de l'extérieur peut être un leurre : polyglotte (à la fois
 * JPEG valide et HTML/PHP), SVG porteur de script, ou petite archive annonçant des
 * dimensions énormes pour épuiser la mémoire. La parade centrale est le RÉ-ENCODAGE : on
 * décode les pixels et on les ré-émet nous-mêmes. Ce qui n'est pas un pixel — code, script,
 * métadonnées piégées — ne survit pas à l'opération.
 *
 * L'image est stockée en base (colonne avatar), pas sur le disque : elle survit ainsi aux
 * mises à jour (rsync --delete) et aux sauvegardes, et aucun fichier téléversé n'atterrit
 * dans l'arborescence web.
 */

/** Migration idempotente : colonne image (PNG ré-encodé) + version (pour le cache). */
function avatar_migre(PDO $db): void
{
    try {
        $db->exec('ALTER TABLE pf_admins
            ADD COLUMN IF NOT EXISTS avatar MEDIUMBLOB DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS avatar_v VARCHAR(16) DEFAULT NULL');
    } catch (Throwable $e) { /* déjà présentes, ou droits insuffisants */ }
}

/** Version courte de l'avatar d'un compte, ou null. Sert à décider img vs initiale. */
function avatar_version(PDO $db, string $user): ?string
{
    try {
        $st = $db->prepare('SELECT avatar_v FROM pf_admins WHERE username = ?');
        $st->execute([$user]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null && $v !== '') ? (string) $v : null;
    } catch (Throwable $e) {
        return null;   // colonne pas encore créée = pas d'avatar
    }
}

function avatar_supprimer(PDO $db, string $me): void
{
    try { $db->prepare('UPDATE pf_admins SET avatar = NULL, avatar_v = NULL WHERE username = ?')->execute([$me]); }
    catch (Throwable $e) {}
}

/**
 * Valide, ré-encode et enregistre une photo envoyée. Rend [ok(bool), message, version|null].
 * Chaque contrôle est une barrière franchie AVANT la suivante — l'ordre compte.
 */
function avatar_traiter(PDO $db, string $me, array $file): array
{
    // 1. Le ré-encodage EXIGE GD. Sans lui, on refuse : jamais stocker une image brute
    //    venue de l'extérieur, ce serait garder intact précisément ce qu'on doit détruire.
    if (!function_exists('imagecreatefromstring') || !function_exists('getimagesizefromstring')) {
        return [false, "Traitement d'image indisponible sur le serveur (php-gd manquant).", null];
    }

    // 2. Erreur d'envoi (taille dépassée côté PHP, envoi partiel…).
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        $m = ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE)
            ? "Fichier trop volumineux." : "Aucun fichier reçu.";
        return [false, $m, null];
    }

    // 3. Le fichier DOIT provenir d'un vrai téléversement HTTP, jamais d'un chemin fourni.
    //    Sans ce contrôle, un paramètre forgé pourrait faire lire /etc/passwd comme « image ».
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return [false, "Envoi invalide.", null];
    }

    $data = (string) @file_get_contents($file['tmp_name']);
    // 4. Taille brute bornée AVANT tout décodage.
    if ($data === '' || strlen($data) > 5 * 1024 * 1024) {
        return [false, "L'image doit peser entre 1 octet et 5 Mo.", null];
    }

    // 5. Vraie image matricielle ? getimagesizefromstring lit l'en-tête SANS décoder les
    //    pixels. On n'accepte que le raster connu — le SVG est écarté ici (il peut porter
    //    du script), tout comme n'importe quel fichier déguisé.
    $info = @getimagesizefromstring($data);
    if ($info === false) {
        return [false, "Ce fichier n'est pas une image reconnue.", null];
    }
    if (!in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        return [false, "Format non accepté (JPEG, PNG, GIF ou WebP uniquement).", null];
    }

    // 6. BOMBE DE DÉCOMPRESSION : quelques kilo-octets peuvent annoncer 50 000 × 50 000.
    //    imagecreatefromstring allouerait alors des gigaoctets — le serveur tomberait.
    //    On lit les dimensions annoncées (déjà obtenues, sans décoder) et on refuse au-delà
    //    de 40 mégapixels.
    // Seuil resserré à 24 mégapixels (audit) : au-delà, le décodage GD en couleurs vraies
    // (~4 octets/pixel) approcherait la limite mémoire de PHP. 24 Mpx couvre tout appareil
    // réel ; une photo de profil n'a de toute façon aucun besoin de plus.
    $w = (int) ($info[0] ?? 0);
    $h = (int) ($info[1] ?? 0);
    if ($w < 1 || $h < 1 || $w * $h > 24 * 1000 * 1000) {
        return [false, "Dimensions d'image invalides ou trop grandes.", null];
    }

    // 7. Le cœur : décoder puis ré-émettre. Recadrage carré centré, réduction à 256 px.
    $src = @imagecreatefromstring($data);
    if (!$src) {
        return [false, "Image illisible ou corrompue.", null];
    }
    $side = min($w, $h);
    $sx = intdiv($w - $side, 2);
    $sy = intdiv($h - $side, 2);
    $dst = imagecreatetruecolor(256, 256);
    imagealphablending($dst, false);   // préserver la transparence du PNG source
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, 256, 256, $side, $side);
    ob_start();
    imagepng($dst, null, 6);
    $png = (string) ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);
    if ($png === '') {
        return [false, "Ré-encodage impossible.", null];
    }

    // 8. Enregistrer les octets RÉ-ENCODÉS (pas ceux reçus) + une empreinte courte de
    //    version, qui sert à invalider le cache du navigateur quand la photo change.
    $v = substr(sha1($png), 0, 12);
    try {
        $st = $db->prepare('UPDATE pf_admins SET avatar = ?, avatar_v = ? WHERE username = ?');
        $st->bindValue(1, $png, PDO::PARAM_LOB);
        $st->bindValue(2, $v, PDO::PARAM_STR);
        $st->bindValue(3, $me, PDO::PARAM_STR);
        $st->execute();
    } catch (Throwable $e) {
        return [false, "Enregistrement en base impossible.", null];
    }

    return [true, "Photo de profil mise à jour.", $v];
}
