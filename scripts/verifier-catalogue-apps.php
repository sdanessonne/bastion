<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Vérifie que chaque entrée du catalogue du store rend RÉELLEMENT un installeur Windows.
 *
 * Pourquoi ce script : le store se contentait de « curl a rendu 0 et le fichier fait plus
 * de 10 Ko ». Une page d'erreur HTML coche ces deux cases. Résultat, 25 entrées pointant
 * sur une page GitHub étaient enregistrées comme des installeurs sans que rien ne le
 * signale, et l'échec n'apparaissait que sur les postes, des semaines plus tard.
 *
 * On ne télécharge pas l'installeur entier : on demande les premiers octets et on lit la
 * signature (OLE pour un MSI, « MZ » pour un exécutable). Rapide, et ça prouve la seule
 * chose qui compte — que l'adresse rend un binaire, pas une page web.
 *
 * Usage :  php scripts/verifier-catalogue-apps.php [motif]
 *          (« motif » limite aux clés contenant ce texte, pour un essai rapide)
 */

require_once __DIR__ . '/../admin/inc/app-source.php';
$CATALOG = require __DIR__ . '/../admin/inc/app-catalog.php';

$filtre = $argv[1] ?? '';
$ok = $ko = $avert = $manuel = 0;
$echecs = [];

foreach ($CATALOG as $cle => $c) {
    if ($filtre !== '' && strpos($cle, $filtre) === false) {
        continue;
    }
    // Une entrée déclarée « manuelle » n'est pas une panne : c'est une source qui ne
    // publie pas d'installeur récupérable, et le store le dit à l'administrateur au lieu
    // de lui offrir un bouton qui échoue. Elle ne doit donc pas compter comme un échec —
    // sinon la vérification serait rouge en permanence et on cesserait de la regarder.
    if (!empty($c['manuel'])) {
        printf("  MANUEL  %-14s %s\n", $cle, $c['manuel']);
        $manuel++;
        continue;
    }
    $src = app_src_resoudre($c);
    if (isset($src['err'])) {
        printf("  ECHEC   %-14s %s\n", $cle, $src['err']);
        $echecs[$cle] = $src['err'];
        $ko++;
        continue;
    }

    // Premiers octets seulement. Certains hébergeurs ignorent l'en-tête « Range » et
    // envoient tout : « --max-filesize » borne alors les dégâts, et un dépassement n'est
    // pas un échec de l'entrée — on le distingue explicitement.
    $tmp = sys_get_temp_dir() . '/bastion-cat-' . $cle;
    // Même chemin que le store : l'URL passe par un fichier d'options, jamais par le
    // shell. C'est ce qui permet de vérifier depuis Windows des adresses contenant « % »
    // (SourceForge encode les espaces des noms de dossiers).
    $opt = app_src_opts($src['url'], true, '40');
    $opt[] = ['output', $tmp];
    $opt[] = ['range', '0-2047'];
    $r = app_src_curl($opt);
    $info = $r['sortie'];
    $probleme = $r['rc'] === 0 ? app_src_verifier($tmp, (bool) $c['msi'])
                               : ($info ?: 'curl code ' . $r['rc']);

    // Repli : tous les hébergeurs n'honorent pas « Range ». SourceForge, par exemple, rend
    // sa page de choix de miroir au lieu des premiers octets — ce qui faisait passer pour
    // cassées quatre entrées parfaitement bonnes. On retente alors un vrai téléchargement,
    // borné dans le TEMPS : curl est interrompu (code 28) mais les premiers octets sont
    // déjà sur le disque, et ce sont les seuls qui nous intéressent.
    if ($probleme !== '') {
        @unlink($tmp);
        $o2 = app_src_opts($src['url'], true, '20');
        $o2[] = ['output', $tmp];
        $r2 = app_src_curl($o2);
        if (($r2['rc'] === 0 || $r2['rc'] === 28) && is_file($tmp) && filesize($tmp) > 8) {
            $p2 = app_src_verifier($tmp, (bool) $c['msi']);
            if ($p2 === '') { $probleme = ''; } else { $probleme = $p2; $info = $r2['sortie']; }
        } elseif ($r2['rc'] !== 0) {
            $probleme = $r2['sortie'] ?: 'curl code ' . $r2['rc'];
        }
    }
    @unlink($tmp);

    if ($probleme === '') {
        $v = ($src['version'] ?? '') !== '' ? ' [' . $src['version'] . ']' : '';
        printf("  OK      %-14s %s%s\n", $cle, $c['name'], $v);
        $ok++;
    } elseif (strpos($probleme, 'arguments') !== false) {
        // Le fichier EST un installeur, mais pas du type déclaré : utilisable après
        // correction des arguments silencieux. Ce n'est pas la même chose qu'un 404.
        printf("  AVERT   %-14s %s\n", $cle, $probleme);
        $avert++;
    } else {
        printf("  ECHEC   %-14s %s (%s)\n", $cle, $probleme, $info);
        $echecs[$cle] = $probleme;
        $ko++;
    }
}

$tot = $ok + $ko + $avert + $manuel;
echo "\n";
printf("%d entrées vérifiées : %d bonnes, %d à surveiller, %d à déposer à la main, %d en échec.\n",
       $tot, $ok, $avert, $manuel, $ko);
if ($echecs) {
    echo "\nÀ corriger dans admin/inc/app-catalog.php :\n";
    foreach ($echecs as $k => $m) {
        printf("  - %-14s %s\n", $k, $m);
    }
}
exit($ko > 0 ? 1 : 0);
