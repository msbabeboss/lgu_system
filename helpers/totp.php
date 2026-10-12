<?php
/**
 * Minimal TOTP (RFC 6238) implementation — no Composer required.
 * Compatible with Google Authenticator, Authy, Microsoft Authenticator.
 */
class TOTP
{
    // Generate a random Base32 secret (160-bit = 32 chars)
    public static function generateSecret(): string
    {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    // Decode Base32 string to binary
    private static function base32Decode(string $secret): string
    {
        $map    = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $secret = strtoupper($secret);
        $bits   = '';
        foreach (str_split($secret) as $char) {
            if (!isset($map[$char])) continue;
            $bits .= str_pad(decbin($map[$char]), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }
        return $bytes;
    }

    // Generate a TOTP code for a given timestamp (default: now)
    public static function getCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter   = (int)floor($timestamp / 30);
        $key       = self::base32Decode($secret);
        $msg       = pack('N*', 0) . pack('N*', $counter);
        $hash      = hash_hmac('sha1', $msg, $key, true);
        $offset    = ord($hash[19]) & 0x0F;
        $code      = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
             (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    // Verify a code — checks current window ±1 (90-second tolerance)
    public static function verify(string $secret, string $code): bool
    {
        $code = trim($code);
        $now  = time();
        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals(self::getCode($secret, $now + $offset * 30), $code)) {
                return true;
            }
        }
        return false;
    }

    // Build a QR code URL using Google Charts API
    public static function getQRCodeUrl(string $secret, string $email, string $issuer = 'LGU System'): string
    {
        $issuer  = rawurlencode($issuer);
        $email   = rawurlencode($email);
        $uri     = "otpauth://totp/{$issuer}:{$email}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($uri);
    }
}
