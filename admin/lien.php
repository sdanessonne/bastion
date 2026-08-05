<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — liaison inter-sites.
 *
 * Deux rôles, sur la même page parce que c'est la même question posée dans les deux
 * sens : « à qui ce serveur se rattache-t-il » ou « qui se rattache à lui ».
 *
 *   site      — le cas courant : un commissariat, qui compose vers son principal.
 *   principal — UN serveur par département. Il écoute, porte 10.90.0.1, et connaît
 *               la clé publique de chaque site. Seul serveur de la flotte à avoir
 *               besoin d'un point de contact public.
 *
 * Voir services/scripts/lien-ctl.sh pour le raisonnement réseau (tunnel sortant,
 * rien d'ouvert sur les box, seul le réseau de gestion transite).
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';

$db = pf_db();
try {
    $db->exec('CREATE TABLE IF NOT EXISTS pf_lien_sites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(64) NOT NULL,
        commissariat VARCHAR(96) NOT NULL DEFAULT \'\',
        cle CHAR(44) NOT NULL,
        adresse VARCHAR(15) NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (cle), UNIQUE KEY (adresse))');
} catch (Throwable $e) {}

function lien(string ...$args): string {
    $cmd = 'sudo /usr/local/sbin/proxyfibre-lien';
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
    return (string) shell_exec($cmd . ' 2>&1');
}

/** Envoie des paramètres au script par l'ENTRÉE STANDARD : une clé en argument
 *  apparaîtrait dans la liste des processus de la machine. */
function lien_stdin(string $verbe, string $entree): string {
    $d = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open('sudo /usr/local/sbin/proxyfibre-lien ' . escapeshellarg($verbe), $d, $t);
    if (!is_resource($p)) { return 'ECHEC: lancement impossible'; }
    fwrite($t[0], $entree); fclose($t[0]);
    $out = stream_get_contents($t[1]) . stream_get_contents($t[2]);
    fclose($t[1]); fclose($t[2]); proc_close($p);
    return $out;
}

/** Réécrit la configuration du concentrateur à partir de la table : la console est
 *  la seule source de vérité, et une suppression doit disparaître du tunnel aussi. */
function hub_appliquer(PDO $db, int $port): string {
    $e = (string) $port . "\n";
    foreach ($db->query('SELECT nom,cle,adresse FROM pf_lien_sites ORDER BY adresse') as $r) {
        $e .= $r['cle'] . '|' . $r['adresse'] . '|' . $r['nom'] . "\n";
    }
    return lien_stdin('hub-config', $e);
}

$reglage = function (string $k, ?string $v = null) use ($db) {
    if ($v === null) {
        try { return (string) ($db->query('SELECT v FROM pf_settings WHERE k=' . $db->quote($k))->fetchColumn() ?: ''); }
        catch (Throwable $e) { return ''; }
    }
    try { $db->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$k, $v]); }
    catch (Throwable $e) {}
    return $v;
};

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'role') {
        $r = ($_POST['role'] ?? '') === 'principal' ? 'principal' : 'site';
        $dep = substr(trim((string) ($_POST['departement'] ?? '')), 0, 64);
        $out = lien('role', $r);
        $reglage('lien_departement', $dep);
        audit('lien.role', $r . ($dep !== '' ? ' / ' . $dep : ''));
        $flash = [trim($out), strpos($out, 'OK:') === 0 ? 'ok' : 'err'];

    } elseif ($do === 'cles') {
        lien('init');
        audit('lien.cles', 'paire de clés');
        $flash = ['Clé de ce serveur prête.', 'ok'];

    } elseif ($do === 'hub') {
        $port = (int) ($_POST['port'] ?? 51820);
        if ($port < 1 || $port > 65535) {
            $flash = ["Port d'écoute invalide.", 'err'];
        } else {
            $reglage('lien_port', (string) $port);
            $out = hub_appliquer($db, $port);
            $ok = strpos($out, 'OK:') !== false;
            audit('lien.hub', $ok ? 'port ' . $port : 'échec');
            $flash = [trim($out), $ok ? 'ok' : 'err'];
        }

    } elseif ($do === 'site_add') {
        $nom = substr(trim((string) ($_POST['nom'] ?? '')), 0, 64);
        $com = substr(trim((string) ($_POST['commissariat'] ?? '')), 0, 96);
        $cle = trim((string) ($_POST['cle'] ?? ''));
        $ad  = trim((string) ($_POST['adresse'] ?? ''));
        // Mêmes contrôles que le script : on refuse ici pour donner un message clair,
        // et le script refuse à nouveau — un garde-fou qui ne tient qu'à l'interface
        // ne tient pas.
        if ($nom === '' || !preg_match('#^[A-Za-z0-9+/]{43}=$#', $cle)
            || !preg_match('#^10\.90\.0\.(\d{1,3})$#', $ad, $m)
            || (int) $m[1] < 2 || (int) $m[1] > 254) {
            $flash = ['Nom, clé publique (44 caractères finissant par =) et adresse entre 10.90.0.2 et 10.90.0.254 sont requis.', 'err'];
        } else {
            try {
                $db->prepare('INSERT INTO pf_lien_sites (nom,commissariat,cle,adresse) VALUES (?,?,?,?)')
                   ->execute([$nom, $com, $cle, $ad]);
                $out = hub_appliquer($db, (int) ($reglage('lien_port') ?: 51820));
                audit('lien.site_add', $nom . ' / ' . $ad);
                $flash = ['Site « ' . $nom .' » rattaché (' . $ad . ').', 'ok'];
            } catch (Throwable $e) {
                $flash = ['Cette clé ou cette adresse est déjà attribuée à un autre site.', 'err'];
            }
        }

    } elseif ($do === 'site_del') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $n = (string) ($db->query('SELECT nom FROM pf_lien_sites WHERE id=' . $id)->fetchColumn() ?: '');
            $db->prepare('DELETE FROM pf_lien_sites WHERE id=?')->execute([$id]);
            hub_appliquer($db, (int) ($reglage('lien_port') ?: 51820));
            audit('lien.site_del', $n);
            $flash = ['Site « ' . $n . ' » retiré. Son tunnel ne sera plus accepté.', 'ok'];
        } catch (Throwable $e) { $flash = ['Suppression impossible.', 'err']; }

    } elseif ($do === 'config') {
        $out = lien_stdin('config', trim((string) ($_POST['hub_pub'] ?? '')) . "\n"
                                  . trim((string) ($_POST['hub_pt'] ?? '')) . "\n"
                                  . trim((string) ($_POST['moi'] ?? '')) . "\n");
        $ok = strpos($out, 'OK:') !== false;
        audit('lien.config', $ok ? trim((string) ($_POST['hub_pt'] ?? '')) : 'échec');
        $flash = [$ok ? 'Liaison configurée. Cliquez « Connecter ».' : trim($out), $ok ? 'ok' : 'err'];

    } elseif ($do === 'up' || $do === 'down') {
        $out = lien($do);
        $ok = strpos($out, 'OK:') !== false;
        audit('lien.' . $do, $ok ? 'ok' : 'échec');
        $flash = [$ok ? ($do === 'up' ? 'Liaison montée.' : 'Liaison arrêtée.') : trim($out), $ok ? 'ok' : 'err'];

    } elseif ($do === 'check') {
        $out = trim(lien('check'));
        $flash = [$out, strpos($out, 'OK:') === 0 ? 'ok' : 'err'];
    }
}

$e = json_decode(lien('state'), true) ?: [];
$role       = (string) ($e['role'] ?? 'site');
$principal  = $role === 'principal';
$configuree = !empty($e['configuree']);
$montee     = !empty($e['montee']);
$pubLocale  = (string) ($e['publique'] ?? '');
$poignee    = (int) ($e['poignee'] ?? 0);
$vivante    = $poignee > 0 && (time() - $poignee) < 300;
$dep        = $reglage('lien_departement');
$port       = (int) ($reglage('lien_port') ?: 51820);

// Dernier échange par clé publique, pour la table des sites.
$vus = [];
foreach ((array) ($e['pairs'] ?? []) as $p) { $vus[(string) ($p['cle'] ?? '')] = (int) ($p['poignee'] ?? 0); }

$sites = [];
try { $sites = $db->query('SELECT * FROM pf_lien_sites ORDER BY adresse')->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $ex) {}

/**
 * Carte de la flotte — schéma en étoile, dessiné à partir de l'état réel.
 *
 * Une table de sites répond à « qui est déclaré ». La carte répond à « qui répond »,
 * ce qui n'est pas la même question : un site déclaré dont le tunnel ne s'établit
 * pas se lit d'un coup d'œil ici, alors qu'il se confond avec les autres dans une
 * liste. Le trait est plein quand la liaison vit, pointillé sinon.
 *
 * SVG écrit à la main, sans bibliothèque : la console n'a aucune dépendance
 * extérieure et ce n'est pas ici qu'on va en introduire une.
 */
function lien_carte(array $noeuds, string $centre, string $sousCentre, bool $actif): string
{
    $n = count($noeuds);
    $L = 760; $H = $n > 6 ? 420 : 340;
    $cx = $L / 2; $cy = $H / 2;
    $rx = $L * 0.36; $ry = $H * 0.33;

    $o  = '<svg viewBox="0 0 ' . $L . ' ' . $H . '" width="100%" height="auto" role="img"'
        . ' aria-label="Carte des commissariats rattachés" style="max-height:' . $H . 'px">';
    $o .= '<defs><style>'
        . '.lk{stroke:#22c55e;stroke-width:2}'
        . '.lk.ko{stroke:#f87171;stroke-dasharray:5 5}'
        . '.lk.off{stroke:#475569;stroke-dasharray:3 6}'
        . '.nd{fill:#152238;stroke:#28374f}'
        . '.nd.ok{stroke:#22c55e}.nd.ko{stroke:#f87171}'
        . '.hub{fill:#14324f;stroke:#38bdf8;stroke-width:2}'
        . '.t{fill:#e6edf6;font:600 12px system-ui,sans-serif}'
        . '.s{fill:#8ea2bd;font:11px system-ui,sans-serif}'
        . '</style></defs>';

    // Les liaisons d'abord : elles passent sous les pastilles.
    $pos = [];
    foreach (array_values($noeuds) as $i => $nd) {
        // Départ à midi puis sens horaire : l'ordre à l'écran suit celui de la table.
        $a = -M_PI / 2 + ($n > 0 ? 2 * M_PI * $i / $n : 0);
        $x = $cx + $rx * cos($a); $y = $cy + $ry * sin($a);
        $pos[$i] = [$x, $y];
        $cls = !$actif ? 'off' : ($nd['ok'] ? '' : 'ko');
        $o .= sprintf('<line class="lk %s" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>', $cls, $cx, $cy, $x, $y);
    }

    // Le centre.
    $o .= sprintf('<circle class="hub" cx="%.1f" cy="%.1f" r="42"/>', $cx, $cy);
    $o .= sprintf('<text class="t" x="%.1f" y="%.1f" text-anchor="middle">%s</text>', $cx, $cy - 2, e($centre));
    $o .= sprintf('<text class="s" x="%.1f" y="%.1f" text-anchor="middle">%s</text>', $cx, $cy + 14, e($sousCentre));

    foreach (array_values($noeuds) as $i => $nd) {
        [$x, $y] = $pos[$i];
        $cls = !$actif ? '' : ($nd['ok'] ? 'ok' : 'ko');
        $o .= sprintf('<circle class="nd %s" cx="%.1f" cy="%.1f" r="30" stroke-width="2"/>', $cls, $x, $y);
        // Le libellé sort du cercle du côté où il y a de la place.
        $dy = $y < $cy ? -40 : 46;
        $o .= sprintf('<text class="t" x="%.1f" y="%.1f" text-anchor="middle">%s</text>',
                      $x, $y + $dy, e(mb_substr($nd['nom'], 0, 22)));
        $o .= sprintf('<text class="s" x="%.1f" y="%.1f" text-anchor="middle">%s</text>',
                      $x, $y + $dy + 14, e($nd['sous']));
        $o .= sprintf('<text class="s" x="%.1f" y="%.1f" text-anchor="middle" style="font-size:15px">%s</text>',
                      $x, $y + 5, !$actif ? '·' : ($nd['ok'] ? '✓' : '✕'));
    }
    return $o . '</svg>';
}

pf_header('Liaison inter-sites', 'lien.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>

<?php
// ── Carte ────────────────────────────────────────────────────────────────────
$noeuds = [];
if ($principal) {
    foreach ($sites as $s) {
        $hs = $vus[$s['cle']] ?? 0;
        $noeuds[] = ['nom' => $s['nom'], 'sous' => $s['adresse'],
                     'ok'  => $montee && $hs > 0 && (time() - $hs) < 300];
    }
    $centre = 'Principal'; $sousCentre = $dep !== '' ? $dep : '10.90.0.1';
} elseif ($configuree) {
    // Vu d'un site : le centre, c'est le principal ; le seul nœud, c'est nous.
    $noeuds[] = ['nom' => 'Ce commissariat', 'sous' => (string) ($e['adresse'] ?? ''), 'ok' => $vivante];
    $centre = 'Principal'; $sousCentre = (string) ($e['concentrateur'] ?? '');
}
if ($noeuds):
    $joignables = count(array_filter($noeuds, fn($x) => $x['ok']));
?>
<section class="panel">
  <div class="panel-head"><h2>🗺️ Carte de la flotte</h2>
    <span class="badge<?= $joignables === count($noeuds) && $montee ? ' on' : ($montee ? ' off' : '') ?>">
      <?= $joignables ?>/<?= count($noeuds) ?> en liaison</span>
  </div>
  <div style="padding:.6rem 1.2rem 1rem">
    <?= lien_carte($noeuds, $centre, $sousCentre, $montee) ?>
    <p class="muted small" style="margin:.2rem 0 0;display:flex;gap:1.2rem;flex-wrap:wrap">
      <span><span style="color:#22c55e">━</span> liaison active (échange il y a moins de 5 min)</span>
      <span><span style="color:#f87171">╌</span> déclaré, jamais vu</span>
      <span><span style="color:#475569">╌</span> tunnel arrêté</span>
    </p>
    <p class="muted small" style="margin:.6rem 0 0">
      La carte ne montre pas ce qui est <em>déclaré</em> mais ce qui <strong>répond</strong>.
      Un trait rouge signifie que le site est enregistré ici et n'a encore rien échangé : point de contact
      erroné de son côté, clé mal recopiée, ou sortie UDP bloquée par son opérateur.
    </p>
  </div>
</section>
<?php endif; ?>
<section class="panel">
  <div class="panel-head"><h2>🔗 Rôle de ce serveur</h2>
    <?php if ($principal): ?><span class="badge on">principal<?= $dep !== '' ? ' — ' . e($dep) : '' ?></span>
    <?php else: ?><span class="badge">rattaché</span><?php endif; ?>
  </div>
  <div style="padding:1rem 1.2rem">
    <p class="muted small" style="margin-top:0">
      Un seul serveur par département est <strong>principal</strong> : c'est lui qui reçoit les tunnels
      des autres commissariats et qui porte la console de flotte. Tous les autres s'y rattachent.
    </p>
    <form method="post" class="stack" style="padding:0;max-width:640px">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="role">
      <label style="display:flex;gap:.5rem;align-items:flex-start">
        <input type="radio" name="role" value="site" <?= $principal ? '' : 'checked' ?> style="width:auto;flex:none;margin-top:.25rem">
        <span><strong>Rattaché</strong> à un serveur principal
          <br><span class="muted small">Ce commissariat compose un tunnel sortant vers son département.</span></span></label>
      <label style="display:flex;gap:.5rem;align-items:flex-start">
        <input type="radio" name="role" value="principal" <?= $principal ? 'checked' : '' ?> style="width:auto;flex:none;margin-top:.25rem">
        <span><strong>Principal du département</strong>
          <br><span class="muted small">Ce serveur écoute et accepte les tunnels des autres commissariats.
          Il lui faut un point de contact joignable depuis l'extérieur — c'est le seul de la flotte
          dans ce cas.</span></span></label>
      <label>Département <span class="muted small">(pour l'affichage)</span>
        <input type="text" name="departement" maxlength="64" value="<?= e($dep) ?>" placeholder="Alpes-Maritimes"></label>
      <p class="muted small" style="margin:-.4rem 0 0">
        Changer de rôle retire la configuration en place : elle n'a pas de sens dans l'autre sens.
        Elle est à refaire juste après.
      </p>
      <div><button class="btn">💾 Enregistrer le rôle</button></div>
    </form>
  </div>
</section>

<?php if ($pubLocale === ''): ?>
<section class="panel" style="margin-top:1.4rem">
  <div style="padding:1rem 1.2rem">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="cles">
      <p class="muted small" style="margin:0 0 .6rem">Ce serveur n'a pas encore de clé. La clé privée
        restera sur cette machine et ne sera jamais affichée.</p>
      <button class="btn">🔑 Créer la clé de ce serveur</button>
    </form>
  </div>
</section>

<?php elseif ($principal): ?>
<!-- ══════════════ RÔLE PRINCIPAL ══════════════ -->
<section class="panel" style="margin-top:1.4rem">
  <div class="panel-head"><h2>📡 Concentrateur du département</h2>
    <?php if ($montee): ?><span class="badge on">à l'écoute</span>
    <?php elseif ($configuree): ?><span class="badge off">arrêté</span><?php endif; ?>
  </div>
  <div style="padding:1rem 1.2rem">
    <label class="muted small" style="display:block">Clé publique de ce concentrateur
      <span class="muted small">— à donner à chaque commissariat rattaché</span></label>
    <div style="display:flex;gap:.5rem;align-items:center;margin:.3rem 0 1rem;flex-wrap:wrap">
      <input type="text" id="mapub" readonly value="<?= e($pubLocale) ?>"
             style="flex:1;min-width:320px;padding:.55rem .7rem;background:var(--bg);color:var(--text);
                    border:1px solid var(--line);border-radius:9px;font-family:ui-monospace,monospace;font-size:.82rem">
      <button type="button" class="btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('mapub').value);this.textContent='✓ copiée'">📋 Copier</button>
    </div>

    <form method="post" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="hub">
      <label style="display:grid;gap:.3rem;font-size:.82rem;color:var(--muted)">
        <span>Port d'écoute (UDP)</span>
        <input type="number" name="port" min="1" max="65535" value="<?= $port ?>"
               style="width:12ch;padding:.5rem .7rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px"></label>
      <button class="btn">💾 Appliquer</button>
      <?php if ($configuree): ?>
        <span style="display:inline-flex;gap:.5rem">
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="<?= $montee ? 'down' : 'up' ?>">
            <button class="btn-sm" type="submit"><?= $montee ? '⏻ Arrêter' : '⏻ Démarrer' ?></button>
          </form>
        </span>
      <?php endif; ?>
    </form>
    <p class="muted small" style="margin:.7rem 0 0">
      Les commissariats devront saisir <strong>cette clé</strong> et le point de contact
      <code>&lt;adresse publique de ce serveur&gt;:<?= $port ?></code>. C'est le seul point de la flotte
      joignable depuis l'extérieur : à faire connaître de votre SSI.
    </p>
  </div>
</section>

<section class="panel" style="margin-top:1.4rem">
  <div class="panel-head"><h2>🏢 Commissariats rattachés (<?= count($sites) ?>)</h2></div>
  <div style="padding:1rem 1.2rem">
    <?php if (!$sites): ?>
      <p class="muted small" style="margin:0 0 1rem">Aucun site déclaré. Un commissariat ne peut pas
        se rattacher tant que sa clé publique n'est pas enregistrée ici.</p>
    <?php else: ?>
      <table class="grid-table" style="margin-bottom:1rem">
        <thead><tr><th>Site</th><th>Adresse</th><th>Dernier échange</th><th style="width:90px"></th></tr></thead>
        <tbody>
        <?php foreach ($sites as $s):
            $hs = $vus[$s['cle']] ?? -1;   // -1 = pas dans le tunnel (concentrateur arrêté)
        ?>
          <tr>
            <td><strong><?= e($s['nom']) ?></strong>
              <?php if ($s['commissariat']): ?><br><span class="muted small"><?= e($s['commissariat']) ?></span><?php endif; ?>
              <br><span class="muted mono" style="font-size:.7rem"><?= e(substr($s['cle'], 0, 16)) ?>…</span></td>
            <td class="mono small"><?= e($s['adresse']) ?></td>
            <td class="small">
              <?php if (!$montee): ?><span class="muted">concentrateur arrêté</span>
              <?php elseif ($hs > 0): ?><span class="badge on"><?= max(0, time() - $hs) ?> s</span>
              <?php else: ?><span class="badge off">jamais vu</span><?php endif; ?>
            </td>
            <td class="row-actions">
              <form method="post" onsubmit="return confirm('Retirer « <?= e($s['nom']) ?> » ? Son tunnel ne sera plus accepté.')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="do" value="site_del"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn-sm">Retirer</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted small" style="margin:0 0 1rem">
        « Jamais vu » signifie que le site est déclaré mais n'a encore rien échangé : point de contact
        erroné de son côté, clé mal recopiée, ou sortie UDP bloquée par son opérateur. La déclaration
        seule ne prouve rien.
      </p>
    <?php endif; ?>

    <form method="post" class="stack" style="padding:0;max-width:720px">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="site_add">
      <div class="two">
        <label>Nom du site<input type="text" name="nom" required maxlength="64" placeholder="Nice — Hôtel de police"></label>
        <label>Commissariat <span class="muted small">(facultatif)</span>
          <input type="text" name="commissariat" maxlength="96"></label>
      </div>
      <label>Clé publique du site <span class="muted small">(fournie par sa console)</span>
        <input type="text" name="cle" required maxlength="44" placeholder="44 caractères se terminant par ="></label>
      <label>Adresse à lui attribuer
        <input type="text" name="adresse" required maxlength="15"
               placeholder="10.90.0.<?= count($sites) + 11 ?>" value="10.90.0.<?= count($sites) + 11 ?>"></label>
      <div><button class="btn">➕ Rattacher ce site</button></div>
    </form>
  </div>
</section>

<?php else: ?>
<!-- ══════════════ RÔLE RATTACHÉ ══════════════ -->
<section class="panel" style="margin-top:1.4rem">
  <div class="panel-head"><h2>🔗 Rattachement au principal</h2>
    <?php if (!$configuree): ?><span class="badge">non configuré</span>
    <?php elseif ($vivante): ?><span class="badge on">✓ principal joignable</span>
    <?php elseif ($montee): ?><span class="badge off">monté, sans réponse</span>
    <?php else: ?><span class="badge off">arrêté</span><?php endif; ?>
  </div>
  <div style="padding:1rem 1.2rem">
    <p class="muted small" style="margin:0 0 1rem;padding:.6rem .8rem;border-radius:8px;
       background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.28)">
      Le tunnel part <strong>d'ici vers le principal</strong> : rien à ouvrir sur la box de l'opérateur.
      Seul le réseau de gestion <code>10.90.0.0/24</code> y transite — la navigation des agents ne
      l'emprunte pas, et une panne du principal ne coupe pas Internet au commissariat.
    </p>

    <label class="muted small" style="display:block">Clé publique de ce site
      <span class="muted small">— à communiquer au principal du département</span></label>
    <div style="display:flex;gap:.5rem;align-items:center;margin:.3rem 0 1.2rem;flex-wrap:wrap">
      <input type="text" id="mapub" readonly value="<?= e($pubLocale) ?>"
             style="flex:1;min-width:320px;padding:.55rem .7rem;background:var(--bg);color:var(--text);
                    border:1px solid var(--line);border-radius:9px;font-family:ui-monospace,monospace;font-size:.82rem">
      <button type="button" class="btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('mapub').value);this.textContent='✓ copiée'">📋 Copier</button>
    </div>

    <form method="post" class="stack" style="max-width:720px;padding:0">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="config">
      <label>Clé publique du principal
        <input type="text" name="hub_pub" required maxlength="44" placeholder="44 caractères se terminant par ="></label>
      <label>Point de contact du principal <span class="muted small">(hôte:port)</span>
        <input type="text" name="hub_pt" required maxlength="120" placeholder="principal-06.exemple.fr:51820"></label>
      <label>Adresse de ce site <span class="muted small">(attribuée par le principal)</span>
        <input type="text" name="moi" required maxlength="15" placeholder="10.90.0.11"
               value="<?= e((string) ($e['adresse'] ?? '')) ?>"></label>
      <div><button class="btn">💾 Enregistrer</button></div>
    </form>

    <?php if ($configuree): ?>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1.2rem;align-items:center">
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="<?= $montee ? 'down' : 'up' ?>">
          <button class="btn"><?= $montee ? '⏻ Déconnecter' : '⏻ Connecter' ?></button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="check">
          <button class="btn-sm">🩺 Vérifier</button>
        </form>
        <span class="muted small">
          Principal : <code><?= e((string) ($e['concentrateur'] ?? '—')) ?></code> ·
          adresse ici : <code><?= e((string) ($e['adresse'] ?? '—')) ?></code>
          <?php if ($montee): ?> ·
            <?= $poignee > 0 ? 'dernier échange il y a ' . max(0, time() - $poignee) . ' s'
                             : '<strong>aucun échange depuis le montage</strong>' ?>
            · reçu <?= fmtBytes((int) ($e['recu'] ?? 0)) ?>, émis <?= fmtBytes((int) ($e['emis'] ?? 0)) ?>
          <?php endif; ?>
        </span>
      </div>
      <?php if ($montee && !$vivante): ?>
        <p class="muted small" style="margin:.8rem 0 0;padding:.6rem .8rem;border-radius:8px;
           background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#fca5a5">
          L'interface est montée mais <strong>aucun échange n'a eu lieu</strong> : point de contact erroné,
          clé non enregistrée sur le principal, ou sortie UDP bloquée. Une interface montée ne prouve rien.
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section class="panel" style="margin-top:1.4rem">
  <div class="panel-head"><h2>🛡️ Ce que cette liaison protège, et ce qui reste à surveiller</h2></div>
  <div style="padding:1rem 1.2rem">
    <p class="muted small" style="margin:0 0 .7rem">
      Ces serveurs équipent les postes <strong>hors réseau interministériel</strong> : la liaison passe donc
      par les accès opérateur des commissariats. Le tunnel n'est pas un confort, c'est ce qui remplace ici
      le réseau privé — tout ce qui circule entre les sites est chiffré de bout en bout, et chaque
      commissariat possède sa propre clé.
    </p>
    <ul class="muted small" style="margin:0;padding-left:1.2rem;line-height:1.8">
      <li><strong>Un seul point exposé</strong> dans tout le département : le principal, sur son port UDP.
      Aucun autre commissariat n'ouvre quoi que ce soit.</li>
      <li><strong>Une clé par site</strong>, révocable seule : retirer un commissariat de la table suffit à
      lui fermer la liaison, sans toucher aux autres.</li>
      <li><strong>Rien d'autre ne transite</strong> : seul <code>10.90.0.0/24</code> emprunte le tunnel.
      La navigation des agents reste sur l'accès local du commissariat.</li>
      <li>La clé privée de chaque serveur <strong>ne quitte jamais sa machine</strong> et n'est affichée nulle part.</li>
    </ul>
  </div>
</section>

<?php pf_footer(); ?>
