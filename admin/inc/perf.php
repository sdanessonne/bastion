<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — la console mesure sa propre lenteur.
 *
 * ── POURQUOI ─────────────────────────────────────────────────────────────────
 * « La console est lente » est un constat, pas un diagnostic. Sans chiffre, on
 * répond par des hypothèses : la mémoire, l'annuaire, le disque, PHP… toutes
 * plausibles, aucune vérifiable. Et la lenteur ne laisse AUCUNE trace : la page
 * finit par s'afficher, le journal d'Apache note un code 200, rien ne distingue
 * une page rendue en 200 ms d'une page rendue en huit secondes.
 *
 * Ce fichier pose donc un chronomètre sur chaque page. Le coût est celui de deux
 * appels à microtime() ; en échange, la prochaine fois que quelqu'un trouve la
 * console lente, il y a un nombre au lieu d'une supposition.
 *
 * Ce qui est mesuré :
 *   - le temps TOTAL de génération de la page côté serveur ;
 *   - le temps passé dans les commandes système (samba-tool, ndsctl…), qui sont
 *     le poste de dépense habituel — chaque « samba-tool » coûte 600 à 770 ms ;
 *   - le nombre de ces commandes.
 * Le réseau et le navigateur ne sont PAS comptés : c'est volontaire, ils se
 * mesurent depuis le poste, alors qu'ici on cherche ce que fait le serveur.
 */

if (!defined('PF_PERF_T0')) {
    define('PF_PERF_T0', microtime(true));
}

/** Cumul du temps passé dans les commandes système, alimenté par pf_run(). */
$GLOBALS['pf_perf_sys']   = 0.0;
$GLOBALS['pf_perf_calls'] = 0;

if (!function_exists('pf_run')) {
    /**
     * shell_exec chronométré.
     *
     * Remplacer les appels directs par celui-ci rend visible ce qui, sinon, se
     * cache : une page peut être lente sans qu'aucune ligne de code n'ait l'air
     * coûteuse, parce que la dépense est répartie sur douze commandes de 700 ms.
     */
    function pf_run(string $cmd): string {
        $t = microtime(true);
        $r = (string) shell_exec($cmd);
        $GLOBALS['pf_perf_sys']   += microtime(true) - $t;
        $GLOBALS['pf_perf_calls'] += 1;
        return $r;
    }
}

if (!function_exists('pf_perf_ms')) {
    /** Durée écoulée depuis le début de la page, en millisecondes. */
    function pf_perf_ms(): int { return (int) round((microtime(true) - PF_PERF_T0) * 1000); }
}

/**
 * À la fin de chaque page : on consigne les pages lentes.
 *
 * Le seuil est bas volontairement. Une console d'administration sert à agir vite
 * en situation tendue ; au-delà d'une seconde et demie, l'agent doute et
 * recharge — ce qui relance tout le travail et aggrave la charge.
 *
 * La trace part dans le journal d'erreurs d'Apache, pas dans une table : le point
 * de la mesure est de fonctionner même quand la base est justement ce qui rame.
 */
register_shutdown_function(function () {
    $ms = pf_perf_ms();
    if ($ms < 1500) { return; }
    $sys = (int) round(($GLOBALS['pf_perf_sys'] ?? 0) * 1000);
    $n   = (int) ($GLOBALS['pf_perf_calls'] ?? 0);
    error_log(sprintf(
        '[bastion-perf] %s %s — %d ms au total, dont %d ms en %d commande(s) système',
        $_SERVER['REQUEST_METHOD'] ?? '?',
        strtok((string) ($_SERVER['REQUEST_URI'] ?? '?'), '?'),
        $ms, $sys, $n
    ));
});
