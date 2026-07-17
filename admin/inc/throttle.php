<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Limitation des tentatives d'authentification.
 *
 * La console de connexion n'en avait AUCUNE : un script pouvait deviner un mot de passe
 * en boucle sans jamais être ralenti. Ce module ferme cela, avec les mêmes précautions
 * que l'action station.auth (dont un audit adversarial a révélé les deux pièges) :
 *
 *  1) ATOMICITÉ. Compter les échecs puis enregistrer le nouveau n'est pas atomique :
 *     entre les deux se glisse le bcrypt (~370 ms). Une rafale concurrente lit toutes
 *     « compte < seuil » avant que le premier échec ne soit écrit, et franchit le plafond
 *     en masse. On sérialise donc « compter + réserver » sous un verrou nommé MySQL, et la
 *     tentative est RÉSERVÉE (ligne ok=0) AVANT le bcrypt — la suivante la voit déjà.
 *
 *  2) DOUBLE PLAFOND. Un plafond par IP seul se contourne en faisant tourner ses adresses
 *     sources (trivial sur un LAN). On plafonne AUSSI par compte cible, indépendamment de
 *     l'IP.
 *
 * Le verrou n'est tenu que le temps du comptage et de la réservation (quelques
 * millisecondes) ; le bcrypt se fait hors verrou. La connexion PDO n'étant pas
 * persistante, le verrou est de toute façon relâché en fin de script.
 */

/**
 * Ouvre une tentative : vérifie les plafonds et réserve une ligne.
 *
 * @return array{ok:bool, id:?int, retry:bool, msg:string}
 *   ok=true                → autorisé à poursuivre ; « id » identifie la ligne à finaliser
 *   ok=false, retry=true   → plafond atteint ; « msg » à afficher
 *   ok=false, retry=false  → journal indisponible ; on REFUSE (fail-closed), « msg » à afficher
 */
function throttle_begin(PDO $db, string $ip, string $user, int $maxIp = 10, int $maxUser = 25): array
{
    $verrou = 'pf_login_attempts';
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS pf_login_attempts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, ts DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT \'\', username VARCHAR(64) NOT NULL DEFAULT \'\',
            ok TINYINT NOT NULL DEFAULT 0, INDEX(ts), INDEX(ip), INDEX(username))');

        $lk = $db->prepare('SELECT GET_LOCK(?, 5)');
        $lk->execute([$verrou]);
        if ((int) $lk->fetchColumn() !== 1) {
            return ['ok' => false, 'id' => null, 'retry' => false, 'msg' => 'Service occupé, réessayez dans un instant.'];
        }

        $st = $db->prepare('SELECT
            COALESCE(SUM(username = ?), 0) AS par_compte,
            COALESCE(SUM(ip = ?), 0)       AS par_ip
            FROM pf_login_attempts
            WHERE ok = 0 AND ts > NOW() - INTERVAL 15 MINUTE');
        $st->execute([$user, $ip]);
        $r = $st->fetch();

        if ((int) $r['par_compte'] >= $maxUser || (int) $r['par_ip'] >= $maxIp) {
            // ok=2 : un blocage n'est ni un succès ni un échec de mot de passe. Il NE compte
            // PAS dans le plafond (WHERE ok=0), sinon marteler la porte prolongerait la
            // fenêtre de 15 min sans fin.
            $db->prepare('INSERT INTO pf_login_attempts (ts,ip,username,ok) VALUES (NOW(),?,?,2)')
               ->execute([$ip, $user]);
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$verrou]);
            return ['ok' => false, 'id' => null, 'retry' => true,
                    'msg' => 'Trop de tentatives. Patientez 15 minutes avant de réessayer.'];
        }

        // Réservation : la tentative compte dès maintenant, avant le bcrypt. C'est ce qui
        // ferme le TOCTOU. Finalisée en succès (ok=1) ou laissée en échec (ok=0).
        $db->prepare('INSERT INTO pf_login_attempts (ts,ip,username,ok) VALUES (NOW(),?,?,0)')
           ->execute([$ip, $user]);
        $id = (int) $db->lastInsertId();
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$verrou]);
        return ['ok' => true, 'id' => $id, 'retry' => false, 'msg' => ''];
    } catch (Throwable $e) {
        try { $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$verrou]); } catch (Throwable $e2) {}
        // Le comptage s'appuie sur cette table : si elle est indisponible, on ne peut pas
        // garantir la limite. On refuse plutôt que d'ouvrir la console sans frein.
        return ['ok' => false, 'id' => null, 'retry' => false,
                'msg' => 'Service d\'authentification momentanément indisponible.'];
    }
}

/** Finalise une tentative réservée : succès (ne compte plus) ou échec (continue de compter). */
function throttle_finish(PDO $db, ?int $id, bool $ok): void
{
    if (!$id) { return; }
    try {
        $db->prepare('UPDATE pf_login_attempts SET ok = ? WHERE id = ?')->execute([$ok ? 1 : 0, $id]);
    } catch (Throwable $e) { /* la ligne reste ok=0 : au pire elle compte, jamais l'inverse */ }
}
