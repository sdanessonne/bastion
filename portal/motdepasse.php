<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — changement de mot de passe par l'agent lui-même (portail, port 2443).
 *
 * ── POURQUOI LE MOT DE PASSE ACTUEL EST EXIGÉ ────────────────────────────────
 * Le portail reconnaît l'agent par son ADRESSE IP : OpenNDS associe la session au
 * client, et le matricule est lu dans le champ « custom ». C'est suffisant pour
 * afficher un tableau de bord ; ce ne l'est pas pour changer un mot de passe. Un
 * appareil qui récupérerait l'adresse d'un agent parti — bail DHCP relâché, poste
 * partagé, adresse fixée à la main — hériterait de son identité.
 *
 * Le mot de passe actuel est donc redemandé. C'est lui, et non l'adresse, qui
 * autorise le changement.
 *
 * ── LES DEUX MOTS DE PASSE N'EN FONT QU'UN ───────────────────────────────────
 * L'agent en a un seul de son point de vue : celui de sa session Windows et celui
 * du portail. Ils vivent pourtant à deux endroits — « radcheck » pour RADIUS,
 * l'annuaire pour Windows. La console les change ensemble ; cette page fait de
 * même, sans quoi l'agent repartirait avec deux mots de passe différents et
 * découvrirait le second au pire moment.
 */
require_once __DIR__ . '/https_guard.php';
require_once __DIR__ . '/nds.php';
require_once __DIR__ . '/intranet/_common.php';
require_once __DIR__ . '/mdp-regles.php';   // MDP_MIN + mdp_refus(), isolés pour être testables

// Session propre au portail : le nom par défaut serait partagé avec la console
// d'administration, qui tourne sur le même serveur.
session_name('PFPORTAL');
session_start();

const MDP_ESSAIS   = 5;    // tentatives autorisées…
const MDP_FENETRE  = 900;  // …par quart d'heure

// ── Qui est l'agent ? ────────────────────────────────────────────────────────
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$client   = pf_nds_client($clientIp, 10);
$authentifie = $client && (($client['state'] ?? '') === 'Authenticated');

$username = '';
if ($client && !empty($client['custom'])) {
    $dec = base64_decode((string) $client['custom'], true);
    if ($dec !== false && preg_match('/user=([^,]+)/', $dec, $m)) { $username = $m[1]; }
}

if (empty($_SESSION['csrf_mdp'])) { $_SESSION['csrf_mdp'] = bin2hex(random_bytes(16)); }

$erreur = '';
$succes = false;

/** Tentatives ratées récentes, pour ce compte et cette adresse. */
function mdp_essais_recents(PDO $pdo, string $user, string $ip): int {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM pf_login_attempts
                              WHERE ok=0 AND username=? AND ip=? AND ts > (NOW() - INTERVAL ? SECOND)');
        $st->execute([$user, $ip, MDP_FENETRE]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function mdp_tracer(PDO $pdo, string $user, string $ip, bool $ok): void {
    try {
        $pdo->prepare('INSERT INTO pf_login_attempts (ts,ip,username,ok) VALUES (NOW(),?,?,?)')
            ->execute([$ip, $user, $ok ? 1 : 0]);
    } catch (Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = intranet_db();
    if (!hash_equals((string) ($_SESSION['csrf_mdp'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        $erreur = 'Formulaire expiré. Rechargez la page et recommencez.';
    } elseif (!$authentifie || $username === '') {
        $erreur = 'Votre session n’est plus active. Reconnectez-vous au portail.';
    } elseif ($pdo === null) {
        $erreur = 'Service indisponible pour le moment.';
    } elseif (mdp_essais_recents($pdo, $username, $clientIp) >= MDP_ESSAIS) {
        $erreur = 'Trop de tentatives. Réessayez dans un quart d’heure.';
    } else {
        $actuel  = (string) ($_POST['actuel'] ?? '');
        $nouveau = (string) ($_POST['nouveau'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        $enBase = null;
        try {
            $st = $pdo->prepare('SELECT value FROM radcheck WHERE username=? AND attribute="Cleartext-Password" LIMIT 1');
            $st->execute([$username]);
            $v = $st->fetchColumn();
            if ($v !== false) { $enBase = (string) $v; }
        } catch (Throwable $e) {}

        if ($enBase === null) {
            $erreur = 'Aucun accès au portail n’est associé à votre compte. Adressez-vous à l’administrateur.';
        } elseif (!hash_equals($enBase, $actuel)) {
            // Comparaison à temps constant : un simple « === » laisse mesurer la
            // progression du mot de passe caractère par caractère.
            mdp_tracer($pdo, $username, $clientIp, false);
            $erreur = 'Mot de passe actuel incorrect.';
        } elseif ($nouveau !== $confirm) {
            $erreur = 'Les deux nouveaux mots de passe ne correspondent pas.';
        } elseif (($r = mdp_refus($nouveau, $username, $enBase)) !== '') {
            $erreur = $r;
        } else {
            try {
                $st = $pdo->prepare('UPDATE radcheck SET value=? WHERE username=? AND attribute="Cleartext-Password"');
                $st->execute([$nouveau, $username]);

                // L'annuaire : le même mot de passe doit ouvrir la session Windows. Un échec
                // ici n'annule pas le changement côté portail, mais il est DIT — laisser
                // croire que tout est aligné serait pire que l'aveu.
                $adOk = true;
                $sortie = (string) shell_exec('sudo /usr/local/sbin/proxyfibre-ad user setpassword '
                                            . escapeshellarg($username) . ' ' . escapeshellarg($nouveau) . ' 2>&1');
                if (preg_match('/ERROR|Failed|introuvable/i', $sortie)) { $adOk = false; }

                mdp_tracer($pdo, $username, $clientIp, true);
                // Journal d'audit : QUI a changé son mot de passe et QUAND. Jamais le mot
                // de passe lui-même, ni l'ancien, ni le nouveau.
                try {
                    $pdo->prepare('INSERT INTO pf_audit (ts,admin,action,detail,ip) VALUES (NOW(),?,?,?,?)')
                        ->execute([$username, 'portail.motdepasse',
                                   $adOk ? 'portail + annuaire' : 'portail seul — annuaire en échec', $clientIp]);
                } catch (Throwable $e) {}

                $succes = true;
                $avertAd = !$adOk;
                // Le jeton est renouvelé : le formulaire ne peut pas être rejoué.
                $_SESSION['csrf_mdp'] = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $erreur = 'Le changement n’a pas pu être enregistré. Réessayez.';
            }
        }
    }
}

$titre = 'Changer mon mot de passe';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titre, ENT_QUOTES) ?> — Bastion</title>
<style>
  :root{--bg:#0b1120;--panel:#111c31;--line:#1e2f4a;--text:#e2e8f0;--muted:#94a3b8;--acc:#38bdf8}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,"Segoe UI",sans-serif;
       display:flex;align-items:flex-start;justify-content:center;padding:2rem 1rem;min-height:100vh}
  .carte{width:min(480px,100%);background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:1.6rem 1.5rem}
  h1{font-size:1.15rem;margin:0 0 .3rem}
  .qui{color:var(--muted);font-size:.85rem;margin:0 0 1.2rem}
  label{display:block;font-size:.85rem;color:var(--muted);margin:.9rem 0 .3rem}
  input[type=password]{width:100%;padding:.6rem .7rem;background:var(--bg);color:var(--text);
       border:1px solid var(--line);border-radius:9px;font-size:1rem}
  input[type=password]:focus{outline:2px solid var(--acc);outline-offset:1px}
  .aide{font-size:.78rem;color:var(--muted);margin:.4rem 0 0;line-height:1.45}
  button{margin-top:1.3rem;width:100%;padding:.7rem;background:var(--acc);color:#04121f;border:0;
       border-radius:10px;font-size:.95rem;font-weight:600;cursor:pointer}
  button:hover{filter:brightness(1.08)}
  .msg{padding:.75rem .9rem;border-radius:10px;font-size:.88rem;margin:0 0 1rem;line-height:1.5}
  .err{background:rgba(248,113,113,.13);border:1px solid rgba(248,113,113,.4);color:#fca5a5}
  .ok{background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.38);color:#86efac}
  .avert{background:rgba(250,204,21,.12);border:1px solid rgba(250,204,21,.38);color:#fde68a}
  .retour{display:inline-block;margin-top:1.1rem;color:var(--acc);text-decoration:none;font-size:.88rem}
</style>
</head>
<body>
<div class="carte">
  <h1>🔑 <?= htmlspecialchars($titre, ENT_QUOTES) ?></h1>

<?php if (!$authentifie || $username === ''): ?>
  <p class="msg err">Vous n’êtes pas connecté au portail. Identifiez-vous d’abord, puis revenez sur cette page.</p>
  <a class="retour" href="/portal/fas.php">← Aller à la page de connexion</a>

<?php elseif ($succes): ?>
  <p class="msg ok"><strong>Mot de passe changé.</strong> Il sera demandé à votre prochaine connexion —
     au portail comme à l’ouverture de votre session Windows.</p>
  <?php if (!empty($avertAd)): ?>
    <p class="msg avert">Le mot de passe du portail est bien changé, mais il n’a <strong>pas pu être mis à jour
       dans l’annuaire</strong> : votre session Windows garde l’ancien. Signalez-le à l’administrateur.</p>
  <?php endif; ?>
  <a class="retour" href="/portal/account.php">← Retour à mon compte</a>

<?php else: ?>
  <p class="qui">Compte <strong><?= htmlspecialchars($username, ENT_QUOTES) ?></strong></p>
  <?php if ($erreur !== ''): ?><p class="msg err"><?= htmlspecialchars($erreur, ENT_QUOTES) ?></p><?php endif; ?>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_mdp'], ENT_QUOTES) ?>">

    <label for="actuel">Mot de passe actuel</label>
    <input type="password" id="actuel" name="actuel" required autocomplete="current-password" autofocus>

    <label for="nouveau">Nouveau mot de passe</label>
    <input type="password" id="nouveau" name="nouveau" required autocomplete="new-password" minlength="<?= MDP_MIN ?>">
    <p class="aide"><?= MDP_MIN ?> caractères au minimum, différent de l’actuel, sans votre matricule
       et pas uniquement des chiffres. Une phrase dont vous vous souvenez vaut mieux qu’un mot compliqué.</p>

    <label for="confirm">Confirmer le nouveau mot de passe</label>
    <input type="password" id="confirm" name="confirm" required autocomplete="new-password" minlength="<?= MDP_MIN ?>">

    <button type="submit">Changer mon mot de passe</button>
  </form>
  <a class="retour" href="/portal/account.php">← Retour à mon compte</a>
<?php endif; ?>
</div>
</body>
</html>
