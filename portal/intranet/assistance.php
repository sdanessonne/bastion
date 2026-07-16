<?php
/** Bastion — Assistance informatique : contact + formulaire de demande (enregistré en base). */
require_once __DIR__ . '/_common.php';
$me = intranet_user();
$db = intranet_db();
if ($db) {
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS pf_support (
            id INT AUTO_INCREMENT PRIMARY KEY, ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            username VARCHAR(64), client_ip VARCHAR(45), subject VARCHAR(160), message TEXT,
            status VARCHAR(20) DEFAULT "nouveau")');
    } catch (Throwable $e) {}
}

$sent = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    if ($subject === '' || $message === '') {
        $err = 'Merci d\'indiquer un objet et une description.';
    } elseif (!$db) {
        $err = 'Service indisponible, réessayez plus tard.';
    } else {
        try {
            $db->prepare('INSERT INTO pf_support (username,client_ip,subject,message) VALUES (?,?,?,?)')
               ->execute([$me['user'] ?: 'anonyme', $_SERVER['REMOTE_ADDR'] ?? '', substr($subject, 0, 160), substr($message, 0, 4000)]);
            $sent = true;
        } catch (Throwable $e) { $err = 'Erreur lors de l\'envoi.'; }
    }
}

intranet_head('Assistance informatique', 'assistance');
?>
<h1>Assistance informatique</h1>
<div class="card">
  <p><strong>Besoin d'aide ?</strong> Décrivez votre problème ci-dessous : le service informatique le prendra
  en charge. Pour une urgence, contactez directement le support technique de votre site.</p>
</div>

<?php if ($sent): ?>
  <div class="ok">✅ Votre demande a bien été enregistrée. Le service informatique vous recontactera.</div>
<?php endif; ?>
<?php if ($err): ?><div class="ok" style="background:rgba(248,113,113,.12);border-color:rgba(248,113,113,.35);color:#fca5a5"><?= e_($err) ?></div><?php endif; ?>

<div class="card">
  <form method="post">
    <label>Objet
      <input type="text" name="subject" maxlength="160" required placeholder="ex. Impossible d'accéder à un site">
    </label>
    <label>Description
      <textarea name="message" rows="6" required placeholder="Décrivez le problème, le poste concerné, l'heure…"></textarea>
    </label>
    <?php if ($me['user']): ?><p class="muted" style="font-size:.8rem">Demande envoyée au nom de <strong><?= e_($me['user']) ?></strong>.</p><?php endif; ?>
    <button type="submit">Envoyer la demande</button>
  </form>
</div>
<?php intranet_foot();
