<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — déconnexion. */
require_once __DIR__ . '/inc/config.php';
$_SESSION = [];
session_destroy();
header('Location: /login.php');
