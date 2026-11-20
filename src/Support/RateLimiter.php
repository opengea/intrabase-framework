<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;

/**
 * Generic Rate Limiter
 *
 * Persists hit counts in the `ib_rate_limits` table using a string key.
 * A key can represent any rate-limited resource: an API token, an IP
 * address, a login identifier, etc.
 *
 * Table schema (ib_rate_limits):
 *   id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   key        VARCHAR(255)
 *   created_at DATETIME DEFAULT CURRENT_TIMESTAMP
 *   INDEX idx_key_created (key(100), created_at)
 *
 * Usage:
 *   $limiter = new RateLimiter($db);
 *
 *   if ($limiter->isBlocked('login:192.168.1.1', 5, 900)) {
 *       throw new TooManyRequestsException();
 *   }
 *   $limiter->record('login:192.168.1.1');
 *   // ... on success:
 *   $limiter->clear('login:192.168.1.1');
 *
 * @package Intrabase\Support
 */
class RateLimiter
{
    /** Records older than this are pruned automatically (24 h) */
    private const CLEANUP_TTL = 86400;

    public function __construct(private readonly Connection $db) {}

    /**
     * Check whether the key has exceeded the allowed hit count.
     *
     * Counts hits within the last $windowSeconds seconds. If the count
     * meets or exceeds $maxHits, the key is considered blocked.
     *
     * Old records (> CLEANUP_TTL) are pruned on each call to keep the
     * table from growing indefinitely.
     *
     * @param  string $key           Rate-limit key (e.g. 'api_token_42', 'login:1.2.3.4')
     * @param  int    $maxHits       Maximum allowed hits in the window
     * @param  int    $windowSeconds Sliding window size in seconds
     * @return bool                  TRUE if further requests should be blocked
     */
    public function isBlocked(string $key, int $maxHits, int $windowSeconds): bool
    {
        $this->pruneOldRecords();

        $since = date('Y-m-d H:i:s', time() - $windowSeconds);

        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS hit_count
               FROM `ib_rate_limits`
              WHERE `key` = :key
                AND created_at >= :since',
            ['key' => $key, 'since' => $since]
        );

        return (int) ($row['hit_count'] ?? 0) >= $maxHits;
    }

    /**
     * Record a hit for the given key.
     *
     * @param string $key
     */
    public function record(string $key): void
    {
        $this->db->insert('ib_rate_limits', [
            'key'        => $key,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Clear all recorded hits for the given key.
     *
     * Call this after a successful operation to reset the counter.
     *
     * @param string $key
     */
    public function clear(string $key): void
    {
        $this->db->delete(
            'ib_rate_limits',
            '`key` = :key',
            ['key' => $key]
        );
    }

    /**
     * Return the number of recent hits within the given window.
     *
     * @param string $key
     * @param int    $windowSeconds
     * @return int
     */
    public function countHits(string $key, int $windowSeconds): int
    {
        $since = date('Y-m-d H:i:s', time() - $windowSeconds);

        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS hit_count
               FROM `ib_rate_limits`
              WHERE `key` = :key
                AND created_at >= :since',
            ['key' => $key, 'since' => $since]
        );

        return (int) ($row['hit_count'] ?? 0);
    }

    /**
     * Return the datetime when the block will lift, or null if not blocked.
     *
     * @param string $key
     * @param int    $maxHits
     * @param int    $windowSeconds
     * @return \DateTimeImmutable|null
     */
    public function unblockedAt(string $key, int $maxHits, int $windowSeconds): ?\DateTimeImmutable
    {
        if (!$this->isBlocked($key, $maxHits, $windowSeconds)) {
            return null;
        }

        $since = date('Y-m-d H:i:s', time() - $windowSeconds);

        $row = $this->db->fetchOne(
            'SELECT MIN(created_at) AS oldest
               FROM `ib_rate_limits`
              WHERE `key` = :key
                AND created_at >= :since',
            ['key' => $key, 'since' => $since]
        );

        if ($row === null || $row['oldest'] === null) {
            return null;
        }

        $oldest = new \DateTimeImmutable($row['oldest']);
        return $oldest->modify("+{$windowSeconds} seconds");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Delete records older than CLEANUP_TTL seconds.
     * Runs on every isBlocked() call — overhead is minimal for infrequent endpoints.
     */
    private function pruneOldRecords(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::CLEANUP_TTL);
        $this->db->delete('ib_rate_limits', 'created_at < :cutoff', ['cutoff' => $cutoff]);
    }
}
