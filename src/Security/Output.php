<?php

declare(strict_types=1);

namespace Intrabase\Security;

/**
 * Output Encoding / Escaping Helpers
 *
 * ALWAYS escape values before rendering them in HTML.
 * These helpers prevent Cross-Site Scripting (XSS) attacks.
 *
 * Usage in templates:
 *   <?= Output::e($userSuppliedValue) ?>      <!-- for general HTML context -->
 *   <?= Output::attr($value) ?>               <!-- for HTML attribute values -->
 *   <?= Output::js($value) ?>                 <!-- inside <script> tags -->
 *   <?= Output::url($value) ?>                <!-- for href/src attributes -->
 *
 * @package Intrabase\Security
 */
class Output
{
    /**
     * Escape a value for safe output in an HTML text context.
     *
     * Use this for the vast majority of outputs.
     *
     * @param  mixed  $value Any scalar value; objects are cast to string
     * @return string        HTML-safe string
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape a value for use inside an HTML attribute (already inside quotes).
     *
     * Example: <input value="<?= Output::attr($val) ?>">
     */
    public static function attr(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Encode a value as a JSON-safe JavaScript literal.
     *
     * Safe to embed directly in a <script> tag or inline event handler.
     *
     * @param  mixed  $value Any JSON-serializable value
     * @return string        JSON-encoded string, safe for inline JS
     */
    public static function js(mixed $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    /**
     * Validate and return a safe URL for use in href/src attributes.
     *
     * Only http/https/mailto schemes are allowed.
     * Returns '#' if the URL is unsafe or invalid.
     */
    public static function url(string $url): string
    {
        $url = trim($url);

        // Relative URLs are safe
        if (str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
            return self::attr($url);
        }

        // Check scheme whitelist
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return '#';
        }

        return self::attr($url);
    }

    /**
     * Render a value as a plain-text snippet (strips all HTML tags).
     *
     * Useful for email bodies, notifications, tooltips, etc.
     */
    public static function text(string $html): string
    {
        return strip_tags($html);
    }

    /**
     * Truncate a string to $maxLength chars and append $suffix.
     *
     * Output is HTML-escaped.
     *
     * @param  string $value
     * @param  int    $maxLength
     * @param  string $suffix
     * @return string
     */
    public static function truncate(string $value, int $maxLength = 100, string $suffix = '…'): string
    {
        if (mb_strlen($value, 'UTF-8') <= $maxLength) {
            return self::e($value);
        }

        return self::e(mb_substr($value, 0, $maxLength, 'UTF-8')) . self::e($suffix);
    }

    /**
     * Format a numeric value as currency.
     *
     * @param  float  $amount
     * @param  string $symbol   Currency symbol
     * @param  string $locale   PHP locale for number formatting
     * @return string           HTML-safe formatted string
     */
    /**
     * Badge d'estat amb l'estil del KMS (kms-styles.css .kms-status-badge):
     * <span class="kms-status-badge kms-status-{valor}">{etiqueta}</span>.
     * Ho fan servir DataBrowser i DataDetailer per al camp `status`.
     */
    public static function kmsStatusBadge(string $value, string $label): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9_-]+/i', '-', $value) ?? '', '-'));
        return '<span class="kms-status-badge kms-status-' . self::attr($slug) . '">' . self::e($label) . '</span>';
    }

    public static function money(float $amount, string $symbol = '€', string $locale = 'ca_ES'): string
    {
        $formatted = number_format($amount, 2, ',', '.');
        return self::e($formatted . ' ' . $symbol);
    }

    /**
     * Format a date string for display.
     *
     * @param  string $date   ISO date string (YYYY-MM-DD or datetime)
     * @param  string $format PHP date() format string
     * @return string         HTML-safe formatted date or empty string if invalid
     */
    public static function date(string $date, string $format = 'd/m/Y'): string
    {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '';
        }

        try {
            $dt = new \DateTimeImmutable($date);
            return self::e($dt->format($format));
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * Render a boolean value as a colored badge.
     *
     * @param  mixed  $value    Truthy/falsy
     * @param  string $trueText
     * @param  string $falseText
     * @return string           Safe HTML badge
     */
    public static function badge(mixed $value, string $trueText = 'Yes', string $falseText = 'No'): string
    {
        if ($value) {
            return '<span class="ib-badge ib-badge--success">' . self::e($trueText) . '</span>';
        }
        return '<span class="ib-badge ib-badge--muted">' . self::e($falseText) . '</span>';
    }
}
