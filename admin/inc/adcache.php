<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — accès mutualisé à l'annuaire (Samba AD DC), avec CACHE.
 *
 * Pourquoi : chaque invocation de « samba-tool » coûte 600 à 770 ms (démarrage de Python +
 * import des modules Samba). Une page qui liste comptes, groupes, postes, GPO et OU payait
 * donc plusieurs SECONDES à chaque affichage — d'où une console perçue comme lente.
 *
 * Le cache est posé dans /dev/shm (mémoire, remis à zéro au redémarrage) et il est PURGÉ
 * explicitement par ad_cache_clear() après chaque action modifiante : la durée de vie peut
 * donc être longue sans jamais afficher une information périmée du fait de la console.
 * Seule une modification faite HORS console (en ligne de commande sur le serveur) peut
 * mettre jusqu'à AD_TTL secondes à apparaître ; le bouton « Actualiser » force la relecture.
 */

/** Durée de vie du cache de lecture, en secondes. */
const AD_TTL = 300;

if (!function_exists('ad')) {
    /** Appel direct (NON mis en cache) : à utiliser pour les ÉCRITURES. */
    function ad(...$args): string {
        $cmd = 'sudo /usr/local/sbin/proxyfibre-ad';
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
        return (string) shell_exec($cmd . ' 2>&1');
    }
}

if (!function_exists('ad_cache_clear')) {
    /** Purge le cache : à appeler après toute action qui modifie l'annuaire. */
    function ad_cache_clear(): void {
        foreach (glob('/dev/shm/pf-ad-*.cache') ?: [] as $f) { @unlink($f); }
    }
}

if (!function_exists('ad_cache')) {
    /** Lecture avec cache. $ttl = 0 → durée par défaut (AD_TTL). */
    function ad_cache(string $key, int $ttl, ...$args): string {
        $f = '/dev/shm/pf-ad-' . preg_replace('/[^a-z0-9]/', '', $key) . '.cache';
        if ($ttl <= 0) { $ttl = AD_TTL; }
        if (is_file($f) && (time() - filemtime($f)) < $ttl) {
            $r = @file_get_contents($f);
            if ($r !== false) { return $r; }
        }
        $r = ad(...$args);
        // On ne met en cache qu'un résultat exploitable : une erreur ponctuelle (annuaire qui
        // redémarre) ne doit pas être figée pour toute la durée de vie du cache.
        if (trim($r) !== '' && !preg_match('/^(ERROR|Traceback|Failed)/i', trim($r))) {
            @file_put_contents($f, $r);
        }
        return $r;
    }
}

if (!function_exists('ad_lines_cached')) {
    /** Lecture avec cache, découpée en lignes non vides. */
    function ad_lines_cached(string $key, int $ttl, ...$args): array {
        return array_values(array_filter(
            array_map('trim', explode("\n", ad_cache($key, $ttl, ...$args))),
            fn($l) => $l !== ''));
    }
}

if (!function_exists('ad_lines')) {
    /**
     * Lecture en lignes. Les listes courantes (comptes, groupes, postes, OU) passent
     * automatiquement par le cache : ce sont elles qui coûtaient le plus cher.
     */
    function ad_lines(...$args): array {
        $k = strtolower(implode('', array_map('strval', $args)));
        if (in_array($k, ['userlist', 'grouplist', 'computerlist', 'oulist'], true)) {
            return ad_lines_cached($k === 'userlist' ? 'users' : ($k === 'grouplist' ? 'groups'
                   : ($k === 'computerlist' ? 'computers' : 'ous')), AD_TTL, ...$args);
        }
        return array_values(array_filter(
            array_map('trim', explode("\n", ad(...$args))), fn($l) => $l !== ''));
    }
}

if (!function_exists('pf_cmd_cache')) {
    /**
     * Cache générique pour une commande externe lente (hors annuaire) : chaque appel via sudo
     * à un script de la passerelle coûte de 250 à 600 ms, ce qui alourdit l'affichage.
     * Purgé par pf_cmd_cache_clear() après toute action qui change l'état concerné.
     */
    function pf_cmd_cache(string $key, int $ttl, string $cmd): string {
        $f = '/dev/shm/pf-cmd-' . preg_replace('/[^a-z0-9]/', '', $key) . '.cache';
        if (is_file($f) && (time() - filemtime($f)) < $ttl) {
            $r = @file_get_contents($f);
            if ($r !== false) { return $r; }
        }
        $r = (string) shell_exec($cmd);
        if (trim($r) !== '') { @file_put_contents($f, $r); }
        return $r;
    }
    function pf_cmd_cache_clear(): void {
        foreach (glob('/dev/shm/pf-cmd-*.cache') ?: [] as $f) { @unlink($f); }
    }
}

/** État du contrôleur de domaine (appel court, ~34 ms — pas de cache nécessaire). */
if (!function_exists('ad_dc_up')) {
    function ad_dc_up(): bool {
        return trim((string) shell_exec('systemctl is-active samba-ad-dc 2>/dev/null')) === 'active';
    }
}
