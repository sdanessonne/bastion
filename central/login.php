<?php
/** Bastion Central — connexion (mêmes comptes que la console : table pf_admins). */
require_once __DIR__ . '/inc/config.php';
if (!empty($_SESSION['central'])) { header('Location: /index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = trim((string) ($_POST['username'] ?? ''));
    $p = (string) ($_POST['password'] ?? '');
    try {
        $st = pf_db()->prepare('SELECT password_hash FROM pf_admins WHERE username = ?');
        $st->execute([$u]);
        $row = $st->fetch();
        if ($row && password_verify($p, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['central'] = $u;
            header('Location: /index.php'); exit;
        }
    } catch (Throwable $ex) { /* erreur générique */ }
    $error = 'Identifiant ou mot de passe incorrect.';
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bastion Central — Connexion</title>
  <link rel="icon" href="/assets/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="/assets/bastion-icon.svg">
  <link rel="stylesheet" href="/assets/central.css">
</head>
<body class="login-body">
  <main class="login-card">
    <div class="brand-center"><img class="logo" src="/assets/bastion-icon.svg" alt="Bastion"><h1>Bastion Central</h1>
      <p class="muted">Supervision départementale des passerelles</p></div>
    <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label>Identifiant<input type="text" name="username" required autofocus></label>
      <label>Mot de passe<input type="password" name="password" required></label>
      <button type="submit" class="btn">Se connecter</button>
    </form>
  </main>
</body>
</html>
