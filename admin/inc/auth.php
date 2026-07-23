<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — garde d'authentification.
 * À inclure en tête de chaque page protégée. Redirige vers login si non connecté.
 */
require_once __DIR__ . '/config.php';

if (empty($_SESSION['admin'])) {
    header('Location: /login.php');
    exit;
}
$ADMIN_USER = $_SESSION['admin'];

// ── Rôles d'administration ───────────────────────────────────────────────────
// GARDE-FOU anti-verrouillage : le compte « admin » a TOUJOURS tous les droits, et un rôle
// absent (colonne pas encore créée) vaut « full ». Impossible donc de se verrouiller hors de
// la console : le compte admin passe partout, quoi qu'il arrive.
$ADMIN_ROLE = 'full';
if ($ADMIN_USER !== 'admin') {
    try {
        $st = pf_db()->prepare('SELECT role FROM pf_admins WHERE username = ?');
        $st->execute([$ADMIN_USER]);
        $r = (string) ($st->fetchColumn() ?: '');
        if (in_array($r, ['comptes', 'lecture'], true)) { $ADMIN_ROLE = $r; }
    } catch (Throwable $e) { $ADMIN_ROLE = 'full'; }
}
$_SESSION['admin_role'] = $ADMIN_ROLE;

// Application du rôle. On ne bloque JAMAIS durement une navigation : on redirige vers une page
// autorisée. Les pages transverses (profil, déconnexion, images de profil) restent ouvertes.
if ($ADMIN_ROLE !== 'full') {
    $pfPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $pfAlways = ['index.php', 'profil.php', 'logout.php', 'login.php', 'avatar.php', 'user-photo.php'];
    if ($ADMIN_ROLE === 'comptes') {
        // Gestion des comptes et des agents uniquement.
        if (!in_array($pfPage, array_merge($pfAlways, ['users.php', 'annuaire.php', 'badge.php', 'groups.php']), true)) {
            header('Location: /users.php'); exit;
        }
    } elseif ($ADMIN_ROLE === 'lecture') {
        // Lecture seule : consultation partout, AUCUNE modification (toute écriture POST refusée),
        // sauf sa propre fiche (profil) et la déconnexion.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && !in_array($pfPage, ['profil.php', 'logout.php', 'login.php'], true)) {
            http_response_code(403);
            echo '<!doctype html><meta charset="utf-8"><body style="font-family:system-ui,sans-serif;background:#0b1120;color:#e2e8f0;padding:2rem">'
               . '<h2>Compte en lecture seule</h2><p>Votre compte administrateur est en <strong>lecture seule</strong> : '
               . 'les modifications ne sont pas autorisées.</p><p><a href="/' . htmlspecialchars($pfPage, ENT_QUOTES) . '" style="color:#38bdf8">← Retour</a></p></body>';
            exit;
        }
    }
}
