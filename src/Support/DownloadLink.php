<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Core\Application;

/**
 * Signed links for /download.
 *
 * /download serves files from config('upload_dir'). A plain path parameter
 * would let any logged-in user fetch any file by guessing its path, also
 * another tenant's. So the views that show a file (lists, detail pages)
 * build the link here, signed with config('app_secret') over the tenant
 * database, the user and the path: the controller only serves a path the
 * application itself linked for that user.
 *
 * Without app_secret no link verifies (downloads fail closed).
 */
final class DownloadLink
{
    public static function url(string $relPath): string
    {
        return '/download?p=' . rawurlencode($relPath) . '&s=' . self::signature($relPath);
    }

    public static function verify(string $relPath, string $signature): bool
    {
        $expected = self::signature($relPath);
        return $expected !== '' && $signature !== '' && hash_equals($expected, $signature);
    }

    private static function signature(string $relPath): string
    {
        $app    = Application::getInstance();
        $secret = (string) ($app->getConfig()['app_secret'] ?? '');
        if ($secret === '') {
            return '';
        }
        $db     = (string) ($app->getTenantConfig()['db_name'] ?? $app->getConfig()['db_name'] ?? '');
        $userId = (int) ($app->getSession()->userId() ?? 0);
        return substr(hash_hmac('sha256', $db . "\0" . $userId . "\0" . $relPath, $secret), 0, 32);
    }
}
