<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Core\Application;
use Intrabase\Security\Session;

/**
 * TenantEntities
 *
 * Organizations supplied by the TENANT's own data model instead of the native
 * ib_obj_entities/ib_user_entities pair (e.g. tenants whose
 * organizations are legacy actors). The tenant declares a provider at
 * <tenant data dir>/profile_entities.php returning
 * callable(array $ibUser): array of rows:
 *   id, name, type_label, role_label, is_active, logo_url, url,
 *   panel_src, panel_fn (optional in-page panel opener)
 *
 * Consumed by ProfileController (organizations section, read-only cards) and
 * Layout (header avatar duo). Resolved once per request.
 */
final class TenantEntities
{
    private static bool $resolved = false;

    /** @var array<int, array<string, mixed>>|null null = tenant has no provider */
    private static ?array $rows = null;

    /**
     * Rows from the tenant provider; null when the tenant has no provider
     * (native entities apply), [] when it has one but yields nothing/fails.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function rows(Session $session): ?array
    {
        if (self::$resolved) {
            return self::$rows;
        }
        self::$resolved = true;
        self::$rows     = null;

        try {
            $dataPath = Application::getInstance()->getTenantDataPath();
        } catch (\Throwable) {
            return null;
        }
        if ($dataPath === null) {
            return null;
        }
        $hook = $dataPath . '/profile_entities.php';
        if (!is_file($hook)) {
            return null;
        }

        self::$rows = [];
        try {
            $provider = require $hook;
            if (is_callable($provider)) {
                $rows = $provider($session->user() ?? []);
                if (is_array($rows)) {
                    $rows = array_values(array_filter($rows, 'is_array'));
                    foreach ($rows as &$row) {
                        $row['_tenant'] = true;
                    }
                    unset($row);
                    self::$rows = $rows;
                }
            }
        } catch (\Throwable) {
            // Provider failed — behave as "no organizations" rather than break
        }

        return self::$rows;
    }

    /**
     * The user's active tenant organization mapped to the shape the header
     * avatar expects (_ib_active_entity-like), or null when the tenant has no
     * provider or the user has no organization.
     *
     * @return array<string, mixed>|null
     */
    public static function active(Session $session): ?array
    {
        $rows = self::rows($session);
        if ($rows === null || $rows === []) {
            return null;
        }

        $active = null;
        foreach ($rows as $row) {
            if (!empty($row['is_active'])) {
                $active = $row;
                break;
            }
        }
        // Amb el COMPTE PERSONAL actiu (o cap), la capçalera mostra només
        // l'avatar de l'usuari: cap duo d'organització.
        if ($active === null || !empty($active['is_personal'])) {
            return null;
        }

        return [
            'id'       => (int) ($active['id'] ?? 0),
            'name'     => (string) ($active['name'] ?? ''),
            'logo'     => '',
            'logo_url' => (string) ($active['logo_url'] ?? ''),
            'logo_bg'  => (string) ($active['logo_bg'] ?? ''),
            'color'    => (string) ($active['color'] ?? ''),
            'type'     => '',
        ];
    }
}
