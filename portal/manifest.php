<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion — manifeste PWA de l'intranet (nom repris du réglage intranet_title). */
require_once __DIR__ . '/intranet/_common.php';
header('Content-Type: application/manifest+json; charset=utf-8');
$name = intranet_setting('intranet_title', 'Intranet');
echo json_encode([
    'name'             => $name,
    'short_name'       => strlen($name) > 12 ? 'Intranet' : $name,
    'description'      => 'Espace interne — ' . $name,
    'start_url'        => '/portal/intranet.php',
    'scope'            => '/portal/',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#0f172a',
    'theme_color'      => '#0f172a',
    'lang'             => 'fr',
    'icons'            => [
        ['src' => '/portal/assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => '/portal/assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
