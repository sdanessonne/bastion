<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — message de test, envoyé avec LE MÊME MODÈLE que les alertes réelles.
 *
 * ── POURQUOI PAS UN MESSAGE ÉCRIT À LA MAIN ──────────────────────────────────
 * Un test qui fabrique son propre message ne prouve que sa propre existence. Si
 * le modèle des alertes contenait une erreur — un en-tête MIME mal formé, un
 * accent mal encodé, une partie HTML tronquée — le test passerait au vert et les
 * vraies alertes arriveraient illisibles. On teste donc ce qui part vraiment.
 *
 * Lancé par « proxyfibre-mail test », donc EN ROOT : /etc/msmtprc contient le
 * mot de passe du relais et reste en 600 root:root. Un envoi depuis la console,
 * servie par www-data, échouerait sur « aucun fichier de configuration ».
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Réservé à la ligne de commande.\n"); }

require_once __DIR__ . '/mailer.php';

$dest = (string) ($argv[1] ?? '');
if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ECHEC: adresse invalide\n");
    exit(2);
}

$hote = trim((string) shell_exec('hostname 2>/dev/null')) ?: 'bastion';

$ok = pf_mail_notif(
    $dest,
    'ok',
    "Test d'envoi réussi",
    "Ce message a été émis depuis la console d'administration pour vérifier la chaîne de "
    . "notification. Il utilise exactement la mise en forme des alertes réelles : si celui-ci "
    . "vous parvient lisible, les alertes de surveillance vous parviendront de la même façon.",
    [
        'Passerelle'  => $hote,
        'Émis le'     => date('d/m/Y à H:i:s'),
        'Nature'      => 'Test manuel — aucune anomalie en cours',
    ],
    "Aucune action n'est attendue. Si ce message était inattendu, quelqu'un a utilisé le bouton "
    . "de test de la console : l'action figure au journal d'audit avec son auteur."
);

if ($ok) {
    echo "OK: message remis au relais pour {$dest}\n";
    exit(0);
}
// Le détail utile est dans le journal de msmtp : l'y renvoyer évite de laisser
// l'administrateur devant un « échec » sans piste.
fwrite(STDERR, "ECHEC: le relais a refuse le message. Detail : /var/log/msmtp.log\n");
exit(1);
