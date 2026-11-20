<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Core\Application;

/**
 * Guard for the free-form SQL condition of saved views (ib_sys_views.where_clause).
 *
 * Saved views come from the legacy system and hold raw SQL (subqueries
 * included), so the condition cannot be parameterised. Only administrators can
 * write them (sys_views), but a tenant administrator must still not be able to
 * read other tenants' databases or the server through them. A condition is
 * refused when it contains:
 *   - statement separators or comments (; -- # /*),
 *   - UNION, system schemas (information_schema, mysql., performance_schema, sys.),
 *   - file and timing functions (LOAD_FILE, INTO OUTFILE/DUMPFILE, SLEEP, BENCHMARK),
 *   - the name of another tenant's database followed by a dot.
 * A refused condition is not applied (the view shows unfiltered data, which the
 * user can see anyway) and is logged.
 */
final class ViewSqlGuard
{
    /** @var list<string>|null */
    private static ?array $otherDatabases = null;

    public static function filter(string $where): string
    {
        $where = trim($where);
        if ($where === '') {
            return '';
        }
        $lower = strtolower($where);

        $forbidden = '/;|--|#|\/\*|\bunion\b|information_schema|performance_schema|\bmysql\s*\.|\bsys\s*\.'
                   . '|\bload_file\s*\(|\binto\s+(outfile|dumpfile)\b|\bsleep\s*\(|\bbenchmark\s*\(/';
        if (preg_match($forbidden, $lower) === 1) {
            return self::refuse($where, 'forbidden construct');
        }

        foreach (self::otherDatabases() as $db) {
            if (preg_match('/[`\s(,=]*\b' . preg_quote(strtolower($db), '/') . '`?\s*\./', $lower) === 1) {
                return self::refuse($where, 'reference to another database');
            }
        }

        return $where;
    }

    /** @return list<string> databases of the other tenants on this host */
    private static function otherDatabases(): array
    {
        if (self::$otherDatabases !== null) {
            return self::$otherDatabases;
        }
        self::$otherDatabases = [];
        try {
            $app     = Application::getInstance();
            $current = (string) ($app->getTenantConfig()['db_name'] ?? $app->getConfig()['db_name'] ?? '');
            $names   = $app->getMasterConnection()->query('SELECT db_name FROM ib_tenants')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            $names[] = (string) ($app->getConfig()['db_name'] ?? '');
            foreach (array_unique($names) as $name) {
                $name = (string) $name;
                if ($name !== '' && $name !== $current) {
                    self::$otherDatabases[] = $name;
                }
            }
        } catch (\Throwable) {
            // No master table: nothing else to protect.
        }
        return self::$otherDatabases;
    }

    private static function refuse(string $where, string $why): string
    {
        error_log('[ViewSqlGuard] saved view condition refused (' . $why . '): ' . mb_substr($where, 0, 200));
        return '';
    }
}
