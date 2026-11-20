<?php

declare(strict_types=1);

namespace Intrabase\Support;

/**
 * AssetResolver
 *
 * Resolves asset URLs to their minified versions using the build manifest.
 * Provides cache-busting via content hash in the manifest.
 *
 * Usage:
 *   AssetResolver::url('/assets/css/mail.css')
 *   // → /assets/css/mail.min.css?v=b7821393
 */
final class AssetResolver
{
    private static ?array $manifest = null;

    /**
     * Resolve an asset URL to its minified+versioned counterpart.
     */
    public static function url(string $url): string
    {
        $manifest = self::loadManifest();

        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return $url;
        }

        $basename = basename($path);
        $dir = dirname($path);

        // Look up in manifest (e.g. "mail.css" → "mail.min.css?v=b7821393")
        if (isset($manifest[$basename])) {
            return $dir . '/' . $manifest[$basename];
        }

        // Fallback: filemtime cache-busting
        return self::cacheBustFallback($url);
    }

    /**
     * Load the manifest.json (lazy, cached per request).
     */
    private static function loadManifest(): array
    {
        if (self::$manifest === null) {
            $path = __DIR__ . '/../../public/assets/manifest.json';
            if (file_exists($path)) {
                $data = json_decode(file_get_contents($path), true);
                self::$manifest = is_array($data) ? $data : [];
            } else {
                self::$manifest = [];
            }
        }
        return self::$manifest;
    }

    /**
     * Fallback cache-busting using file modification time.
     */
    private static function cacheBustFallback(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path) {
            $file = __DIR__ . '/../../public' . $path;
            if (file_exists($file)) {
                $sep = str_contains($url, '?') ? '&' : '?';
                return $url . $sep . 'v=' . filemtime($file);
            }
        }
        return $url;
    }
}
