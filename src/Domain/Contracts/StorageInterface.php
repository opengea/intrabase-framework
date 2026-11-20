<?php

declare(strict_types=1);

namespace Intrabase\Domain\Contracts;

/**
 * StorageInterface — persistent blob/file storage boundary
 *
 * Abstracts where uploaded files, attachments and reports end up. Older
 * code writes to the upload directory directly; new code should depend on
 * this interface and let Infrastructure pick the backend.
 *
 * Concrete implementations:
 *   - Intrabase\Infrastructure\Storage\LocalStorage  (filesystem)
 *   - Intrabase\Tests\Fakes\InMemoryStorage          (array-backed, for tests)
 *
 * Paths are logical — relative to the storage root ("drive/u/42/docs/x.pdf").
 * The backend maps them to physical paths as needed.
 *
 * @package Intrabase\Domain\Contracts
 */
interface StorageInterface
{
    /**
     * Write $contents to $path. Creates parent dirs if needed.
     * Returns the number of bytes written.
     */
    public function put(string $path, string $contents): int;

    /**
     * Read the whole file at $path. Returns null if it does not exist.
     */
    public function get(string $path): ?string;

    /**
     * Check whether $path exists (file or directory).
     */
    public function exists(string $path): bool;

    /**
     * Delete a file. Returns true on success (or if it didn't exist).
     * To delete a directory recursively use deleteDirectory().
     */
    public function delete(string $path): bool;

    /**
     * Move/rename $from to $to. Returns true on success.
     */
    public function move(string $from, string $to): bool;

    /**
     * Return file size in bytes, or null if file does not exist.
     */
    public function size(string $path): ?int;

    /**
     * Return last-modified UNIX timestamp, or null if $path does not exist.
     */
    public function lastModified(string $path): ?int;

    /**
     * List the direct children of $directory (non-recursive).
     * Entries are logical paths (e.g. "drive/u/42/docs/x.pdf").
     * Returns empty array if $directory does not exist.
     *
     * @return string[]
     */
    public function listDirectory(string $directory): array;

    /**
     * Create $directory (and its parents) if it does not exist.
     */
    public function makeDirectory(string $directory): bool;

    /**
     * Recursively delete a directory and all its contents.
     */
    public function deleteDirectory(string $directory): bool;
}
