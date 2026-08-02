<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — certificat de l'autorité, téléchargeable depuis le portail.
 *
 * ── POURQUOI C'EST PUBLIC, ET POURQUOI CE N'EST PAS UNE FUITE ───────────────
 * Un certificat d'AUTORITÉ ne contient aucune clé privée : c'est la partie
 * publique, destinée à être distribuée. C'est même sa seule raison d'être.
 * Il était pourtant offert uniquement par la console d'administration — donc
 * hors de portée d'un téléphone, qui est précisément l'appareil qui en a besoin
 * pour reconnaître le portail.
 *
 * Sans cette confiance, le navigateur affiche « Non sécurisé », REFUSE
 * d'enregistrer le service worker, et l'installation de l'application devient
 * impossible. Le manque ne se voyait nulle part : la bannière d'installation
 * restait simplement muette.
 */
require_once __DIR__ . '/https_guard.php';

$f = '/etc/proxyfibre/bastion-ca.crt';
$pem = @file_get_contents($f);
if ($pem === false || strpos($pem, 'BEGIN CERTIFICATE') === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Certificat d'autorité introuvable sur cette passerelle.\n");
}

// GARDE-FOU : on refuse de servir quoi que ce soit qui contiendrait une clé privée.
// Le fichier attendu n'en a pas ; si un jour quelqu'un place le mauvais fichier ici,
// mieux vaut une erreur qu'une diffusion silencieuse de la clé de l'autorité.
if (stripos($pem, 'PRIVATE KEY') !== false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Refus : ce fichier contient une clé privée.\n");
}

// Android exige « application/x-x509-ca-cert » pour proposer l'installation ;
// iOS accepte ce type et ouvre l'assistant de profil.
header('Content-Type: application/x-x509-ca-cert');
header('Content-Disposition: attachment; filename="Bastion-autorite.crt"');
header('Content-Length: ' . strlen($pem));
header('Cache-Control: public, max-age=86400');
echo $pem;
