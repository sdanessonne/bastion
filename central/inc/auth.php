<?php
/** Bastion Central — garde d'authentification (comptes pf_admins). */
require_once __DIR__ . '/config.php';
if (empty($_SESSION['central'])) { header('Location: /login.php'); exit; }
$ADMIN = $_SESSION['central'];
