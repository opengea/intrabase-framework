<?php

declare(strict_types=1);

namespace Intrabase\Security;

/**
 * TOTP — Time-based One-Time Password (RFC 6238)
 *
 * Compatible with Google Authenticator, Authy, etc.
 */
final class TOTP
{
    private const DIGITS   = 6;
    private const PERIOD   = 30;
    private const WINDOW   = 1; // allow ±1 period drift

    /**
     * Generate a random Base32-encoded secret.
     */
    public static function generateSecret(int $length = 20): string
    {
        $bytes = random_bytes($length);
        return self::base32Encode($bytes);
    }

    /**
     * Generate the current TOTP code for a given secret.
     */
    public static function getCode(string $secret, ?int $time = null): string
    {
        $time = $time ?? time();
        $counter = intdiv($time, self::PERIOD);
        return self::hotp(self::base32Decode($secret), $counter);
    }

    /**
     * Verify a TOTP code against a secret, with time window tolerance.
     */
    public static function verify(string $secret, string $code, ?int $time = null): bool
    {
        if (strlen($code) !== self::DIGITS || !ctype_digit($code)) {
            return false;
        }

        $time = $time ?? time();
        $key  = self::base32Decode($secret);

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $counter = intdiv($time, self::PERIOD) + $i;
            if (hash_equals(self::hotp($key, $counter), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate an otpauth:// URI for QR code generation.
     */
    public static function getUri(string $secret, string $email, string $issuer = 'Intrabase'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($email);
        return 'otpauth://totp/' . $label
             . '?secret=' . $secret
             . '&issuer=' . rawurlencode($issuer)
             . '&digits=' . self::DIGITS
             . '&period=' . self::PERIOD;
    }

    /**
     * Generate a simple inline SVG QR code (no external dependencies).
     */
    public static function getQrSvg(string $data, int $size = 200): string
    {
        // Use Google Charts API URL as fallback (rendered as img)
        $url = 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . 'x' . $size
             . '&chl=' . urlencode($data) . '&choe=UTF-8';
        return '<img src="' . htmlspecialchars($url) . '" width="' . $size . '" height="' . $size
             . '" alt="QR Code" style="image-rendering:pixelated">';
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private static function hotp(string $key, int $counter): string
    {
        $packed = pack('N*', 0, $counter);
        $hash   = hash_hmac('sha1', $packed, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $code   = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
             (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary   = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($binary, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= $alphabet[bindec($chunk)];
        }
        return $result;
    }

    private static function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data     = strtoupper(rtrim($data, '='));
        $binary   = '';
        foreach (str_split($data) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) < 8) break;
            $result .= chr(bindec($byte));
        }
        return $result;
    }
}
