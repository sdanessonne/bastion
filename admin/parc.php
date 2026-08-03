<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — Parc informatique : fiche complète de chaque poste (matériel, système, logiciels). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';
require_once __DIR__ . '/inc/adcache.php';
$db = pf_db();

/** Jeton porté par le collecteur déployé sur les postes (créé au premier besoin). */
function parc_token(PDO $db): string {
    try {
        $t = (string) $db->query("SELECT v FROM pf_settings WHERE k='inventory_token'")->fetchColumn();
        if ($t === '') {
            $t = bin2hex(random_bytes(24));
            $db->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')
               ->execute(['inventory_token', $t]);
        }
        return $t;
    } catch (Throwable $e) { return ''; }
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'deploy') {
        // L'URL doit être joignable par les postes : on prend l'IP du LAN, pas « localhost ».
        $lan = trim((string) shell_exec("ip -4 addr show scope global 2>/dev/null | awk '/inet /{print \$2}' | cut -d/ -f1 | head -1"))
               ?: '192.168.182.1';
        $url = 'https://' . $lan . ':8443/api.php?action=poste.inventaire';
        $out = ad('gpo', 'inventaire', $url, parc_token($db));
        $err = stripos($out, 'ERROR') !== false;
        audit('parc.deploy', $err ? 'echec' : $url);
        $flash = [$err ? 'Déploiement impossible : ' . $out
                       : "Collecteur déployé. Chaque poste se signalera à la prochaine ouverture de session d'un agent.",
                  $err ? 'err' : 'ok'];
    } elseif ($do === 'forget') {
        $p = strtoupper(preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($_POST['poste'] ?? '')));
        if ($p !== '') {
            try { $db->prepare('DELETE FROM pf_inventaire WHERE poste=?')->execute([$p]); } catch (Throwable $e) {}
            audit('parc.forget', $p);
            $flash = ["Fiche de « $p » retirée de l'inventaire.", 'ok'];
        }
    }
}

// ── Inventaire remonté par les postes ────────────────────────────────────────
$inv = [];
try { $inv = $db->query('SELECT * FROM pf_inventaire ORDER BY poste')->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { /* table pas encore créée : aucun poste ne s'est encore signalé */ }
// Colonnes ajoutées après coup : tant qu'aucun poste ne s'est signalé depuis la mise à
// jour, la migration n'a pas tourné et « SELECT * » ne les renvoie pas. On les normalise
// une fois ici plutôt que de s'en méfier à chaque affichage.
foreach ($inv as &$r) { $r += ['activation' => null, 'activation_det' => null]; }
unset($r);
$byName = [];
foreach ($inv as $r) { $byName[strtoupper($r['poste'])] = $r; }

// Postes connus de l'annuaire : permet de repérer ceux qui ne se signalent PAS.
$adPostes = [];
foreach (ad_lines_cached('computers', 0, 'computer', 'list') as $c) {
    $c = trim($c); if ($c !== '') { $adPostes[strtoupper($c)] = true; }
}
$jamais = array_values(array_filter(array_keys($adPostes), fn($n) => !isset($byName[$n])));
sort($jamais);

$gpoOn = in_array('Bastion — Inventaire du parc',
                  array_map(fn($g) => $g['name'] ?? '', $gpos ?? []), true);
try { $gpoOn = $gpoOn || (bool) $db->query("SELECT 1 FROM pf_settings WHERE k='inventory_token'")->fetchColumn(); }
catch (Throwable $e) {}

// Export CSV (avant tout affichage).
if (($_GET['csv'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="parc-bastion-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w');
    fputs($o, "\xEF\xBB\xBF");   // marque d'ordre : Excel ouvre l'UTF-8 correctement
    fputcsv($o, ['Poste', 'Vu le', 'Utilisateur', 'Système', 'Version', 'Build', 'Fabricant', 'Modèle',
                 'N° de série', 'Processeur', 'Cœurs', 'Mémoire (Mo)', 'Disque (Go)', 'Libre (Go)',
                 'IP', 'MAC', 'Démarrage sécurisé'], ';');
    foreach ($inv as $r) {
        fputcsv($o, [$r['poste'], $r['vu_le'], $r['utilisateur'], $r['os_nom'], $r['os_version'], $r['os_build'],
                     $r['fabricant'], $r['modele'], $r['serie'], $r['processeur'], $r['coeurs'], $r['memoire_mo'],
                     $r['disque_go'], $r['libre_go'], $r['ip'], $r['mac'],
                     ((int) $r['secureboot'] === 1 ? 'oui' : ((int) $r['secureboot'] === 0 ? 'non' : 'inconnu'))], ';');
    }
    fclose($o); exit;
}

$typeLbl = [1 => 'Fixe', 2 => 'Portable', 3 => 'Station de travail', 4 => 'Serveur'];
$skuLbl  = [4 => 'Entreprise', 27 => 'Entreprise N', 125 => 'Entreprise', 121 => 'Éducation', 122 => 'Éducation N',
            48 => 'Professionnel', 49 => 'Professionnel N', 101 => 'Famille', 98 => 'Famille N'];
$go = fn($v) => $v > 0 ? number_format((float) $v, 0, ',', ' ') . ' Go' : '—';

pf_header('Parc informatique', 'parc.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<style>
  .parc-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.8rem;margin-bottom:1.2rem}
  .parc-kpi .k{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:.9rem 1rem}
  .parc-kpi .k b{display:block;font-size:1.5rem;color:var(--text)}
  .parc-kpi .k span{font-size:.8rem;color:var(--muted)}
  .fiche dl{display:grid;grid-template-columns:auto 1fr;gap:.25rem .9rem;margin:0}
  .fiche dt{color:var(--muted);font-size:.82rem}
  .fiche dd{margin:0;font-size:.86rem}
  .apps{max-height:220px;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:.5rem .7rem;font-size:.8rem}
</style>

<?php
$nbP = count($inv);
$nbPortables = count(array_filter($inv, fn($r) => (int) $r['type_machine'] === 2));
$memMoy = $nbP ? round(array_sum(array_column($inv, 'memoire_mo')) / $nbP / 1024, 1) : 0;
$disqueTendu = count(array_filter($inv, fn($r) => (int) $r['disque_go'] > 0 && (int) $r['libre_go'] < 10));
// Postes dont l'horloge est trop décalée : au-delà de 5 minutes, l'authentification du domaine
// est refusée et AUCUNE stratégie ordinateur ne s'applique (ni applications, ni chiffrement).
$horloge = count(array_filter($inv, fn($r) => $r['horloge_ecart'] !== null && abs((int) $r['horloge_ecart']) > 300));

// ── ACTIVATION WINDOWS ────────────────────────────────────────────────────────
// Une activation — et surtout une montée d'édition — qui échoue ne se voyait nulle
// part : le poste restait en Professionnel et il fallait aller le constater sur place.
// Le compte rendu déposé par la stratégie remonte maintenant avec l'inventaire.
$actLbl = [
    'active'       => ['on',  'activé',                'Activé par le serveur KMS de Bastion'],
    'ok-existant'  => ['on',  'déjà activé',           'Licence existante préservée : Bastion n’y a pas touché'],
    'hors-bastion' => ['',    'activé hors Bastion',   'Licence en place, mais la stratégie d’activation n’a jamais été appliquée'],
    'redemarrage'  => ['',    'redémarrage à faire',   'La clé Entreprise est posée ; le changement d’édition prendra effet au prochain démarrage'],
    'echec'        => ['off', 'échec',                 'Le poste n’a pas pu être activé — journal C:\\Windows\\Temp\\bastion-activation.log'],
    'non-active'   => ['off', 'non activé',            'La stratégie d’activation n’a jamais tourné sur ce poste'],
    'ignore'       => ['',    'édition non gérée',     'Cette édition de Windows n’est pas prise en charge par l’activation KMS'],
];
$actKo = count(array_filter($inv, fn($r) => in_array((string) $r['activation'], ['echec', 'non-active'], true)));
?>
<div class="parc-kpi">
  <div class="k"><b><?= $nbP ?></b><span>poste(s) inventorié(s)</span></div>
  <div class="k"><b><?= count($jamais) ?></b><span>jamais signalé(s)</span></div>
  <div class="k"><b><?= $nbPortables ?></b><span>portable(s)</span></div>
  <div class="k"><b><?= $memMoy ?: '—' ?></b><span>Go de mémoire en moyenne</span></div>
  <div class="k"><b style="color:<?= $disqueTendu ? '#f87171' : 'inherit' ?>"><?= $disqueTendu ?></b><span>disque(s) &lt; 10 Go libres</span></div>
  <div class="k"><b style="color:<?= $horloge ? '#f87171' : 'inherit' ?>"><?= $horloge ?></b><span>horloge(s) décalée(s)</span></div>
  <div class="k"><b style="color:<?= $actKo ? '#f87171' : 'inherit' ?>"><?= $actKo ?></b><span>Windows non activé(s)</span></div>
</div>

<section class="panel">
  <div class="panel-head"><h2>🗃️ Postes inventoriés (<?= $nbP ?>)</h2>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <?php if ($nbP): ?><a class="btn-sm" href="?csv=1">⬇ Exporter (CSV)</a><?php endif; ?>
      <form method="post" style="margin:0" onsubmit="this.querySelector('button').textContent='Déploiement…';this.querySelector('button').disabled=true">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="deploy">
        <button class="btn"><?= $gpoOn ? '🔄 Redéployer le collecteur' : '🚀 Activer l\'inventaire' ?></button>
      </form>
    </div>
  </div>
  <div style="padding:0 1.2rem 1.2rem">
    <p class="lead" style="margin:.7rem 0">Chaque poste du domaine relève lui-même ses caractéristiques
    (matériel, système, disques, logiciels installés) et les transmet à la passerelle
    <strong>à l'ouverture de session</strong> d'un agent, une fois par jour au plus.</p>

    <?php if (!$nbP): ?>
      <p class="dir-help">Aucun poste ne s'est encore signalé.
      <?= $gpoOn ? "Le collecteur est déployé : les fiches apparaîtront à la prochaine ouverture de session des agents."
                 : "Cliquez « Activer l'inventaire » pour déployer le collecteur sur les postes du domaine." ?></p>
    <?php else: ?>
      <table class="grid-table">
        <thead><tr><th>Poste</th><th>Système</th><th>Matériel</th><th style="width:120px">Mémoire / Disque</th>
          <th style="width:120px">Réseau</th><th style="width:110px">Vu le</th><th style="width:90px"></th></tr></thead>
        <tbody>
          <?php foreach ($inv as $r): $pn = strtoupper($r['poste']); ?>
            <tr>
              <td><strong>💻 <?= e($r['poste']) ?></strong>
                <?php if (!isset($adPostes[$pn])): ?><br><span class="badge" title="Ce poste n'est pas (ou plus) dans l'annuaire">hors annuaire</span><?php endif; ?>
                <?php if ($r['utilisateur']): ?><br><span class="muted small">👤 <?= e($r['utilisateur']) ?></span><?php endif; ?></td>
              <td class="small"><?= e($r['os_nom']) ?: '—' ?>
                <?php $sk = (int) $r['os_sku']; if (isset($skuLbl[$sk])): ?>
                  <span class="badge<?= in_array($sk, [4,27,125,121,122], true) ? ' on' : '' ?>"><?= e($skuLbl[$sk]) ?></span>
                <?php endif; ?>
                <br><span class="muted">build <?= e($r['os_build']) ?></span>
                <?php $ac = (string) $r['activation'];
                      // Seuls les états qui appellent une action sont montrés dans la liste ;
                      // « activé » n'a pas besoin d'être répété sur chaque ligne.
                      if (isset($actLbl[$ac]) && in_array($ac, ['echec', 'non-active', 'redemarrage'], true)): ?>
                  <br><span class="badge <?= $actLbl[$ac][0] ?>" title="<?= e($actLbl[$ac][2]) ?>">🔑 <?= e($actLbl[$ac][1]) ?></span>
                <?php endif; ?></td>
              <td class="small"><?= e(trim($r['fabricant'] . ' ' . $r['modele'])) ?: '—' ?>
                <?php $t = (int) $r['type_machine']; if (isset($typeLbl[$t])): ?><span class="badge"><?= $typeLbl[$t] ?></span><?php endif; ?>
                <?php if ($r['serie']): ?><br><span class="muted mono" style="font-size:.72rem">n° <?= e($r['serie']) ?></span><?php endif; ?></td>
              <td class="small"><?= $r['memoire_mo'] ? number_format($r['memoire_mo'] / 1024, 0, ',', ' ') . ' Go' : '—' ?>
                <br><span class="muted"><?= $go($r['libre_go']) ?> libres / <?= $go($r['disque_go']) ?></span>
                <?php if ((int) $r['disque_go'] > 0 && (int) $r['libre_go'] < 10): ?>
                  <br><span class="badge off">disque plein</span><?php endif; ?></td>
              <td class="small mono" style="font-size:.74rem"><?= e($r['ip']) ?: '—' ?>
                <br><span class="muted"><?= e($r['mac']) ?></span></td>
              <td class="small"><?= e(substr((string) $r['vu_le'], 0, 16)) ?>
                <?php if ($r['horloge_ecart'] !== null && abs((int) $r['horloge_ecart']) > 300): ?>
                  <br><span class="badge off" title="Au-delà de 5 minutes d'écart, aucune stratégie ordinateur ne s'applique">⏰ horloge décalée</span>
                <?php endif; ?></td>
              <td class="row-actions">
                <button type="button" class="btn-sm js-fiche" data-p="<?= e($pn) ?>">Fiche</button>
              </td>
            </tr>
            <tr class="fiche-row" id="f-<?= e($pn) ?>" hidden><td colspan="7" style="background:rgba(56,189,248,.05)">
              <div class="fiche" style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem">
                <div>
                  <h4 style="margin:.2rem 0 .6rem">Détail du poste</h4>
                  <dl>
                    <dt>Processeur</dt><dd><?= e($r['processeur']) ?: '—' ?> <?= $r['coeurs'] ? '(' . (int) $r['coeurs'] . ' cœurs)' : '' ?></dd>
                    <dt>Mémoire</dt><dd><?= $r['memoire_mo'] ? number_format($r['memoire_mo'], 0, ',', ' ') . ' Mo' : '—' ?></dd>
                    <dt>Disque</dt><dd><?= e($r['disque_mdl']) ?: '—' ?> — <?= $go($r['disque_go']) ?> (<?= $go($r['libre_go']) ?> libres)</dd>
                    <dt>BIOS</dt><dd><?= e($r['bios']) ?: '—' ?></dd>
                    <dt>Démarrage sécurisé</dt>
                    <dd><?php $sb = (int) $r['secureboot'];
                        echo $sb === 1 ? '<span class="badge on">activé</span>'
                           : ($sb === 0 ? '<span class="badge off">désactivé</span>' : '<span class="muted">inconnu</span>'); ?></dd>
                    <dt>Système installé le</dt><dd><?= $r['os_install'] ? e(date('d/m/Y', strtotime($r['os_install']))) : '—' ?></dd>
                    <dt>Domaine</dt><dd><?= e($r['domaine']) ?: '—' ?></dd>
                    <dt>Écart d'horloge</dt>
                    <dd><?php $ec = $r['horloge_ecart'];
                        if ($ec === null) { echo '<span class="muted">non mesuré</span>'; }
                        else { $a = abs((int) $ec);
                            echo $a > 300 ? '<span class="badge off">' . (int) round($a / 60) . ' min — bloque les stratégies</span>'
                               : ($a > 60 ? '<span class="badge">' . $a . ' s</span>'
                                          : '<span class="badge on">' . $a . ' s</span>'); } ?></dd>
                    <dt>Applications</dt>
                    <dd><?= (int) $r['apps_ok'] ?> installée(s) par Bastion</dd>
                    <dt>Activation Windows</dt>
                    <dd><?php $ac = (string) $r['activation'];
                        if (!isset($actLbl[$ac])) { echo '<span class="muted">non remontée</span>'; }
                        else {
                            echo '<span class="badge ' . $actLbl[$ac][0] . '" title="' . e($actLbl[$ac][2]) . '">'
                               . e($actLbl[$ac][1]) . '</span>';
                            if ($r['activation_det']) { echo '<br><span class="muted small">' . e($r['activation_det']) . '</span>'; }
                        } ?></dd>
                    <dt>Adresse d'origine</dt><dd class="mono" style="font-size:.78rem"><?= e($r['ip_source']) ?: '—' ?>
                      <?php if ($r['ip_source'] && $r['ip'] && $r['ip_source'] !== $r['ip']): ?>
                        <span class="badge" title="L'adresse vue par la passerelle diffère de celle déclarée par le poste">≠ déclarée</span>
                      <?php endif; ?></dd>
                  </dl>
                  <form method="post" style="margin-top:.8rem" onsubmit="return confirm('Retirer la fiche de « <?= e($pn) ?> » ?\n\nElle réapparaîtra si le poste se signale à nouveau.')">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="forget">
                    <input type="hidden" name="poste" value="<?= e($pn) ?>">
                    <button class="btn-sm btn-danger">Retirer la fiche</button>
                  </form>
                </div>
                <div>
                  <?php if (trim((string) $r['apps_log']) !== ''): ?>
                    <h4 style="margin:.2rem 0 .6rem">Journal du déploiement d'applications</h4>
                    <pre class="apps" style="white-space:pre-wrap;font-size:.74rem;margin:0 0 .9rem"><?= e($r['apps_log']) ?></pre>
                  <?php endif; ?>
                  <?php $apps = json_decode((string) $r['logiciels'], true) ?: []; ?>
                  <h4 style="margin:.2rem 0 .6rem">Logiciels installés (<?= count($apps) ?>)</h4>
                  <?php if (!$apps): ?><p class="muted small">Aucun logiciel remonté.</p>
                  <?php else: ?>
                    <div class="apps"><?php foreach ($apps as $a): ?>
                      <div><?= e($a['n'] ?? '') ?> <span class="muted"><?= e($a['v'] ?? '') ?></span></div>
                    <?php endforeach; ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($jamais): ?>
      <h3 style="font-size:.95rem;margin:1.2rem 0 .5rem">Postes de l'annuaire jamais signalés (<?= count($jamais) ?>)</h3>
      <p class="muted small" style="margin-top:0">Ils existent dans l'annuaire mais n'ont pas encore transmis leur fiche :
      aucun agent n'y a ouvert de session depuis le déploiement du collecteur, ou le poste est éteint.</p>
      <p><?php foreach ($jamais as $n): ?><span class="badge" style="margin:.15rem"><?= e($n) ?></span><?php endforeach; ?></p>
    <?php endif; ?>

    <p class="dir-help" style="margin-top:1rem">
      L'inventaire est <strong>déclaratif</strong> : c'est le poste qui décrit son propre matériel. C'est une donnée
      d'exploitation (savoir quoi remplacer, qui manque de mémoire, quel disque sature) — <strong>pas une preuve</strong>.
      L'adresse réellement vue par la passerelle est conservée à côté de l'adresse déclarée pour permettre un recoupement.
    </p>
  </div>
</section>
<script>
document.querySelectorAll('.js-fiche').forEach(function (b) {
  b.addEventListener('click', function () {
    var r = document.getElementById('f-' + b.dataset.p);
    if (r) { r.hidden = !r.hidden; if (!r.hidden) { r.scrollIntoView({ block: 'nearest' }); } }
  });
});
</script>
<?php pf_footer(); ?>
