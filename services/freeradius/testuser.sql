-- Bastion — utilisateur de test pour valider la Phase 1
-- Injecté dans la base RADIUS par provisioning/install.sh (envsubst).
-- Le mot de passe est stocké en clair ici UNIQUEMENT pour la démo ;
-- en production, utiliser un hash (Crypt-Password / SSHA) — traité en Phase 2.

-- Nettoyage idempotent
DELETE FROM radcheck WHERE username = '${TEST_USER}';
DELETE FROM radusergroup WHERE username = '${TEST_USER}';

-- Identifiant + mot de passe
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('${TEST_USER}', 'Cleartext-Password', ':=', '${TEST_PASS}');

-- Rattachement à un groupe par défaut (servira aux quotas — pilier 3)
INSERT INTO radusergroup (username, groupname, priority)
VALUES ('${TEST_USER}', 'default', 1);

-- Exemple d'attribut de réponse au niveau groupe : durée de session max (secondes).
-- (démonstration du mécanisme ; les vrais quotas arrivent en Phase 4)
DELETE FROM radgroupreply WHERE groupname = 'default' AND attribute = 'Session-Timeout';
INSERT INTO radgroupreply (groupname, attribute, op, value)
VALUES ('default', 'Session-Timeout', ':=', '3600');
