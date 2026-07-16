<?php
/** Bastion Central — en-tête / navigation / pied de page. */

function pf_header(string $title, string $active = ''): void {
    $nav = [
        'index.php'  => ['Vue d\'ensemble', '▚'],
        'sites.php'  => ['Sites / passerelles', '🏢'],
        'push.php'   => ['Actions groupées', '📤'],
    ];
    $admin = $_SESSION['central'] ?? '';
    ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bastion Central — <?= e($title) ?></title>
  <link rel="icon" href="/assets/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="/assets/bastion-icon.svg">
  <link rel="stylesheet" href="/assets/central.css">
</head>
<body>
  <aside class="sidebar">
    <div class="brand"><img class="logo" src="/assets/bastion-icon.svg" alt="Bastion"><span>Bastion<br><small>Central</small></span></div>
    <nav>
      <?php foreach ($nav as $file => [$label, $icon]): ?>
        <a href="/<?= $file ?>" class="<?= $active === $file ? 'active' : '' ?>">
          <span class="ico"><?= $icon ?></span><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <span class="muted small"><?= e($admin) ?></span>
      <a class="logout" href="/logout.php">Se déconnecter</a>
    </div>
  </aside>
  <main class="content">
    <header class="topbar"><h1><?= e($title) ?></h1></header>
    <div class="page">
    <?php
}

function pf_footer(): void {
    ?>
    </div>
  </main>
</body>
</html>
    <?php
}

function pf_flash(string $msg, string $type = 'ok'): void {
    echo '<div class="flash ' . e($type) . '">' . e($msg) . '</div>';
}
