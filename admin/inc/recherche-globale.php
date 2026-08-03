<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — moteur de recherche global de la console.
 *
 * Un seul champ interroge TOUT ce que la passerelle connaît : agents, postes du parc,
 * réservations d'adresses, groupes, applications, pages de l'intranet, domaines bloqués,
 * historique de navigation, journal d'audit, alertes, stratégies de groupe, et les pages
 * de la console elles-mêmes.
 *
 * ── CE QUI N'EST PAS CHERCHÉ, ET POURQUOI ────────────────────────────────────
 * La table « pf_settings » est écartée en bloc : elle contient api_token, station_token,
 * inventory_token et wifi_psk. Une recherche qui affiche ce qu'elle trouve n'a rien à
 * faire dans une table de secrets — un administrateur en lecture seule aurait pu lire la
 * clé Wi-Fi en tapant « wifi ». Pour la même raison, on ne lit jamais la colonne
 * « password » des accès visiteur ni la valeur des attributs RADIUS : seuls les
 * identifiants remontent.
 *
 * ── LE PÉRIMÈTRE SUIT LE RÔLE ────────────────────────────────────────────────
 * Chaque source déclare la page qui l'affiche. Une source n'est interrogée que si le rôle
 * peut ouvrir cette page (pf_page_autorisee). Sans cela, la recherche serait devenue le
 * moyen de contourner les rôles : un compte « comptes », qui ne peut pas ouvrir le parc,
 * en aurait lu l'inventaire dans les résultats.
 */

require_once __DIR__ . '/config.php';

/**
 * Neutralise les métacaractères de LIKE.
 *
 * Sans cela, « % » cherché par un administrateur ramène TOUTE la base, et « _ » remplace
 * n'importe quel caractère : les résultats semblent justes, ils ne le sont pas.
 */
function rg_like(string $terme): string {
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $terme) . '%';
}

/** Coupe proprement un extrait, sans casser l'UTF-8 ni couper un mot en deux. */
function rg_extrait(string $txt, int $max = 120): string {
    $txt = trim(preg_replace('/\s+/u', ' ', strip_tags($txt)) ?? '');
    if (mb_strlen($txt) <= $max) { return $txt; }
    $court = mb_substr($txt, 0, $max);
    $esp = mb_strrpos($court, ' ');
    return ($esp > $max * 0.6 ? mb_substr($court, 0, $esp) : $court) . '…';
}

/** Met en évidence le terme trouvé. L'échappement a lieu AVANT : seul <mark> est réinjecté. */
function rg_marquer(string $txt, string $terme): string {
    $h = htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');
    if ($terme === '') { return $h; }
    $t = preg_quote(htmlspecialchars($terme, ENT_QUOTES, 'UTF-8'), '/');
    return preg_replace('/(' . $t . ')/iu', '<mark>$1</mark>', $h) ?? $h;
}

/**
 * Interroge toutes les sources autorisées.
 *
 * @param string $terme  ce que l'administrateur a tapé
 * @param int    $parSrc nombre de résultats retenus par source
 * @return array{groupes:array<int,array{cle:string,titre:string,icone:string,page:string,resultats:array,total:int}>,total:int,ecartees:array<int,string>}
 */
function rg_chercher(PDO $db, string $terme, int $parSrc = 6): array {
    $terme = trim($terme);
    $groupes = []; $total = 0; $ecartees = [];
    if (mb_strlen($terme) < 2) { return ['groupes' => [], 'total' => 0, 'ecartees' => []]; }

    $l = rg_like($terme);
    // « LIMIT » n'accepte pas de paramètre lié sur toutes les versions : on borne l'entier
    // nous-mêmes et on l'interpole — il ne vient jamais de l'utilisateur.
    $n = max(1, min(50, $parSrc));

    foreach (rg_sources() as $src) {
        if (!pf_page_autorisee($src['page'])) { $ecartees[] = $src['titre']; continue; }
        try {
            $res = ($src['requete'])($db, $l, $n, $terme);
        } catch (Throwable $e) {
            // Une table absente (fonction jamais utilisée sur ce site) ne doit pas faire
            // tomber toute la recherche : la source est simplement vide.
            continue;
        }
        if (!$res) { continue; }
        $groupes[] = ['cle' => $src['cle'], 'titre' => $src['titre'], 'icone' => $src['icone'],
                      'page' => $src['page'], 'resultats' => $res, 'total' => count($res)];
        $total += count($res);
    }
    return ['groupes' => $groupes, 'total' => $total, 'ecartees' => array_unique($ecartees)];
}

/** Un résultat : titre, sous-titre, lien, et une étiquette facultative. */
function rg_item(string $titre, string $sous, string $url, string $tag = ''): array {
    return ['titre' => $titre, 'sous' => $sous, 'url' => $url, 'tag' => $tag];
}

/**
 * Les sources. Chaque entrée : clé, titre, icône, page qui l'affiche, et la requête.
 * La requête reçoit (PDO, motif LIKE déjà échappé, limite, terme brut).
 *
 * @return array<int,array{cle:string,titre:string,icone:string,page:string,requete:callable}>
 */
function rg_sources(): array {
    return [

    // ── Les pages de la console elles-mêmes ──────────────────────────────────
    // Première source volontairement : quand on tape « quota », on cherche neuf fois sur
    // dix la PAGE des quotas, pas une ligne de base de données.
    ['cle' => 'pages', 'titre' => 'Pages de la console', 'icone' => '🧭', 'page' => 'index.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $out = [];
        foreach (pf_nav_groups() as $groupe => $items) {
            foreach ($items as $fichier => $d) {
                if (!pf_page_autorisee($fichier)) { continue; }
                $foin = $d[0] . ' ' . ($d[2] ?? '');
                if (mb_stripos($foin, $t) === false) { continue; }
                $out[] = rg_item(($d[1] ?? '') . ' ' . $d[0], $groupe, '/' . $fichier, 'page');
                if (count($out) >= $n) { return $out; }
            }
        }
        return $out;
     }],

    // ── Stratégies de groupe prêtes à déployer ───────────────────────────────
    ['cle' => 'gpo', 'titre' => 'Catalogue des stratégies de groupe', 'icone' => '🗄️', 'page' => 'ad.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $cat = @include __DIR__ . '/gpo-catalog.php';
        if (!is_array($cat)) { return []; }
        $out = [];
        foreach ($cat as $cle => $g) {
            $foin = ($g['title'] ?? '') . ' ' . ($g['desc'] ?? '') . ' ' . ($g['cat'] ?? '');
            foreach (($g['policies'] ?? []) as $p) { $foin .= ' ' . ($p['keyname'] ?? '') . ' ' . ($p['valuename'] ?? ''); }
            if (mb_stripos($foin, $t) === false) { continue; }
            $out[] = rg_item(($g['icon'] ?? '📋') . ' ' . ($g['title'] ?? $cle),
                             rg_extrait((string) ($g['desc'] ?? ''), 110),
                             '/ad.php#gpo-' . rawurlencode((string) $cle),
                             (string) ($g['scope'] ?? ''));
            if (count($out) >= $n) { break; }
        }
        return $out;
     }],

    // ── Fonctionnaires ───────────────────────────────────────────────────────
    ['cle' => 'agents', 'titre' => 'Fonctionnaires', 'icone' => '👤', 'page' => 'users.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        // On part des PROFILS et des comptes portail, joints au commissariat. La valeur des
        // attributs RADIUS (le mot de passe en clair) n'est jamais lue.
        $st = $db->prepare(
            "SELECT u.username, p.nom, p.prenom, p.service, c.name AS site, c.cpn
               FROM (SELECT username FROM radcheck WHERE attribute='Cleartext-Password'
                     UNION SELECT username FROM pf_user_profile
                     UNION SELECT username FROM pf_user_site) u
          LEFT JOIN pf_user_profile p ON p.username = u.username
          LEFT JOIN pf_user_site   s ON s.username = u.username
          LEFT JOIN pf_commissariats c ON c.id = s.commissariat_id
              WHERE u.username LIKE ? OR p.nom LIKE ? OR p.prenom LIKE ?
                 OR p.service LIKE ? OR c.name LIKE ? OR c.cpn LIKE ?
           ORDER BY p.nom, p.prenom, u.username LIMIT $n");
        $st->execute([$l, $l, $l, $l, $l, $l]);
        $out = [];
        foreach ($st as $r) {
            $ident = trim(((string) $r['prenom']) . ' ' . ((string) $r['nom']));
            $sous = implode(' · ', array_filter([$r['service'] ?: '', $r['site'] ?: '']));
            $out[] = rg_item($ident !== '' ? $ident : (string) $r['username'],
                             trim(((string) $r['username']) . ($sous !== '' ? ' — ' . $sous : '')),
                             '/recherche.php?u=' . rawurlencode((string) $r['username']), 'agent');
        }
        return $out;
     }],

    ['cle' => 'sites', 'titre' => 'Commissariats & services', 'icone' => '🏛️', 'page' => 'users.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT id,name,cpn FROM pf_commissariats WHERE name LIKE ? OR cpn LIKE ? ORDER BY cpn,name LIMIT $n");
        $st->execute([$l, $l]);
        $out = [];
        foreach ($st as $r) { $out[] = rg_item((string) $r['name'], 'CPN ' . (string) $r['cpn'], '/users.php', 'site'); }
        return $out;
     }],

    ['cle' => 'groupes', 'titre' => 'Groupes & quotas', 'icone' => '⚙', 'page' => 'groups.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT groupname,down_rate_kbps,down_quota_mb,hours_start,hours_end FROM pf_groups WHERE groupname LIKE ? ORDER BY groupname LIMIT $n");
        $st->execute([$l]);
        $out = [];
        foreach ($st as $r) {
            $d = [];
            if ((int) $r['down_rate_kbps'] > 0) { $d[] = round(((int) $r['down_rate_kbps']) / 1024, 1) . ' Mb/s'; }
            if ((int) $r['down_quota_mb'] > 0)  { $d[] = (int) $r['down_quota_mb'] . ' Mo/jour'; }
            if ($r['hours_start'] !== null && $r['hours_end'] !== null) { $d[] = $r['hours_start'] . 'h–' . $r['hours_end'] . 'h'; }
            $out[] = rg_item((string) $r['groupname'], implode(' · ', $d) ?: 'aucune limite', '/groups.php', 'groupe');
        }
        return $out;
     }],

    // Accès visiteur : identifiant et libellé SEULEMENT. La colonne « password » porte le
    // code remis au visiteur — elle n'est ni lue ni affichée.
    ['cle' => 'visiteurs', 'titre' => 'Accès visiteur', 'icone' => '🎟️', 'page' => 'visiteurs.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT username,label,grp,expires_at,revoked FROM pf_voucher WHERE username LIKE ? OR label LIKE ? ORDER BY created_at DESC LIMIT $n");
        $st->execute([$l, $l]);
        $out = [];
        foreach ($st as $r) {
            $etat = ((int) $r['revoked'] === 1) ? 'révoqué' : ('expire le ' . substr((string) $r['expires_at'], 0, 10));
            $out[] = rg_item((string) ($r['label'] ?: $r['username']),
                             ((string) $r['username']) . ' · ' . ((string) $r['grp']) . ' · ' . $etat,
                             '/visiteurs.php', 'visiteur');
        }
        return $out;
     }],

    // ── Postes & réseau ──────────────────────────────────────────────────────
    ['cle' => 'postes', 'titre' => 'Postes du parc', 'icone' => '💻', 'page' => 'parc.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare(
            "SELECT poste,ip,mac,serie,fabricant,modele,os_nom,utilisateur,vu_le FROM pf_inventaire
              WHERE poste LIKE ? OR ip LIKE ? OR mac LIKE ? OR serie LIKE ?
                 OR fabricant LIKE ? OR modele LIKE ? OR utilisateur LIKE ? OR os_nom LIKE ?
           ORDER BY poste LIMIT $n");
        $st->execute([$l, $l, $l, $l, $l, $l, $l, $l]);
        $out = [];
        foreach ($st as $r) {
            $d = array_filter([trim(((string) $r['fabricant']) . ' ' . ((string) $r['modele'])),
                               (string) $r['ip'], (string) $r['utilisateur']]);
            $out[] = rg_item((string) $r['poste'], implode(' · ', $d), '/parc.php', 'poste');
        }
        return $out;
     }],

    ['cle' => 'dhcp', 'titre' => "Réservations d'adresses", 'icone' => '🔌', 'page' => 'dhcp.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT mac,ip,label FROM pf_dhcp WHERE mac LIKE ? OR ip LIKE ? OR label LIKE ? ORDER BY ip LIMIT $n");
        $st->execute([$l, $l, $l]);
        $out = [];
        foreach ($st as $r) { $out[] = rg_item((string) ($r['label'] ?: $r['ip']), ((string) $r['ip']) . ' · ' . ((string) $r['mac']), '/dhcp.php', 'réservation'); }
        return $out;
     }],

    ['cle' => 'quarantaine', 'titre' => 'Postes en quarantaine', 'icone' => '🚫', 'page' => 'quarantaine.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT ip,label,since FROM pf_quarantine WHERE ip LIKE ? OR label LIKE ? ORDER BY since DESC LIMIT $n");
        $st->execute([$l, $l]);
        $out = [];
        foreach ($st as $r) { $out[] = rg_item((string) ($r['label'] ?: $r['ip']), 'isolé depuis le ' . substr((string) $r['since'], 0, 16), '/quarantaine.php', 'quarantaine'); }
        return $out;
     }],

    ['cle' => 'apps', 'titre' => 'Applications', 'icone' => '🏪', 'page' => 'apps.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT id,name,description,deployed FROM pf_apps WHERE name LIKE ? OR description LIKE ? OR filename LIKE ? ORDER BY name LIMIT $n");
        $st->execute([$l, $l, $l]);
        $out = [];
        foreach ($st as $r) {
            $out[] = rg_item((string) $r['name'], rg_extrait((string) $r['description'], 110), '/apps.php',
                             ((int) $r['deployed'] === 1) ? 'déployée' : 'non déployée');
        }
        return $out;
     }],

    ['cle' => 'lecteurs', 'titre' => 'Lecteurs réseau', 'icone' => '🗂️', 'page' => 'ad.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT letter,path,label,group_name FROM pf_drives WHERE letter LIKE ? OR path LIKE ? OR label LIKE ? OR group_name LIKE ? ORDER BY letter LIMIT $n");
        $st->execute([$l, $l, $l, $l]);
        $out = [];
        foreach ($st as $r) { $out[] = rg_item(((string) $r['letter']) . ': ' . ((string) $r['label']), (string) $r['path'], '/ad.php', (string) $r['group_name']); }
        return $out;
     }],

    // ── Intranet ─────────────────────────────────────────────────────────────
    ['cle' => 'pages_intranet', 'titre' => "Pages de l'intranet", 'icone' => '🏠', 'page' => 'cms.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT id,slug,title,body,published FROM pf_cms_pages WHERE title LIKE ? OR body LIKE ? OR slug LIKE ? ORDER BY menu_order,title LIMIT $n");
        $st->execute([$l, $l, $l]);
        $out = [];
        foreach ($st as $r) {
            $out[] = rg_item((string) $r['title'], rg_extrait((string) $r['body'], 120), '/cms.php',
                             ((int) $r['published'] === 1) ? 'publiée' : 'brouillon');
        }
        return $out;
     }],

    ['cle' => 'actus', 'titre' => 'Actualités', 'icone' => '📰', 'page' => 'cms.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT id,title,body,category,created_at,published FROM pf_cms_news WHERE title LIKE ? OR body LIKE ? OR category LIKE ? ORDER BY created_at DESC LIMIT $n");
        $st->execute([$l, $l, $l]);
        $out = [];
        foreach ($st as $r) {
            $out[] = rg_item((string) $r['title'],
                             substr((string) $r['created_at'], 0, 10) . ' — ' . rg_extrait((string) $r['body'], 100),
                             '/cms.php', (string) ($r['category'] ?: ''));
        }
        return $out;
     }],

    ['cle' => 'assistance', 'titre' => "Demandes d'assistance", 'icone' => '📨', 'page' => 'assistance.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT id,ts,username,subject,message,status FROM pf_support WHERE subject LIKE ? OR message LIKE ? OR username LIKE ? ORDER BY ts DESC LIMIT $n");
        $st->execute([$l, $l, $l]);
        $out = [];
        foreach ($st as $r) {
            $out[] = rg_item((string) $r['subject'],
                             ((string) $r['username']) . ' · ' . substr((string) $r['ts'], 0, 16) . ' — ' . rg_extrait((string) $r['message'], 90),
                             '/assistance.php', (string) $r['status']);
        }
        return $out;
     }],

    // ── Protection & traces ──────────────────────────────────────────────────
    ['cle' => 'bloques', 'titre' => 'Domaines bloqués', 'icone' => '⛔', 'page' => 'filter.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT domain,category,added_at FROM pf_blocklist WHERE domain LIKE ? OR category LIKE ? ORDER BY domain LIMIT $n");
        $st->execute([$l, $l]);
        $out = [];
        foreach ($st as $r) { $out[] = rg_item((string) $r['domain'], 'ajouté le ' . substr((string) $r['added_at'], 0, 10), '/filter.php', (string) ($r['category'] ?: 'liste noire')); }
        return $out;
     }],

    // Navigation : on AGRÈGE par domaine plutôt que de lister des milliers de lignes
    // identiques. Ce que l'administrateur veut savoir, c'est « qui est allé là, et
    // combien de fois » — pas la liste brute des requêtes.
    ['cle' => 'navigation', 'titre' => 'Historique de navigation', 'icone' => '🌐', 'page' => 'journal.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare(
            "SELECT domain, COUNT(*) AS n, COUNT(DISTINCT username) AS agents, MAX(ts) AS dernier
               FROM pf_weblog WHERE domain LIKE ? GROUP BY domain ORDER BY n DESC LIMIT $n");
        $st->execute([$l]);
        $out = [];
        foreach ($st as $r) {
            $out[] = rg_item((string) $r['domain'],
                             ((int) $r['n']) . ' visite(s) · ' . ((int) $r['agents']) . ' agent(s) · dernier le ' . substr((string) $r['dernier'], 0, 16),
                             '/weblog.php?q=' . rawurlencode((string) $r['domain']), 'navigation');
        }
        return $out;
     }],

    ['cle' => 'audit', 'titre' => "Journal d'audit", 'icone' => '📋', 'page' => 'journal.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT ts,admin,action,detail,ip FROM pf_audit WHERE action LIKE ? OR detail LIKE ? OR admin LIKE ? ORDER BY ts DESC LIMIT $n");
        $st->execute([$l, $l, $l]);
        $out = [];
        foreach ($st as $r) {
            $out[] = rg_item((string) $r['action'],
                             substr((string) $r['ts'], 0, 16) . ' · ' . ((string) $r['admin']) . ' — ' . rg_extrait((string) $r['detail'], 90),
                             '/journal.php', 'audit');
        }
        return $out;
     }],

    ['cle' => 'alertes', 'titre' => 'Alertes', 'icone' => '🚨', 'page' => 'securite.php',
     'requete' => function (PDO $db, string $l, int $n, string $t): array {
        $st = $db->prepare("SELECT sig,lvl,txt,opened_at,closed_at FROM pf_alerts WHERE txt LIKE ? OR sig LIKE ? ORDER BY opened_at DESC LIMIT $n");
        $st->execute([$l, $l]);
        $out = [];
        foreach ($st as $r) {
            $out[] = rg_item(rg_extrait((string) $r['txt'], 110),
                             'ouverte le ' . substr((string) $r['opened_at'], 0, 16) . ($r['closed_at'] ? ' · refermée' : ' · EN COURS'),
                             '/securite.php', (string) $r['lvl']);
        }
        return $out;
     }],

    ];
}
