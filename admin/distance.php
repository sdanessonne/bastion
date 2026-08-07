<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Prise de main à distance.
 *
 * Les postes se connectent en SORTANT vers un relais hébergé sur la passerelle. Il
 * n'existe aucune route du réseau d'administration vers celui des postes, et c'est
 * ce qui protège l'administration d'un poste compromis : le relais permet la prise
 * de main sans percer cet isolement.
 *
 * Cette page ne prend pas la main elle-même — un navigateur ne sait pas le faire.
 * Elle donne à l'administrateur ce qu'il ne peut obtenir autrement : quel poste
 * porte quel identifiant, sous quel régime de consentement, et depuis quand il
 * s'est manifesté.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';
$db = pf_db();

$db->exec("CREATE TABLE IF NOT EXISTS pf_settings (k VARCHAR(64) PRIMARY KEY, v TEXT)");
foreach (['distance_id VARCHAR(32)', 'distance_mode VARCHAR(12)', 'distance_vu TIMESTAMP NULL'] as $col) {
    try { $db->exec('ALTER TABLE pf_inventaire ADD COLUMN ' . $col); } catch (Throwable $e) {}
}

/** État du relais, lu directement sur la passerelle. */
function distance_etat(): array
{
    $j = shell_exec('sudo /usr/local/sbin/proxyfibre-distance state 2>/dev/null');
    $d = json_decode((string) $j, true);
    return is_array($d) ? $d : [];
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!pf_page_autorisee('distance.php')) { http_response_code(403); exit('Interdit'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'defaut') {
        $m = ($_POST['mode'] ?? '') === 'libre' ? 'libre' : 'accord';
        $db->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')
           ->execute(['distance_mode_defaut', $m]);
        audit('distance.defaut', 'Régime par défaut du parc : ' . $m);
        $flash = ['Régime par défaut enregistré : ' . ($m === 'libre' ? 'sans accord' : 'accord de l\'agent')
                . '. Il s\'applique aux postes au prochain démarrage.', 'ok'];
    }

    if ($action === 'poste') {
        $p = strtoupper(substr(trim((string) ($_POST['poste'] ?? '')), 0, 64));
        $m = (string) ($_POST['mode'] ?? '');
        $v = in_array($m, ['accord', 'libre'], true) ? $m : null;   // null = suivre le défaut
        $db->prepare('UPDATE pf_inventaire SET distance_mode=? WHERE poste=?')->execute([$v, $p]);
        // Tracé nominativement : lever le consentement sur un poste est une décision
        // qui engage, pas un réglage d'affichage.
        audit('distance.poste', 'Régime du poste ' . $p . ' : ' . ($v ?? 'défaut du parc'));
        $flash = ['Régime du poste ' . $p . ' enregistré. Il s\'applique au prochain démarrage du poste.', 'ok'];
    }

    if ($action === 'session') {
        // La prise de main elle-même a lieu dans le client, hors du navigateur. On
        // enregistre la DÉCLARATION d'intervention : sans elle, une prise de main ne
        // laisserait aucune trace côté console, et « qui est intervenu sur ce poste »
        // resterait sans réponse.
        $p = strtoupper(substr(trim((string) ($_POST['poste'] ?? '')), 0, 64));
        $motif = substr(trim((string) ($_POST['motif'] ?? '')), 0, 190);
        audit('distance.session', 'Prise de main sur ' . $p . ($motif !== '' ? ' — ' . $motif : ''));
        $flash = ['Intervention sur ' . $p . ' inscrite au journal d\'audit.', 'ok'];
    }
}

$defaut = 'accord';
try {
    $v = $db->query("SELECT v FROM pf_settings WHERE k='distance_mode_defaut'")->fetchColumn();
    if ($v === 'libre') { $defaut = 'libre'; }
} catch (Throwable $e) {}

$etat = distance_etat();
$postes = [];
try {
    $postes = $db->query('SELECT poste, utilisateur, ip, vu_le, distance_id, distance_mode, distance_vu
                          FROM pf_inventaire ORDER BY (distance_id IS NULL), poste')->fetchAll();
} catch (Throwable $e) {}

$actifs = count(array_filter($postes, fn($p) => (string) $p['distance_id'] !== ''));

pf_header('Prise de main à distance');
?>
<?php if ($flash): ?><div class="flash <?= e($flash[1]) ?>"><?= e($flash[0]) ?></div><?php endif; ?>

<div class="panel">
  <div class="panel-head"><h2>🖥️ Relais de prise de main</h2></div>
  <div style="padding:1rem 1.2rem">
    <?php
      $ok = ($etat['hbbs'] ?? '') === 'active' && ($etat['hbbr'] ?? '') === 'active';
      $portail = (bool) ($etat['portail_ouvert'] ?? false);
    ?>
    <p>
      <span class="badge <?= $ok ? 'on' : 'off' ?>"><?= $ok ? '✓ Relais en service' : '✗ Relais arrêté' ?></span>
      <span class="badge <?= $portail ? 'on' : 'off' ?>" title="Le portail captif doit autoriser les postes à joindre le relais">
        <?= $portail ? '✓ Postes autorisés à joindre le relais' : '✗ Portail captif : accès au relais fermé' ?></span>
      <?php $pub = (bool) ($etat['public'] ?? false); $cleOk = (bool) ($etat['cle_exigee'] ?? false); ?>
      <span class="badge"><?= $pub ? '🌐 Adresse publique' : '🏠 Adresse locale' ?></span>
      <span class="badge <?= $cleOk ? 'on' : 'off' ?>" title="Le relais doit exiger sa clé de tout client">
        <?= $cleOk ? '✓ Clé exigée des clients' : '✗ Clé non exigée' ?></span>
    </p>
    <?php if ($pub && !$cleOk): ?>
      <?php /* La combinaison la plus dangereuse : joignable du monde entier, et
                n'importe qui peut s'y enregistrer. À dire fort, pas en note de bas de page. */ ?>
      <p class="flash err">⚠ Le relais est annoncé sur une adresse publique <strong>sans exiger sa clé</strong> :
      n'importe qui sur Internet peut s'y enregistrer et s'en servir. Relancez
      <code>setup-distance.sh install</code> sur la passerelle, qui ajoute l'option manquante.</p>
    <?php elseif ($pub): ?>
      <p class="tip">Le relais est joignable depuis Internet — c'est ce qui permet de dépanner un poste depuis
      l'extérieur et de raccorder d'autres commissariats. Ce qui le protège est la clé, exigée de tout client.
      Elle doit rester connue des seuls postes du service : la diffuser revient à ouvrir le relais.</p>
    <?php endif; ?>
    <?php if (!$portail): ?>
      <p class="tip">Les postes ne peuvent pas atteindre le relais : la chaîne <code>ndsRTR</code> du portail captif
      les rejette. Les services ont beau tourner, aucun poste ne s'enregistrera. Relancez
      <code>setup-distance.sh install</code> sur la passerelle, qui ajoute les règles manquantes.</p>
    <?php endif; ?>
    <table class="tbl" style="max-width:760px">
      <tr><th style="width:180px">Adresse du relais</th><td><code><?= e((string) ($etat['relais'] ?? '—')) ?></code></td></tr>
      <tr><th>Clé publique</th><td><code style="word-break:break-all"><?= e((string) ($etat['cle'] ?? '—')) ?></code></td></tr>
      <tr><th>Ports en écoute</th><td><?= (int) ($etat['ports_ecoutes'] ?? 0) ?> sur 6</td></tr>
    </table>
    <?php
      // L'installeur est celui que la passerelle sert déjà aux postes : administrateur
      // et postes emploient donc EXACTEMENT le même fichier et la même version. Aller
      // le chercher sur Internet ferait diverger les deux côtés, et une version de
      // client plus récente que le relais refuse parfois de s'y connecter.
      $client = '/var/www/html/apps/rustdesk-client.exe';
      $dispo  = is_file($client);
      $poids  = $dispo ? round(filesize($client) / 1048576, 1) : 0;
      $gwPub  = strtok((string) ($etat['relais'] ?? ''), ':');
    ?>
    <div style="display:flex;gap:.7rem;align-items:center;flex-wrap:wrap;margin:.9rem 0 .3rem">
      <?php if ($dispo): ?>
        <a class="btn-sm" href="https://<?= e($gwPub ?: '') ?>:2443/apps/rustdesk-client.exe" download>
          ⬇ Télécharger le client (<?= $poids ?> Mo)</a>
        <span class="muted small">Windows 64 bits — le même installeur que celui posé sur les postes.</span>
      <?php else: ?>
        <span class="badge off">✗ Installeur absent de la passerelle</span>
        <span class="muted small">Les postes ne pourront pas l'installer non plus : la stratégie le
        télécharge depuis <code>/apps/rustdesk-client.exe</code>. Relancez
        <code>setup-distance.sh install</code>, ou déposez le fichier à la main.</span>
      <?php endif; ?>
    </div>
    <p class="muted small">Installez-le sur votre poste d'administration, puis renseignez le serveur et la clé
    ci-dessus dans <em>Paramètres → Réseau</em> du client. Sans la clé, il se connecterait au serveur public
    de l'éditeur au lieu du vôtre, et le dépannage sortirait du service. Le poste distant se joint ensuite par
    son identifiant, dans le tableau ci-dessous.</p>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><h2>🔐 Consentement de l'agent</h2></div>
  <div style="padding:1rem 1.2rem">
    <p>En régime <strong>« accord de l'agent »</strong>, une fenêtre lui demande d'accepter et une bannière reste
    visible pendant toute l'intervention. En régime <strong>« sans accord »</strong>, la main se prend directement.</p>
    <p class="tip">Le second régime n'a de sens que sur un poste <em>sans utilisateur</em> — borne, poste technique,
    salle libre-service. Sur le poste d'un agent, prendre la main sans son accord ni information préalable expose
    le service : les constatations faites ainsi sont contestables, et la démarche doit figurer au registre RGPD.</p>
    <form method="post" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="defaut">
      <label>Régime par défaut du parc :</label>
      <select name="mode" style="padding:.4rem .6rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
        <option value="accord" <?= $defaut === 'accord' ? 'selected' : '' ?>>Accord de l'agent obligatoire</option>
        <option value="libre"  <?= $defaut === 'libre'  ? 'selected' : '' ?>>Sans accord (postes sans utilisateur)</option>
      </select>
      <button class="btn-sm">Enregistrer</button>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><h2>💻 Postes joignables (<span data-num="<?= $actifs ?>"><?= $actifs ?></span> sur <?= count($postes) ?>)</h2></div>
  <div style="padding:.4rem 1.2rem 1.2rem;overflow-x:auto">
    <?php if (!$postes): ?>
      <p class="muted">Aucun poste inventorié. Les postes apparaissent ici après leur premier démarrage
      avec la stratégie « Bastion — Prise de main à distance ».</p>
    <?php else: ?>
    <table class="tbl">
      <thead><tr><th>Poste</th><th>Agent</th><th>Identifiant</th><th>Régime</th><th>Vu le</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($postes as $p):
        $id = (string) $p['distance_id'];
        $m  = (string) ($p['distance_mode'] ?? '');
        $regime = $m !== '' ? $m : $defaut;
      ?>
        <tr>
          <td><strong><?= e((string) $p['poste']) ?></strong><br><span class="muted small"><?= e((string) $p['ip']) ?></span></td>
          <td><?= e((string) $p['utilisateur']) ?></td>
          <td>
            <?php if ($id !== ''): ?>
              <code style="font-size:1.02rem;letter-spacing:.04em"><?= e($id) ?></code>
            <?php else: ?>
              <span class="muted small">pas encore annoncé</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" style="display:flex;gap:.3rem;align-items:center">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="poste">
              <input type="hidden" name="poste" value="<?= e((string) $p['poste']) ?>">
              <select name="mode" onchange="this.form.submit()" style="padding:.25rem .4rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:7px;font-size:.82rem">
                <option value=""       <?= $m === ''       ? 'selected' : '' ?>>Défaut (<?= $defaut === 'libre' ? 'sans accord' : 'accord' ?>)</option>
                <option value="accord" <?= $m === 'accord' ? 'selected' : '' ?>>Accord obligatoire</option>
                <option value="libre"  <?= $m === 'libre'  ? 'selected' : '' ?>>Sans accord</option>
              </select>
              <?php if ($regime === 'libre'): ?><span title="Prise de main sans accord de l'agent">⚠</span><?php endif; ?>
            </form>
          </td>
          <td class="muted small"><?= e((string) ($p['distance_vu'] ?: $p['vu_le'])) ?></td>
          <td>
            <?php if ($id !== ''): ?>
            <form method="post" onsubmit="return this.motif.value.trim()!==''||confirm('Inscrire l\'intervention sans motif ?')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="session">
              <input type="hidden" name="poste" value="<?= e((string) $p['poste']) ?>">
              <input name="motif" placeholder="motif de l'intervention" style="width:170px;padding:.25rem .4rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:7px;font-size:.8rem">
              <button class="btn-sm">Déclarer</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small" style="margin-top:.8rem">« Déclarer » inscrit l'intervention au journal d'audit avec votre
    nom. La prise de main a lieu dans le client, hors du navigateur : sans cette déclaration, la console ne
    saurait pas dire qui est intervenu sur quel poste.</p>
    <?php endif; ?>
  </div>
</div>
<?php pf_footer(); ?>
