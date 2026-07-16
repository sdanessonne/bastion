<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — déconnexion de l'utilisateur du portail captif.
 * Déauthentifie le client courant (par son IP) auprès d'OpenNDS, puis affiche
 * une confirmation.
 */
require_once __DIR__ . '/https_guard.php';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && filter_var($clientIp, FILTER_VALIDATE_IP)) {
    // ndsctl accepte l'IP directement : deauth mac|ip|token
    shell_exec('sudo /usr/bin/ndsctl deauth ' . escapeshellarg($clientIp) . ' 2>/dev/null');
    $done = true;
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bastion — Déconnexion</title>
  <link rel="icon" href="/portal/assets/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="/portal/assets/bastion-icon.svg">
  <link rel="stylesheet" href="/portal/assets/account.css">
</head>
<body>
  <main class="card centered" style="align-self:center">
    <img class="logo" src="/portal/assets/bastion-icon.svg" alt="Bastion">
    <h1><?= $done ? 'Vous êtes déconnecté' : 'Déconnexion' ?></h1>
    <p class="muted">
      <?= $done
        ? "Votre accès à Internet a été fermé. Reconnectez-vous pour y accéder de nouveau."
        : "Utilisez le bouton du tableau de bord pour vous déconnecter." ?>
    </p>
    <a class="btn" href="/portal/fas.php">Se reconnecter</a>
  </main>
</body>
</html>
