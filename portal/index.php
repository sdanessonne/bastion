<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — page de login du portail captif (protocole UAM CoovaChilli).
 *
 * Flux :
 *   1. CoovaChilli redirige le client non authentifié vers cette page avec, en
 *      paramètres GET : uamip, uamport, challenge, userurl, res, mac, nasid…
 *   2. L'utilisateur saisit login / mot de passe.
 *   3. On calcule la réponse CHAP à partir du challenge et du UAM_SECRET partagé
 *      (le mot de passe ne transite jamais en clair vers CoovaChilli).
 *   4. On redirige le navigateur vers http://<uamip>:<uamport>/logon?...
 *   5. CoovaChilli interroge FreeRADIUS et ouvre (ou non) la session.
 */

// --- Secret UAM partagé avec CoovaChilli (hors webroot) ---
$secretsFile = '/etc/proxyfibre/portal.env';
$UAM_SECRET = '';
if (is_readable($secretsFile)) {
    foreach (file($secretsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^UAM_SECRET="?([^"]*)"?$/', $line, $m)) {
            $UAM_SECRET = $m[1];
        }
    }
}

// --- Paramètres transmis par CoovaChilli ---
function q(string $k, string $d = ''): string {
    return isset($_REQUEST[$k]) ? (string) $_REQUEST[$k] : $d;
}
$uamip    = q('uamip');
$uamport  = q('uamport');
$challenge = q('challenge');
$userurl  = q('userurl');
$res      = q('res');           // notyet | success | failed | logoff | already …
$error    = '';

// --- Traitement de la soumission du formulaire ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $challenge !== '' && $uamip !== '') {
    $username = q('username');
    $password = q('password');

    // Challenge réel = MD5(challenge_binaire XOR uamsecret) si un secret est défini.
    $chalBin = hex2bin($challenge) ?: '';
    if ($UAM_SECRET !== '') {
        $chalBin = md5($chalBin . $UAM_SECRET, true);
    }
    // Réponse CHAP = MD5( \0 + password + challenge_réel )
    $response = md5("\0" . $password . $chalBin);

    // Redirection vers CoovaChilli pour valider l'authentification.
    $logon = sprintf(
        'http://%s:%s/logon?username=%s&response=%s&userurl=%s',
        rawurlencode($uamip),
        rawurlencode($uamport),
        rawurlencode($username),
        $response,
        rawurlencode($userurl)
    );
    header('Location: ' . $logon);
    exit;
}

// --- Messages selon l'état renvoyé par CoovaChilli ---
if ($res === 'failed')  { $error = 'Identifiant ou mot de passe incorrect.'; }
if ($res === 'already') { $error = 'Cette session est déjà connectée.'; }

$authenticated = in_array($res, ['success', 'already'], true);
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bastion — Accès Internet</title>
  <link rel="icon" href="/portal/assets/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="/portal/assets/bastion-icon.svg">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <main class="card">
    <div class="brand">
      <img class="logo" src="/portal/assets/bastion-icon.svg" width="64" height="64" alt="Bastion" style="display:block;margin:0 auto .5rem">
      <h1>Bastion</h1>
      <p class="tagline">Portail d'accès Internet sécurisé</p>
    </div>

    <?php if ($authenticated): ?>
      <div class="status ok">
        <p><strong>Vous êtes connecté.</strong></p>
        <p>Votre accès à Internet est ouvert. Vous pouvez fermer cette fenêtre.</p>
        <?php if ($userurl): ?>
          <a class="btn" href="<?= htmlspecialchars($userurl, ENT_QUOTES) ?>">Continuer</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
      <?php endif; ?>
      <form method="post" class="login" autocomplete="off">
        <input type="hidden" name="uamip"     value="<?= htmlspecialchars($uamip, ENT_QUOTES) ?>">
        <input type="hidden" name="uamport"   value="<?= htmlspecialchars($uamport, ENT_QUOTES) ?>">
        <input type="hidden" name="challenge" value="<?= htmlspecialchars($challenge, ENT_QUOTES) ?>">
        <input type="hidden" name="userurl"   value="<?= htmlspecialchars($userurl, ENT_QUOTES) ?>">

        <label>Identifiant
          <input type="text" name="username" required autofocus>
        </label>
        <label>Mot de passe
          <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn">Se connecter</button>
      </form>
      <?php if ($challenge === ''): ?>
        <p class="hint">Connectez-vous d'abord au réseau Wi-Fi/filaire Bastion :
        cette page s'ouvrira automatiquement.</p>
      <?php endif; ?>
    <?php endif; ?>

    <footer>Accès soumis à authentification et journalisation (art. L.34-1 CPCE).</footer>
  </main>
</body>
</html>
