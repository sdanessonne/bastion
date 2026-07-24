#!/usr/bin/php
<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — ramasse-miettes des bons visiteur (proxyfibre-voucher-gc).
 * Lancé toutes les ~10 min par la minuterie systemd. À l'échéance (ou sur révocation),
 * SUPPRIME le compte du portail (radcheck) et COUPE la session en cours (ndsctl deauth).
 * Tourne en root : accès direct à ndsctl, DB via les identifiants de admin.env.
 */
$env = [];
foreach (@file('/etc/proxyfibre/admin.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    if (preg_match('/^(\w+)="?([^"]*)"?$/', $l, $m)) { $env[$m[1]] = $m[2]; }
}
try {
    $pdo = new PDO('mysql:host=localhost;dbname=' . ($env['DB_NAME'] ?? 'radius') . ';charset=utf8mb4',
        $env['DB_USER'] ?? 'radius', $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) { fwrite(STDERR, 'voucher-gc: db: ' . $e->getMessage() . "\n"); exit(1); }

// Table absente (fonctionnalité jamais utilisée) → rien à faire.
try { $pdo->query('SELECT 1 FROM pf_voucher LIMIT 1'); } catch (Throwable $e) { exit(0); }

// Bons échus ou révoqués QUI ONT ENCORE un compte portail (JOIN → borné, pas de retraitement).
$due = $pdo->query("SELECT v.username FROM pf_voucher v
    JOIN radcheck r ON r.username = v.username AND r.attribute = 'Cleartext-Password'
    WHERE v.revoked = 1 OR v.expires_at <= NOW()")->fetchAll(PDO::FETCH_COLUMN);
if (!$due) { exit(0); }

// Sessions en cours : IP par identifiant (l'agent est dans le champ custom : base64 « user=… »).
$byUser = [];
$raw = (string) shell_exec('/usr/bin/ndsctl json 2>/dev/null');
$j   = json_decode(preg_replace('/[[:cntrl:]]/', '', $raw), true);
if (is_array($j) && is_array($j['clients'] ?? null)) {
    foreach ($j['clients'] as $mac => $c) {
        if (!empty($c['custom']) && ($d = base64_decode($c['custom'], true)) && preg_match('/user=([^,]+)/', $d, $m)) {
            $byUser[$m[1]] = $c['ip'] ?? $mac;
        }
    }
}

$delR = $pdo->prepare('DELETE FROM radcheck WHERE username=?');
$delG = $pdo->prepare('DELETE FROM radusergroup WHERE username=?');
$aud  = $pdo->prepare("INSERT INTO pf_audit (admin,action,detail,ip) VALUES (NULL,'system.voucher_expired',?, 'cron')");
foreach ($due as $u) {
    if (isset($byUser[$u])) {
        shell_exec('/usr/bin/ndsctl deauth ' . escapeshellarg((string) $byUser[$u]) . ' 2>/dev/null');
    }
    $delR->execute([$u]);
    $delG->execute([$u]);
    try { $aud->execute([$u]); } catch (Throwable $e) {}
}
fwrite(STDOUT, count($due) . " bon(s) visiteur nettoyé(s)\n");
