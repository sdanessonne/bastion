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
