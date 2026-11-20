<?php

declare(strict_types=1);

namespace Intrabase\Security;

/**
 * Crypto — symmetric encryption for sensitive at-rest data.
 *
 * Used by the connectors framework to encrypt OAuth tokens and other secrets.
 * AES-256-GCM (authenticated encryption) with a random nonce per ciphertext.
 *
 * The encryption key is derived from config('app_secret') via HKDF.
 * Rotation requires re-encrypting all stored ciphertexts.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_LEN = 32;
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;

    /** @var string raw 32-byte key */
    private readonly string $key;

    public function __construct(string $secret)
    {
        if ($secret === '' || $secret === 'REPLACE_WITH_SECRET') {
            throw new \RuntimeException('Crypto: app_secret is not configured');
        }
        // HKDF-SHA256 derive a 32-byte key. The salt is fixed so the same secret
        // always yields the same key (deterministic for at-rest decryption).
        $this->key = hash_hkdf('sha256', $secret, self::KEY_LEN, 'intrabase:crypto:v1', '');
    }

    /**
     * Encrypt a UTF-8 string. Returns base64(nonce || tag || ciphertext).
     * Empty input returns empty string.
     */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ct = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LEN,
        );
        if ($ct === false) {
            throw new \RuntimeException('Crypto: encryption failed');
        }
        return base64_encode($nonce . $tag . $ct);
    }

    /**
     * Decrypt a base64(nonce || tag || ciphertext). Returns the plaintext, or
     * empty string when input is empty. Throws on tampering / bad input.
     */
    public function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < self::NONCE_LEN + self::TAG_LEN) {
            throw new \RuntimeException('Crypto: malformed ciphertext');
        }
        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag   = substr($raw, self::NONCE_LEN, self::TAG_LEN);
        $ct    = substr($raw, self::NONCE_LEN + self::TAG_LEN);
        $pt = openssl_decrypt($ct, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($pt === false) {
            throw new \RuntimeException('Crypto: decryption failed (tampered or wrong key)');
        }
        return $pt;
    }

    /**
     * Build a Crypto instance from the application config array.
     */
    public static function fromConfig(array $config): self
    {
        return new self((string)($config['app_secret'] ?? ''));
    }
}
