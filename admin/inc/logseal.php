<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — scellement des journaux (intégrité de la journalisation légale).
 *
 * ── LE PROBLÈME ──────────────────────────────────────────────────────────────
 * Les journaux vivent dans MariaDB. Quiconque a un accès à la base — un
 * administrateur, ou un intrus qui a obtenu ses droits — peut supprimer une ligne
 * d'historique ou modifier une session SANS LAISSER LA MOINDRE TRACE. Un dossier de
 * réquisition extrait d'une base modifiable n'a donc qu'une valeur probante faible :
 * rien ne permet d'affirmer qu'il reflète l'état d'origine.
 *
 * ── LE PRINCIPE ──────────────────────────────────────────────────────────────
 * Chaque jour, on calcule une empreinte SHA-256 des journaux de la journée, on la
 * CHAÎNE au scellé de la veille, et on SIGNE le résultat avec le certificat de
 * l'Autorité Bastion (le même qui signe les dossiers de réquisition).
 *
 *   scellé(J) = SHA-256( date(J) || empreinte(J) || scellé(J-1) )
 *
 * Ce que cela permet de détecter :
 *   - une ligne modifiée ou supprimée      → l'empreinte du jour ne correspond plus ;
 *   - un scellé recalculé pour cacher cela → la signature CMS ne vérifie plus
 *                                            (il faudrait la clé privée de l'AC) ;
 *   - un jour entier effacé                → la chaîne se rompt : le scellé du
 *                                            lendemain ne référence plus le bon parent.
 *
 * ── POURQUOI SCELLER SUR L'HEURE DE FIN DE SESSION ───────────────────────────
 * radacct est MODIFIÉ après coup : acctstoptime et les compteurs d'octets ne sont
 * écrits qu'à la fermeture de la session. Sceller sur acctstarttime casserait donc
 * le scellé de toute session à cheval sur minuit — une session ouverte à 23 h 50 et
 * fermée à 00 h 10 changerait après le scellement du jour. On ne scelle donc QUE les
 * sessions TERMINÉES, rattachées au jour de leur fin : elles sont alors définitives.
 * pf_weblog, lui, est en insertion seule : rattaché à la date de l'événement.
 */

/** Valeur d'amorçage de la chaîne : le « scellé » fictif qui précède le premier jour. */
const SEAL_GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

function seal_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS pf_log_seal (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        day        DATE        NOT NULL UNIQUE,
        nb_acct    INT         NOT NULL,
        nb_web     INT         NOT NULL,
        digest     CHAR(64)    NOT NULL,
        prev_seal  CHAR(64)    NOT NULL,
        seal       CHAR(64)    NOT NULL,
        signature  MEDIUMBLOB  NULL,
        created_at DATETIME    NOT NULL,
        purged_at  DATETIME    NULL,
        KEY k_day (day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Installations antérieures au marquage des purges.
    try { $db->exec("ALTER TABLE pf_log_seal ADD COLUMN IF NOT EXISTS purged_at DATETIME NULL"); }
    catch (Throwable $e) { /* colonne déjà là */ }
}

/**
 * Empreinte des journaux d'une journée.
 *
 * DOIT être reproductible à l'identique des mois plus tard : tri explicite sur une
 * clé primaire, champs listés un par un (jamais SELECT *, qui changerait si une
 * colonne est ajoutée), NULL rendu distinctement de la chaîne vide.
 *
 * @return array{digest:string,nb_acct:int,nb_web:int}
 */
function seal_digest_for_day(PDO $db, string $day): array {
    $h = hash_init('sha256');
    hash_update($h, "BASTION-SEAL-v1\n{$day}\n");

    $nbAcct = 0;
    $st = $db->prepare(
        "SELECT radacctid, acctsessionid, username, framedipaddress, callingstationid,
                acctstarttime, acctstoptime, acctsessiontime, acctinputoctets, acctoutputoctets
           FROM radacct
          WHERE acctstoptime IS NOT NULL AND DATE(acctstoptime) = ?
          ORDER BY radacctid"
    );
    $st->execute([$day]);
    hash_update($h, "[radacct]\n");
    while ($r = $st->fetch(PDO::FETCH_NUM)) {
        // « \x1f » (séparateur d'unités) : ne peut pas apparaître dans les données,
        // donc deux jeux de champs différents ne peuvent pas produire la même chaîne.
        hash_update($h, implode("\x1f", array_map(fn($v) => $v === null ? "\x00" : (string) $v, $r)) . "\n");
        $nbAcct++;
    }

    $nbWeb = 0;
    $st = $db->prepare("SELECT id, ts, client_ip, username, domain FROM pf_weblog WHERE DATE(ts) = ? ORDER BY id");
    $st->execute([$day]);
    hash_update($h, "[pf_weblog]\n");
    while ($r = $st->fetch(PDO::FETCH_NUM)) {
        hash_update($h, implode("\x1f", array_map(fn($v) => $v === null ? "\x00" : (string) $v, $r)) . "\n");
        $nbWeb++;
    }

    return ['digest' => hash_final($h), 'nb_acct' => $nbAcct, 'nb_web' => $nbWeb];
}

/** scellé = SHA-256( jour || empreinte || scellé de la veille ). */
function seal_compute(string $day, string $digest, string $prevSeal): string {
    return hash('sha256', "{$day}\x1f{$digest}\x1f{$prevSeal}");
}

/** Scellé du jour précédent, ou l'amorce si c'est le premier. */
function seal_previous(PDO $db, string $day): string {
    $st = $db->prepare("SELECT seal FROM pf_log_seal WHERE day < ? ORDER BY day DESC LIMIT 1");
    $st->execute([$day]);
    return (string) ($st->fetchColumn() ?: SEAL_GENESIS);
}

/**
 * Vérifie la chaîne complète : recalcule chaque empreinte depuis la base, refait
 * chaque scellé, contrôle le chaînage et la signature.
 *
 * @return array{ok:bool, days:array<int,array{day:string,digest_ok:bool,seal_ok:bool,chain_ok:bool,sig_ok:bool,nb_acct:int,nb_web:int}>, resume:string}
 */
function seal_verify_chain(PDO $db, int $limit = 400): array {
    $rows = $db->query("SELECT * FROM pf_log_seal ORDER BY day DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
    $rows = array_reverse($rows);
    $out  = [];
    $ok   = true;
    $prev = null;

    foreach ($rows as $r) {
        // Journée PURGÉE (conservation légale écoulée) : ses lignes ont légalement
        // disparu, l'empreinte ne peut donc plus être recalculée. Ce n'est PAS une
        // altération — l'annoncer comme telle décrédibiliserait tout le dispositif.
        // Le scellé, le chaînage et la signature restent vérifiables : ce sont des
        // valeurs stockées, que la purge ne touche pas. On prouve donc toujours que
        // la chaîne n'a pas été retouchée, seulement plus que les données lui
        // correspondent — elles n'existent plus.
        $purge = !empty($r['purged_at']);

        $digest_ok = $purge ? true : hash_equals($r['digest'], seal_digest_for_day($db, $r['day'])['digest']);
        $seal_ok   = hash_equals($r['seal'], seal_compute($r['day'], $r['digest'], $r['prev_seal']));
        // Le 1er jour examiné n'a pas de parent dans la fenêtre : on ne peut pas
        // conclure sur son chaînage, on ne l'invalide donc pas.
        $chain_ok  = ($prev === null) ? true : hash_equals($r['prev_seal'], $prev);
        $sig_ok    = seal_verify_signature($r['seal'], $r['signature']);

        $out[] = [
            'day' => $r['day'], 'digest_ok' => $digest_ok, 'seal_ok' => $seal_ok,
            'chain_ok' => $chain_ok, 'sig_ok' => $sig_ok, 'purge' => $purge,
            'nb_acct' => (int) $r['nb_acct'], 'nb_web' => (int) $r['nb_web'],
        ];
        if (!$digest_ok || !$seal_ok || !$chain_ok || !$sig_ok) { $ok = false; }
        $prev = $r['seal'];
    }

    $n = count($out);
    $resume = $n === 0
        ? "Aucun jour scellé pour l'instant."
        : ($ok ? "Chaîne intègre sur {$n} jour(s) scellé(s), du {$out[0]['day']} au {$out[$n-1]['day']}."
               : "RUPTURE D'INTÉGRITÉ détectée — voir le détail par jour.");
    return ['ok' => $ok, 'days' => $out, 'resume' => $resume];
}

/** Vérifie la signature CMS détachée d'un scellé contre l'AC Bastion. */
function seal_verify_signature(string $seal, ?string $sig): bool {
    if ($sig === null || $sig === '') { return false; }
    $ca = '/etc/proxyfibre/bastion-ca.crt';
    if (!is_readable($ca)) { return false; }
    $fSeal = tempnam('/tmp', 'seal_');
    $fSig  = tempnam('/tmp', 'sig_');
    file_put_contents($fSeal, $seal);
    file_put_contents($fSig, $sig);
    exec('openssl cms -verify -binary -inform DER -in ' . escapeshellarg($fSig)
        . ' -content ' . escapeshellarg($fSeal)
        . ' -CAfile ' . escapeshellarg($ca) . ' -purpose any -out /dev/null 2>&1', $o, $rc);
    @unlink($fSeal); @unlink($fSig);
    return $rc === 0;
}
