<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;
use Intrabase\Security\Session;

/**
 * EntityPlan
 *
 * Which applications the session's active entity (organization) may use,
 * according to its subscription plan. Used by AppAccess (app bar) to filter
 * by plan in addition to group-based access.
 *
 * The kernel has no notion of plans: without a resolver every entity sees
 * every app. A plugin that sells plans registers one:
 *
 *   EntityPlan::setResolver(fn(Connection $db, int $entityId): ?array => [...catalog slugs]);
 *
 * The resolver returns the allowed catalog slugs (ib_apps.catalog_slug), or
 * null for "no plan: do not filter".
 */
final class EntityPlan
{
    /** @var (callable(Connection, int): ?array)|null */
    private static $resolver = null;

    /** Per-request cache keyed by entity id. */
    private static array $cache = [];

    /** Clear the per-request cache. Intended for tests. */
    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * @param (callable(Connection, int): ?array)|null $resolver
     */
    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
        self::$cache = [];
    }

    public static function activeEntityId(Session $session): int
    {
        $active = $session->get('_ib_active_entity');
        if (!is_array($active)) {
            return 0;
        }
        return (int) ($active['id'] ?? $active['entity_id'] ?? 0);
    }

    /**
     * Allowed catalog slugs for the entity's plan, or NULL when there is no
     * plan to apply (no resolver, no active entity, an error): callers read
     * NULL as "don't filter". An empty array means the plan grants no apps.
     *
     * @return array<int, string>|null
     */
    public static function allowedSlugs(Connection $db, int $entityId): ?array
    {
        if ($entityId <= 0 || self::$resolver === null) {
            return null;
        }
        if (array_key_exists($entityId, self::$cache)) {
            return self::$cache[$entityId];
        }
        try {
            $slugs = (self::$resolver)($db, $entityId);
            $result = is_array($slugs) ? array_values(array_map('strval', $slugs)) : null;
        } catch (\Throwable $e) {
            error_log('[EntityPlan] resolver failed for entity ' . $entityId . ': ' . $e->getMessage());
            $result = null;
        }
        return self::$cache[$entityId] = $result;
    }
}
