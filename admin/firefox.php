<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — réglages Firefox déployés par stratégie de groupe.
 *
 * ── POURQUOI UNE PAGE ET PAS L'ÉDITEUR WINDOWS ───────────────────────────────
 * Les modèles ADMX installés dans le SYSVOL exposent 407 réglages. C'est
 * complet, et inutilisable pour qui cherche simplement à imposer la page
 * d'accueil du service et à couper la télémétrie : il faut connaître l'arbre,
 * ouvrir une console Windows, et savoir lequel des 407 compte.
 *
 * Cette page retient CE QUI COMPTE POUR UN COMMISSARIAT, avec l'explication de
 * ce que chaque réglage change. Les 407 autres restent accessibles dans
 * l'éditeur Windows pour les cas particuliers.
 *
 * ── LE RÉGLAGE LE PLUS IMPORTANT EST LE MOINS ÉVIDENT ────────────────────────
 * « DNS over HTTPS ». Firefox l'active de lui-même dans certaines régions, et il
 * fait alors ses résolutions par un serveur distant, en HTTPS, hors de la vue de
 * la passerelle. Tout le filtrage de contenu de Bastion repose sur le DNS local :
 * un Firefox en DoH y échappe INTÉGRALEMENT, sans que rien ne le signale — la
 * console continue d'afficher un filtrage actif et des listes à jour, pendant que
 * le navigateur les contourne.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';
require_once __DIR__ . '/inc/adcache.php';

const FF_GPO = 'Bastion — Firefox (réglages du service)';
const FF_KEY = 'SOFTWARE\\Policies\\Mozilla\\Firefox';

/**
 * Réglages proposés.
 *
 * « reg » décrit ce qui est écrit QUAND la case est cochée. Une case décochée
 * n'écrit rien : Firefox reprend alors son comportement par défaut, et
 * l'utilisateur retrouve la main. C'est délibéré — écrire « 0 » verrouillerait
 * le réglage dans l'autre sens, ce qui n'est pas la même chose que « ne pas
 * imposer ».
 */
$REGLAGES = [
    'doh' => [
        'titre'  => 'Désactiver DNS over HTTPS',
        'quoi'   => "Firefox résout les noms par un serveur distant en HTTPS, hors de vue de la passerelle. "
                  . "Tout le filtrage de Bastion repose sur le DNS local : sans ce réglage, le navigateur le "
                  . "contourne intégralement — et la console continue d'afficher un filtrage actif.",
        'defaut' => true, 'poids' => 'essentiel',
        'reg'    => [
            [FF_KEY . '\\DNSOverHTTPS', 'Enabled', 'REG_DWORD', 0],
            [FF_KEY . '\\DNSOverHTTPS', 'Locked',  'REG_DWORD', 1],
        ],
    ],
    'cert' => [
        'titre'  => "Faire confiance à l'autorité Bastion",
        'quoi'   => "Firefox utilise son propre magasin de certificats et ignore celui de Windows. Sans ce "
                  . "réglage, le portail et la console affichent un avertissement de sécurité à chaque visite — "
                  . "et les agents prennent l'habitude de passer outre les avertissements.",
        'defaut' => true, 'poids' => 'essentiel',
        'reg'    => [[FF_KEY . '\\Certificates', 'ImportEnterpriseRoots', 'REG_DWORD', 1]],
    ],
    'prive' => [
        'titre'  => 'Interdire la navigation privée',
        'quoi'   => "La navigation privée n'échappe pas à la journalisation de la passerelle — celle-ci voit le "
                  . "trafic quoi qu'il arrive. Elle efface en revanche l'historique local, ce qui complique une "
                  . "vérification sur poste.",
        'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisablePrivateBrowsing', 'REG_DWORD', 1]],
    ],
    'telemetrie' => [
        'titre'  => 'Couper la télémétrie',
        'quoi'   => "Firefox transmet des données d'usage à Mozilla. Sur un poste de commissariat, ces envois "
                  . "sortent du périmètre sans nécessité.",
        'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [
            [FF_KEY, 'DisableTelemetry', 'REG_DWORD', 1],
            [FF_KEY, 'DisablePocket',    'REG_DWORD', 1],
        ],
    ],
    'compte' => [
        'titre'  => 'Désactiver le compte Firefox et la synchronisation',
        'quoi'   => "La synchronisation copie l'historique, les marque-pages et les mots de passe vers les "
                  . "serveurs de Mozilla, sous un compte personnel. Les données du service partiraient sur un "
                  . "compte privé.",
        'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisableFirefoxAccounts', 'REG_DWORD', 1]],
    ],
    'motsdepasse' => [
        'titre'  => 'Ne pas proposer d\'enregistrer les mots de passe',
        'quoi'   => "Sur un poste partagé, un mot de passe enregistré par un agent reste disponible au suivant. "
                  . "Firefox les protège mal en l'absence de mot de passe principal.",
        'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'OfferToSaveLogins', 'REG_DWORD', 0]],
    ],
    'extensions' => [
        'titre'  => "Interdire l'installation d'extensions",
        'quoi'   => "Une extension voit TOUT ce que voit le navigateur : contenu des pages, formulaires, mots de "
                  . "passe saisis. Sur des postes qui consultent des fichiers de procédure, c'est le point "
                  . "d'entrée le plus simple.",
        'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY . '\\InstallAddonsPermission', 'Default', 'REG_DWORD', 0]],
    ],
    'maj' => [
        'titre'  => 'Laisser Firefox se mettre à jour',
        'quoi'   => "Un navigateur non à jour est la faille la plus exploitée. Décochez seulement si un "
                  . "logiciel métier impose une version figée — et sachez alors ce que vous acceptez.",
        'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisableAppUpdate', 'REG_DWORD', 0]],
    ],
];

// ── Réglages enregistrés ────────────────────────────────────────────────────
$db = pf_db();
$actuel = [];
try {
    $v = (string) ($db->query("SELECT v FROM pf_settings WHERE k='firefox_gpo'")->fetchColumn() ?: '');
    $actuel = json_decode($v, true) ?: [];
} catch (Throwable $e) {}

$coche   = $actuel['coche']   ?? array_keys(array_filter($REGLAGES, fn($r) => $r['defaut']));
$accueil = (string) ($actuel['accueil'] ?? '');
$deploye = (string) ($actuel['deploye'] ?? '');

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'deployer') {
    csrf_check();
    $coche   = array_values(array_intersect(array_keys($REGLAGES), (array) ($_POST['r'] ?? [])));
    $accueil = trim((string) ($_POST['accueil'] ?? ''));

    // L'adresse est validée ici : elle finit dans une clé de registre appliquée à
    // tous les postes. Une saisie fantaisiste ne casserait rien, mais donnerait
    // une page d'accueil introuvable sur tout le parc, sans message.
    if ($accueil !== '' && !preg_match('~^https?://[A-Za-z0-9._~:/?#\[\]@!$&\'()*+,;=%-]+$~', $accueil)) {
        $flash = ["Adresse d'accueil invalide : elle doit commencer par http:// ou https://", 'err'];
    } else {
        $pol = [];
        foreach ($coche as $k) {
            foreach ($REGLAGES[$k]['reg'] as [$key, $val, $type, $data]) {
                $pol[] = ['keyname' => $key, 'valuename' => $val, 'class' => 'MACHINE',
                          'type' => $type, 'data' => $data];
            }
        }
        if ($accueil !== '') {
            // « StartPage » sans « URL » ouvrirait une page blanche : les deux vont
            // ensemble, et « Locked » évite qu'un agent la remplace au premier clic.
            $pol[] = ['keyname' => FF_KEY . '\\Homepage', 'valuename' => 'URL',
                      'class' => 'MACHINE', 'type' => 'REG_SZ', 'data' => $accueil];
            $pol[] = ['keyname' => FF_KEY . '\\Homepage', 'valuename' => 'StartPage',
                      'class' => 'MACHINE', 'type' => 'REG_SZ', 'data' => 'homepage'];
            $pol[] = ['keyname' => FF_KEY . '\\Homepage', 'valuename' => 'Locked',
                      'class' => 'MACHINE', 'type' => 'REG_DWORD', 'data' => 1];
        }

        if (!$pol) {
            $flash = ['Aucun réglage sélectionné — rien à déployer.', 'err'];
        } else {
            $tmp = tempnam(sys_get_temp_dir(), 'ffgpo');
            file_put_contents($tmp, json_encode($pol, JSON_UNESCAPED_UNICODE));
            $out = ad('gpo', 'deploy', FF_GPO, $tmp);
            @unlink($tmp);
            // On ne se déclare pas satisfait sur la seule absence d'exception :
            // le script rend « ERROR: … » sur sa sortie en cas d'échec.
            $ok = stripos($out, 'ERROR') === false;
            if ($ok) {
                try {
                    $db->prepare("INSERT INTO pf_settings (k,v) VALUES ('firefox_gpo',?)
                                  ON DUPLICATE KEY UPDATE v=VALUES(v)")
                       ->execute([json_encode(['coche' => $coche, 'accueil' => $accueil,
                                               'deploye' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE)]);
                } catch (Throwable $e) {}
                $deploye = date('Y-m-d H:i:s');
                ad_cache_clear();
            }
            audit('gpo.firefox', ($ok ? 'déployée — ' : 'ÉCHEC — ') . count($coche) . ' réglage(s)'
                . ($accueil !== '' ? ', accueil ' . $accueil : ''));
            $flash = [$ok
                ? count($pol) . " paramètre(s) déployés. Effet au prochain redémarrage des postes, "
                  . "ou immédiatement avec « gpupdate /force »."
                : trim($out), $ok ? 'ok' : 'err'];
        }
    }
}

// Les modèles ADMX ne sont pas nécessaires à CE déploiement — il écrit
// directement le registre. Ils le sont pour relire ces réglages depuis l'éditeur
// Windows : sans eux, l'administrateur y verrait des clés brutes sans libellé.
$admx = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-admx list 2>/dev/null'), true) ?: [];

pf_header('Firefox', 'firefox.php');
?>
<style>
  .ff-r{display:flex;gap:.85rem;align-items:flex-start;padding:.9rem 1rem;border:1px solid var(--line);
        border-radius:11px;background:var(--bg);margin-bottom:.7rem}
  .ff-r input{margin-top:.25rem;width:auto;flex:none}
  .ff-r .t{font-weight:600}
  .ff-r .d{color:var(--muted);font-size:.84rem;line-height:1.6;margin-top:.25rem;max-width:78ch}
  .ff-ess{border-color:rgba(248,113,113,.45);background:rgba(248,113,113,.06)}
  .ff-tag{font-size:.68rem;text-transform:uppercase;letter-spacing:.6px;padding:.1rem .45rem;
          border-radius:20px;margin-left:.5rem;vertical-align:middle}
  .ff-tag.e{background:rgba(248,113,113,.2);color:#f87171}
</style>

<?php if ($flash): ?>
  <div class="<?= $flash[1] === 'ok' ? 'ok' : 'err' ?>" style="margin-bottom:1rem"><?= e($flash[0]) ?></div>
<?php endif; ?>

<section class="panel">
  <div class="panel-head"><h2>🦊 Réglages Firefox du service</h2>
    <span class="muted small"><?= $deploye !== ''
      ? 'déployés le ' . e(date('d/m/Y à H:i', strtotime($deploye)))
      : 'jamais déployés' ?></span></div>
  <div style="padding:1.2rem">
    <p class="muted small" style="margin-top:0;max-width:80ch;line-height:1.7">
      Ces réglages sont appliqués à <strong>tous les postes du domaine</strong> par stratégie de groupe.
      Un réglage décoché n'est pas « interdit » : il n'est simplement pas imposé, et Firefox reprend son
      comportement par défaut.
      <?php if (empty($admx['firefox'])): ?>
        <br><strong>Note :</strong> les modèles ADMX ne sont pas installés dans le SYSVOL. Le déploiement
        fonctionne quand même — il écrit directement le registre — mais ces réglages apparaîtront sans
        libellé dans l'éditeur de stratégies Windows.
      <?php endif; ?>
    </p>

    <form method="post" style="margin-top:1.1rem">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="deployer">

      <?php foreach ($REGLAGES as $k => $r): $ess = $r['poids'] === 'essentiel'; ?>
        <label class="ff-r<?= $ess ? ' ff-ess' : '' ?>">
          <input type="checkbox" name="r[]" value="<?= e($k) ?>" <?= in_array($k, $coche, true) ? 'checked' : '' ?>>
          <span>
            <span class="t"><?= e($r['titre']) ?><?php if ($ess): ?><span class="ff-tag e">essentiel</span><?php endif; ?></span>
            <span class="d"><?= e($r['quoi']) ?></span>
          </span>
        </label>
      <?php endforeach; ?>

      <label class="field" style="margin:1rem 0 0;max-width:34rem">Page d'accueil imposée
        <input type="url" name="accueil" value="<?= e($accueil) ?>"
               placeholder="https://192.168.182.1:2443/portal/intranet.php">
        <span class="hint">Laisser vide pour ne rien imposer. L'intranet Bastion est un choix naturel.</span>
      </label>

      <div class="form-actions" style="margin-top:1.2rem">
        <button class="btn">Déployer sur le domaine</button>
        <span class="muted small">Crée ou met à jour la stratégie « <?= e(FF_GPO) ?> », liée à la racine du domaine.</span>
      </div>
    </form>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>📚 Modèles d'administration</h2></div>
  <div style="padding:1.2rem">
    <?php if (!empty($admx['firefox'])): ?>
      <p style="margin:0"><span class="badge on">installés</span>
        <?= (int) ($admx['admx'] ?? 0) ?> modèle(s) et <?= (int) ($admx['adml'] ?? 0) ?> traduction(s)
        dans le magasin central du SYSVOL.</p>
      <p class="muted small" style="margin:.6rem 0 0;max-width:78ch;line-height:1.65">
        Les 407 réglages Firefox sont donc visibles depuis n'importe quel poste d'administration, sous
        <em>Modèles d'administration → Mozilla → Firefox</em>. Cette page ne couvre que les plus utiles ;
        l'éditeur Windows reste là pour les cas particuliers.
      </p>
    <?php else: ?>
      <p style="margin:0"><span class="badge off">absents</span> Le magasin central du SYSVOL ne contient pas
        les modèles Firefox.</p>
      <p class="muted small" style="margin:.6rem 0 0">Pour les installer, sur le serveur :
        <code>sudo proxyfibre-admx firefox</code></p>
    <?php endif; ?>
  </div>
</section>
<?php pf_footer(); ?>
