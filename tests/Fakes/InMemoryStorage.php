<?php

declare(strict_types=1);

namespace Intrabase\Tests\Fakes;

use Intrabase\Domain\Contracts\StorageInterface;

/**
 * InMemoryStorage — array-backed StorageInterface for tests.
 *
 * Paths are stored as-is after basic normalization. Directory semantics
 * are implicit: any path with children "exists" as a directory.
 *
 * @package Intrabase\Tests\Fakes
 */
final class InMemoryStorage implements StorageInterface
{
    /** @var array<string, string> */
    private array $files = [];

    /** @var array<string, int> */
    private array $mtimes = [];

    public function put(string $path, string $contents): int
    {
        $key = $this->normalize($path);
        $this->files[$key] = $contents;
        $this->mtimes[$key] = time();
        return strlen($contents);
    }

    public function get(string $path): ?string
    {
        return $this->files[$this->normalize($path)] ?? null;
    }

    public function exists(string $path): bool
    {
        $key = $this->normalize($path);
        if (isset($this->files[$key])) {
            return true;
        }
        $prefix = $key . '/';
        foreach (array_keys($this->files) as $p) {
            if (str_starts_with($p, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function delete(string $path): bool
    {
        $key = $this->normalize($path);
        unset($this->files[$key], $this->mtimes[$key]);
        return true;
    }

    public function move(string $from, string $to): bool
    {
        $src = $this->normalize($from);
        $dst = $this->normalize($to);
        if (!isset($this->files[$src])) {
            return false;
        }
        $this->files[$dst] = $this->files[$src];
        $this->mtimes[$dst] = time();
        unset($this->files[$src], $this->mtimes[$src]);
        return true;
    }

    public function size(string $path): ?int
    {
        $c = $this->get($path);
        return $c === null ? null : strlen($c);
    }

    public function lastModified(string $path): ?int
    {
        return $this->mtimes[$this->normalize($path)] ?? null;
    }

    public function listDirectory(string $directory): array
    {
        $dir = $this->normalize($directory);
        $prefix = $dir === '' ? '' : $dir . '/';
        $seen = [];
        foreach (array_keys($this->files) as $p) {
            if ($prefix !== '' && !str_starts_with($p, $prefix)) {
                continue;
            }
            $rest = substr($p, strlen($prefix));
            $first = explode('/', $rest)[0];
            if ($first === '') {
                continue;
            }
            $full = $prefix . $first;
            $seen[$full] = true;
        }
        return array_keys($seen);
    }

    public function makeDirectory(string $directory): bool
    {
        return true;
    }

    public function deleteDirectory(string $directory): bool
    {
        $dir = $this->normalize($directory);
        $prefix = $dir === '' ? '' : $dir . '/';
        foreach (array_keys($this->files) as $p) {
            if ($p === $dir || str_starts_with($p, $prefix)) {
                unset($this->files[$p], $this->mtimes[$p]);
            }
        }
        return true;
    }

    public function reset(): void
    {
        $this->files = [];
        $this->mtimes = [];
    }

    private function normalize(string $path): string
    {
        $clean = trim(str_replace('\\', '/', $path), '/');
        $clean = preg_replace('#/+#', '/', $clean) ?? $clean;

        foreach (explode('/', $clean) as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException(
                    "Path traversal not allowed: {$path}"
                );
            }
        }
        return $clean;
    }
}
