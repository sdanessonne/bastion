<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — téléchargement du certificat de l'autorité racine « Bastion ».
 *
 * Sert le certificat PUBLIC de l'autorité (/etc/proxyfibre/bastion-ca.crt) pour qu'un
 * administrateur puisse l'APPROUVER sur son poste (magasin « Autorités de certification
 * racines de confiance »). Une fois l'autorité approuvée, la console s'ouvre SANS
 * avertissement sur https://127.0.0.1:8443, https://192.168.182.1:8443 et
 * https://bastion.pn.int:8443 — le certificat serveur couvre déjà tous ces noms.
 *
 * SÉCURITÉ : seul le certificat PUBLIC de l'autorité est exposé, JAMAIS sa clé privée
 * (bastion-ca.key). Accès réservé aux administrateurs connectés (inc/auth.php).
 */
require_once __DIR__ . '/inc/auth.php';

$caFile = '/etc/proxyfibre/bastion-ca.crt';
$pem    = @file_get_contents($caFile);

if ($pem === false || strpos($pem, 'BEGIN CERTIFICATE') === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Certificat racine introuvable. Régénérez-le depuis la page Système.";
    exit;
}

// Téléchargement en pièce jointe, type MIME reconnu par Windows/macOS (propose l'import).
header('Content-Type: application/x-x509-ca-cert');
header('Content-Disposition: attachment; filename="bastion-ca.crt"');
header('Content-Length: ' . strlen($pem));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo $pem;
