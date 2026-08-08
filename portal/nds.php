<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — interrogation RÉSILIENTE d'OpenNDS (ndsctl).
 *
 * ── LE PROBLÈME ──────────────────────────────────────────────────────────────
 * ndsctl se sérialise sur un verrou interne et REFUSE tout appel concurrent, en
 * écrivant « ndsctl thread is busy, please try later. » sur sa sortie STANDARD —
 * pas sur la sortie d'erreur, et avec un code de retour 0. Rien ne distingue donc
 * un refus d'une réponse, sinon le fait que ce n'est pas du JSON.
 * MESURÉ : 6 refus sur 6 appels simultanés. Un appel prend par ailleurs ~1,7 s :
 * les collisions sont la RÈGLE dès que plusieurs agents consultent le portail
 * en même temps, pas un cas limite.
 *
 * Conséquence avant ce correctif : le message d'erreur, n'étant ni vide ni du JSON,
 * était (1) pris pour une réponse et (2) MIS EN CACHE. « Mon compte » annonçait
 * « vous n'êtes pas connecté » à un agent pourtant connecté — 4 fois sur 5 en
 * accès simultané — et l'intranet le croyait déconnecté pendant les 60 s du cache.
 *
 * ── LA RÈGLE ─────────────────────────────────────────────────────────────────
 * Un échec n'est JAMAIS mis en cache, et on rend la dernière valeur connue plutôt
 * qu'une valeur fausse. Attention : « {} » (client inconnu) est une réponse VALIDE,
 * à ne pas confondre avec un refus — le seul discriminant est « est-ce du JSON ? ».
 */

/** Sortie de ndsctl exploitable ? « {} » compte comme valide (client inconnu). */
function pf_nds_valide(string $raw): bool
{
    if (trim($raw) === '') { return false; }
    // ndsctl json peut contenir des caractères de contrôle bruts (retour à la ligne
    // dans le champ « custom ») qui feraient échouer json_decode → retirés d'abord.
    return is_array(json_decode(preg_replace('/[[:cntrl:]]/', '', $raw), true));
}

/**
 * État OpenNDS d'un client, par son adresse IP.
 *
 * @param  int $ttl Durée du cache en secondes (ndsctl est lent : ~1,7 s par appel).
 * @return array|null null = client inconnu d'OpenNDS, ou premier appel refusé sans
 *                    aucune valeur antérieure en cache.
 */
/**
 * La dernière réponse de pf_nds_client() venait-elle du CACHE ?
 *
 * Sert à ne pas reposer à OpenNDS une question qu'on vient de lui poser. Un appel à
 * ndsctl coûte un demi-seconde FIXE — mesuré : 510 ms, identique qu'il y ait un client
 * ou cent, c'est le coût de l'appel lui-même et non du travail. Redemander « juste pour
 * être sûr » double donc le temps d'affichage d'une page pour la même réponse.
 *
 * @param bool|null $set réservé à pf_nds_client() ; lecture seule ailleurs.
 */
function pf_nds_dernier_cache(?bool $set = null): bool
{
    static $v = false;
    if ($set !== null) { $v = $set; }
    return $v;
}

/**
 * @param int $ttl Durée de validité du cache, en secondes. 0 = interroger OpenNDS.
 *
 * Trente secondes par défaut, contre dix auparavant. Mesuré le 2026-08-08 : un
 * appel à « ndsctl » coûte 549 ms — un prix FIXE, indépendant du nombre de
 * clients — et la page d'intranet mettait 0,4 s à répondre sur la passerelle
 * elle-même, avant même de traverser le réseau. Sur un lien Wi-Fi médiocre,
 * l'agent additionnait les deux.
 *
 * Le risque de rallonger : un agent déconnecté reste vu comme autorisé pendant
 * trente secondes au plus. Sans conséquence — le pare-feu, lui, a déjà coupé son
 * accès ; seul l'affichage est en retard. L'inverse, un « non autorisé » périmé,
 * serait autrement plus gênant : il tomberait au moment précis où l'agent vient
 * de s'identifier. C'est pourquoi seules les réponses POSITIVES sont mises en
 * cache (voir plus bas), et pourquoi rallonger ce délai est sans danger.
 */
function pf_nds_client(string $ip, int $ttl = 30): ?array
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) { return null; }
    $f   = '/dev/shm/pf-nds-' . preg_replace('/[^0-9a-fA-F:.]/', '', $ip) . '.cache';
    $raw = '';
    pf_nds_dernier_cache(false);

    if (is_file($f) && (time() - filemtime($f)) < $ttl) {
        $c = (string) @file_get_contents($f);
        if (pf_nds_valide($c)) { $raw = $c; pf_nds_dernier_cache(true); }
    }
    if ($raw === '') {
        $r = (string) shell_exec('sudo /usr/bin/ndsctl json ' . escapeshellarg($ip) . ' 2>/dev/null');
        if (pf_nds_valide($r)) {
            @file_put_contents($f, $r);
            $raw = $r;
        } elseif (is_file($f)) {
            pf_nds_dernier_cache(true);
            // Refusé : on rejoue la dernière valeur connue, même périmée. Jamais l'erreur.
            $c = (string) @file_get_contents($f);
            if (pf_nds_valide($c)) { $raw = $c; }
        }
    }
    if ($raw === '') { return null; }

    $d = json_decode(preg_replace('/[[:cntrl:]]/', '', $raw), true);
    return isset($d['ip']) ? $d : null;
}
