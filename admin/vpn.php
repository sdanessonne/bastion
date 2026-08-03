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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['do'] ?? '') === 'check') {
        // La vérification sort sur Internet : elle est donc DÉCLENCHÉE par
        // l'administrateur, jamais jouée automatiquement à l'affichage de la page.
        $verif = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-vpn check 2>&1'));
        audit('vpn.verification', strpos($verif, 'OK:') === 0 ? 'sortie confirmée' : 'ÉCHEC — ' . $verif);
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

pf_header('Sortie par tunnel', 'vpn.php');
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
  .cmd{background:#0b1220;border:1px solid var(--line);border-radius:9px;padding:.6rem .8rem;
       font-family:ui-monospace,monospace;font-size:.82rem;overflow-x:auto;white-space:pre;color:#cbd5e1}
</style>

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
    <p class="muted small" style="margin-top:0;line-height:1.7">
      L'import de la configuration et le montage du tunnel <strong>ne se font pas depuis la console</strong>,
      délibérément : le fichier contient une clé privée, et monter ou démonter le tunnel coupe
      l'accès d'un groupe entier. Ces opérations restent à la main d'un administrateur système sur
      la machine. La console lit l'état et vérifie la sortie — elle ne pilote pas.
    </p>
    <div class="cmd">sudo proxyfibre-vpn import /chemin/vers/configuration.conf
sudo proxyfibre-vpn up
sudo proxyfibre-vpn check</div>
    <p class="muted small" style="margin:1rem 0 0;line-height:1.7">
      <strong>Deux réserves.</strong> Le DNS des postes concernés passe encore par le résolveur local
      de la passerelle : le tunnel masque la connexion, pas la résolution du nom. Et faire transiter
      du trafic d'enquête par un opérateur commercial mérite l'accord de votre SSI — la journalisation
      Bastion, elle, reste entière : on sait toujours qui a fait quoi.
    </p>
  </div>
</section>
<?php pf_footer(); ?>
