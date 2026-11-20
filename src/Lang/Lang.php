<?php

declare(strict_types=1);

namespace Intrabase\Lang;

/**
 * Static UI translation helper.
 *
 * Loads translation arrays from /lang/{locale}.php files.
 * Fallback chain: requested locale → English → key itself.
 *
 * Usage (after Lang::init()):
 *   Lang::get('save')              → 'Desar' (if locale=ca)
 *   Lang::get('too_many_attempts', [5]) → 'Massa intents fallits. Espera 5 minuts.'
 *
 * @package Intrabase\Lang
 */
final class Lang
{
    private static string $locale   = 'en';
    private static string $langDir  = '';

    /** @var array<string, string> */
    private static array $strings   = [];

    /** @var array<string, string> */
    private static array $fallback  = [];

    private static bool $initialized = false;

    /**
     * Initialize with a locale and the directory where lang files live.
     *
     * @param string $locale  e.g. 'ca', 'en', 'es'
     * @param string $langDir Absolute path to the /lang directory
     */
    public static function init(string $locale, string $langDir): void
    {
        self::$langDir     = rtrim($langDir, '/');
        self::$locale      = self::sanitize($locale);
        self::$strings     = self::load(self::$locale);
        self::$fallback    = self::$locale !== 'en' ? self::load('en') : [];
        self::$initialized = true;
    }

    /**
     * Get the active locale.
     */
    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * Translate a key. Supports sprintf-style placeholders via $params.
     *
     * @param  string  $key    Translation key (e.g. 'save', 'too_many_attempts')
     * @param  array<int|string, mixed> $params  Values for sprintf placeholders
     * @return string
     */
    public static function get(string $key, array $params = []): string
    {
        $text = self::$strings[$key]
             ?? self::$fallback[$key]
             ?? $key;

        if ($params !== []) {
            $text = vsprintf($text, $params);
        }

        return $text;
    }

    /**
     * Check whether a key exists in the current locale.
     */
    public static function has(string $key): bool
    {
        return isset(self::$strings[$key]) || isset(self::$fallback[$key]);
    }

    /**
     * Whether Lang has been initialized.
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    // -------------------------------------------------------------------------

    /**
     * Load a locale file and return its array (empty array on failure).
     *
     * @return array<string, string>
     */
    private static function load(string $locale): array
    {
        $file = self::$langDir . '/' . $locale . '.php';
        if (!is_file($file)) {
            return [];
        }
        $data = require $file;
        return is_array($data) ? $data : [];
    }

    /**
     * Sanitize locale to alphanumeric 2-5 chars.
     */
    private static function sanitize(string $locale): string
    {
        $locale = strtolower(preg_replace('/[^a-zA-Z]/', '', substr($locale, 0, 5)) ?? '');
        $valid  = ['en', 'ca', 'es', 'fr', 'de', 'pt', 'it', 'eu'];
        return in_array($locale, $valid, true) ? $locale : 'en';
    }
}
