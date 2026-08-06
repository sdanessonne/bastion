<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Fonctions optionnelles.
 *
 * Activer ou couper ce qui n'est pas indispensable au cœur du service : l'antivirus,
 * la prise de main à distance, l'activation Windows, le Wi-Fi. Le portail captif, le
 * DNS/DHCP, la base et le contrôleur de domaine n'y figurent pas — les couper n'est
 * pas un réglage, c'est une panne.
 *
 * L'état affiché est TOUJOURS relu sur systemd, jamais dans une table. Un indicateur
 * qui annonce « activé » pendant que le service est mort fait chercher la panne
 * ailleurs, et coûte plus cher que pas d'indicateur du tout.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';

// Description de chaque fonction : à quoi elle sert, et surtout CE QUI CESSE DE
// MARCHER si on la coupe. C'est la seule information qui permette de décider.
$FONCTIONS = [
    'antivirus' => [
        'nom'    => 'Antivirus (ClamAV)',
        'icone'  => '🛡️',
        'role'   => 'Analyse les supports amovibles depuis les stations blanches, et distribue '
                  . 'les signatures aux postes.',
        'perte'  => 'Les stations d\'analyse ne peuvent plus rendre de verdict. Les clés USB '
                  . 'apportées de l\'extérieur entrent alors sans contrôle — c\'est la voie '
                  . 'd\'infection la plus banale d\'un réseau fermé.',
        'gain'   => 'C\'est de très loin le premier consommateur de mémoire de la passerelle : '
                  . 'il charge toute sa base de signatures en RAM.',
    ],
    'distance' => [
        'nom'    => 'Prise de main à distance',
        'icone'  => '🖥️',
        'role'   => 'Relais permettant de dépanner l\'écran d\'un poste sans se déplacer.',
        'perte'  => 'Plus aucun dépannage à distance. Les postes gardent leur client installé '
                  . 'mais ne trouvent plus de relais où s\'annoncer.',
        'gain'   => 'Consommation négligeable. En revanche le relais est joignable depuis '
                  . 'Internet : le couper referme cette porte quand personne n\'en a besoin.',
    ],
    'kms' => [
        'nom'    => 'Activation Windows / Office',
        'icone'  => '🔑',
        'role'   => 'Serveur KMS local : les postes du domaine s\'activent seuls, sans clé à '
                  . 'saisir et sans joindre Microsoft.',
        'perte'  => 'Les postes déjà activés le restent pour environ 180 jours, puis repassent '
                  . 'en « non activé ». Ce n\'est donc pas immédiat — et c\'est bien le piège : '
                  . 'la panne apparaît des mois après la décision.',
        'gain'   => 'Consommation négligeable.',
    ],
    'wifi' => [
        'nom'    => 'Point d\'accès Wi-Fi',
        'icone'  => '📶',
        'role'   => 'Publie le réseau sans fil du service, soumis au même portail et au même '
                  . 'filtrage que le réseau filaire.',
        'perte'  => 'Le réseau sans fil disparaît. Les appareils qui n\'ont pas de port réseau '
                  . 'perdent tout accès.',
        'gain'   => 'Consommation négligeable. Réduit la surface exposée si le sans-fil ne sert pas.',
    ],
    'historique' => [
        'nom'    => 'Historique de navigation',
        'icone'  => '📚',
        'role'   => 'Journalise les domaines consultés, par agent et par poste.',
        'perte'  => 'Aucune trace de navigation — donc aucune réponse possible à une réquisition.',
        'gain'   => 'Aucun gain notable.',
        'verrou' => 'Obligation légale de conservation : cette fonction ne se coupe pas depuis '
                  . 'la console. C\'est une des raisons d\'être de la passerelle.',
    ],
];

/** État réel, relu sur systemd à chaque affichage. */
function fonctions_etat(): array
{
    $j = shell_exec('sudo /usr/local/sbin/proxyfibre-fonctions state 2>/dev/null');
    $d = json_decode((string) $j, true);
    if (!is_array($d)) { return []; }
    $out = [];
    foreach ($d as $f) { if (isset($f['nom'])) { $out[$f['nom']] = $f; } }
    return $out;
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!pf_page_autorisee('fonctions.php')) { http_response_code(403); exit('Interdit'); }
    $f  = (string) ($_POST['fonction'] ?? '');
    $op = ($_POST['op'] ?? '') === 'enable' ? 'enable' : 'disable';

    if (!isset($FONCTIONS[$f])) {
        $flash = ['Fonction inconnue.', 'err'];
    } elseif ($op === 'disable' && !empty($FONCTIONS[$f]['verrou'])) {
        $flash = [$FONCTIONS[$f]['verrou'], 'err'];
    } else {
        $out = shell_exec('sudo /usr/local/sbin/proxyfibre-fonctions ' . escapeshellarg($op)
                        . ' ' . escapeshellarg($f) . ' 2>&1');
        // On ne décode que la DERNIÈRE ligne. systemd écrit volontiers quelques lignes
        // d'information avant le résultat (« Created symlink… »), et décoder la sortie
        // entière échouait alors sur une opération pourtant réussie — la console aurait
        // annoncé un échec après avoir bel et bien coupé le service.
        $lignes = array_values(array_filter(array_map('trim', explode("\n", (string) $out)), 'strlen'));
        $r = $lignes ? json_decode(end($lignes), true) : null;
        if (is_array($r) && isset($r['etat'])) {
            audit('fonction.' . $op, $FONCTIONS[$f]['nom'] . ' → ' . $r['etat']);
            $flash = [$FONCTIONS[$f]['nom'] . ' : ' . ($op === 'enable' ? 'activée' : 'désactivée')
                    . ' (état réel : ' . $r['etat'] . ').', 'ok'];
        } else {
            // On rend la sortie du script telle quelle : « échec » sans le motif
            // obligerait à aller lire les journaux du système.
            $flash = ['Échec : ' . trim((string) $out), 'err'];
        }
    }
}

$etats = fonctions_etat();
pf_header('Fonctions');
?>
<?php if ($flash): ?><div class="flash <?= e($flash[1]) ?>"><?= e($flash[0]) ?></div><?php endif; ?>

<div class="panel">
  <div class="panel-head"><h2>🧩 Fonctions optionnelles</h2></div>
  <div style="padding:1rem 1.2rem">
    <p class="muted">Ce qui peut être coupé sans arrêter le service. Le portail captif, le DNS/DHCP, la base de
    données et le contrôleur de domaine n'apparaissent pas ici : les couper n'est pas un réglage, c'est une panne.
    Pour un redémarrage ponctuel, voyez la page <em>Services</em>.</p>
    <?php if (!$etats): ?>
      <p class="flash err">⚠ État des fonctions illisible. Le script <code>proxyfibre-fonctions</code> est peut-être
      absent, ou la console n'a pas le droit de l'appeler. Rien n'est affiché plutôt que d'afficher des états
      inventés.</p>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($FONCTIONS as $cle => $d):
  $e = $etats[$cle] ?? null;
  $etat = $e['etat'] ?? 'inconnu';
  $mo   = (int) ($e['memoire_mo'] ?? 0);
  $verrou = !empty($d['verrou']);
  $badge = ['active' => 'on', 'arretee' => 'off', 'partielle' => 'off', 'absente' => 'off'][$etat] ?? 'off';
  $libelle = ['active' => '✓ Active', 'arretee' => '○ Arrêtée', 'partielle' => '⚠ Partielle',
              'absente' => '✗ Non installée', 'inconnu' => '? État inconnu'][$etat] ?? $etat;
?>
<div class="panel">
  <div class="panel-head"><h2><?= $d['icone'] ?> <?= e($d['nom']) ?></h2></div>
  <div style="padding:1rem 1.2rem">
    <p style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
      <span class="badge <?= $badge ?>"><?= $libelle ?></span>
      <?php if ($mo > 0): ?><span class="badge"><?= $mo ?> Mo de mémoire</span><?php endif; ?>
      <?php if (($e['auto'] ?? 0) > 0 && $etat === 'arretee'): ?>
        <span class="badge off" title="Elle repartira au prochain démarrage">⚠ Redémarre au prochain boot</span>
      <?php endif; ?>
    </p>
    <?php if ($etat === 'partielle'): ?>
      <?php /* L'état le plus trompeur : une partie tourne, donc tout a l'air d'aller. */ ?>
      <p class="flash err">⚠ <?= (int) ($e['actives'] ?? 0) ?> composant sur <?= (int) ($e['unites'] ?? 0) ?>
      seulement tourne. La fonction a l'apparence de marcher et ne marche pas. Réactivez-la, ou consultez
      la page <em>Services</em> pour voir lequel est tombé.</p>
    <?php endif; ?>

    <p><?= e($d['role']) ?></p>
    <p class="muted small"><strong>Si vous la coupez :</strong> <?= e($d['perte']) ?></p>
    <p class="muted small"><strong>Ce que ça libère :</strong> <?= e($d['gain']) ?></p>

    <?php if ($verrou): ?>
      <p class="tip">🔒 <?= e($d['verrou']) ?></p>
    <?php elseif ($etat !== 'absente' && $etat !== 'inconnu'): ?>
      <form method="post" style="margin-top:.6rem"
            onsubmit="return <?= $etat === 'active' ? "confirm('Couper « " . e($d['nom']) . " » ? " . e(str_replace("'", ' ', (string) $d['perte'])) . "')" : 'true' ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="fonction" value="<?= e($cle) ?>">
        <input type="hidden" name="op" value="<?= $etat === 'active' ? 'disable' : 'enable' ?>">
        <button class="btn-sm"><?= $etat === 'active' ? '⏻ Désactiver' : '▶ Activer' ?></button>
        <span class="muted small">L'effet est immédiat et survit au redémarrage.</span>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php pf_footer(); ?>
