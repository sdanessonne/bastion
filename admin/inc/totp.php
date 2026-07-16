<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — TOTP (RFC 6238) en PHP pur, sans dépendance externe.
 * Sert à la double authentification (2FA) des administrateurs de la console.
 */
declare(strict_types=1);

/** Génère un secret aléatoire encodé en base32 (par défaut 20 octets = 160 bits). */
function totp_gen_secret(int $bytes = 20): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $raw = random_bytes($bytes);
    $out = '';
    $buffer = 0; $bits = 0;
    for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
        $buffer = ($buffer << 8) | ord($raw[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= $alphabet[($buffer >> $bits) & 31];
        }
    }
    if ($bits > 0) { $out .= $alphabet[($buffer << (5 - $bits)) & 31]; }
    return $out;
}

/** Décode une chaîne base32 (RFC 4648, insensible à la casse, espaces ignorés). */
function totp_base32_decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $buffer = 0; $bits = 0; $out = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $val = strpos($alphabet, $b32[$i]);
        if ($val === false) { continue; }
        $buffer = ($buffer << 5) | $val;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($buffer >> $bits) & 0xFF);
        }
    }
    return $out;
}

/** Calcule le code TOTP à 6 chiffres pour un secret base32 et un compteur de fenêtre. */
function totp_code(string $secret, int $counter, int $digits = 6): string {
    $key = totp_base32_decode($secret);
    // Compteur sur 8 octets big-endian.
    $bin = pack('N*', 0, $counter);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = (ord($hash[$offset]) & 0x7F) << 24
          | (ord($hash[$offset + 1]) & 0xFF) << 16
          | (ord($hash[$offset + 2]) & 0xFF) << 8
          | (ord($hash[$offset + 3]) & 0xFF);
    $mod = 10 ** $digits;
    return str_pad((string) ($part % $mod), $digits, '0', STR_PAD_LEFT);
}

/**
 * Vérifie un code saisi contre le secret, avec tolérance de dérive d'horloge
 * (±$window fenêtres de 30 s). Comparaison à temps constant.
 */
function totp_verify(string $secret, string $input, int $window = 1, int $period = 30): bool {
    $input = preg_replace('/\D/', '', $input);
    if (strlen($input) < 6) { return false; }
    $now = intdiv(time(), $period);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $now + $i), $input)) { return true; }
    }
    return false;
}

/** Construit l'URI otpauth:// à encoder dans le QR code d'un authentificateur. */
function totp_uri(string $secret, string $account, string $issuer = 'Bastion'): string {
    return sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        rawurlencode($issuer), rawurlencode($account), $secret, rawurlencode($issuer)
    );
}
