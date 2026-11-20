<?php

declare(strict_types=1);

namespace Intrabase\Core;

use Intrabase\Database\Connection;
use Intrabase\Security\CSRF;
use PDO;

/**
 * Module Loader
 *
 * Resolves and instantiates modules following a priority chain:
 *
 * 1. Tenant override:   /path/to/vhost/data/mod/{name}.php
 * 2. Built-in module:   {INTRABASE_ROOT}/modules/{prefix}/{PascalCase}.php
 * 3. Database-defined:  ib_sys_mods table (DynamicModule)
 *
 * Module names may only contain [a-z0-9_] characters.
 *
 * @package Intrabase\Core
 */
class ModuleLoader
{
    /** Root directory for built-in modules */
    private string $modulesDir;

    /** Optional tenant-specific module override directory */
    private ?string $tenantModulesDir = null;

    public function __construct(
        private readonly string     $domain,
        private readonly string     $subdomain,
        private readonly Container  $container
    ) {
        $this->modulesDir = dirname(__DIR__, 2) . '/modules';
    }

    /**
     * Set optional tenant override directory
     */
    public function setTenantModulesDir(string $path): void
    {
        $this->tenantModulesDir = rtrim($path, '/');
    }

    /**
     * Load and instantiate a module by name.
     *
     * Priority:
     *   1. Tenant override PHP file
     *   2. Built-in PHP module file
     *   3. ib_sys_mods database definition  (DynamicModule)
     *
     * @param  string $name Module name (e.g. 'sys_users', 'lib_files', 'my_custom')
     * @return Module
     * @throws ModuleNotFoundException If not found in any source
     * @throws \InvalidArgumentException For invalid module names
     */
    /**
     * Check if a module exists (file or dynamic).
     */
    public function exists(string $name): bool
    {
        try {
            $name = $this->validateName($name);
        } catch (\InvalidArgumentException) {
            return false;
        }
        return $this->resolvePath($name) !== null || $this->loadDynamic($name) !== null;
    }

    public function load(string $name): Module
    {
        $name = $this->validateName($name);
        $path = $this->resolvePath($name);

        if ($path !== null) {
            // ── Static PHP module ──────────────────────────────────────────
            require_once $path;

            $className = $this->resolveClassName($name, $path);

            if (!class_exists($className)) {
                throw new \RuntimeException(
                    "Module file loaded but class '{$className}' not found"
                );
            }

            if (!is_subclass_of($className, Module::class)) {
                throw new \RuntimeException(
                    "'{$className}' must extend " . Module::class
                );
            }

            $module = new $className(
                $this->container->get(Connection::class),
                $this->container->get(CSRF::class),
                $this->container
            );
            // Auto-register $selects static data into $combos so that modules
            // using 'select' component type get proper dropdown rendering.
            $module->registerSelects();
            // Convert $advancedFilters (new format) into $filterFields/$filterOptions
            // (legacy format expected by DataBrowser and CSV export).
            $module->registerAdvancedFilters();
            // Apply DB overrides from ib_sys_mod_attributes (if configured in sys_mod)
            $module->applyDbOverrides();
            return $module;
        }

        // ── Fallback: database-defined module (ib_sys_mods) ───────────────
        $dynamic = $this->loadDynamic($name);
        if ($dynamic !== null) {
            return $dynamic;
        }

        throw new ModuleNotFoundException("Module not found: {$name}");
    }

    /**
     * Check whether a module exists (without loading it).
     * Checks PHP files first, then ib_sys_mods.
     */
    public function has(string $name): bool
    {
        try {
            $name = $this->validateName($name);

            if ($this->resolvePath($name) !== null) {
                return true;
            }

            return $this->loadDynamic($name) !== null;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * List all available module names (built-in PHP + database-defined).
     *
     * @return string[]
     */
    public function available(): array
    {
        $modules = [];

        // PHP built-in modules
        foreach (glob($this->modulesDir . '/**/*.php') ?: [] as $file) {
            $rel  = str_replace($this->modulesDir . '/', '', $file);
            $name = pathinfo($rel, PATHINFO_FILENAME);
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                $modules[] = strtolower($name);
            }
        }

        // Tenant override modules
        if ($this->tenantModulesDir && is_dir($this->tenantModulesDir)) {
            foreach (glob($this->tenantModulesDir . '/*.php') ?: [] as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if (preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                    $modules[] = strtolower($name);
                }
            }
        }

        // Database-defined modules
        try {
            $db   = $this->container->get(Connection::class);
            $rows = $db->fetchAll(
                "SELECT name FROM ib_sys_mods WHERE status = 'active' ORDER BY name"
            );
            foreach ($rows as $row) {
                $modules[] = (string) $row['name'];
            }
        } catch (\Throwable) {
            // Silently skip if DB is unavailable
        }

        return array_unique($modules);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate and sanitize a module name.
     *
     * @throws \InvalidArgumentException
     */
    private function validateName(string $name): string
    {
        $clean = preg_replace('/[^a-z0-9_]/', '', strtolower($name));
        if ($clean !== $name || $clean === '') {
            throw new \InvalidArgumentException(
                "Invalid module name '{$name}'. Only lowercase letters, digits and underscores allowed."
            );
        }
        return $clean;
    }

    /**
     * Resolve file path using priority chain.
     * Tries both PascalCase and snake_case filenames.
     */
    private function resolvePath(string $name): ?string
    {
        // 1. Tenant override (snake_case)
        if ($this->tenantModulesDir) {
            $path = $this->tenantModulesDir . '/' . $name . '.php';
            if (is_file($path)) {
                return $path;
            }
        }

        $pascal = implode('', array_map('ucfirst', explode('_', $name)));
        $prefix = explode('_', $name)[0];

        // 2. Built-in module in prefix subdirectory (PascalCase primary)
        $candidates = [
            $this->modulesDir . '/' . $prefix . '/' . $pascal . '.php', // modules/sys/SysUsers.php
            $this->modulesDir . '/' . $prefix . '/' . $name  . '.php',  // modules/sys/sys_users.php
            $this->modulesDir . '/' . $pascal . '.php',                  // modules/SysUsers.php
            $this->modulesDir . '/' . $name  . '.php',                   // modules/sys_users.php
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        // 3. Qualsevol subdirectori: el nom del directori no sempre coincideix
        // amb el prefix del mòdul (p.ex. project_budgets viu a modules/projects/)
        foreach (glob($this->modulesDir . '/*/' . $pascal . '.php') ?: [] as $path) {
            return $path;
        }

        return null;
    }

    /**
     * Convert snake_case module name to PascalCase class name.
     *
     * Examples:
     *   sys_users → Intrabase\Modules\Sys\SysUsers
     *   lib_files → Intrabase\Modules\Lib\LibFiles
     *   obj_fam   → Intrabase\Modules\Obj\ObjFam
     *   obj       → Intrabase\Modules\Obj\Obj
     *
     * Quan es coneix el $path resolt, el namespace es deriva del directori
     * real del fitxer (el prefix del nom no sempre hi coincideix — p.ex.
     * project_budgets viu a modules/projects/ → Modules\Projects).
     */
    private function resolveClassName(string $name, ?string $path = null): string
    {
        $parts  = explode('_', $name);
        $prefix = ucfirst($parts[0]);
        $pascal = implode('', array_map('ucfirst', $parts));

        if ($path !== null) {
            $dir  = basename(dirname($path));
            $base = pathinfo($path, PATHINFO_FILENAME);
            // Fitxer PascalCase → és el nom de classe real
            if (!str_contains($base, '_') && ctype_upper($base[0] ?? '')) {
                $pascal = $base;
            }
            if ($dir !== '' && $dir !== basename($this->modulesDir)
                && preg_match('/^[a-z][a-z0-9]*$/', $dir)) {
                $prefix = ucfirst($dir);
            }
        }

        return "Intrabase\\Modules\\{$prefix}\\{$pascal}";
    }

    /**
     * Try to load a module defined in ib_sys_mods.
     *
     * Fetches the module row, its attributes and actions, then creates and
     * configures a DynamicModule instance.
     *
     * @return DynamicModule|null  null if not found or module is inactive
     */
    private function loadDynamic(string $name): ?DynamicModule
    {
        try {
            $db  = $this->container->get(Connection::class);
            $pdo = $db->pdo();

            // 1. Load module definition
            $stmt = $pdo->prepare(
                "SELECT id, name, label, table_name, primary_key, icon_class,
                        order_by, per_page, base_where, show_key,
                        can_create, can_edit, can_delete, can_export,
                        can_duplicate, can_import, can_multiselect,
                        on_document_ready, totals_fields, custom_css, status
                   FROM ib_sys_mods
                  WHERE name = :name AND status = 'active'
                  LIMIT 1"
            );
            $stmt->execute([':name' => $name]);
            $modRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$modRow) {
                return null;
            }

            $modId = (int) $modRow['id'];

            // 2. Load attributes ordered by sort_order
            $attrStmt = $pdo->prepare(
                'SELECT field_name, field_label, abbrev, field_type, field_options,
                        default_value, is_required, is_searchable, is_readonly,
                        show_in_list, show_in_edit, show_in_detail,
                        sort_order, field_group, validation_regex, notes,
                        help_text, max_length, no_wrap, css_class,
                        on_change_js, is_totals
                   FROM ib_sys_mod_attributes
                  WHERE mod_id = :mod_id
                  ORDER BY sort_order ASC, id ASC'
            );
            $attrStmt->execute([':mod_id' => $modId]);
            $attributes = $attrStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // 3. Load active custom actions ordered by sort_order
            $actStmt = $pdo->prepare(
                'SELECT action_name, action_label, action_type, icon_class,
                        url_pattern, confirm_message, target, sort_order, is_active
                   FROM ib_sys_mod_actions
                  WHERE mod_id = :mod_id AND is_active = 1
                  ORDER BY sort_order ASC, id ASC'
            );
            $actStmt->execute([':mod_id' => $modId]);
            $actions = $actStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // 4. Instantiate and configure DynamicModule
            $module = new DynamicModule(
                $this->container->get(Connection::class),
                $this->container->get(CSRF::class),
                $this->container
            );
            $module->configure($modRow, $attributes, $actions);

            return $module;

        } catch (\Throwable $e) {
            // Log but do not propagate — caller will throw ModuleNotFoundException
            error_log('[Intrabase] DynamicModule load error for "' . $name . '": ' . $e->getMessage());
            return null;
        }
    }
}

/**
 * Exception thrown when a requested module cannot be found
 */
class ModuleNotFoundException extends \RuntimeException
{
}
