<?php

declare(strict_types=1);

namespace Intrabase\View;

/**
 * TemplateResolver
 *
 * Resolves template/panel file paths with tenant-specific override support.
 *
 * Priority chain (mirrors KMS behaviour):
 *   1. Tenant override:  {tenantDir}/{type}/{name}.php
 *   2. Intrabase default: {INTRABASE_ROOT}/tpl/{type}/{name}.php
 *
 * Types:
 *   - panels/     — Module header panels
 *   - partials/   — Reusable view partials
 *   - layouts/    — Layout templates
 *   - emails/     — Email templates
 *
 * Usage:
 *   TemplateResolver::setTenantDir('/var/www/vhosts/dom/subdomains/sub/httpdocs/data/tpl');
 *   $path = TemplateResolver::resolve('panels', 'sys_users');  // panels/sys_users.php
 *   $path = TemplateResolver::resolve('panels', 'default');     // fallback to default.php
 *
 * @package Intrabase\View
 */
final class TemplateResolver
{
    /** Intrabase default templates directory */
    private static string $defaultDir = '';

    /** Tenant-specific templates directory (optional) */
    private static ?string $tenantDir = null;

    /**
     * Set the tenant-specific templates directory.
     * Files here take priority over Intrabase defaults.
     */
    public static function setTenantDir(string $path): void
    {
        self::$tenantDir = rtrim($path, '/');
    }

    /**
     * Get the tenant templates directory.
     */
    public static function getTenantDir(): ?string
    {
        return self::$tenantDir;
    }

    /**
     * Resolve a template file path by type and name.
     *
     * @param string $type Template type subdirectory (e.g. 'panels', 'partials')
     * @param string $name Template name without .php extension
     * @param string|null $fallback Fallback template name (e.g. 'default')
     * @return string|null Full path to the template file, or null if not found
     */
    public static function resolve(string $type, string $name, ?string $fallback = null): ?string
    {
        self::ensureDefaultDir();

        $type = trim($type, '/');
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name);

        // 1. Tenant override
        if (self::$tenantDir !== null) {
            $path = self::$tenantDir . '/' . $type . '/' . $name . '.php';
            if (is_file($path)) {
                return $path;
            }
        }

        // 2. Intrabase default
        $path = self::$defaultDir . '/' . $type . '/' . $name . '.php';
        if (is_file($path)) {
            return $path;
        }

        // 3. Fallback template
        if ($fallback !== null && $fallback !== $name) {
            return self::resolve($type, $fallback);
        }

        return null;
    }

    /**
     * Resolve a panel template for a module.
     *
     * Priority:
     *   1. Tenant: {tenantDir}/panels/{moduleName}.php
     *   2. Default: {defaultDir}/panels/{moduleName}.php
     *   3. Fallback: {defaultDir}/panels/default.php
     *
     * @param string $moduleName Module name (e.g. 'sys_users')
     * @return string|null Full path to the panel file
     */
    public static function resolvePanel(string $moduleName): ?string
    {
        return self::resolve('panels', $moduleName, 'default');
    }

    /**
     * Resolve a partial template.
     *
     * @param string $name Partial name (e.g. 'header', 'sidebar')
     * @return string|null Full path to the partial file
     */
    public static function resolvePartial(string $name): ?string
    {
        return self::resolve('partials', $name);
    }

    /**
     * Include a resolved template with optional variables.
     *
     * @param string $type Template type
     * @param string $name Template name
     * @param array<string, mixed> $vars Variables to extract into template scope
     * @param string|null $fallback Fallback template name
     * @return bool True if template was found and included
     */
    public static function render(string $type, string $name, array $vars = [], ?string $fallback = null): bool
    {
        $path = self::resolve($type, $name, $fallback);
        if ($path === null) {
            return false;
        }

        // Extract variables into local scope and include template
        extract($vars, EXTR_SKIP);
        include $path;
        return true;
    }

    /**
     * Check if a template exists (in tenant or default locations).
     */
    public static function exists(string $type, string $name): bool
    {
        return self::resolve($type, $name) !== null;
    }

    /**
     * List available templates of a given type (merged from tenant + default).
     *
     * @return string[] Template names (without .php extension)
     */
    public static function available(string $type): array
    {
        self::ensureDefaultDir();

        $templates = [];
        $type = trim($type, '/');

        // Default templates
        $defaultPath = self::$defaultDir . '/' . $type;
        if (is_dir($defaultPath)) {
            foreach (glob($defaultPath . '/*.php') ?: [] as $file) {
                $templates[] = pathinfo($file, PATHINFO_FILENAME);
            }
        }

        // Tenant templates (override + additions)
        if (self::$tenantDir !== null) {
            $tenantPath = self::$tenantDir . '/' . $type;
            if (is_dir($tenantPath)) {
                foreach (glob($tenantPath . '/*.php') ?: [] as $file) {
                    $name = pathinfo($file, PATHINFO_FILENAME);
                    if (!in_array($name, $templates, true)) {
                        $templates[] = $name;
                    }
                }
            }
        }

        sort($templates);
        return $templates;
    }

    /**
     * Set the default Intrabase templates directory.
     */
    private static function ensureDefaultDir(): void
    {
        if (self::$defaultDir === '') {
            self::$defaultDir = dirname(__DIR__, 2) . '/tpl';
        }
    }
}
