<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — identité de l'agent qui demande la page, en JSON.
 *
 * ── POURQUOI CE POINT D'ACCÈS EXISTE ─────────────────────────────────────────
 * L'en-tête de l'intranet affichait le nom et la photo de l'agent, écrits par le
 * serveur DANS LE HTML de chaque page. Or ces pages sont conservées par le
 * service worker pour la lecture hors ligne. Le nom partait donc dans le cache
 * avec elles, et sur un téléphone de service PARTAGÉ l'agent suivant pouvait
 * ouvrir l'intranet et y lire le nom du précédent.
 *
 * L'exclusion posée précédemment visait « account.php » et « photo.php ». Elle ne
 * suffisait pas : l'identité ne se trouvait pas seulement sur ces deux pages, elle
 * était dans l'EN-TÊTE COMMUN à toutes.
 *
 * La correction consiste à sortir l'identité du HTML. Les pages deviennent
 * identiques pour tous les agents — donc conservables sans risque — et le nom
 * est demandé séparément à ce point d'accès, qui n'est jamais mis en cache.
 * Hors ligne, aucun nom ne s'affiche : c'est le comportement voulu, on ne sait
 * pas qui tient l'appareil.
 */
require_once __DIR__ . '/https_guard.php';
require_once __DIR__ . '/intranet/_common.php';

$u = intranet_user();

// « no-store » et non « no-cache » : le second autorise la conservation à condition
// de revalider, ce qui laisserait la réponse sur le disque de l'appareil.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

echo json_encode([
    'auth'    => (bool) $u['auth'],
    // Strictement ce que l'en-tête et le bandeau d'accueil affichent. Les quotas,
    // la consommation et l'historique restent sur « account.php » : ce point
    // d'accès n'a pas à devenir un second tableau de bord.
    'complet' => $u['auth'] ? (string) $u['complet'] : '',
    'photo'   => $u['auth'] ? (string) $u['photo'] : '',
    // Le bandeau d'accueil affiche le prénom seul, et le matricule.
    'prenom'  => $u['auth'] ? (string) $u['affiche'] : '',
    'user'    => $u['auth'] ? (string) $u['user'] : '',
], JSON_UNESCAPED_UNICODE);
