<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — liaison inter-sites (rattachement au concentrateur de la flotte).
 *
 * Voir services/scripts/lien-ctl.sh pour le raisonnement : tunnel SORTANT, aucune
 * ouverture sur la box du commissariat, et seul le réseau de gestion y transite.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';

function lien(string ...$args): string {
    $cmd = 'sudo /usr/local/sbin/proxyfibre-lien';
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
    return (string) shell_exec($cmd . ' 2>&1');
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'cles') {
        $out = lien('init');
        audit('lien.cles', 'paire de clés');
        $flash = [trim($out) !== '' ? 'Clé de ce site prête. Communiquez la clé publique ci-dessous au responsable du concentrateur.'
                                    : 'Génération impossible.', 'ok'];

    } elseif ($do === 'config') {
        $pub = trim((string) ($_POST['hub_pub'] ?? ''));
        $pt  = trim((string) ($_POST['hub_pt'] ?? ''));
        $moi = trim((string) ($_POST['moi'] ?? ''));
        // Les paramètres passent par l'ENTRÉE STANDARD : en argument, la clé du
        // concentrateur apparaîtrait dans la liste des processus de la machine.
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open('sudo /usr/local/sbin/proxyfibre-lien config', $desc, $tubes);
        $out = '';
        if (is_resource($p)) {
            fwrite($tubes[0], $pub . "\n" . $pt . "\n" . $moi . "\n");
            fclose($tubes[0]);
            $out = stream_get_contents($tubes[1]) . stream_get_contents($tubes[2]);
            fclose($tubes[1]); fclose($tubes[2]); proc_close($p);
        }
        $ok = strpos($out, 'OK:') !== false;
        // Le point de contact est tracé, jamais la clé : elle n'a rien à faire dans
        // un journal, même publique — inutile d'y habituer l'œil.
        audit('lien.config', $ok ? $pt . ' / ' . $moi : 'échec');
        $flash = [$ok ? 'Liaison configurée. Cliquez « Connecter » pour la monter.' : trim($out), $ok ? 'ok' : 'err'];

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
$configuree = !empty($e['configuree']);
$montee     = !empty($e['montee']);
$pubLocale  = (string) ($e['publique'] ?? '');
$poignee    = (int) ($e['poignee'] ?? 0);
// Une interface montée ne prouve rien : WireGuard n'établit de session qu'au premier
// paquet utile. C'est la POIGNÉE DE MAIN qui dit si le concentrateur répond.
$vivante    = $poignee > 0 && (time() - $poignee) < 300;

pf_header('Liaison inter-sites', 'lien.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<section class="panel">
  <div class="panel-head"><h2>🔗 Liaison inter-sites</h2>
    <?php if (!$configuree): ?><span class="badge">non configurée</span>
    <?php elseif ($vivante): ?><span class="badge on">✓ concentrateur joignable</span>
    <?php elseif ($montee): ?><span class="badge off">montée, sans réponse</span>
    <?php else: ?><span class="badge off">arrêtée</span><?php endif; ?>
  </div>
  <div style="padding:1rem 1.2rem">
    <p class="muted small" style="margin-top:0">
      Rattache cette passerelle au <strong>concentrateur de la flotte</strong>, pour que la console
      centrale voie l'état de ce commissariat. Le tunnel part <strong>d'ici vers le concentrateur</strong> :
      rien à ouvrir sur la box de l'opérateur, et une adresse qui change n'y fait rien.
    </p>
    <p class="muted small" style="margin:.5rem 0 1rem;padding:.6rem .8rem;border-radius:8px;
       background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.28)">
      <strong>Ce qui passe dans ce tunnel :</strong> uniquement le réseau de gestion <code>10.90.0.0/24</code>.
      La navigation des agents ne l'emprunte pas, et la route par défaut n'est pas touchée — une panne du
      concentrateur ne coupe pas Internet au commissariat.
    </p>

    <?php if ($pubLocale === ''): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="cles">
        <p class="muted small" style="margin:0 0 .6rem">Première étape : créer la clé de ce site.
          La clé privée reste sur cette machine et n'est jamais affichée.</p>
        <button class="btn">🔑 Créer la clé de ce site</button>
      </form>
    <?php else: ?>
      <label class="muted small" style="display:block">Clé publique de ce site
        <span class="muted small">— à communiquer au responsable du concentrateur</span></label>
      <div style="display:flex;gap:.5rem;align-items:center;margin:.3rem 0 1.2rem;flex-wrap:wrap">
        <input type="text" id="mapub" readonly value="<?= e($pubLocale) ?>"
               style="flex:1;min-width:320px;padding:.55rem .7rem;background:var(--bg);color:var(--text);
                      border:1px solid var(--line);border-radius:9px;font-family:ui-monospace,monospace;font-size:.82rem">
        <button type="button" class="btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('mapub').value);this.textContent='✓ copiée'">📋 Copier</button>
      </div>

      <form method="post" class="stack" style="max-width:720px;padding:0">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="config">
        <label>Clé publique du concentrateur
          <input type="text" name="hub_pub" required maxlength="44" placeholder="44 caractères se terminant par =">
        </label>
        <label>Point de contact du concentrateur <span class="muted small">(hôte:port)</span>
          <input type="text" name="hub_pt" required maxlength="120" placeholder="central.exemple.fr:51820">
        </label>
        <label>Adresse de ce site dans le tunnel <span class="muted small">(attribuée par le concentrateur)</span>
          <input type="text" name="moi" required maxlength="15" placeholder="10.90.0.11"
                 value="<?= e((string) ($e['adresse'] ?? '')) ?>">
        </label>
        <div><button class="btn">💾 Enregistrer la liaison</button></div>
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
            Concentrateur : <code><?= e((string) ($e['concentrateur'] ?? '—')) ?></code> ·
            adresse ici : <code><?= e((string) ($e['adresse'] ?? '—')) ?></code>
            <?php if ($montee): ?> ·
              <?= $poignee > 0
                    ? 'dernier échange il y a ' . max(0, time() - $poignee) . ' s'
                    : '<strong>aucun échange depuis le montage</strong>' ?>
              · reçu <?= fmtBytes((int) ($e['recu'] ?? 0)) ?>, émis <?= fmtBytes((int) ($e['emis'] ?? 0)) ?>
            <?php endif; ?>
          </span>
        </div>
        <?php if ($montee && !$vivante): ?>
          <p class="muted small" style="margin:.8rem 0 0;padding:.6rem .8rem;border-radius:8px;
             background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#fca5a5">
            L'interface est montée mais <strong>aucun échange n'a eu lieu</strong>. C'est le cas typique
            d'un point de contact erroné, d'une clé non enregistrée côté concentrateur, ou d'un pare-feu
            qui bloque la sortie UDP. Une interface montée ne prouve rien à elle seule.
          </p>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<section class="panel" style="margin-top:1.4rem">
  <div class="panel-head"><h2>📋 Ce qu'il reste à faire côté concentrateur</h2></div>
  <div style="padding:1rem 1.2rem">
    <ol class="muted small" style="margin:0;padding-left:1.2rem;line-height:1.9">
      <li>Déclarer ce site sur le concentrateur avec la <strong>clé publique ci-dessus</strong> et
          l'adresse qui lui est attribuée.</li>
      <li>Dans <em>Bastion Central → Sites</em>, saisir l'URL <code>https://&lt;adresse du tunnel&gt;:8443</code>
          et le jeton d'API de ce site.</li>
      <li>Vérifier depuis le concentrateur que la passerelle répond. Tant que la poignée de main
          n'apparaît pas ici, elle ne répondra pas.</li>
    </ol>
    <p class="muted small" style="margin:.9rem 0 0">
      <strong>Un mot sur le fond.</strong> Relier des commissariats à travers l'Internet public engage
      la sécurité des systèmes d'information de votre administration. Le réseau interministériel de l'État
      existe pour cet usage : si vos sites y ont accès, ce tunnel devient inutile et le concentrateur
      les joint directement. À trancher avant de généraliser.
    </p>
  </div>
</section>

<?php pf_footer(); ?>
