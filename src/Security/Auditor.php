<?php

declare(strict_types=1);

namespace Intrabase\Security;

use Intrabase\Database\Connection;

/**
 * Auditor — Write-only audit log
 *
 * Records create/update/delete operations on module records into
 * the `ib_audit` table. Initialized once per request via Auditor::init()
 * and then called as Auditor::log() from anywhere.
 *
 * Table schema (ib_audit):
 *   id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   user_id     INT NULL
 *   action      VARCHAR(32)  (create|update|delete)
 *   table_name  VARCHAR(64)  NULL
 *   record_id   INT          NULL
 *   data_before LONGTEXT     NULL (JSON)
 *   data_after  LONGTEXT     NULL (JSON)
 *   ip_address  VARCHAR(45)  NULL
 *   created_at  TIMESTAMP DEFAULT current_timestamp()
 *
 * @package Intrabase\Security
 */
final class Auditor
{
    private static ?Connection $db      = null;
    private static ?Session    $session = null;

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    /**
     * Initialize the auditor for the current request.
     * Call once from Application::registerCoreServices().
     */
    public static function init(Connection $db, Session $session): void
    {
        self::$db      = $db;
        self::$session = $session;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Write an audit log entry.
     *
     * @param  string                    $action     'create' | 'update' | 'delete'
     * @param  string|null               $table      Database table affected
     * @param  int|null                  $recordId   Primary key of the affected record
     * @param  array<string, mixed>|null $dataBefore Record state before the operation (for update/delete)
     * @param  array<string, mixed>|null $dataAfter  Record state after the operation (for create/update)
     */
    public static function log(
        string $action,
        ?string $table   = null,
        ?int $recordId   = null,
        ?array $dataBefore = null,
        ?array $dataAfter  = null
    ): void {
        if (self::$db === null) {
            // Not initialized (e.g. in tests or CLI) — silently skip
            return;
        }

        $userId = self::$session?->userId();
        $ip     = self::clientIp();

        try {
            self::$db->insert('ib_audit', [
                'user_id'     => $userId,
                'action'      => $action,
                'table_name'  => $table,
                'record_id'   => $recordId,
                'data_before' => $dataBefore !== null
                    ? json_encode($dataBefore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'data_after'  => $dataAfter !== null
                    ? json_encode($dataAfter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'ip_address'  => $ip,
            ]);
        } catch (\Throwable) {
            // Audit failures must never break the main application flow
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /** Prevent instantiation — static-only class */
    private function __construct() {}
}
