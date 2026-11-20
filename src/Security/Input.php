<?php

declare(strict_types=1);

namespace Intrabase\Security;

/**
 * Input Sanitization & Validation
 *
 * Centralizes all user-input handling:
 * - GET/POST parameter access with type coercion
 * - String, date, HTML and email sanitization
 * - Common validation rules
 *
 * NEVER pass raw $_GET/$_POST to SQL queries.
 * Always use these helpers or PDO bound parameters.
 *
 * @package Intrabase\Security
 */
class Input
{
    // =========================================================================
    // Request parameter accessors
    // =========================================================================

    /**
     * Get a string from $_GET, stripped of tags.
     */
    public static function get(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? $default;
        return self::sanitizeString((string) $value);
    }

    /**
     * Get a string from $_GET preserving leading/trailing whitespace.
     * Use this for fields where exact value matters (e.g. IMAP folder names,
     * which can legitimately contain trailing/leading spaces). Still strips
     * HTML tags to keep the XSS guarantee that callers expect.
     */
    public static function getRaw(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? $default;
        return strip_tags((string) $value);
    }

    /**
     * Get an integer from $_GET.
     */
    public static function getInt(string $key, int $default = 0): int
    {
        return filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT) ?: $default;
    }

    /**
     * Get a string from $_POST, stripped of tags.
     */
    public static function post(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $default;
        return self::sanitizeString((string) $value);
    }

    /**
     * Get an integer from $_POST.
     */
    public static function postInt(string $key, int $default = 0): int
    {
        return filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT) ?: $default;
    }

    /**
     * Get a float from $_POST.
     */
    public static function postFloat(string $key, float $default = 0.0): float
    {
        return (float) (filter_input(INPUT_POST, $key, FILTER_VALIDATE_FLOAT) ?: $default);
    }

    /**
     * Get a boolean from $_POST (true if value is '1', 'true', 'on', 'yes').
     */
    public static function postBool(string $key, bool $default = false): bool
    {
        if (!isset($_POST[$key])) {
            return $default;
        }
        return filter_var($_POST[$key], FILTER_VALIDATE_BOOLEAN) ?? $default;
    }

    /**
     * Get all POST values for a multi-select field (array).
     *
     * @return string[]
     */
    public static function postArray(string $key): array
    {
        $values = $_POST[$key] ?? [];
        if (!is_array($values)) {
            return [];
        }
        return array_map(fn($v) => self::sanitizeString((string) $v), $values);
    }

    // =========================================================================
    // Sanitizers
    // =========================================================================

    /**
     * Strip tags and trim a string.
     * Preserves internal whitespace (does NOT collapse).
     */
    public static function sanitizeString(string $value): string
    {
        return trim(strip_tags($value));
    }

    /**
     * Sanitize rich HTML content (from a WYSIWYG editor).
     *
     * Uses a whitelist of safe tags. Strips dangerous attributes and
     * protocols. In a production environment, consider using HTMLPurifier.
     */
    public static function sanitizeHtml(string $html): string
    {
        // Allowed tags (safe subset). <i>/<b> alongside <em>/<strong>: Google
        // Calendar's own description editor (and many other sources pasted in
        // as rich text) emits the legacy tags, not the semantic ones — without
        // them, a synced description like "<i><u>Agenda:</u></i>" loses its
        // italics here even though the tag is perfectly safe.
        $allowed = '<p><br><strong><em><i><b><u><s><ul><ol><li><blockquote>'
                 . '<h1><h2><h3><h4><h5><h6><pre><code><a><img><table>'
                 . '<thead><tbody><tr><th><td><span><div>';

        $html = strip_tags($html, $allowed);

        // Decode HTML entities until stable, to catch encoded (and double
        // encoded: &amp;#x09;) bypass attempts, then re-strip tags.
        for ($i = 0; $i < 5; $i++) {
            $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $html) {
                break;
            }
            $html = strip_tags($decoded, $allowed);
        }

        // Remove ALL event handler attributes (on*=) — covers any quoting style or no quotes
        $html = preg_replace('/\bon\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html);

        // Remove dangerous URI schemes (javascript:, vbscript:, data:, blob:).
        // Browsers ignore whitespace and control characters anywhere in a
        // scheme (jav<TAB>ascript:), so any of them may sit between letters.
        $gap = '[\\x00-\\x20]*';
        $schemes = array_map(
            static fn(string $w): string => implode($gap, str_split($w)),
            ['javascript', 'vbscript', 'data', 'blob']
        );
        $html = preg_replace('/(?<![a-z0-9])(?:' . implode('|', $schemes) . ')' . $gap . ':/i', 'blocked:', $html);

        // Remove style attributes containing expression() or url() with dangerous schemes
        $html = preg_replace('/\bstyle\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', $html);

        return $html;
    }

    /**
     * Sanitize a date string to YYYY-MM-DD format.
     * Returns empty string if invalid.
     */
    public static function sanitizeDate(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return $value;
            }
        }
        return '';
    }

    /**
     * Sanitize a datetime string to YYYY-MM-DD HH:MM:SS format.
     * Accepts both DB format ("YYYY-MM-DD HH:MM:SS") and
     * HTML datetime-local format ("YYYY-MM-DDTHH:MM" or "YYYY-MM-DDTHH:MM:SS").
     * Returns empty string if invalid.
     */
    public static function sanitizeDatetime(string $value): string
    {
        $value = trim($value);
        // Normalize HTML datetime-local "T" separator to space
        $value = str_replace('T', ' ', $value);
        // Accept HH:MM (no seconds) by appending :00
        if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', $value, $m)) {
            $value = $m[1] . ' ' . $m[2] . ':00';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s(\d{2}):(\d{2}):(\d{2})$/', $value, $m)) {
            if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return $value;
            }
        }
        return '';
    }

    // =========================================================================
    // Validators
    // =========================================================================

    /**
     * Validate an email address.
     */
    public static function isEmail(string $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Validate a URL (http/https only).
     */
    public static function isUrl(string $value): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }
        return in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    /**
     * Validate that a string is within given length bounds.
     */
    public static function withinLength(string $value, int $min = 0, int $max = PHP_INT_MAX): bool
    {
        $len = mb_strlen($value, 'UTF-8');
        return $len >= $min && $len <= $max;
    }

    /**
     * Validate that a value is a positive integer.
     */
    public static function isPositiveInt(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    // =========================================================================
    // Bulk validation
    // =========================================================================

    /**
     * Validate a set of POST fields against a rule map.
     *
     * Rules supported:
     *   'required'  - field must not be empty
     *   'email'     - must be a valid email
     *   'url'       - must be a valid http/https URL
     *   'int'       - must be an integer
     *   'positive'  - must be a positive integer
     *   'max:N'     - string length must be <= N
     *   'min:N'     - string length must be >= N
     *
     * @param  array<string, string[]> $rules   e.g. ['name' => ['required', 'max:255']]
     * @return array<string, string[]>           Errors keyed by field name
     */
    public static function validate(array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $_POST[$field] ?? '';

            foreach ($fieldRules as $rule) {
                if ($rule === 'required' && trim((string) $value) === '') {
                    $errors[$field][] = 'This field is required.';
                    break; // Skip further checks if empty
                }

                if ($rule === 'email' && $value !== '' && !self::isEmail((string) $value)) {
                    $errors[$field][] = 'Must be a valid email address.';
                }

                if ($rule === 'url' && $value !== '' && !self::isUrl((string) $value)) {
                    $errors[$field][] = 'Must be a valid URL (http/https).';
                }

                if ($rule === 'int' && $value !== '' && !filter_var($value, FILTER_VALIDATE_INT)) {
                    $errors[$field][] = 'Must be an integer.';
                }

                if ($rule === 'positive' && $value !== '' && !self::isPositiveInt($value)) {
                    $errors[$field][] = 'Must be a positive integer.';
                }

                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (!self::withinLength((string) $value, max: $max)) {
                        $errors[$field][] = "Maximum {$max} characters allowed.";
                    }
                }

                if (str_starts_with($rule, 'min:')) {
                    $min = (int) substr($rule, 4);
                    if (!self::withinLength((string) $value, min: $min)) {
                        $errors[$field][] = "Minimum {$min} characters required.";
                    }
                }
            }
        }

        return $errors;
    }
}
