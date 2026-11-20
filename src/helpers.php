<?php

declare(strict_types=1);

/**
 * Global helper functions for Intrabase.
 * Auto-loaded via Composer (see composer.json: autoload.files).
 */

use Intrabase\Lang\Lang;

if (!function_exists('__')) {
    /**
     * Translate a UI string key.
     *
     * @param  string $key    Translation key, e.g. 'save', 'confirm_delete'
     * @param  mixed  ...$params  Optional sprintf parameters
     * @return string
     */
    function __(string $key, mixed ...$params): string
    {
        return Lang::get($key, $params);
    }
}
