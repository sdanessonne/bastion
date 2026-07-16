<?php
/** Bastion — affichage d'une page de contenu du CMS intranet. */
require_once __DIR__ . '/_common.php';

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$page = null;
if ($slug !== '' && ($db = intranet_db())) {
    try {
        $st = $db->prepare('SELECT * FROM pf_cms_pages WHERE slug=? AND published=1');
        $st->execute([$slug]);
        $page = $st->fetch();
    } catch (Throwable $e) {}
}

// Page réservée à un groupe ?
$denied = $page && !empty($page['group_required']) && !in_array($page['group_required'], intranet_groups(), true);

intranet_head($page['title'] ?? 'Page introuvable', $slug);
if (!$page) {
    echo '<div class="card"><h1>Page introuvable</h1><p class="muted">Cette page n\'existe pas ou n\'est pas publiée.</p>'
       . '<p><a class="back" href="/portal/intranet.php">← Retour à l\'accueil</a></p></div>';
} elseif ($denied) {
    echo '<div class="card"><h1>🔒 Accès réservé</h1><p class="muted">Cette page est réservée au groupe « '
       . e_($page['group_required']) . ' ». Rapprochez-vous de votre administrateur si besoin.</p>'
       . '<p><a class="back" href="/portal/intranet.php">← Retour à l\'accueil</a></p></div>';
} else {
    echo '<article class="card prose">';
    echo '<h1>' . e_($page['title']) . '</h1>';
    echo cms_render((string) $page['body']);
    echo '</article>';
    echo '<p><a class="back" href="/portal/intranet.php">← Retour à l\'accueil</a></p>';
}
intranet_foot();
