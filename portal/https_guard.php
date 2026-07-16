<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — garde HTTPS du portail.
 * OpenNDS redirige d'abord le client en HTTP (:2080). Ce garde rebondit vers le
 * portail HTTPS (:2443) en conservant le chemin et les paramètres, pour que
 * l'affichage et l'envoi du mot de passe se fassent chiffrés.
 * Inclure en tête de chaque page du portail, avant toute sortie.
 */
$onHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == '2443');
if (!$onHttps) {
    $host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0] ?: ($_SERVER['SERVER_ADDR'] ?? '192.168.182.1');
    $uri  = $_SERVER['REQUEST_URI'] ?? '/portal/fas.php';
    header('Location: https://' . $host . ':2443' . $uri);
    exit;
}
