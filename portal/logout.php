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
    // « proxyfibre-deauth » et non « ndsctl deauth » : ce dernier retire bien les
    // règles de pare-feu, mais laisse VIVRE les connexions déjà établies dans le
    // suivi de connexions du noyau. Mesuré ici : 46 connexions survivaient à la
    // déconnexion, le navigateur les réutilisait, et l'agent restait en ligne sous
    // une session officiellement close. Le script purge donc aussi le conntrack.
    $r = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-deauth '
        . escapeshellarg($clientIp) . ' 2>&1'));
    // On ne déclare plus la réussite d'office. La version précédente affichait
    // « Vous êtes déconnecté » quoi qu'il arrive — y compris quand la commande
    // échouait. Un écran rassurant et faux est pire qu'un message d'échec.
    $done = (strpos($r, 'deconnecte:') === 0 || strpos($r, 'absent:') === 0);
    $erreur = $done ? '' : $r;
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
    <h1><?= $done ? 'Vous êtes déconnecté' : (!empty($erreur) ? 'Déconnexion incomplète' : 'Déconnexion') ?></h1>
    <p class="muted">
      <?php if ($done): ?>
        Votre accès à Internet a été fermé, et les connexions en cours ont été coupées.
        Reconnectez-vous pour y accéder de nouveau.
      <?php elseif (!empty($erreur)): ?>
        La passerelle n'a pas confirmé la fermeture de votre accès. Signalez-le à
        l'administrateur ; votre session est peut-être encore ouverte.
        <br><span class="mono small"><?= htmlspecialchars($erreur) ?></span>
      <?php else: ?>
        Utilisez le bouton du tableau de bord pour vous déconnecter.
      <?php endif; ?>
    </p>
    <a class="btn" href="/portal/fas.php">Se reconnecter</a>
  </main>
  <script>
  // Purge du cache de l'application à la déconnexion. Le service worker conserve
  // les pages consultées pour le mode hors ligne ; sur un téléphone de service
  // PARTAGÉ, l'agent suivant pourrait s'y voir servir le tableau de bord du
  // précédent. La déconnexion doit donc effacer, pas seulement fermer la session.
  (function () {
    if (!('serviceWorker' in navigator)) { return; }
    navigator.serviceWorker.ready.then(function (reg) {
      if (reg.active) { reg.active.postMessage('purge'); }
    }).catch(function () {});
    // Repli : si le service worker ne répond pas, on vide depuis la page.
    if (window.caches && caches.keys) {
      caches.keys().then(function (ks) { ks.forEach(function (k) { caches.delete(k); }); }).catch(function () {});
    }
  })();
  </script>
</body>
</html>
