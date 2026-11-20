<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * CipherComponent
 *
 * Encrypted field supporting AES-256-CBC, MD5, PLAIN and PROTECTED modes.
 *
 * options:
 *   type           — 'AES256', 'MD5', 'PLAIN', 'PROTECTED' (default: 'AES256')
 *   encryption_key — key for AES-256-CBC encryption/decryption (default: '')
 */
final class CipherComponent implements ComponentInterface
{
    private const CIPHER_METHOD = 'aes-256-cbc';

    public function render(string $field, mixed $value, array $options = []): string
    {
        $type = strtoupper($options['type'] ?? 'AES256');
        $name = Output::attr($field);
        $id   = 'field_' . Output::attr($field);
        $req  = !empty($options['required']) ? ' required' : '';
        $ro   = !empty($options['readonly']) ? ' readonly' : '';

        if ($type === 'MD5' || $type === 'PLAIN') {
            // Password input with show/hide toggle
            $val = ($type === 'PLAIN') ? Output::attr((string) $value) : '';
            $ph  = ($type === 'MD5') ? ' placeholder="Enter new value to update hash"' : '';

            return "<div class=\"ib-input-group\">"
                 . "<input type=\"password\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\""
                 . " class=\"ib-input ib-input--cipher\"{$ph}{$req}{$ro}>"
                 . "<button type=\"button\" class=\"ib-input-group__suffix ib-btn ib-btn--icon\" "
                 . "onclick=\"var i=document.getElementById('{$id}');i.type=i.type==='password'?'text':'password'\" "
                 . "title=\"Show/Hide\"><i class=\"fa-solid fa-eye\" aria-hidden=\"true\"></i></button>"
                 . "</div>";
        }

        if ($type === 'PROTECTED') {
            // Read-only masked display
            $masked = $value !== '' && $value !== null ? str_repeat('*', 12) : '';
            return "<div class=\"ib-input-group\">"
                 . "<input type=\"text\" name=\"{$name}\" id=\"{$id}\" value=\"" . Output::attr((string) $value) . "\""
                 . " class=\"ib-input ib-input--cipher\" style=\"-webkit-text-security:disc\"{$req}{$ro}>"
                 . "</div>";
        }

        // AES256 — text input showing decrypted value
        $decrypted = $this->decrypt((string) $value, $options['encryption_key'] ?? '');
        $val = Output::attr($decrypted);

        return "<div class=\"ib-input-group\">"
             . "<span class=\"ib-input-group__prefix\"><i class=\"fa-solid fa-lock\" aria-hidden=\"true\"></i></span>"
             . "<input type=\"text\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\""
             . " class=\"ib-input ib-input--cipher\"{$req}{$ro}>"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val  = (string) $value;
        if ($val === '') return '';

        $type = strtoupper($options['type'] ?? 'AES256');

        if ($type === 'MD5' || $type === 'PROTECTED') {
            return '<span class="ib-cipher--masked">' . str_repeat('&bull;', 8) . '</span>';
        }

        if ($type === 'AES256') {
            $decrypted = $this->decrypt($val, $options['encryption_key'] ?? '');
            return Output::e($decrypted);
        }

        // PLAIN
        return Output::e($val);
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str  = (string) $raw;
        $type = strtoupper($options['type'] ?? 'AES256');

        if ($type === 'MD5') {
            // Only hash if a new value was provided
            if ($str === '') return '';
            return md5($str);
        }

        if ($type === 'AES256') {
            if ($str === '') return '';
            return $this->encrypt($str, $options['encryption_key'] ?? '');
        }

        // PLAIN / PROTECTED — return raw
        return $str;
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $label = $options['label'] ?? $field;

        if ($required && trim((string) $value) === '') {
            return ["{$label} is required."];
        }
        return [];
    }

    /**
     * Encrypt a plaintext string using AES-256-CBC.
     */
    private function encrypt(string $plaintext, string $key): string
    {
        if ($plaintext === '' || $key === '') return $plaintext;

        $key    = hash('sha256', $key, true);
        $iv     = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::CIPHER_METHOD));
        $cipher = openssl_encrypt($plaintext, self::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) return '';

        return base64_encode($iv . $cipher);
    }

    /**
     * Decrypt an AES-256-CBC encrypted string.
     */
    private function decrypt(string $ciphertext, string $key): string
    {
        if ($ciphertext === '' || $key === '') return $ciphertext;

        $data = base64_decode($ciphertext, true);
        if ($data === false) return $ciphertext;

        $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
        if (strlen($data) <= $ivLength) return $ciphertext;

        $iv     = substr($data, 0, $ivLength);
        $cipher = substr($data, $ivLength);
        $key    = hash('sha256', $key, true);

        $decrypted = openssl_decrypt($cipher, self::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false ? $decrypted : $ciphertext;
    }
}
