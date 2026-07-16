<?php
/** Bastion Central — déconnexion. */
require_once __DIR__ . '/inc/config.php';
$_SESSION = [];
session_destroy();
header('Location: /login.php');
