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
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'essentiel',
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
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'essentiel',
        'reg'    => [[FF_KEY . '\\Certificates', 'ImportEnterpriseRoots', 'REG_DWORD', 1]],
    ],
    'prive' => [
        'titre'  => 'Interdire la navigation privée',
        'quoi'   => "La navigation privée n'échappe pas à la journalisation de la passerelle — celle-ci voit le "
                  . "trafic quoi qu'il arrive. Elle efface en revanche l'historique local, ce qui complique une "
                  . "vérification sur poste.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisablePrivateBrowsing', 'REG_DWORD', 1]],
    ],
    'telemetrie' => [
        'titre'  => 'Couper la télémétrie',
        'quoi'   => "Firefox transmet des données d'usage à Mozilla. Sur un poste de commissariat, ces envois "
                  . "sortent du périmètre sans nécessité.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        // Pocket était posé ici : ce n'est pas de la télémétrie, et l'administrateur qui
        // décochait « télémétrie » réactivait Pocket sans le vouloir. C'est désormais un
        // réglage à part entière.
        'reg'    => [[FF_KEY, 'DisableTelemetry', 'REG_DWORD', 1]],
    ],
    'compte' => [
        'titre'  => 'Désactiver le compte Firefox et la synchronisation',
        'quoi'   => "La synchronisation copie l'historique, les marque-pages et les mots de passe vers les "
                  . "serveurs de Mozilla, sous un compte personnel. Les données du service partiraient sur un "
                  . "compte privé.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisableFirefoxAccounts', 'REG_DWORD', 1]],
    ],
    'motsdepasse' => [
        'titre'  => 'Ne pas proposer d\'enregistrer les mots de passe',
        'quoi'   => "Sur un poste partagé, un mot de passe enregistré par un agent reste disponible au suivant. "
                  . "Firefox les protège mal en l'absence de mot de passe principal.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'OfferToSaveLogins', 'REG_DWORD', 0]],
    ],
    'extensions' => [
        'titre'  => "Interdire l'installation d'extensions",
        'quoi'   => "Une extension voit TOUT ce que voit le navigateur : contenu des pages, formulaires, mots de "
                  . "passe saisis. Sur des postes qui consultent des fichiers de procédure, c'est le point "
                  . "d'entrée le plus simple.",
        'cat' => 'Verrouillage du navigateur', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY . '\\InstallAddonsPermission', 'Default', 'REG_DWORD', 0]],
    ],
    'maj' => [
        'titre'  => 'Laisser Firefox se mettre à jour',
        'quoi'   => "Un navigateur non à jour est la faille la plus exploitée. Décochez seulement si un "
                  . "logiciel métier impose une version figée — et sachez alors ce que vous acceptez.",
        'cat' => 'Verrouillage du navigateur', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisableAppUpdate', 'REG_DWORD', 0]],
    ],

    // ── Filtrage & vie privée ────────────────────────────────────────────────
    'etudes' => [
        'titre'  => 'Refuser les études Mozilla',
        'quoi'   => "Firefox accepte par défaut que Mozilla lui pousse du code expérimental à distance, sans "
                  . "que l'administrateur en soit informé. Sur un poste de service, du code non prévu qui "
                  . "arrive tout seul n'est pas acceptable.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisableFirefoxStudies', 'REG_DWORD', 1]],
    ],
    'pocket' => [
        'titre'  => 'Désactiver Pocket',
        'quoi'   => "Pocket enregistre les articles mis de côté chez un prestataire tiers. Ce qu'un agent "
                  . "consulte dans le cadre du service n'a rien à y faire.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisablePocket', 'REG_DWORD', 1]],
    ],
    'formulaires' => [
        'titre'  => "Ne pas mémoriser les saisies de formulaire",
        'quoi'   => "Firefox complète les champs avec ce qui a été tapé avant — noms, numéros, références "
                  . "d'affaire. Sur un poste partagé, l'agent suivant voit apparaître les saisies du précédent.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisableFormHistory', 'REG_DWORD', 1]],
    ],
    'reveler' => [
        'titre'  => "Interdire l'affichage en clair des mots de passe enregistrés",
        'quoi'   => "Le gestionnaire de Firefox révèle un mot de passe enregistré d'un simple clic. Sur un "
                  . "poste laissé déverrouillé quelques minutes, cela suffit.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisablePasswordReveal', 'REG_DWORD', 1]],
    ],
    'suggestions' => [
        'titre'  => 'Supprimer les suggestions et nouveautés Mozilla',
        'quoi'   => "Bandeaux « nouveautés », recommandations d'extensions, écrans de bienvenue après chaque "
                  . "mise à jour : autant de sollicitations commerciales sur un poste de service, et autant "
                  . "d'occasions de cliquer sur autre chose que son travail.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [
            [FF_KEY . '\\UserMessaging', 'WhatsNew',                 'REG_DWORD', 0],
            [FF_KEY . '\\UserMessaging', 'ExtensionRecommendations', 'REG_DWORD', 0],
            [FF_KEY . '\\UserMessaging', 'FeatureRecommendations',   'REG_DWORD', 0],
            [FF_KEY . '\\UserMessaging', 'MoreFromMozilla',          'REG_DWORD', 0],
            [FF_KEY . '\\UserMessaging', 'SkipOnboarding',           'REG_DWORD', 1],
        ],
    ],

    // ── Verrouillage du navigateur ───────────────────────────────────────────
    'aboutconfig' => [
        'titre'  => 'Bloquer about:config',
        'quoi'   => "C'est la page qui donne accès à toutes les préférences internes. Les réglages posés par "
                  . "stratégie y résistent, mais tout le reste s'y défait — proxy, sécurité TLS, téléchargements.",
        'cat' => 'Verrouillage du navigateur', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'BlockAboutConfig', 'REG_DWORD', 1]],
    ],
    'moderesolu' => [
        'titre'  => 'Interdire le mode sans échec',
        'quoi'   => "Le mode sans échec démarre Firefox sans les extensions ni les personnalisations. C'est le "
                  . "premier réflexe de qui cherche à retrouver un navigateur « comme avant ».",
        'cat' => 'Verrouillage du navigateur', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisableSafeMode', 'REG_DWORD', 1]],
    ],
    'profil' => [
        'titre'  => "Interdire l'import et la réinitialisation du profil",
        'quoi'   => "Réinitialiser le profil recrée un Firefox neuf ; importer celui d'un autre navigateur y "
                  . "verse des marque-pages et des mots de passe venus d'ailleurs. Les deux contournent la "
                  . "configuration du service.",
        'cat' => 'Verrouillage du navigateur', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [
            [FF_KEY, 'DisableProfileImport',  'REG_DWORD', 1],
            [FF_KEY, 'DisableProfileRefresh', 'REG_DWORD', 1],
        ],
    ],
    'devtools' => [
        'titre'  => 'Désactiver les outils de développement',
        'quoi'   => "Ils permettent de lire et de modifier n'importe quelle page, y compris les formulaires de "
                  . "la console. Utile à un informaticien, sans usage pour un agent — mais laissez-les si vous "
                  . "dépannez vous-même l'intranet depuis un poste du domaine.",
        'cat' => 'Verrouillage du navigateur', 'defaut' => false, 'poids' => 'facultatif',
        'reg'    => [[FF_KEY, 'DisableDeveloperTools', 'REG_DWORD', 1]],
    ],

    // ── Présentation ─────────────────────────────────────────────────────────
    'pagesmozilla' => [
        'titre'  => 'Supprimer les pages et marque-pages Mozilla',
        'quoi'   => "À la première ouverture et après chaque mise à jour, Firefox affiche ses propres pages et "
                  . "installe ses marque-pages. Sur un parc, cela revient à accueillir chaque agent par une "
                  . "publicité plutôt que par l'intranet.",
        'cat' => 'Présentation', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [
            [FF_KEY, 'OverrideFirstRunPage',   'REG_SZ', 'about:blank'],
            [FF_KEY, 'OverridePostUpdatePage', 'REG_SZ', 'about:blank'],
            [FF_KEY, 'NoDefaultBookmarks',     'REG_DWORD', 1],
        ],
    ],
    'langue' => [
        'titre'  => "Imposer l'interface en français",
        'quoi'   => "Sans cela, la langue suit celle de l'installation, qui varie d'un poste à l'autre selon "
                  . "l'image utilisée. Une consigne écrite ne correspond alors plus à ce que l'agent voit.",
        'cat' => 'Présentation', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'RequestedLocales', 'REG_SZ', 'fr-FR']],
    ],
    'navigateurdefaut' => [
        'titre'  => 'Ne plus demander « navigateur par défaut »',
        'quoi'   => "La question revient à chaque démarrage tant qu'on n'y répond pas, et la réponse ne regarde "
                  . "pas l'agent : c'est un choix de parc.",
        'cat' => 'Présentation', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DontCheckDefaultBrowser', 'REG_DWORD', 1]],
    ],
    'boutonaccueil' => [
        'titre'  => 'Afficher le bouton Accueil',
        'quoi'   => "Avec une page d'accueil imposée, le bouton y ramène l'agent en un clic. Sans lui, elle "
                  . "n'est atteignable qu'en ouvrant un nouvel onglet.",
        'cat' => 'Présentation', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'ShowHomeButton', 'REG_DWORD', 1]],
    ],
    'barremarquepages' => [
        'titre'  => 'Toujours afficher la barre de marque-pages',
        'quoi'   => "Pratique si vous publiez des raccourcis vers les applications métier. Affaire de goût : "
                  . "la barre occupe une ligne d'écran.",
        'cat' => 'Présentation', 'defaut' => false, 'poids' => 'facultatif',
        'reg'    => [[FF_KEY, 'DisplayBookmarksToolbar', 'REG_SZ', 'always']],
    ],


    // ── Deuxième série ───────────────────────────────────────────────────────
    'suggestionsbarre' => [
        'titre'  => 'Ne rien envoyer au moteur de recherche pendant la frappe',
        'quoi'   => "Par défaut, Firefox transmet au moteur de recherche CE QUI EST TAPÉ dans la barre "
                  . "d'adresse, lettre après lettre, pour proposer des suggestions — avant même d'avoir "
                  . "validé, et même si l'on se ravise. Un nom, une plaque, une adresse commencés puis "
                  . "effacés sont malgré tout partis.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'essentiel',
        'reg'    => [[FF_KEY, 'SearchSuggestEnabled', 'REG_DWORD', 0]],
    ],
    'cookiespisteurs' => [
        'titre'  => 'Rejeter les cookies de pistage',
        'quoi'   => "Bloque les cookies déposés par les régies présentes sur les pages consultées, sans "
                  . "toucher à ceux des sites eux-mêmes — les applications métier continuent de "
                  . "fonctionner.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY . '\\Cookies', 'Behavior', 'REG_SZ', 'reject-tracker']],
    ],
    'remplissage' => [
        'titre'  => 'Pas de remplissage automatique des cartes et adresses',
        'quoi'   => "Firefox propose d'enregistrer coordonnées bancaires et adresses postales pour les "
                  . "réinjecter ensuite. Sur un poste partagé, c'est l'agent suivant qui se les voit "
                  . "proposer.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [
            [FF_KEY, 'AutofillCreditCardEnabled', 'REG_DWORD', 0],
            [FF_KEY, 'AutofillAddressEnabled',    'REG_DWORD', 0],
        ],
    ],
    'prediction' => [
        'titre'  => 'Désactiver les connexions anticipées',
        'quoi'   => "Firefox résout et contacte à l'avance des adresses que l'agent n'a pas encore "
                  . "demandées, en devinant la suite de sa frappe. Ces contacts figurent dans le journal "
                  . "de navigation sans qu'aucune page n'ait été ouverte — ce qui brouille une "
                  . "vérification a posteriori.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'NetworkPrediction', 'REG_DWORD', 0]],
    ],
    'agentdefaut' => [
        'titre'  => "Supprimer le service de fond « navigateur par défaut »",
        'quoi'   => "Une tâche planifiée tourne en dehors de Firefox pour vérifier s'il est le navigateur "
                  . "par défaut, et transmet le résultat à Mozilla. Elle continue même navigateur fermé.",
        'cat' => 'Filtrage & vie privée', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisableDefaultBrowserAgent', 'REG_DWORD', 1]],
    ],

    'aboutprofils' => [
        'titre'  => 'Bloquer about:profiles et about:support',
        'quoi'   => "La première permet de créer et de basculer entre des profils Firefox, la seconde "
                  . "expose la configuration complète du poste et ses chemins de fichiers. Complément "
                  . "naturel du blocage d'about:config.",
        'cat' => 'Verrouillage du navigateur', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [
            [FF_KEY, 'BlockAboutProfiles', 'REG_DWORD', 1],
            [FF_KEY, 'BlockAboutSupport',  'REG_DWORD', 1],
        ],
    ],
    'tls' => [
        'titre'  => 'Refuser les vieilles versions de TLS',
        'quoi'   => "Impose TLS 1.2 au minimum. Les versions antérieures sont cassées depuis des années ; "
                  . "les refuser évite qu'un site mal configuré fasse retomber la connexion dessus sans "
                  . "que personne ne s'en aperçoive.",
        'cat' => 'Verrouillage du navigateur', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'SSLVersionMin', 'REG_SZ', 'tls1.2']],
    ],
    'fondecran' => [
        'titre'  => "Interdire « définir comme fond d'écran »",
        'quoi'   => "Firefox propose ce menu sur n'importe quelle image d'une page. Sur un parc où le fond "
                  . "d'écran est imposé par stratégie, c'est le seul moyen simple de le contourner.",
        'cat' => 'Verrouillage du navigateur', 'defaut' => true, 'poids' => 'recommandé',
        'reg'    => [[FF_KEY, 'DisableSetDesktopBackground', 'REG_DWORD', 1]],
    ],

    'telechargement' => [
        'titre'  => 'Demander où enregistrer chaque téléchargement',
        'quoi'   => "Sans cela tout atterrit dans « Téléchargements » sans que l'agent y pense, et s'y "
                  . "accumule sur un poste partagé. Demander le dossier ralentit un peu, mais rend le "
                  . "geste conscient.",
        'cat' => 'Présentation', 'defaut' => false, 'poids' => 'facultatif',
        'reg'    => [[FF_KEY, 'PromptForDownloadLocation', 'REG_DWORD', 1]],
    ],
    'capture' => [
        'titre'  => "Désactiver l'outil de capture d'écran de Firefox",
        'quoi'   => "Firefox sait capturer une page entière en un clic droit. À décocher si vos agents s'en "
                  . "servent pour leurs comptes rendus — l'outil de Windows reste disponible dans tous les cas.",
        'cat' => 'Présentation', 'defaut' => false, 'poids' => 'facultatif',
        'reg'    => [[FF_KEY, 'DisableFirefoxScreenshots', 'REG_DWORD', 1]],
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
  .ff-tag:not(.e):not(.n){background:rgba(148,163,184,.16);color:var(--muted)}
  .ff-tag.n{background:rgba(56,189,248,.18);color:#7dd3fc}
  .ff-cat{font-size:.82rem;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);
          margin:1.5rem 0 .6rem;padding-bottom:.35rem;border-bottom:1px solid var(--line)}
  .ff-cat:first-of-type{margin-top:.2rem}
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

      <?php
      // Groupé par thème : la liste est passée de huit à vingt-deux réglages, et une
      // colonne de vingt-deux cases à cocher ne se lit plus — on ne sait plus ce qu'on
      // a déjà examiné. L'ordre des catégories suit celui du tableau.
      $parCat = [];
      foreach ($REGLAGES as $k => $r) { $parCat[$r['cat'] ?? 'Autres'][$k] = $r; }

      // Réglages recommandés qui ne sont PAS dans le déploiement en cours. Après une
      // mise à jour de Bastion, les nouveaux arrivent décochés — c'est voulu, rien ne
      // doit changer sur le domaine sans décision. Mais noyés parmi une vingtaine de
      // cases, ils passeraient inaperçus : on les compte et on les marque.
      $nouveaux = $deploye !== ''
          ? array_keys(array_filter($REGLAGES, fn($r, $k) => $r['defaut'] && !in_array($k, $coche, true),
                                    ARRAY_FILTER_USE_BOTH))
          : [];
      ?>
      <?php if ($nouveaux): ?>
        <p class="flash" style="background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.35);
           border-radius:10px;padding:.7rem .9rem;font-size:.87rem;margin:0 0 1rem">
          <strong><?= count($nouveaux) ?> réglage<?= count($nouveaux) > 1 ? 's' : '' ?> recommandé<?= count($nouveaux) > 1 ? 's' : '' ?></strong>
          ne <?= count($nouveaux) > 1 ? 'sont' : 'est' ?> pas dans le déploiement actuel — repérable<?= count($nouveaux) > 1 ? 's' : '' ?>
          ci-dessous par l’étiquette « non déployé ». Cochez ce que vous voulez appliquer, puis redéployez.
        </p>
      <?php endif; ?>
      <?php foreach ($parCat as $cat => $items): ?>
        <h3 class="ff-cat"><?= e($cat) ?>
          <span class="muted small">(<?= count($items) ?>)</span></h3>
        <?php foreach ($items as $k => $r): $ess = $r['poids'] === 'essentiel'; ?>
          <label class="ff-r<?= $ess ? ' ff-ess' : '' ?>">
            <input type="checkbox" name="r[]" value="<?= e($k) ?>" <?= in_array($k, $coche, true) ? 'checked' : '' ?>>
            <span>
              <span class="t"><?= e($r['titre']) ?>
                <?php if ($ess): ?><span class="ff-tag e">essentiel</span>
                <?php elseif (($r['poids'] ?? '') === 'facultatif'): ?><span class="ff-tag">facultatif</span>
                <?php endif; ?>
                <?php if (in_array($k, $nouveaux, true)): ?><span class="ff-tag n">non déployé</span><?php endif; ?></span>
              <span class="d"><?= e($r['quoi']) ?></span>
            </span>
          </label>
        <?php endforeach; ?>
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
