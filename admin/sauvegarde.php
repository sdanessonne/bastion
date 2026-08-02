<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — sauvegarde et restauration (base + configuration + médias + AD). */
require_once __DIR__ . '/inc/auth.php';

function bk(...$args): string {
    $cmd = 'sudo /usr/local/sbin/proxyfibre-backup';
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
    return (string) shell_exec($cmd . ' 2>&1');
}
function bk_parse(string $raw): array {
    $out = [];
    foreach (explode("\n", $raw) as $l) { if (preg_match('/^(\w+)=(.*)$/', $l, $m)) { $out[$m[1]] = $m[2]; } }
    return $out;
}

// ── Téléchargement (avant tout affichage) ────────────────────────────────────
if (isset($_GET['dl'])) {
    $name = basename((string) $_GET['dl']);
    if (preg_match('/^bastion-[0-9-]+\.tar\.gz(\.gpg)?$/', $name)) {
        $path = trim(bk('path', $name));
        if (is_file($path)) {
            header('Content-Type: ' . (substr($name, -4) === '.gpg' ? 'application/octet-stream' : 'application/gzip'));
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
    }
    http_response_code(404);
    exit('Sauvegarde introuvable.');
}

// ── API JSON (statut de progression, lancement, planification) ───────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = (string) $_GET['api'];
    if ($api === 'status') {
        $s = bk_parse(bk('status'));
        echo json_encode([
            'state'  => $s['state']  ?? 'idle',
            'op'     => $s['op']     ?? '',
            'pct'    => (int) ($s['pct'] ?? 0),
            'step'   => $s['step']   ?? '',
            'result' => $s['result'] ?? '',
        ]);
        exit;
    }
    if ($api === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $op   = in_array($_POST['op'] ?? '', ['restore', 'verify'], true) ? (string) $_POST['op'] : 'create';
        $name = basename((string) ($_POST['name'] ?? ''));
        $out  = trim(bk('start', $op, $name));
        echo json_encode(['ok' => $out === 'started', 'msg' => $out]);
        exit;
    }
    if ($api === 'auto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $sub = ($_POST['sub'] ?? '') === 'enable' ? 'enable' : 'disable';
        $out = trim(bk('auto', $sub));
        echo json_encode(['ok' => in_array($out, ['active', 'desactive'], true), 'msg' => $out]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'requête invalide']);
    exit;
}

require_once __DIR__ . '/inc/layout.php';

$flash = null;
$newpass = null;    // phrase secrète fraîchement générée (affichée une fois)
$revealed = null;   // phrase secrète ré-affichée sur demande
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'delete') {
        bk('delete', (string) ($_POST['name'] ?? ''));
        $flash = ['Sauvegarde supprimée.', 'ok'];
    } elseif ($do === 'keygen') {
        $r = trim(bk('key', 'gen'));
        if ($r !== '' && $r !== 'existe' && $r !== 'echec') {
            $newpass = $r;
            if (function_exists('audit')) { audit('backup.key.gen'); }   // jamais la phrase elle-même
            $flash = ['Chiffrement activé. Notez la phrase secrète ci-dessous — elle ne sera plus affichée ainsi.', 'ok'];
        } else {
            $flash = [$r === 'existe' ? 'Une clé de chiffrement existe déjà.' : 'Échec de génération de la clé.', 'warn'];
        }
    } elseif ($do === 'keyshow') {
        $revealed = trim(bk('key', 'show'));
        if (function_exists('audit')) { audit('backup.key.show'); }
    } elseif ($do === 'usb_export') {
        $dev = (string) ($_POST['dev'] ?? '');
        if (!preg_match('#^/dev/[a-zA-Z0-9]+$#', $dev)) {   // le script re-valide « amovible »
            $flash = ['Cible USB invalide.', 'err'];
        } else {
            $r  = trim(bk('usb', 'export', $dev));
            $ok = strpos($r, 'exporte:') === 0;
            if (function_exists('audit')) { audit('backup.usb_export', $ok ? $dev : 'echec'); }
            $flash = [$ok ? 'Sauvegarde copiée sur la clé USB (' . $dev . ').' : 'Échec de l\'export : ' . $r, $ok ? 'ok' : 'err'];
        }
    } elseif ($do === 'usb_format') {
        // Effacement complet d'une clé. La confirmation du navigateur ne compte pas :
        // elle se contourne. On exige la saisie EXACTE du chemin du périphérique —
        // le seul geste qu'on ne fait pas par distraction. Le script revalide ensuite
        // « amovible » et refuse toute partition système.
        $dev  = (string) ($_POST['dev'] ?? '');
        $fs   = (string) ($_POST['fs'] ?? 'exfat');
        $conf = trim((string) ($_POST['confirm_dev'] ?? ''));
        if (!preg_match('#^/dev/[a-zA-Z0-9]+$#', $dev)) {
            $flash = ['Cible USB invalide.', 'err'];
        } elseif (!in_array($fs, ['exfat', 'ntfs', 'fat32'], true)) {
            $flash = ['Système de fichiers non pris en charge.', 'err'];
        } elseif ($conf !== $dev) {
            $flash = ['Formatage annulé : recopiez exactement ' . e($dev) . ' pour confirmer.', 'err'];
        } else {
            $r  = trim(bk('usb', 'format', $dev, $fs));
            $ok = strpos($r, 'formate:') === 0;
            if (function_exists('audit')) { audit('backup.usb_format', ($ok ? '' : 'ECHEC ') . $dev . ' → ' . $fs); }
            $flash = [$ok ? 'Clé formatée en ' . $fs . ' (' . $dev . '), étiquette BASTION.'
                          : 'Échec du formatage : ' . $r, $ok ? 'ok' : 'err'];
        }
    } elseif ($do === 'offsite_set') {
        $host  = trim((string) ($_POST['host'] ?? ''));
        $share = trim((string) ($_POST['share'] ?? ''));
        $ouser = trim((string) ($_POST['ouser'] ?? ''));
        $opass = (string) ($_POST['opass'] ?? '');
        $sub   = trim((string) ($_POST['subdir'] ?? 'Bastion')) ?: 'Bastion';
        if ($host === '' || $share === '' || $ouser === '' || $opass === '') {
            $flash = ['Renseignez l\'hôte, le partage, l\'utilisateur et le mot de passe.', 'err'];
        } else {
            $r = trim(bk('offsite', 'set', $host, $share, $ouser, $opass, $sub));
            if (function_exists('audit')) { audit('backup.offsite.set', $host . '/' . $share); }   // jamais le mot de passe
            $flash = [$r === 'enregistre' ? 'Destination hors-site enregistrée.' : 'Échec : ' . $r, $r === 'enregistre' ? 'ok' : 'err'];
        }
    } elseif ($do === 'offsite_off') {
        bk('offsite', 'off');
        if (function_exists('audit')) { audit('backup.offsite.off'); }
        $flash = ['Destination hors-site supprimée.', 'ok'];
    } elseif ($do === 'offsite_test') {
        $r  = trim(bk('offsite', 'test'));
        $ok = strpos($r, 'ok:') === 0;
        $flash = [$ok ? 'Partage joignable ✔' : 'Test échoué : ' . $r, $ok ? 'ok' : 'err'];
    } elseif ($do === 'offsite_push') {
        $r  = trim(bk('offsite', 'push'));
        $ok = strpos($r, 'envoye:') === 0;
        if (function_exists('audit')) { audit('backup.offsite.push', $ok ? 'ok' : 'echec'); }
        $flash = [$ok ? 'Sauvegarde envoyée hors-site ✔' : 'Envoi échoué : ' . $r, $ok ? 'ok' : 'err'];
    } elseif ($do === 'offsite_auto') {
        $sub = ($_POST['sub'] ?? '') === 'on' ? 'on' : 'off';
        bk('offsite', 'auto', $sub);
        $flash = ['Envoi automatique ' . ($sub === 'on' ? 'activé' : 'désactivé') . '.', 'ok'];
    }
}
// État du chiffrement.
$keySt = bk_parse(bk('key', 'status'));
$encOn = ($keySt['key'] ?? '') === 'yes';
// Cibles USB amovibles détectées (export hors-machine).
$usbTargets = [];
foreach (explode("\n", bk('usb', 'list')) as $l) {
    $p = explode("\t", $l);
    if (count($p) >= 4 && $p[0] !== '') {
        // tot/use en octets : « 0 » signifie « illisible » (système de fichiers
        // inconnu du noyau, clé défectueuse). On l'affiche alors comme tel plutôt
        // que de dessiner une jauge vide, qui laisserait croire à une clé neuve.
        $tot = (int) ($p[5] ?? 0);
        $use = (int) ($p[6] ?? 0);
        $usbTargets[] = [
            'dev' => $p[0], 'label' => $p[1], 'fs' => $p[2], 'size' => $p[3],
            'mp'  => $p[4] ?? '', 'tot' => $tot, 'use' => $use,
            'pct' => $tot > 0 ? (int) round($use * 100 / $tot) : -1,
        ];
    }
}

$rows = [];
foreach (explode("\n", bk('list')) as $l) {
    $p = explode("\t", $l);
    if (count($p) >= 3) { $rows[] = ['name' => $p[0], 'size' => (int) $p[1], 'date' => $p[2]]; }
}
usort($rows, fn($a, $b) => strcmp($b['name'], $a['name']));
$fmt = function ($n) { $u = ['o','Ko','Mo','Go']; $i = 0; while ($n >= 1024 && $i < 3) { $n /= 1024; $i++; } return number_format($n, $i ? 1 : 0, ',', ' ') . ' ' . $u[$i]; };

// État de la sauvegarde automatique (timer systemd).
$auto = bk_parse(bk('auto', 'status'));
$autoOn = ($auto['enabled'] ?? '') === 'enabled';
$autoNext = trim((string) ($auto['next'] ?? ''));   // déjà mis en forme par le script

// État de la sauvegarde hors-site (partage SMB).
$off   = bk_parse(bk('offsite', 'status'));
$offOn = ($off['configured'] ?? '') === 'yes';

pf_header('Sauvegarde', 'sauvegarde.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<!-- ── Chiffrement des sauvegardes ── -->
<section class="panel">
  <div class="panel-head"><h2>🔐 Chiffrement des sauvegardes</h2>
    <span class="badge <?= $encOn ? 'on' : 'off' ?>"><?= $encOn ? 'Actif · AES-256' : 'Inactif' ?></span>
  </div>
  <div style="padding:1.1rem 1.2rem">
    <?php if (!$encOn): ?>
      <p class="muted small" style="margin:0 0 .9rem">⚠️ <strong>Vos sauvegardes ne sont pas chiffrées.</strong>
      Chaque archive contient l'annuaire Active Directory — <strong>empreintes de mots de passe</strong> et
      <strong>clés de récupération BitLocker</strong> — ainsi que toute la base. Une archive qui fuite exposerait
      le secret le plus sensible du commissariat.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="keygen">
        <button class="btn">🔒 Activer le chiffrement (AES-256)</button>
      </form>
    <?php else: ?>
      <p class="muted small" style="margin:0 0 .9rem">✅ Les nouvelles sauvegardes sont chiffrées en <strong>AES-256</strong>.
      Il vous faut la <strong>phrase secrète</strong> pour restaurer sur une autre machine : conservez-la
      <strong>hors de la passerelle</strong> (coffre, gestionnaire de mots de passe).</p>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="keyshow">
        <button class="btn-sm">👁 Afficher la phrase secrète</button>
      </form>
    <?php endif; ?>
    <?php if ($newpass !== null || $revealed !== null): $pw = $newpass ?? $revealed; ?>
      <div class="passbox">
        <p style="margin:0 0 .5rem"><strong>Phrase secrète de chiffrement</strong> — notez-la et conservez-la en lieu sûr, <em>hors de la passerelle</em> :</p>
        <code class="passval" id="passval"><?= e($pw) ?></code>
        <button type="button" class="btn-sm" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('passval').textContent)">Copier</button>
        <p class="muted small" style="margin:.6rem 0 0">Sans cette phrase, une sauvegarde chiffrée est <strong>irrécupérable</strong> — Bastion ne peut pas la retrouver à votre place.</p>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ── Sauvegarde automatique ── -->
<section class="panel">
  <div class="panel-head"><h2>🗓️ Sauvegarde automatique</h2>
    <span class="badge <?= $autoOn ? 'on' : 'off' ?>" id="autobadge"><?= $autoOn ? 'Activée' : 'Désactivée' ?></span>
  </div>
  <div style="padding:1.1rem 1.2rem;display:flex;align-items:center;gap:1.2rem;flex-wrap:wrap">
    <p class="muted small" style="margin:0;flex:1;min-width:220px">Une sauvegarde complète est créée
      <strong>chaque semaine</strong> (lundi vers 02h30) et les <strong>8 dernières</strong> sont conservées
      automatiquement.<?php if ($autoOn && $autoNext): ?><br>Prochaine exécution : <strong><?= e($autoNext) ?></strong>.<?php endif; ?></p>
    <label class="switch" title="Activer / désactiver">
      <input type="checkbox" id="autotoggle" <?= $autoOn ? 'checked' : '' ?>>
      <span class="slider"></span>
    </label>
  </div>
</section>

<!-- ── Sauvegarde hors-site (partage SMB) ── -->
<section class="panel">
  <div class="panel-head"><h2>🌐 Sauvegarde hors-site</h2>
    <span class="badge <?= $offOn ? 'on' : 'off' ?>"><?= $offOn ? 'Configurée' : 'Non configurée' ?></span></div>
  <div style="padding:1.1rem 1.2rem">
    <p class="muted small" style="margin:0 0 .9rem">Copie de la dernière sauvegarde (<strong>déjà chiffrée</strong>) vers un
    <strong>partage réseau SMB</strong> — NAS ou 2ᵉ passerelle. Une panne matérielle de la passerelle ne fait alors plus
    tout perdre. <strong>Reste sur votre réseau</strong> : aucune donnée n'est envoyée sur Internet.</p>
    <?php if ($offOn): ?>
      <p class="small" style="margin:0 0 .7rem">Destination : <code>\\<?= e($off['host'] ?? '') ?>\<?= e($off['share'] ?? '') ?>\<?= e($off['subdir'] ?: 'Bastion') ?></code>
        (utilisateur <code><?= e($off['user'] ?? '') ?></code>).
        <?php if (!empty($off['last_status'])): ?><br>Dernier envoi :
          <?php if ($off['last_status'] === 'ok'): ?><span class="badge on">réussi</span> le <?= e($off['last_at'] ?? '') ?> — <code><?= e($off['last_file'] ?? '') ?></code>
          <?php else: ?><span class="badge off">échec</span> le <?= e($off['last_at'] ?? '') ?><?php endif; ?>
        <?php endif; ?></p>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="offsite_test"><button class="btn-sm">🔌 Tester la connexion</button></form>
        <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="offsite_push"><button class="btn-sm">⬆️ Envoyer maintenant</button></form>
        <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="offsite_auto"><input type="hidden" name="sub" value="<?= ($off['auto'] ?? '1') === '1' ? 'off' : 'on' ?>"><button class="btn-sm"><?= ($off['auto'] ?? '1') === '1' ? '⏸ Désactiver l\'envoi auto' : '▶️ Activer l\'envoi auto' ?></button></form>
        <form method="post" style="margin:0" onsubmit="return confirm('Retirer la destination hors-site ?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="offsite_off"><button class="btn-sm btn-danger">Retirer</button></form>
      </div>
      <?php if (($off['auto'] ?? '1') === '1'): ?><p class="muted small" style="margin:.7rem 0 0">✅ Envoi automatique après chaque sauvegarde planifiée.</p><?php endif; ?>
    <?php else: ?>
      <form method="post" style="display:grid;gap:.6rem;max-width:540px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="offsite_set">
        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
          <label style="flex:1 1 200px">Hôte (IP ou nom)<input type="text" name="host" required placeholder="192.168.10.5"></label>
          <label style="flex:1 1 140px">Partage<input type="text" name="share" required placeholder="Sauvegardes"></label>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
          <label style="flex:1 1 150px">Utilisateur<input type="text" name="ouser" required autocomplete="off"></label>
          <label style="flex:1 1 150px">Mot de passe<input type="password" name="opass" required autocomplete="new-password"></label>
          <label style="flex:1 1 120px">Sous-dossier<input type="text" name="subdir" value="Bastion"></label>
        </div>
        <div><button class="btn">💾 Enregistrer la destination</button></div>
      </form>
      <p class="muted small" style="margin:.6rem 0 0">Le mot de passe est conservé sur la passerelle en <strong>accès root strict</strong> (fichier 600) et n'apparaît jamais dans les journaux.</p>
    <?php endif; ?>
  </div>
</section>

<!-- ── Sauvegardes manuelles ── -->
<section class="panel">
  <div class="panel-head"><h2>💾 Sauvegardes</h2>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <button class="btn-sm" id="btnverify" title="Restaure la dernière sauvegarde dans un espace jetable pour prouver qu'elle est vraiment récupérable (non destructif)">🧪 Tester la restauration</button>
      <button class="btn" id="btncreate">➕ Créer une sauvegarde</button>
    </div>
  </div>
  <p class="muted small" style="padding:.2rem 1.2rem 0">Chaque sauvegarde contient la <strong>base de données</strong>
  (comptes, groupes, filtrage, journaux, intranet, réglages), la <strong>configuration</strong>, les <strong>médias</strong>
  de l'intranet et une <strong>sauvegarde du domaine Active Directory</strong>. La création peut prendre ~30 s.</p>
  <div class="table-wrap">
  <table class="grid-table">
    <thead><tr><th>Sauvegarde</th><th>Date</th><th>Taille</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="4" class="muted center">Aucune sauvegarde. Créez-en une ci-dessus.</td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td class="mono svc-meta"><?= e($r['name']) ?><?php if (substr($r['name'], -4) === '.gpg'): ?> <span title="Chiffrée AES-256" style="color:var(--accent)">🔐</span><?php endif; ?></td>
        <td class="muted"><?= e($r['date']) ?></td>
        <td><?= e($fmt($r['size'])) ?></td>
        <td class="row-actions">
          <a class="btn-sm" href="/sauvegarde.php?dl=<?= urlencode($r['name']) ?>">⬇ Télécharger</a>
          <button class="btn-sm btn-danger btnrestore" data-name="<?= e($r['name']) ?>">Restaurer</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette sauvegarde ?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="delete">
            <input type="hidden" name="name" value="<?= e($r['name']) ?>"><button class="btn-sm">Suppr.</button></form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small" style="padding:0 1.2rem 1rem">💡 Téléchargez régulièrement une sauvegarde hors de la
  passerelle. La restauration recharge la base, la configuration et les médias ; le domaine AD se restaure à part
  (<code>samba-tool domain backup restore</code>).</p>
</section>

<!-- ── Copie vers une clé USB (hors-machine, souverain) ── -->
<section class="panel">
  <div class="panel-head"><h2>📤 Copie vers une clé USB</h2></div>
  <div style="padding:1.1rem 1.2rem">
    <p class="muted small" style="margin-top:0">Copie la <strong>dernière sauvegarde</strong> (chiffrée) sur une clé/disque
    USB branché sur la passerelle : une copie <strong>hors-machine</strong>, sans réseau et sous votre contrôle. On n'écrit
    jamais sur un disque système — seules les partitions <strong>amovibles</strong> sont proposées.</p>
    <?php if (!$usbTargets): ?>
      <?php
      // État « rien de branché ». Il annonçait « rechargez cette page » et une manœuvre
      // VirtualBox — inutile sur un serveur physique, et surtout muet sur ce qui est
      // possible. Sans clé, la jauge et le formatage n'ont rien à afficher : autant le
      // dire, plutôt que de laisser croire à une fonction absente.
      // Un lecteur de cartes VIDE se présente au noyau comme un disque amovible de
      // 0 octet : il est volontairement ignoré, sinon il apparaîtrait comme une clé.
      ?>
      <div class="flash" style="margin:.4rem 0">
        <b>Aucune clé USB détectée.</b> Branchez une clé sur la passerelle, puis rechargez
        cette page : sa capacité s'affichera, avec la copie de sauvegarde et le formatage.
      </div>
      <p class="muted small" style="margin:.5rem 0 0">
        Une clé <b>neuve ou effacée</b>, sans partition, est reconnue et formatable. Un
        lecteur de cartes vide, lui, est ignoré — il se présente au système comme un
        support de 0 octet.
        <?php if ($u = trim((string) shell_exec("lsblk -dno PATH,SIZE,MODEL 2>/dev/null | grep -viE 'sda|loop' | head -3"))): ?>
          <br>Supports amovibles vus par le système : <code><?= e(preg_replace('/\s+/', ' ', $u)) ?></code>
        <?php endif; ?>
      </p>
    <?php else: ?>
      <?php
      // Octets → unité lisible. Une jauge sans chiffres ne dit pas si les 12 % libres
      // sont 400 Mo ou 40 Go — or c'est cela qui décide si la copie passera.
      $fmt = static function (int $o): string {
          if ($o <= 0) return '—';
          $u = ['o', 'Ko', 'Mo', 'Go', 'To']; $i = 0;
          while ($o >= 1024 && $i < 4) { $o /= 1024; $i++; }
          return number_format($o, $o < 10 && $i > 0 ? 1 : 0, ',', ' ') . ' ' . $u[$i];
      };
      ?>
      <div style="display:grid;gap:.9rem;margin:.2rem 0 1rem">
        <?php foreach ($usbTargets as $u): ?>
          <div style="border:1px solid var(--line);border-radius:10px;padding:.75rem .9rem;background:var(--bg)">
            <div style="display:flex;justify-content:space-between;gap:.8rem;flex-wrap:wrap;align-items:baseline">
              <b><?= e($u['label']) ?></b>
              <span class="muted small"><code><?= e($u['dev']) ?></code> · <?= e($u['fs']) ?> · <?= e($u['size']) ?></span>
            </div>
            <?php if ($u['pct'] >= 0): ?>
              <?php $crit = $u['pct'] >= 90; ?>
              <div class="gauge" style="margin:.5rem 0 .3rem">
                <div class="gauge-bar" style="width:<?= max(2, min(100, $u['pct'])) ?>%<?= $crit ? ';background:#ef4444' : '' ?>"></div>
              </div>
              <div class="muted small">
                <?= $fmt($u['use']) ?> occupés sur <?= $fmt($u['tot']) ?> —
                <b><?= $fmt($u['tot'] - $u['use']) ?> libres</b> (<?= $u['pct'] ?> %)
                <?= $crit ? ' · <span style="color:#ef4444">clé presque pleine</span>' : '' ?>
              </div>
            <?php else: ?>
              <div class="muted small" style="margin-top:.4rem">
                Occupation illisible — système de fichiers non reconnu par la passerelle.
                Un formatage la rendra utilisable.
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <form method="post" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap"
            onsubmit="return confirm('Copier la dernière sauvegarde chiffrée sur cette clé USB ?')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="usb_export">
        <select name="dev" style="padding:.5rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px;max-width:100%">
          <?php foreach ($usbTargets as $u): ?>
            <option value="<?= e($u['dev']) ?>"><?= e($u['label']) ?> — <?= e($u['fs']) ?> <?= e($u['size']) ?> (<?= e($u['dev']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <button class="btn">📤 Exporter</button>
      </form>
      <p class="muted small" style="margin:.6rem 0 0">La copie est placée dans un dossier <code>Bastion-sauvegardes</code>
      de la clé. Rangez la clé en lieu sûr : l'archive contient l'annuaire et les clés BitLocker (chiffrés).</p>

      <!-- ── Formatage ─────────────────────────────────────────────────────
           Séparé visuellement du reste : c'est la seule commande de la console
           qui détruit des données. La case à cocher du navigateur ne suffit pas
           comme rempart — on exige la recopie du chemin, et le script revalide
           « amovible » de son côté. -->
      <details style="margin-top:1.1rem;border-top:1px solid var(--line);padding-top:.9rem">
        <summary style="cursor:pointer;font-weight:600">🧹 Formater une clé — efface tout son contenu</summary>
        <p class="muted small" style="margin:.6rem 0 .8rem">
          Utile pour une clé neuve, illisible, ou formatée en FAT32 — ce dernier plafonne
          les fichiers à 4 Go, ce qu'une sauvegarde chiffrée dépasse vite. <b>exFAT</b> est
          le choix conseillé : pas de limite gênante, et relisible sur Windows comme sur Linux.
        </p>
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.7rem;align-items:end"
              onsubmit="return confirm('EFFACER définitivement tout le contenu de cette clé ?')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="usb_format">
          <label>Clé à effacer
            <select name="dev" id="fmtdev">
              <?php foreach ($usbTargets as $u): ?>
                <option value="<?= e($u['dev']) ?>"><?= e($u['label']) ?> — <?= e($u['size']) ?> (<?= e($u['dev']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Système de fichiers
            <select name="fs">
              <option value="exfat">exFAT — conseillé</option>
              <option value="ntfs">NTFS</option>
              <option value="fat32">FAT32 — fichiers limités à 4 Go</option>
            </select>
          </label>
          <label>Confirmation
            <input name="confirm_dev" required autocomplete="off" placeholder="recopier /dev/sdX">
          </label>
          <button class="btn" style="background:#b91c1c;border-color:#b91c1c">Formater</button>
        </form>
        <p class="muted small" style="margin:.7rem 0 0">
          Recopiez exactement le chemin de la clé choisie pour confirmer. Toute autre
          saisie annule l'opération. L'action est inscrite au journal d'audit.
        </p>
      </details>
    <?php endif; ?>
  </div>
</section>

<!-- ── Fenêtre de progression (jauge) ── -->
<div class="modal-ov" id="progmodal">
  <div class="modal" style="max-width:440px" role="dialog" aria-modal="true">
    <div class="modal-head"><h2 id="progtitle"><span class="spin"></span>Sauvegarde en cours…</h2></div>
    <div class="modal-body">
      <div class="gauge-wrap">
        <div class="gauge"><div class="gauge-bar run" id="gbar" style="width:5%"></div></div>
        <div class="gauge-info"><span class="gauge-step" id="gstep">Démarrage…</span><span id="gpct">5 %</span></div>
      </div>
      <p class="muted small" id="gnote" style="margin:1rem 0 0">Ne fermez pas cette fenêtre.</p>
      <div id="gdone" style="display:none;margin-top:1rem;text-align:right">
        <button class="btn" onclick="location.reload()">Fermer</button>
      </div>
    </div>
  </div>
</div>

<style>
  .switch{position:relative;display:inline-block;width:52px;height:28px;flex:none}
  .switch input{opacity:0;width:0;height:0}
  .switch .slider{position:absolute;inset:0;background:var(--bg);border:1px solid var(--line);border-radius:99px;
    cursor:pointer;transition:background .2s ease,border-color .2s ease}
  .switch .slider::before{content:"";position:absolute;height:20px;width:20px;left:3px;top:3px;background:var(--muted);
    border-radius:50%;transition:transform .22s cubic-bezier(.16,1,.3,1),background .2s ease}
  .switch input:checked + .slider{background:rgba(56,189,248,.25);border-color:var(--accent)}
  .switch input:checked + .slider::before{transform:translateX(24px);background:var(--accent)}
  .passbox{margin-top:1rem;padding:.9rem 1rem;border:1px solid var(--accent);border-radius:10px;background:rgba(56,189,248,.06)}
  .passval{display:inline-block;font-size:1.05rem;letter-spacing:.04em;padding:.35rem .6rem;background:var(--bg);
    border:1px solid var(--line);border-radius:6px;user-select:all;word-break:break-all;margin-right:.4rem}
</style>
<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>;
  var ov=document.getElementById('progmodal'), bar=document.getElementById('gbar'),
      step=document.getElementById('gstep'), pct=document.getElementById('gpct'),
      title=document.getElementById('progtitle'), note=document.getElementById('gnote'),
      done=document.getElementById('gdone'), poll=null;

  function show(op){
    title.innerHTML='<span class="spin"></span>'+(op==='verify'?'Test de restauration en cours…':(op==='restore'?'Restauration en cours…':'Sauvegarde en cours…'));
    bar.className='gauge-bar run'; bar.style.width='5%'; pct.textContent='5 %';
    step.textContent='Démarrage…'; note.style.display=''; done.style.display='none';
    ov.classList.add('open');
  }
  function update(s){
    bar.style.width=Math.max(5,s.pct)+'%'; pct.textContent=s.pct+' %'; step.textContent=s.step||'…';
    if(s.state==='done'){
      clearInterval(poll); poll=null;
      bar.className='gauge-bar'; bar.style.width='100%'; pct.textContent='100 %';
      if(s.op==='verify'){
        var okv=/base=ok/.test(s.result||'')&&/ad=ok/.test(s.result||'');
        title.innerHTML=okv?'✅ Sauvegarde restaurable':'⚠ Vérification incomplète';
        step.textContent='Résultat : '+(s.result||'—');
        note.textContent=okv?'La base et l\'Active Directory se restaurent correctement.':'Un composant n\'a pas pu être restauré — vérifiez la sauvegarde.';
        done.style.display='block';
      } else {
        title.innerHTML='✅ Terminé'; step.textContent=(s.op==='restore'?'Restauration effectuée.':'Sauvegarde créée : '+s.result);
        note.textContent=(s.op==='restore'?'Reconnectez-vous si besoin.':'La liste va se rafraîchir.');
        done.style.display='block';
        setTimeout(function(){location.reload();}, s.op==='restore'?2500:1400);
      }
    } else if(s.state==='error'){
      clearInterval(poll); poll=null;
      bar.className='gauge-bar err'; title.innerHTML='⚠ Échec';
      step.textContent='Erreur : '+(s.result||s.step); done.style.display='block';
    }
  }
  function track(op){
    show(op);
    poll=setInterval(function(){
      fetch('/sauvegarde.php?api=status',{headers:{'Accept':'application/json'}})
        .then(function(r){return r.json();}).then(update).catch(function(){});
    },1000);
  }
  function start(op,name){
    var b=new URLSearchParams(); b.set('csrf',CSRF); b.set('op',op); if(name)b.set('name',name);
    fetch('/sauvegarde.php?api=start',{method:'POST',body:b}).then(function(r){return r.json();})
      .then(function(res){ if(res.ok){track(op);} else {alert('Impossible de lancer : '+(res.msg||'occupé'));} })
      .catch(function(){alert('Erreur réseau.');});
  }

  document.getElementById('btncreate').addEventListener('click',function(){ start('create'); });
  var btnv=document.getElementById('btnverify');
  if(btnv) btnv.addEventListener('click',function(){ start('verify'); });
  [].forEach.call(document.querySelectorAll('.btnrestore'),function(b){
    b.addEventListener('click',function(){
      if(confirm('RESTAURER cette sauvegarde ? Les données actuelles (base, config, médias) seront REMPLACÉES.'))
        start('restore', b.dataset.name);
    });
  });

  // Bascule de la sauvegarde automatique.
  var tog=document.getElementById('autotoggle'), badge=document.getElementById('autobadge');
  tog.addEventListener('change',function(){
    var sub=tog.checked?'enable':'disable';
    var b=new URLSearchParams(); b.set('csrf',CSRF); b.set('sub',sub);
    tog.disabled=true;
    fetch('/sauvegarde.php?api=auto',{method:'POST',body:b}).then(function(r){return r.json();})
      .then(function(res){
        tog.disabled=false;
        if(res.ok){ badge.textContent=tog.checked?'Activée':'Désactivée'; badge.className='badge '+(tog.checked?'on':'off');
          if(tog.checked) setTimeout(function(){location.reload();},600); }
        else { tog.checked=!tog.checked; alert('Échec : '+(res.msg||'')); }
      }).catch(function(){tog.disabled=false;tog.checked=!tog.checked;alert('Erreur réseau.');});
  });

  // Reprise de l'affichage si une opération tourne déjà (rechargement de page).
  fetch('/sauvegarde.php?api=status').then(function(r){return r.json();}).then(function(s){
    if(s.state==='running'){ track(s.op||'create'); }
  }).catch(function(){});
})();
</script>
<?php pf_footer(); ?>
