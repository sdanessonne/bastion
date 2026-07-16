<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — personnalisation du site intranet (affiché après connexion). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();

$DEF = [
    'intranet_title'   => 'Intranet',
    'intranet_welcome' => 'Bienvenue sur l’espace interne. Retrouvez ici vos services et informations.',
    'intranet_notice'  => '',
    'intranet_links'   => "Mon compte & consommation|/portal/account.php|📊\n"
                        . "Annuaire du personnel|/portal/intranet/annuaire.php|📇\n"
                        . "Documentation|/portal/intranet/documentation.php|📚\n"
                        . "Assistance informatique|/portal/intranet/assistance.php|🛠️",
];

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $up = $db->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
    $up->execute(['intranet_title',   trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['intranet_title'] ?? '')))]);
    $up->execute(['intranet_welcome', trim((string) ($_POST['intranet_welcome'] ?? ''))]);
    $up->execute(['intranet_notice',  trim((string) ($_POST['intranet_notice'] ?? ''))]);
    $up->execute(['intranet_links',   trim((string) ($_POST['intranet_links'] ?? ''))]);
    $flash = ['Intranet mis à jour — visible dès la prochaine connexion des utilisateurs.', 'ok'];
}

$S = $DEF;
try { foreach ($db->query("SELECT k,v FROM pf_settings WHERE k LIKE 'intranet\\_%'") as $r) {
    if ($r['v'] !== null && $r['v'] !== '') { $S[$r['k']] = $r['v']; } } }
catch (Throwable $e) {}
$v = fn($k) => e($S[$k] ?? '');

pf_header('Intranet', 'intranet.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<style>
  .field{display:grid;gap:.35rem;margin-bottom:1rem;font-size:.82rem;color:var(--muted)}
  .field input,.field textarea{padding:.6rem .7rem;background:var(--bg);color:var(--text);
    border:1px solid var(--line);border-radius:8px;font-size:.95rem}
  .field textarea{font-family:ui-monospace,monospace;font-size:.85rem;line-height:1.6}
</style>
<section class="panel form-panel">
  <div class="panel-head"><h2>🌐 Contenu du site intranet</h2>
    <a class="btn-sm" href="https://<?= e($_SERVER['SERVER_NAME'] ?? 'localhost') ?>" onclick="return false" style="pointer-events:none;opacity:.5">après connexion</a>
  </div>
  <form method="post" style="padding:1.2rem">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label class="field">Titre <input type="text" name="intranet_title" value="<?= $v('intranet_title') ?>"></label>
    <label class="field">Message d'accueil
      <textarea name="intranet_welcome" rows="2"><?= $v('intranet_welcome') ?></textarea></label>
    <label class="field">Bandeau d'annonce <span class="muted small">(optionnel, laisser vide pour masquer)</span>
      <input type="text" name="intranet_notice" value="<?= $v('intranet_notice') ?>" placeholder="ex. Maintenance prévue vendredi 18h"></label>
    <label class="field">Services / liens <span class="muted small">— un par ligne, format <code>Libellé|URL|emoji</code></span>
      <textarea name="intranet_links" rows="8"><?= $v('intranet_links') ?></textarea></label>
    <div class="form-actions"><button class="btn">💾 Enregistrer</button></div>
    <p class="muted small">Astuce : une URL commençant par <code>http</code> s'ouvre dans un nouvel onglet ;
    <code>#</code> = lien à définir. Exemple : <code>Messagerie|https://webmail.interne.lan|✉️</code>.</p>
  </form>
</section>
<?php pf_footer(); ?>
