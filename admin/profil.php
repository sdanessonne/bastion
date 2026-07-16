<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Mon profil : mot de passe et double authentification (2FA/TOTP).
 * Chaque administrateur gère ici sa propre sécurité de connexion.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/totp.php';
$db = pf_db();
$me = (string) $_SESSION['admin'];

$row = $db->query('SELECT password_hash, totp_secret, totp_enabled FROM pf_admins WHERE username=' . $db->quote($me))->fetch();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Changer son mot de passe ──
    if ($action === 'password') {
        $cur = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        $cnf = (string) ($_POST['confirm'] ?? '');
        if (!$row || !password_verify($cur, $row['password_hash'])) {
            $flash = ['Mot de passe actuel incorrect.', 'err'];
        } elseif (strlen($new) < 8 || !preg_match('/[A-Z]/', $new) || !preg_match('/\d/', $new)) {
            $flash = ['Le nouveau mot de passe doit faire 8+ caractères, avec majuscule et chiffre.', 'err'];
        } elseif ($new !== $cnf) {
            $flash = ['La confirmation ne correspond pas.', 'err'];
        } else {
            $h = password_hash($new, PASSWORD_DEFAULT);
            $db->prepare('UPDATE pf_admins SET password_hash=? WHERE username=?')->execute([$h, $me]);
            $flash = ['Mot de passe modifié.', 'ok'];
        }
    }

    // ── Démarrer l'activation 2FA : génère un secret provisoire en session ──
    if ($action === '2fa_start') {
        $_SESSION['totp_setup'] = totp_gen_secret();
        $flash = ['Scannez le QR code puis saisissez un code pour activer.', 'ok'];
    }

    // ── Confirmer l'activation 2FA (vérifie un premier code) ──
    if ($action === '2fa_enable' && !empty($_SESSION['totp_setup'])) {
        $sec = (string) $_SESSION['totp_setup'];
        if (totp_verify($sec, (string) ($_POST['code'] ?? ''))) {
            $db->prepare('UPDATE pf_admins SET totp_secret=?, totp_enabled=1 WHERE username=?')->execute([$sec, $me]);
            unset($_SESSION['totp_setup']);
            $flash = ['Double authentification activée. ✅', 'ok'];
        } else {
            $flash = ['Code incorrect, réessayez (vérifiez l\'heure de l\'appareil).', 'err'];
        }
    }

    // ── Désactiver la 2FA (exige le mot de passe) ──
    if ($action === '2fa_disable') {
        if (!$row || !password_verify((string) ($_POST['password'] ?? ''), $row['password_hash'])) {
            $flash = ['Mot de passe incorrect : désactivation refusée.', 'err'];
        } else {
            $db->prepare('UPDATE pf_admins SET totp_secret=NULL, totp_enabled=0 WHERE username=?')->execute([$me]);
            unset($_SESSION['totp_setup']);
            $flash = ['Double authentification désactivée.', 'ok'];
        }
    }

    // Recharge l'état après action.
    $row = $db->query('SELECT password_hash, totp_secret, totp_enabled FROM pf_admins WHERE username=' . $db->quote($me))->fetch();
}

$enabled = !empty($row['totp_enabled']);
$setup   = $_SESSION['totp_setup'] ?? '';   // secret provisoire en cours d'activation

// QR code (data URI PNG) via qrencode, si une activation est en cours.
$qrData = '';
if ($setup !== '') {
    $uri = totp_uri($setup, $me, 'Bastion');
    $png = shell_exec('printf %s ' . escapeshellarg($uri) . ' | qrencode -m 1 -s 6 -o - -t PNG 2>/dev/null');
    if ($png) { $qrData = 'data:image/png;base64,' . base64_encode($png); }
    $secretGrouped = trim(chunk_split($setup, 4, ' '));
}

pf_header('Mon profil', '');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<style>
  .prof-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start}
  @media (max-width:900px){.prof-grid{grid-template-columns:1fr}}
  .prof-form label{display:grid;gap:.3rem;margin-bottom:.8rem;font-size:.82rem;color:var(--muted)}
  .prof-form input{padding:.6rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px}
  .twofa-state{display:inline-flex;align-items:center;gap:.4rem;font-size:.8rem;padding:.2rem .6rem;border-radius:20px}
  .st-on{background:rgba(34,197,94,.15);color:var(--ok)} .st-off{background:rgba(248,113,113,.15);color:var(--danger)}
  .qrbox{text-align:center;padding:1rem;background:#fff;border-radius:10px;display:inline-block}
  .qrbox img{display:block;width:180px;height:180px;image-rendering:pixelated}
  .secretkey{font-family:monospace;font-size:1.05rem;letter-spacing:.08em;background:var(--bg);border:1px dashed var(--line);
             border-radius:8px;padding:.6rem .8rem;text-align:center;user-select:all}
</style>

<div class="prof-grid">
  <!-- ── Identité + mot de passe ── -->
  <section class="panel">
    <div class="panel-head"><h2>👤 Mon compte</h2></div>
    <div style="padding:1.2rem">
      <p style="margin:0 0 1rem"><strong style="font-size:1.1rem"><?= e($me) ?></strong><br>
        <span class="muted small">Administrateur de la console Bastion</span></p>
      <h3 style="font-size:.95rem;margin:.5rem 0 .8rem">Changer mon mot de passe</h3>
      <form method="post" class="prof-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="password">
        <label>Mot de passe actuel<input type="password" name="current" required></label>
        <label>Nouveau mot de passe <span class="muted small">(8+ car., majuscule, chiffre)</span><input type="password" name="new" required></label>
        <label>Confirmer<input type="password" name="confirm" required></label>
        <button class="btn">Mettre à jour</button>
      </form>
    </div>
  </section>

  <!-- ── Double authentification ── -->
  <section class="panel">
    <div class="panel-head"><h2>🔐 Double authentification (2FA)</h2>
      <span class="twofa-state <?= $enabled ? 'st-on' : 'st-off' ?>"><?= $enabled ? '● Activée' : '○ Désactivée' ?></span>
    </div>
    <div style="padding:1.2rem">
      <p class="muted small" style="margin-top:0">Un code à usage unique généré par une application (Google
        Authenticator, Microsoft Authenticator, FreeOTP, Aegis…) est demandé à chaque connexion, en plus du mot de passe.</p>

      <?php if ($enabled): ?>
        <div class="flash ok" style="margin:.5rem 0 1rem">✅ La double authentification protège votre compte.</div>
        <form method="post" class="prof-form" autocomplete="off"
              onsubmit="return confirm('Désactiver la double authentification ?')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="2fa_disable">
          <label>Confirmez avec votre mot de passe<input type="password" name="password" required></label>
          <button class="btn btn-danger">Désactiver la 2FA</button>
        </form>

      <?php elseif ($setup !== ''): ?>
        <ol class="muted small" style="margin:.2rem 0 1rem;padding-left:1.1rem;line-height:1.7">
          <li>Ouvrez votre application d'authentification.</li>
          <li>Scannez le QR code (ou saisissez la clé manuellement).</li>
          <li>Entrez le code à 6 chiffres affiché pour confirmer.</li>
        </ol>
        <div style="display:flex;gap:1.2rem;flex-wrap:wrap;align-items:flex-start">
          <?php if ($qrData): ?><div class="qrbox"><img src="<?= e($qrData) ?>" alt="QR code 2FA"></div><?php endif; ?>
          <div style="flex:1;min-width:180px">
            <p class="muted small" style="margin:.2rem 0 .3rem">Clé manuelle :</p>
            <div class="secretkey"><?= e($secretGrouped) ?></div>
          </div>
        </div>
        <form method="post" class="prof-form" autocomplete="off" style="margin-top:1.2rem">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="2fa_enable">
          <label>Code de vérification
            <input type="text" name="code" inputmode="numeric" maxlength="7" required autofocus
                   style="text-align:center;letter-spacing:.3em;font-size:1.2rem"></label>
          <div style="display:flex;gap:.6rem">
            <button class="btn">Activer</button>
            <a class="btn-sm" href="/profil.php" style="align-self:center">Annuler</a>
          </div>
        </form>

      <?php else: ?>
        <form method="post" style="margin-top:.5rem">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="2fa_start">
          <button class="btn">Activer la double authentification</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php pf_footer(); ?>
