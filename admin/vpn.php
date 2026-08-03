<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Sortie Internet par tunnel : état, contrôle, postes concernés.
 *
 * ── POURQUOI CETTE PAGE EXISTE ───────────────────────────────────────────────
 * Le réglage vivait uniquement dans le formulaire d'un groupe. Conséquence : si
 * le tunnel tombait, les postes du groupe perdaient Internet et RIEN dans la
 * console ne l'annonçait — il fallait ouvrir par hasard le formulaire d'un
 * groupe pour l'apprendre. Une panne visible côté agent, invisible côté
 * administrateur : exactement ce qu'il faut éviter.
 *
 * Cette page rend l'état permanent et consultable, et surtout elle permet de
 * VÉRIFIER l'adresse de sortie réelle — le seul contrôle qui prouve que le
 * dispositif fait ce qu'il promet.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';
$db = pf_db();

$flash = null;
$verif = null;

/** Chemin de dépôt UNIQUE, le seul que sudoers autorise à « import ». */
const VPN_DEPOT = '/run/bastion/vpn-import.conf';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'check') {
        // La vérification sort sur Internet : elle est donc DÉCLENCHÉE par
        // l'administrateur, jamais jouée automatiquement à l'affichage de la page.
        $verif = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-vpn check 2>&1'));
        audit('vpn.verification', strpos($verif, 'OK:') === 0 ? 'sortie confirmée' : 'ÉCHEC — ' . $verif);

    } elseif ($do === 'import') {
        // ── LE FICHIER CONTIENT UNE CLÉ PRIVÉE ───────────────────────────────
        // Il n'est jamais réaffiché, jamais journalisé, jamais conservé côté web.
        // Il transite par un chemin unique, hors de la racine du serveur, que le
        // script efface aussitôt l'import fait.
        $f = $_FILES['conf'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flash = ['Aucun fichier reçu.', 'err'];
        } elseif (($f['size'] ?? 0) > 20480) {
            // Une configuration WireGuard fait quelques centaines d'octets. Au-delà
            // de 20 Ko, ce n'en est pas une — on refuse avant de lire.
            $flash = ['Fichier trop volumineux pour une configuration WireGuard.', 'err'];
        } else {
            $brut = (string) @file_get_contents($f['tmp_name']);
            if (strpos($brut, '[Interface]') === false || strpos($brut, '[Peer]') === false
                || !preg_match('/^\s*PrivateKey\s*=/mi', $brut)) {
                $flash = ["Ce fichier n'est pas une configuration WireGuard complète "
                        . "(sections [Interface] et [Peer], clé privée).", 'err'];
            } elseif (!is_dir(dirname(VPN_DEPOT)) || !is_writable(dirname(VPN_DEPOT))) {
                $flash = ['Dépôt indisponible (' . dirname(VPN_DEPOT) . ') — relancer le déploiement.', 'err'];
            } else {
                // umask AVANT l'écriture : créer puis corriger laisserait le secret
                // lisible pendant l'intervalle, si court soit-il.
                $old = umask(0177);
                $ok = @file_put_contents(VPN_DEPOT, $brut) !== false;
                umask($old);
                if (!$ok) {
                    $flash = ['Écriture impossible dans le dépôt.', 'err'];
                } else {
                    $r = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-vpn import '
                        . escapeshellarg(VPN_DEPOT) . ' 2>&1'));
                    @unlink(VPN_DEPOT);   // au cas où le script n'aurait pas abouti
                    $bon = strpos($r, 'configuration importée') !== false;
                    // Le nom du fichier déposé est tracé, jamais son contenu.
                    audit('vpn.import', ($bon ? 'configuration importée — ' : 'ÉCHEC — ')
                        . basename((string) ($f['name'] ?? '')));
                    $flash = [$bon ? 'Configuration importée. Vous pouvez monter le tunnel.' : $r,
                              $bon ? 'ok' : 'err'];
                }
            }
        }

    } elseif ($do === 'up' || $do === 'down') {
        // « down » coupe l'accès des groupes concernés : c'est voulu, et c'est
        // précisément pour cela que l'action est tracée avec son auteur.
        $r = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-vpn ' . $do . ' 2>&1'));
        $bon = $do === 'up' ? (strpos($r, 'tunnel actif') !== false)
                            : (strpos($r, 'tunnel arrêté') !== false);
        audit('vpn.' . $do, $bon ? 'succès' : 'ÉCHEC — ' . $r);
        $flash = [$r !== '' ? $r : ($bon ? 'Terminé.' : 'Échec.'), $bon ? 'ok' : 'err'];
    }
}

$st = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-vpn state 2>/dev/null'), true) ?: [];
$conf  = !empty($st['config']);
$iface = !empty($st['interface']);
$actif = !empty($st['actif']);
$age   = (int) ($st['handshake_s'] ?? -1);

$postes = array_values(array_filter(array_map('trim',
    explode("\n", (string) shell_exec('sudo /usr/local/sbin/proxyfibre-vpn clients 2>/dev/null')))));

$grpVpn = [];
try {
    foreach ($db->query('SELECT groupname FROM pf_groups WHERE vpn_exit=1 ORDER BY groupname') as $r) {
        $grpVpn[] = (string) $r['groupname'];
    }
} catch (Throwable $e) {}

// ── L'ALERTE QUI MANQUAIT ───────────────────────────────────────────────────
// Un groupe marqué « tunnel » alors que le tunnel est mort, c'est un service
// privé d'Internet. C'est la seule combinaison qui exige une action immédiate.
$alerte = $grpVpn && !$actif;

function octets(int $n): string {
    if ($n <= 0) { return '—'; }
    $u = ['o', 'Ko', 'Mo', 'Go', 'To']; $i = 0;
    while ($n >= 1024 && $i < 4) { $n = (int) ($n / 1024); $i++; }
    return $n . ' ' . $u[$i];
}

pf_header('VPN', 'vpn.php');
?>
<style>
  .etat{display:flex;align-items:center;gap:1.1rem;padding:1.1rem 1.3rem;border-radius:13px;
        border:1px solid;margin-bottom:1.2rem;flex-wrap:wrap}
  .etat.ok{background:rgba(74,222,128,.08);border-color:rgba(74,222,128,.35)}
  .etat.ko{background:rgba(248,113,113,.09);border-color:rgba(248,113,113,.4)}
  .etat.off{background:rgba(148,163,184,.07);border-color:var(--line)}
  .etat .p{font-size:1.9rem;line-height:1}
  .etat .t{font-weight:700;font-size:1.05rem}
  .etat .d{color:var(--muted);font-size:.87rem;margin-top:.2rem;line-height:1.55;max-width:70ch}
  .kv{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.7rem}
  .kv .c{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem}
  .kv .c .v{font-size:1.15rem;font-weight:700}
  .kv .c .l{color:var(--muted);font-size:.75rem;margin-top:.2rem}
  .etapes{margin:0;padding-left:1.3rem;display:grid;gap:1.1rem}
  .etapes li{line-height:1.5}
  .etapes .d{display:block;color:var(--muted);font-size:.84rem;line-height:1.65;margin-top:.25rem}
  .etapes code{background:var(--bg);padding:.05rem .3rem;border-radius:5px;font-size:.85em}
</style>

<?php
// ── LE RETOUR D'ACTION EST AFFICHÉ ───────────────────────────────────────────
// Écrit lors de la première rédaction, ce bloc manquait : $flash était renseigné
// à chaque import, chaque montage, chaque échec… et n'apparaissait nulle part.
// Un import refusé n'aurait produit AUCUN message — la page se serait rechargée
// à l'identique, et l'administrateur aurait conclu que rien ne se passe.
if ($flash): ?>
  <div class="<?= $flash[1] === 'ok' ? 'ok' : 'err' ?>" style="margin-bottom:1rem"><?= e($flash[0]) ?></div>
<?php endif; ?>

<?php if ($alerte): ?>
<div class="etat ko">
  <span class="p">✖</span>
  <div>
    <div class="t">Des agents sont privés d'Internet</div>
    <div class="d">
      <?= count($grpVpn) ?> groupe(s) — <strong><?= e(implode(', ', $grpVpn)) ?></strong> — sont configurés pour sortir
      par le tunnel, mais celui-ci ne répond pas. Leurs postes sont <strong>bloqués</strong>, et c'est voulu :
      les laisser repasser en sortie directe les ferait travailler sous l'adresse du commissariat
      en croyant être couverts. Remontez le tunnel, ou décochez la case dans le groupe concerné.
    </div>
  </div>
</div>
<?php endif; ?>

<div class="etat <?= $actif ? 'ok' : ($conf ? 'ko' : 'off') ?>">
  <span class="p"><?= $actif ? '🔒' : ($conf ? '⚠' : '○') ?></span>
  <div>
    <div class="t">
      <?= $actif ? 'Tunnel actif' : ($conf ? 'Tunnel configuré mais inactif' : 'Aucun tunnel configuré') ?>
    </div>
    <div class="d">
      <?php if ($actif): ?>
        Les postes des groupes concernés sortent sous l'adresse du fournisseur du tunnel.
        Dernière poignée de main il y a <?= $age ?> s.
      <?php elseif ($conf): ?>
        Une configuration est présente, mais le pair ne répond pas — l'interface peut exister
        sans que rien ne passe. Tant que la poignée de main n'est pas rétablie, le trafic des
        groupes concernés reste bloqué.
      <?php else: ?>
        Aucune configuration WireGuard n'a été importée. Les groupes cochés « sortie par tunnel »
        n'auraient pas d'accès Internet du tout.
      <?php endif; ?>
    </div>
  </div>
</div>

<section class="panel">
  <div class="panel-head"><h2>État</h2></div>
  <div style="padding:1.2rem">
    <div class="kv">
      <div class="c"><div class="v"><?= $conf ? 'oui' : 'non' ?></div><div class="l">configuration importée</div></div>
      <div class="c"><div class="v"><?= $iface ? 'oui' : 'non' ?></div><div class="l">interface montée</div></div>
      <div class="c"><div class="v"><?= $age >= 0 ? $age . ' s' : '—' ?></div><div class="l">dernière poignée de main</div></div>
      <div class="c"><div class="v" style="font-size:.9rem"><?= e((string) ($st['endpoint'] ?? '')) ?: '—' ?></div><div class="l">point de sortie</div></div>
      <div class="c"><div class="v"><?= octets((int) ($st['rx'] ?? 0)) ?></div><div class="l">reçu</div></div>
      <div class="c"><div class="v"><?= octets((int) ($st['tx'] ?? 0)) ?></div><div class="l">émis</div></div>
      <div class="c"><div class="v"><?= count($postes) ?></div><div class="l">postes routés</div></div>
      <div class="c"><div class="v"><?= count($grpVpn) ?></div><div class="l">groupes concernés</div></div>
    </div>
    <p class="muted small" style="margin:1rem 0 0;line-height:1.6">
      <strong>« Interface montée » ne veut pas dire « tunnel actif ».</strong> WireGuard crée l'interface
      même quand le pair ne répond pas ; seule une poignée de main récente (moins de 5 minutes)
      prouve que le trafic passe. C'est ce critère qui commande le verrou.
    </p>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>Vérifier l'adresse de sortie</h2></div>
  <div style="padding:1.2rem">
    <p class="muted small" style="margin-top:0;line-height:1.6">
      Une poignée de main prouve que le tunnel vit, pas que le trafic sort par lui. Ce contrôle
      interroge l'adresse publique <strong>deux fois</strong> — par le tunnel et en direct — et
      échoue si elles sont identiques : le tunnel ne servirait alors à rien.
      Il émet deux requêtes vers Internet ; il n'est donc lancé que sur demande, jamais tout seul.
    </p>
    <?php if ($verif !== null): ?>
      <div class="<?= strpos($verif, 'OK:') === 0 ? 'ok' : 'err' ?>" style="margin:.8rem 0"><?= e($verif) ?></div>
    <?php endif; ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="check">
      <button class="btn" <?= $actif ? '' : 'disabled title="Tunnel inactif — rien à vérifier"' ?>>Vérifier maintenant</button>
    </form>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>Postes actuellement routés (<?= count($postes) ?>)</h2></div>
  <div style="padding:1.2rem">
    <?php if (!$postes): ?>
      <p class="muted" style="margin:0">Aucun poste. Les postes sont basculés automatiquement
      <strong>à leur connexion au portail</strong>, s'ils appartiennent à un groupe coché
      « sortie par tunnel ». Un agent déjà connecté ne bascule qu'à sa prochaine connexion.</p>
    <?php else: ?>
      <div class="kv">
        <?php foreach ($postes as $p): ?><div class="c"><div class="v" style="font-size:.95rem"><?= e($p) ?></div><div class="l">poste routé</div></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="muted small" style="margin:1rem 0 0">
      Groupes concernés :
      <?= $grpVpn ? '<strong>' . e(implode(', ', $grpVpn)) . '</strong>' : 'aucun' ?>.
      Le réglage se trouve dans <a href="/groups.php">Groupes &amp; quotas</a>.
    </p>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>Mise en service</h2></div>
  <div style="padding:1.2rem">
    <ol class="etapes">
      <li>
        <strong>Récupérer la configuration chez Proton</strong>
        <span class="d">Compte Proton VPN → <em>Downloads</em> → <em>WireGuard configuration</em>.
        Choisissez un serveur, cochez la plateforme <em>Router</em> ou <em>Linux</em>, et téléchargez
        le fichier <code>.conf</code>. Cette étape se fait chez Proton : aucune interface programmable
        ne permet de s'y connecter depuis un outil tiers, et Bastion ne vous demandera jamais vos
        identifiants Proton.</span>
      </li>
      <li>
        <strong>Déposer le fichier ici</strong>
        <span class="d">Il contient une <strong>clé privée</strong> : il est écrit hors de la racine du
        serveur web, en lecture pour le seul compte root, et effacé du dépôt aussitôt l'import
        terminé. Il n'est jamais réaffiché ni journalisé — seul son nom apparaît dans l'audit.</span>
        <form method="post" enctype="multipart/form-data" style="margin:.7rem 0 0;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="import">
          <input type="file" name="conf" accept=".conf,text/plain" required
                 style="flex:1;min-width:230px;font-size:.85rem">
          <button class="btn">Importer</button>
        </form>
      </li>
      <li>
        <strong>Monter le tunnel</strong>
        <span class="d">Le bouton attend une <em>poignée de main</em> réelle avant d'annoncer le succès :
        l'interface se crée même quand le pair ne répond pas, et déclarer « actif » à ce moment-là
        promettrait une protection inexistante.</span>
        <div style="margin:.7rem 0 0;display:flex;gap:.6rem;flex-wrap:wrap">
          <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="up">
            <button class="btn" <?= $conf ? '' : 'disabled title="Importez d\'abord une configuration"' ?>>
              <?= $actif ? 'Remonter le tunnel' : 'Monter le tunnel' ?>
            </button>
          </form>
          <?php if ($iface): ?>
          <form method="post" style="margin:0"
                onsubmit="return confirm('Arrêter le tunnel ?\n\nLes postes des groupes concernés perdront leur accès Internet — ils ne repasseront pas en sortie directe.')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="down">
            <button class="btn-danger">Arrêter le tunnel</button>
          </form>
          <?php endif; ?>
        </div>
      </li>
      <li>
        <strong>Vérifier l'adresse de sortie</strong>
        <span class="d">Le bouton du panneau ci-dessus. Ne sautez pas cette étape : un tunnel qui
        répond n'est pas un tunnel par lequel le trafic passe.</span>
      </li>
    </ol>

    <p class="muted small" style="margin:1.2rem 0 0;line-height:1.7">
      <strong>Deux réserves.</strong> Le DNS des postes concernés passe encore par le résolveur local
      de la passerelle : le tunnel masque la connexion, pas la résolution du nom. Et faire transiter
      du trafic d'enquête par un opérateur commercial mérite l'accord de votre SSI — la journalisation
      Bastion, elle, reste entière : on sait toujours qui a fait quoi.
    </p>
  </div>
</section>
<?php pf_footer(); ?>
