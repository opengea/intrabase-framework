<?php

declare(strict_types=1);

namespace Intrabase\Infrastructure\Storage;

use Intrabase\Domain\Contracts\StorageInterface;

/**
 * LocalStorage — filesystem-backed StorageInterface
 *
 * Root directory is set at construction; all logical paths are resolved
 * relative to it. Paths are sanitized against traversal ("../") before
 * any filesystem operation.
 *
 * This adapter is new — the legacy code (DriveController, MailApiController,
 * etc.) writes to absolute paths directly. It can start consuming this
 * adapter incrementally, without breaking the existing writers.
 *
 * @package Intrabase\Infrastructure\Storage
 */
final class LocalStorage implements StorageInterface
{
    public function __construct(
        private readonly string $root,
    ) {
        if ($root === '' || !str_starts_with($root, '/')) {
            throw new \InvalidArgumentException(
                'LocalStorage root must be an absolute path, got: ' . $root
            );
        }
    }

    public function put(string $path, string $contents): int
    {
        $abs = $this->resolve($path);
        $dir = dirname($abs);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }
        $bytes = file_put_contents($abs, $contents);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot write to: {$abs}");
        }
        return $bytes;
    }

    public function get(string $path): ?string
    {
        $abs = $this->resolve($path);
        if (!is_file($abs)) {
            return null;
        }
        $contents = file_get_contents($abs);
        return $contents === false ? null : $contents;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->resolve($path));
    }

    public function delete(string $path): bool
    {
        $abs = $this->resolve($path);
        if (!file_exists($abs)) {
            return true;
        }
        if (is_dir($abs)) {
            throw new \RuntimeException(
                "Refusing to delete directory with delete() — use deleteDirectory()"
            );
        }
        return @unlink($abs);
    }

    public function move(string $from, string $to): bool
    {
        $src = $this->resolve($from);
        $dst = $this->resolve($to);
        if (!file_exists($src)) {
            return false;
        }
        $dstDir = dirname($dst);
        if (!is_dir($dstDir) && !mkdir($dstDir, 0775, true) && !is_dir($dstDir)) {
            return false;
        }
        return @rename($src, $dst);
    }

    public function size(string $path): ?int
    {
        $abs = $this->resolve($path);
        if (!is_file($abs)) {
            return null;
        }
        $size = filesize($abs);
        return $size === false ? null : $size;
    }

    public function lastModified(string $path): ?int
    {
        $abs = $this->resolve($path);
        if (!file_exists($abs)) {
            return null;
        }
        $mtime = filemtime($abs);
        return $mtime === false ? null : $mtime;
    }

    public function listDirectory(string $directory): array
    {
        $abs = $this->resolve($directory);
        if (!is_dir($abs)) {
            return [];
        }
        $entries = scandir($abs);
        if ($entries === false) {
            return [];
        }
        $logicalBase = ltrim($directory, '/');
        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $out[] = $logicalBase === ''
                ? $entry
                : $logicalBase . '/' . $entry;
        }
        return $out;
    }

    public function makeDirectory(string $directory): bool
    {
        $abs = $this->resolve($directory);
        if (is_dir($abs)) {
            return true;
        }
        return mkdir($abs, 0775, true);
    }

    public function deleteDirectory(string $directory): bool
    {
        $abs = $this->resolve($directory);
        if (!is_dir($abs)) {
            return true;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        return @rmdir($abs);
    }

    /**
     * Resolve a logical path against $root, blocking traversal.
     */
    private function resolve(string $path): string
    {
        $clean = str_replace('\\', '/', trim($path));
        $clean = preg_replace('#/+#', '/', $clean) ?? $clean;
        $clean = ltrim($clean, '/');

        $parts = [];
        foreach (explode('/', $clean) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new \InvalidArgumentException(
                    "Path traversal not allowed: {$path}"
                );
            }
            $parts[] = $segment;
        }

        $rel = implode('/', $parts);
        return rtrim($this->root, '/') . ($rel === '' ? '' : '/' . $rel);
    }
}
