<?php

declare(strict_types=1);

namespace Intrabase\Security;

use Intrabase\Core\Application;
use Intrabase\Database\Connection;

/**
 * Who may change who-can-do-what (24/11/2026).
 *
 * The tables that grant rights — group permissions, field rules, groups, group
 * membership, apps (their group_access), users and saved views (raw SQL in
 * where_clause) — are written only by members
 * of the administrators group, whatever app or group gives a user access to the
 * modules that edit them. Otherwise whoever reached the System app through a
 * group of their own could grant themselves everything, from the web or the API.
 *
 * The administrators group is identified by its id (config('admin_group_id'),
 * 1 by default: the installer's first group), never by its name, which can be
 * edited.
 */
final class Privilege
{
    /** Tables whose rows decide what users may do. */
    public const TABLES = [
        'ib_permissions',
        'ib_sys_field_access_rules',
        'ib_groups',
        'ib_user_groups',
        'ib_apps',
        'ib_users',
        'ib_sys_views',   // saved views carry a raw SQL condition (where_clause)
    ];

    public static function guards(string $table): bool
    {
        return in_array($table, self::TABLES, true);
    }

    public static function adminGroupId(): int
    {
        try {
            $id = (int) (Application::getInstance()->getConfig()['admin_group_id'] ?? 1);
        } catch (\Throwable) {
            $id = 1;
        }
        return $id > 0 ? $id : 1;
    }

    public static function isAdmin(Connection $db, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            return $db->fetchOne(
                'SELECT 1 AS ok FROM ib_user_groups WHERE user_id = :uid AND group_id = :gid LIMIT 1',
                ['uid' => $userId, 'gid' => self::adminGroupId()]
            ) !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
