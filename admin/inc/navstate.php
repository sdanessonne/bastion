<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — ce que le menu doit signaler, et ce que l'administrateur ouvre souvent.
 *
 * ── POURQUOI LES PASTILLES ───────────────────────────────────────────────────
 * Les alertes existaient déjà, mais UNIQUEMENT sur le tableau de bord. Tant
 * qu'on était ailleurs dans la console, une panne restait invisible : le portail
 * captif arrêté, un disque plein, un tunnel mort privant un groupe d'Internet —
 * il fallait revenir à l'accueil, ou ouvrir par hasard la bonne page.
 *
 * Le menu est présent sur TOUTES les pages. Y porter le signal, c'est faire en
 * sorte qu'une anomalie se remarque sans qu'on la cherche. C'est exactement le
 * défaut corrigé ailleurs dans ce projet : une panne qui ne s'annonce pas.
 *
 * ── POURQUOI LES FRÉQUENTES ──────────────────────────────────────────────────
 * 27 destinations, dont quatre ou cinq servent chaque jour. Les faire remonter
 * évite de parcourir la colonne pour retrouver toujours les mêmes.
 * On compte des PAGES DE CONSOLE ouvertes par un administrateur — aucune donnée
 * d'agent, aucune navigation d'utilisateur n'est enregistrée ici.
 */

if (!function_exists('nav_badges')) {
    /**
     * Anomalies à signaler, rattachées à la page qui permet d'agir.
     *
     * @return array<string,array{lvl:string,txt:string}> fichier => pastille
     */
    function nav_badges(): array {
        // Le résultat est mis en cache une minute : ce menu est rendu sur chaque
        // page, et sys_alerts() interroge systemd, le disque et la base. Sans
        // cache, on paierait ce coût à chaque clic — la console est déjà jugée
        // lente, ce serait aggraver le mal en voulant le montrer.
        static $memo = null;
        if ($memo !== null) { return $memo; }
        $cacheF = '/dev/shm/pf-navbadges.json';
        if (is_file($cacheF) && (time() - filemtime($cacheF)) < 60) {
            $j = json_decode((string) @file_get_contents($cacheF), true);
            if (is_array($j)) { return $memo = $j; }
        }

        $out = [];
        try {
            foreach (sys_alerts() as $a) {
                // sys_alerts() porte déjà l'adresse de la page qui traite le
                // problème : on s'en sert plutôt que de redéfinir une seconde
                // correspondance, qui divergerait au premier ajout d'alerte.
                $page = (string) ($a['url'] ?? '');
                if ($page === '') { continue; }
                $lvl = ($a['lvl'] ?? 'warn') === 'danger' ? 'danger' : 'warn';
                // Le niveau le plus grave l'emporte : une page peut cumuler deux
                // anomalies, et n'afficher que la seconde minimiserait la première.
                if (!isset($out[$page]) || ($lvl === 'danger' && $out[$page]['lvl'] !== 'danger')) {
                    $out[$page] = ['lvl' => $lvl, 'txt' => (string) ($a['txt'] ?? '')];
                }
            }
        } catch (Throwable $e) { /* une alerte illisible ne doit pas casser le menu */ }

        // ── LES MISES A JOUR NE SONT PAS UNE ALERTE ──────────────────────
        // Elles meritent une pastille, PAS un courriel : sys_alerts() alimente
        // aussi le surveillant, et prevenir a chaque lot de correctifs
        // produirait un bruit qui finirait par faire ignorer les vraies
        // alertes. Le signal reste donc dans la console.
        try {
            $m = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-maj state 2>/dev/null'), true) ?: [];
            if (!empty($m['connu'])) {
                $n = (int) ($m['apt'] ?? 0) + (int) ($m['git'] ?? 0);
                if ($n > 0 && !isset($out['systeme.php'])) {
                    $out['systeme.php'] = [
                        'lvl' => ((int) ($m['secu'] ?? 0) > 0) ? 'danger' : 'warn',
                        'txt' => $n . ' mise(s) a jour en attente'
                              . ((int) ($m['secu'] ?? 0) > 0 ? ', dont ' . (int) $m['secu'] . ' de securite' : ''),
                    ];
                }
            }
        } catch (Throwable $e) { /* sans effet sur le menu */ }

        @file_put_contents($cacheF, json_encode($out));
        return $memo = $out;
    }
}

if (!function_exists('nav_freq_note')) {
    /**
     * Enregistre l'ouverture d'une page par l'administrateur connecté.
     *
     * Volontairement silencieux : si la table n'existe pas ou si la base est
     * indisponible, la console doit continuer de fonctionner. Un menu sans bloc
     * « Fréquentes » est un désagrément ; une console qui refuse de s'afficher
     * parce qu'un compteur a échoué serait une panne.
     */
    function nav_freq_note(string $page, string $admin): void {
        if ($page === '' || $admin === '' || $page === 'login.php') { return; }
        try {
            $db = pf_db();
            $db->exec('CREATE TABLE IF NOT EXISTS pf_nav_freq (
                admin VARCHAR(64) NOT NULL, page VARCHAR(64) NOT NULL,
                n INT NOT NULL DEFAULT 0, vu_le TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (admin, page))');
            $db->prepare('INSERT INTO pf_nav_freq (admin,page,n) VALUES (?,?,1)
                          ON DUPLICATE KEY UPDATE n=n+1')->execute([$admin, $page]);
        } catch (Throwable $e) { /* sans effet sur l'affichage */ }
    }
}

if (!function_exists('nav_freq_top')) {
    /**
     * Les pages les plus ouvertes par cet administrateur.
     *
     * Le seuil de 3 ouvertures évite qu'un bloc « Fréquentes » apparaisse dès la
     * première visite en affichant une seule entrée — il ne rendrait alors aucun
     * service et déplacerait le menu sans raison.
     *
     * @return string[] noms de fichiers, du plus consulté au moins consulté
     */
    function nav_freq_top(string $admin, int $max = 5): array {
        if ($admin === '') { return []; }
        try {
            $st = pf_db()->prepare('SELECT page FROM pf_nav_freq WHERE admin=? AND n>=3
                                    ORDER BY n DESC, vu_le DESC LIMIT ' . max(1, min(8, $max)));
            $st->execute([$admin]);
            return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { return []; }
    }
}
