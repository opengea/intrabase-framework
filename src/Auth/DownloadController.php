<?php

declare(strict_types=1);

namespace Intrabase\Auth;

use Intrabase\Core\Application;
use Intrabase\Security\Input;

/**
 * DownloadController — Authenticated file download handler
 *
 * Serves files stored in /var/intrabase/uploads/ with authentication.
 * Files are NOT directly web-accessible; all access goes through here.
 *
 * URL: /download?p=files/abc123.pdf
 */
class DownloadController
{
    public function handle(): void
    {
        $app = Application::getInstance();

        // Authentication required
        if (!$app->getSession()->isAuthenticated()) {
            http_response_code(401);
            exit('Unauthorized');
        }

        $relPath = Input::get('p');

        if (empty($relPath)) {
            http_response_code(400);
            exit('Bad Request: missing file parameter');
        }

        // Only paths the application linked for this user (DownloadLink): a
        // guessed path, or one linked to another user or tenant, is refused.
        if (!\Intrabase\Support\DownloadLink::verify((string) $relPath, (string) Input::get('s'))) {
            http_response_code(403);
            exit('Forbidden');
        }

        // Sanitize path: only alphanumeric, hyphens, underscores, dots, forward slashes
        // Explicitly reject '..' traversal and absolute paths
        if (
            !preg_match('#^[a-zA-Z0-9_/.\-]+$#', $relPath) ||
            str_contains($relPath, '..') ||
            str_starts_with($relPath, '/')
        ) {
            http_response_code(403);
            exit('Forbidden');
        }

        $config    = $app->getConfig();
        $uploadDir = rtrim($config['upload_dir'] ?? '/var/intrabase/uploads', '/');
        $fullPath  = $uploadDir . '/' . $relPath;

        // Reject symlinks before resolving — prevents symlink-based path traversal
        if (is_link($fullPath)) {
            http_response_code(403);
            exit('Forbidden');
        }

        // Resolve real path and verify it's inside the upload directory
        $realUploadDir = realpath($uploadDir);
        $realFullPath  = realpath($fullPath);

        if (
            $realFullPath === false ||
            $realUploadDir === false ||
            !str_starts_with($realFullPath, $realUploadDir . DIRECTORY_SEPARATOR)
        ) {
            http_response_code(404);
            exit('File not found');
        }

        // Double-check: reject if any component in the resolved path is a symlink
        if ($realFullPath !== $fullPath && is_link($fullPath)) {
            http_response_code(403);
            exit('Forbidden');
        }

        if (!is_file($realFullPath)) {
            http_response_code(404);
            exit('File not found');
        }

        // Detect MIME via filesystem — do NOT trust client-supplied type
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mime     = $finfo->file($realFullPath) ?: 'application/octet-stream';
        $filename = basename($realFullPath);
        $size     = filesize($realFullPath);

        // Disallow execution-prone and dangerous MIME types
        $blockedMimes = [
            'text/html', 'text/xml', 'application/xhtml+xml',
            'application/x-php', 'application/x-httpd-php',
            'text/x-php', 'text/x-python', 'text/x-perl',
            'text/x-shellscript', 'application/x-sh',
            'application/x-executable', 'application/x-msdos-program',
            'application/javascript', 'text/javascript',
        ];
        if (in_array($mime, $blockedMimes, true)) {
            $mime = 'application/octet-stream';
        }

        // Clear any PHP output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . $size);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-cache, no-store');
        header('Pragma: no-cache');

        readfile($realFullPath);
        exit;
    }
}
