<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — identifier un appareil à partir de son adresse MAC.
 *
 * ── CE QU'UNE ADRESSE MAC PERMET, ET CE QU'ELLE NE PERMET PAS ────────────────
 * Les trois premiers octets d'une MAC (l'OUI) sont attribués par l'IEEE à un
 * FABRICANT. On peut donc en déduire la marque de la carte réseau — et rien de
 * plus. Le modèle de la machine n'y figure pas, et la marque de la carte n'est
 * pas toujours celle de l'appareil : un portable Dell embarque couramment une
 * carte Intel ou Realtek, et l'OUI dira « Intel ».
 *
 * D'où deux sources, dans cet ordre :
 *   1. l'INVENTAIRE (pf_inventaire), rempli par le poste lui-même : marque et
 *      modèle réels, exacts, mais disponibles seulement pour les postes du parc
 *      équipés de l'agent ;
 *   2. l'OUI, pour tout le reste — téléphones, imprimantes, appareils
 *      personnels — avec la mention explicite qu'il s'agit de la carte réseau.
 *
 * ── AUCUNE INTERROGATION EXTÉRIEURE ──────────────────────────────────────────
 * Il existe des services web qui résolvent une MAC en marque. Les utiliser
 * enverrait à un tiers la liste des appareils présents dans un commissariat.
 * La résolution est donc PUREMENT LOCALE : le fichier de l'IEEE fourni par
 * Debian (paquet « ieee-data »), et à défaut une table intégrée.
 */

/**
 * Table de secours : les fabricants les plus courants sur un parc administratif.
 * Elle sert quand le paquet « ieee-data » n'est pas installé — mieux vaut
 * reconnaître trente marques que zéro.
 */
const OUI_CONNUS = [
    '000569' => 'VMware',          '000c29' => 'VMware',          '005056' => 'VMware',
    '080027' => 'VirtualBox',      '525400' => 'QEMU/KVM',        '00155d' => 'Hyper-V',
    '001a2b' => 'Dell',            '00188b' => 'Dell',            'b083fe' => 'Dell',
    '18dbf2' => 'Dell',            'd067e5' => 'Dell',            'f8bc12' => 'Dell',
    '001b78' => 'HP',              '3464a9' => 'HP',              '9457a5' => 'HP',
    'ec8eb5' => 'HP',              '00215a' => 'HP',              '308d99' => 'HP',
    '00126f' => 'Lenovo',          '5c60ba' => 'Lenovo',          'e02be9' => 'Lenovo',
    '8cec4b' => 'Lenovo',          '00059a' => 'Cisco',           '0025b4' => 'Cisco',
    '001c23' => 'Intel',           '00212f' => 'Intel',           '3c9757' => 'Intel',
    '8c1645' => 'Intel',           'a0a8cd' => 'Intel',           'e4b318' => 'Intel',
    '000ec6' => 'ASUS',            '1c872c' => 'ASUS',            '00e04c' => 'Realtek',
    '52540a' => 'Realtek',         '001132' => 'Synology',        '0011d8' => 'ASUSTek',
    '001cc0' => 'Intel',           'f4ce46' => 'HP Enterprise',   '00110a' => 'HP',
    '3c2af4' => 'Brother',         '008077' => 'Brother',         '0000aa' => 'Xerox',
    '00000e' => 'Fujitsu',         '000085' => 'Canon',           '00265e' => 'Canon',
    '00008f' => 'Epson',           '444553' => 'Epson',           '0080a3' => 'Lantronix',
    'b827eb' => 'Raspberry Pi',    'dca632' => 'Raspberry Pi',    'e45f01' => 'Raspberry Pi',
    '001451' => 'Apple',           '002608' => 'Apple',           '3c0754' => 'Apple',
    'a4c361' => 'Apple',           'f0dbf8' => 'Apple',           '0021e9' => 'Apple',
    '0016cb' => 'Apple',           '001e52' => 'Apple',           '5c5948' => 'Apple',
    '0012fb' => 'Samsung',         '002454' => 'Samsung',         '5cf6dc' => 'Samsung',
    '8425db' => 'Samsung',         '0023d6' => 'Samsung',         '347e5c' => 'Sony',
    '000fb5' => 'Netgear',         '00224d' => 'Netgear',         '000c43' => 'Ralink',
    '14cc20' => 'TP-Link',         '50c7bf' => 'TP-Link',         'a42bb0' => 'TP-Link',
    '6466b3' => 'TP-Link',         '001d7e' => 'Cisco-Linksys',   '00248c' => 'ASUSTek',
    'd8eb97' => 'TRENDnet',        '000d88' => 'D-Link',          '1c7ee5' => 'D-Link',
    '00095b' => 'Netgear',         'e8de27' => 'TP-Link',         '9c5c8e' => 'ASUSTek',
];

if (!function_exists('mac_aleatoire')) {
    /**
     * Adresse MAC aléatoire (dite « localement administrée ») ?
     *
     * Le deuxième bit du premier octet indique que l'adresse n'a pas été
     * attribuée par l'IEEE. Les téléphones récents en génèrent une par réseau,
     * pour empêcher le pistage. Chercher un fabricant dans ce cas donnerait un
     * nom faux et convaincant — pire qu'une case vide.
     */
    function mac_aleatoire(string $mac): bool {
        $h = preg_replace('/[^0-9a-f]/', '', strtolower($mac));
        if (strlen($h) < 2) { return false; }
        return (hexdec(substr($h, 0, 2)) & 0x02) === 0x02;
    }
}

if (!function_exists('oui_fabricants')) {
    /**
     * Fabricants de plusieurs adresses MAC, en UN SEUL parcours du fichier IEEE.
     *
     * Le fichier de l'IEEE fait environ 4 Mo. Le relire pour chaque ligne du
     * tableau coûterait plus cher que tout le reste de la page — c'est
     * précisément le genre de lenteur qu'on vient de traquer. Il est donc lu au
     * plus une fois par page, et seulement pour les préfixes encore inconnus ;
     * le résultat est conservé en mémoire partagée pour les affichages suivants.
     *
     * @param  string[] $macs
     * @return array<string,string>  MAC (minuscule) → fabricant, ou '' si inconnu
     */
    function oui_fabricants(array $macs): array {
        $cacheF = '/dev/shm/pf-oui.json';
        $cache  = [];
        if (is_file($cacheF)) {
            $j = json_decode((string) @file_get_contents($cacheF), true);
            if (is_array($j)) { $cache = $j; }
        }

        $res = [];
        $manquants = [];
        foreach ($macs as $m) {
            $m = strtolower(trim($m));
            if ($m === '') { continue; }
            $p = substr(preg_replace('/[^0-9a-f]/', '', $m), 0, 6);
            if (strlen($p) < 6) { $res[$m] = ''; continue; }
            if (mac_aleatoire($m))          { $res[$m] = '~aleatoire'; continue; }
            if (isset($cache[$p]))          { $res[$m] = $cache[$p];   continue; }
            if (isset(OUI_CONNUS[$p]))      { $res[$m] = OUI_CONNUS[$p]; $cache[$p] = OUI_CONNUS[$p]; continue; }
            $manquants[$p][] = $m;
        }

        if ($manquants) {
            // Chemins livrés par le paquet Debian « ieee-data ». Aucun accès réseau.
            $src = '';
            foreach (['/var/lib/ieee-data/oui.txt', '/usr/share/ieee-data/oui.txt'] as $f) {
                if (is_readable($f)) { $src = $f; break; }
            }
            if ($src !== '' && ($fh = @fopen($src, 'r')) !== false) {
                $reste = $manquants;
                while ($reste && ($ligne = fgets($fh)) !== false) {
                    // Format : « AA-BB-CC   (hex)		Nom du fabricant »
                    if (strpos($ligne, '(hex)') === false) { continue; }
                    $p = strtolower(str_replace('-', '', substr($ligne, 0, 8)));
                    if (!isset($reste[$p])) { continue; }
                    $nom = trim(substr($ligne, strpos($ligne, '(hex)') + 5));
                    $nom = trim(preg_replace('/\s+/', ' ', $nom));
                    // Les raisons sociales complètes sont illisibles dans un tableau
                    // (« Dell Inc. », « Hewlett Packard Enterprise »). On coupe au
                    // premier terme juridique.
                    $nom = preg_split('/,| Inc\.?| Ltd\.?| LLC| GmbH| Co\.| Corp/i', $nom)[0];
                    $nom = trim($nom) ?: '';
                    foreach ($reste[$p] as $m) { $res[$m] = $nom; }
                    $cache[$p] = $nom;
                    unset($reste[$p]);
                }
                fclose($fh);
                // Un préfixe absent du fichier est mis en cache comme inconnu :
                // sans cela, le fichier serait relu à chaque affichage pour
                // rechercher une entrée qui n'existe pas.
                foreach ($reste as $p => $liste) {
                    foreach ($liste as $m) { $res[$m] = ''; }
                    $cache[$p] = '';
                }
            } else {
                foreach ($manquants as $p => $liste) {
                    foreach ($liste as $m) { $res[$m] = ''; }
                }
            }
            @file_put_contents($cacheF, json_encode($cache));
        }
        return $res;
    }
}
