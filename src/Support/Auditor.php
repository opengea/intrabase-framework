<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;
use Intrabase\Security\Session;

/**
 * Audit Log Writer
 *
 * Records user actions to the `ib_audit` table for accountability,
 * forensics, and compliance purposes.
 *
 * Supported actions: create, update, delete, login, logout, export.
 *
 * The `data_before` and `data_after` columns store JSON snapshots of the
 * record state before and after the change. For login/logout events,
 * both are typically empty.
 *
 * IP detection: reads X-Forwarded-For (common behind load balancers) but
 * validates each address. Falls back to REMOTE_ADDR.
 *
 * Usage:
 *   Auditor::create($db, $session, 'ib_users', 42, [], $newData);
 *   Auditor::update($db, $session, 'ib_invoices', 7, $old, $new);
 *   Auditor::delete($db, $session, 'ib_products', 99, $old);
 *
 * @package Intrabase\Support
 */
class Auditor
{
    /** Valid action identifiers */
    private const VALID_ACTIONS = ['create', 'update', 'delete', 'login', 'logout', 'export'];

    // =========================================================================
    // Static convenience methods
    // =========================================================================

    /**
     * Log a record creation event.
     *
     * @param  Connection           $db
     * @param  Session              $session
     * @param  string               $table
     * @param  int                  $recordId
     * @param  array<string, mixed> $after     The newly created data
     */
    public static function create(
        Connection $db,
        Session $session,
        string $table,
        int $recordId,
        array $after = []
    ): void {
        self::log('create', $table, $recordId, [], $after, $db, $session);
    }

    /**
     * Log a record update event.
     *
     * @param  Connection           $db
     * @param  Session              $session
     * @param  string               $table
     * @param  int                  $recordId
     * @param  array<string, mixed> $before    State before update
     * @param  array<string, mixed> $after     State after update
     */
    public static function update(
        Connection $db,
        Session $session,
        string $table,
        int $recordId,
        array $before = [],
        array $after = []
    ): void {
        self::log('update', $table, $recordId, $before, $after, $db, $session);
    }

    /**
     * Log a record deletion event.
     *
     * @param  Connection           $db
     * @param  Session              $session
     * @param  string               $table
     * @param  int                  $recordId
     * @param  array<string, mixed> $before    The deleted record's data
     */
    public static function delete(
        Connection $db,
        Session $session,
        string $table,
        int $recordId,
        array $before = []
    ): void {
        self::log('delete', $table, $recordId, $before, [], $db, $session);
    }

    /**
     * Log an export event.
     *
     * @param  Connection $db
     * @param  Session    $session
     * @param  string     $table
     * @param  int        $recordId   Use 0 for bulk exports
     */
    public static function export(
        Connection $db,
        Session $session,
        string $table,
        int $recordId = 0
    ): void {
        self::log('export', $table, $recordId, [], [], $db, $session);
    }

    // =========================================================================
    // Core log method
    // =========================================================================

    /**
     * Write an audit entry to `ib_audit`.
     *
     * @param  string               $action     One of: create, update, delete, login, logout, export
     * @param  string               $table      Affected table name
     * @param  int                  $recordId   Affected record primary key (0 for non-record events)
     * @param  array<string, mixed> $before     State before change (empty for create/login)
     * @param  array<string, mixed> $after      State after change (empty for delete/logout)
     * @param  Connection           $db
     * @param  Session              $session
     *
     * @throws \InvalidArgumentException  On invalid $action
     */
    public static function log(
        string $action,
        string $table,
        int $recordId,
        array $before,
        array $after,
        Connection $db,
        Session $session
    ): void {
        if (!in_array($action, self::VALID_ACTIONS, true)) {
            throw new \InvalidArgumentException(
                "Invalid audit action '{$action}'. Allowed: " . implode(', ', self::VALID_ACTIONS)
            );
        }

        $db->insert('ib_audit', [
            'user_id'     => $session->userId() ?? 0,
            'action'      => $action,
            'table_name'  => $table,
            'record_id'   => $recordId,
            'data_before' => empty($before) ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'data_after'  => empty($after)  ? null : json_encode($after,  JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address'  => self::resolveClientIp(),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    // =========================================================================
    // IP resolution
    // =========================================================================

    /**
     * Resolve the client's real IP address.
     *
     * Checks X-Forwarded-For first (set by reverse proxies / load balancers),
     * but validates every candidate with filter_var before trusting it.
     * Falls back to REMOTE_ADDR.
     *
     * @return string  Valid IP address string, or '0.0.0.0' if unresolvable
     */
    public static function resolveClientIp(): string
    {
        // X-Forwarded-For may contain a chain: "client, proxy1, proxy2"
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            foreach (array_map('trim', explode(',', $forwarded)) as $candidate) {
                if (self::isValidPublicIp($candidate)) {
                    return $candidate;
                }
            }
        }

        // X-Real-IP (set by nginx)
        $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if ($realIp !== '' && self::isValidPublicIp($realIp)) {
            return $realIp;
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false) {
            return $remoteAddr;
        }

        return '0.0.0.0';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Check that an IP is valid and not a private/reserved range.
     *
     * Private IPs in X-Forwarded-For typically indicate an internal proxy
     * rather than the real client, so we skip them in favour of a more
     * meaningful public address.
     */
    private static function isValidPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
