<?php

declare(strict_types=1);

namespace Intrabase\Lang;

use Intrabase\Database\Connection;

/**
 * Multi-language Translation System
 *
 * Translations are stored in the `ib_lang` database table:
 *   - constant (string): unique key, e.g. 'SYS_USERS_TITLE'
 *   - ca, es, en, fr, de, pt (text): translated strings per locale
 *
 * Usage:
 *   $t = Translator::instance();
 *   echo $t->t('SYS_USERS_TITLE');      // Current locale
 *   echo $t->t('SYS_USERS_TITLE', 'en'); // Specific locale
 *
 * @package Intrabase\Lang
 */
class Translator
{
    private static ?self $instance = null;

    /** @var array<string, array<string, string>> */
    private array $cache = [];

    private string $locale = 'en';

    /** Supported locales */
    private const LOCALES = ['ca', 'es', 'en', 'fr', 'de', 'pt', 'it', 'eu'];

    private function __construct(
        private readonly Connection $db,
        string $locale = 'en'
    ) {
        $this->setLocale($locale);
        $this->loadAll();
    }

    public static function instance(Connection $db, string $locale = 'en'): self
    {
        if (self::$instance === null) {
            self::$instance = new self($db, $locale);
        }
        return self::$instance;
    }

    /**
     * Translate a constant key to the current (or specified) locale.
     *
     * @param  string  $constant The translation constant key
     * @param  ?string $locale   Override locale (null = use current)
     * @return string            Translated string, or the constant itself if not found
     */
    public function t(string $constant, ?string $locale = null): string
    {
        $locale = $locale ? $this->sanitizeLocale($locale) : $this->locale;

        return $this->cache[$constant][$locale]
            ?? $this->cache[$constant]['en']
            ?? $constant;
    }

    /**
     * Set the active locale.
     */
    public function setLocale(string $locale): void
    {
        $this->locale = $this->sanitizeLocale($locale);
    }

    /**
     * Get the active locale.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Get all available locales.
     *
     * @return string[]
     */
    public function locales(): array
    {
        return self::LOCALES;
    }

    /**
     * Check if a translation constant exists.
     */
    public function has(string $constant): bool
    {
        return isset($this->cache[$constant]);
    }

    /**
     * Reload translations from the database (e.g. after an update).
     */
    public function reload(): void
    {
        $this->cache = [];
        $this->loadAll();
    }

    /**
     * Upsert a translation constant in the database.
     *
     * @param  string               $constant
     * @param  array<string, string> $translations Locale => text
     */
    public function save(string $constant, array $translations): void
    {
        $constant = strtoupper(preg_replace('/[^A-Z0-9_]/', '_', strtoupper($constant)));

        $existing = $this->db->fetchOne(
            "SELECT id FROM ib_lang WHERE constant = :c",
            ['c' => $constant]
        );

        $allowedLocales = array_intersect_key($translations, array_flip(self::LOCALES));

        if ($existing) {
            $this->db->update('ib_lang', $allowedLocales, 'constant = :c', ['c' => $constant]);
        } else {
            $data = array_merge(['constant' => $constant], $allowedLocales);
            $this->db->insert('ib_lang', $data);
        }

        // Update cache
        if (!isset($this->cache[$constant])) {
            $this->cache[$constant] = [];
        }
        foreach ($allowedLocales as $locale => $text) {
            $this->cache[$constant][$locale] = $text;
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Load all translations into memory cache.
     */
    private function loadAll(): void
    {
        if (!$this->db->tableExists('ib_lang')) {
            return;
        }

        $rows = $this->db->fetchAll("SELECT * FROM `ib_lang`");

        foreach ($rows as $row) {
            $key = $row['constant'];
            foreach (self::LOCALES as $locale) {
                if (isset($row[$locale]) && $row[$locale] !== '') {
                    $this->cache[$key][$locale] = $row[$locale];
                }
            }
        }
    }

    private function sanitizeLocale(string $locale): string
    {
        $locale = strtolower(substr($locale, 0, 5));
        return in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }
}
