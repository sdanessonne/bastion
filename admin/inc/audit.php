<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Journal d'audit des actions d'ADMINISTRATION.
 *
 * Bastion trace déjà les AGENTS (connexions, navigation). Ceci trace les ADMINISTRATEURS :
 * qui a créé/supprimé un compte, révoqué un jeton, désactivé une GPO, changé un mot de passe
 * système… Indispensable pour la responsabilité et un contrôle (RGPD, hiérarchie).
 *
 * On n'enregistre JAMAIS de secret (mot de passe, jeton) — seulement l'action et sa cible.
 */

function audit_migre(PDO $db): void
{
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS pf_audit (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            admin VARCHAR(64) DEFAULT NULL,
            action VARCHAR(64) NOT NULL,
            detail VARCHAR(255) DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            INDEX(ts), INDEX(admin))');
    } catch (Throwable $e) { /* droits insuffisants : l'audit reste silencieusement inactif */ }
}

/**
 * Consigne une action d'administration. Best-effort : ne fait jamais échouer l'action auditée.
 * @param string $action  identifiant court, ex. « users.delete », « ad.gpo_unlink ».
 * @param string $detail  cible/contexte NON sensible (nom de compte, GUID, libellé…).
 */
function audit(string $action, string $detail = ''): void
{
    try {
        $db = pf_db();
        static $migrated = false;
        if (!$migrated) { audit_migre($db); $migrated = true; }
        $db->prepare('INSERT INTO pf_audit (admin, action, detail, ip) VALUES (?,?,?,?)')
           ->execute([
               $_SESSION['admin'] ?? null,
               substr($action, 0, 64),
               ($detail !== '') ? substr($detail, 0, 255) : null,
               $_SERVER['REMOTE_ADDR'] ?? null,
           ]);
    } catch (Throwable $e) { /* jamais bloquant */ }
}
