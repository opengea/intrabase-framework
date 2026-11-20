<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Core\Router;
use Intrabase\Security\CSRF;
use Intrabase\Security\Input;
use Intrabase\Security\Output;
use Intrabase\Support\ConfigManager;

/**
 * SysModuleManager — Visual editor for app/module configuration
 *
 * Allows administrators to:
 *  - Enable / disable apps
 *  - Add / remove modules from an app
 *  - Reorder modules and groups within an app
 *  - Rename group names and icons
 *  - Configure default module per app
 *
 * No database table of its own — reads/writes ib_apps.modules JSON.
 */
class SysModuleManager extends Module
{
    public string $table = '';
    public string $key   = 'id';
    public string $title = 'Gestor de Mòduls';
    public string $icon  = 'fa-solid fa-cubes';

    public bool $canAdd    = false;
    public bool $canEdit   = false;
    public bool $canDelete = false;
    public bool $canExport = false;

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    public function execute(): void
    {
        $this->applyPermissions();

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $action = Input::get('action', '');

        if ($method === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            $this->handlePost();
            return;
        }

        ob_start();
        $this->renderPage();
        $html = ob_get_clean();
        $this->renderLayout((string) $html);
    }

    // -------------------------------------------------------------------------
    // POST handler — returns JSON
    // -------------------------------------------------------------------------

    private function handlePost(): void
    {
        if (!$this->csrf->validateToken()) {
            echo json_encode(['ok' => false, 'error' => 'Token invàlid']);
            return;
        }

        $action = Input::post('action', '');

        switch ($action) {
            case 'save_app_modules':
                $this->actionSaveAppModules();
                break;
            case 'toggle_app':
                $this->actionToggleApp();
                break;
            case 'save_app_meta':
                $this->actionSaveAppMeta();
                break;
            case 'toggle_module':
                $this->actionToggleModule();
                break;
            case 'sync_module':
                $this->actionSyncModule();
                break;
            default:
                echo json_encode(['ok' => false, 'error' => 'Acció desconeguda']);
        }
    }

    private function actionSaveAppModules(): void
    {
        $appId   = (int) Input::post('app_id', '0');
        $modules = Input::post('modules', '');

        if ($appId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'App ID invàlid']);
            return;
        }

        // Validate JSON
        $decoded = json_decode($modules, true);
        if (!is_array($decoded)) {
            echo json_encode(['ok' => false, 'error' => 'JSON invàlid']);
            return;
        }

        // Sanitize: only allow known fields
        $clean = [];
        foreach ($decoded as $group) {
            if (!is_array($group)) continue;
            $cleanMods = [];
            foreach ((array)($group['modules'] ?? []) as $mod) {
                if (empty($mod['key'])) continue;
                $cleanMods[] = [
                    'key'  => preg_replace('/[^a-z0-9_]/', '', (string)($mod['key'] ?? '')),
                    'name' => substr(strip_tags((string)($mod['name'] ?? '')), 0, 100),
                    'icon' => substr(strip_tags((string)($mod['icon'] ?? '')), 0, 80),
                ];
            }
            $clean[] = [
                'group'     => substr(strip_tags((string)($group['group'] ?? 'Mòduls')), 0, 80),
                'icon'      => substr(strip_tags((string)($group['icon'] ?? '')), 0, 80),
                'collapsed' => (bool)($group['collapsed'] ?? false),
                'modules'   => $cleanMods,
            ];
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->db->execute(
            'UPDATE ib_apps SET modules = ? WHERE id = ?',
            [$json, $appId]
        );

        echo json_encode(['ok' => true, 'message' => 'Mòduls desats correctament']);
    }

    private function actionToggleApp(): void
    {
        $appId = (int) Input::post('app_id', '0');
        if ($appId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'App ID invàlid']);
            return;
        }

        $app = $this->db->fetchOne('SELECT is_active FROM ib_apps WHERE id = ?', [$appId]);
        if (!$app) {
            echo json_encode(['ok' => false, 'error' => 'App no trobada']);
            return;
        }

        $newState = $app['is_active'] ? 0 : 1;
        $this->db->execute('UPDATE ib_apps SET is_active = ? WHERE id = ?', [$newState, $appId]);

        echo json_encode(['ok' => true, 'is_active' => $newState]);
    }

    private function actionToggleModule(): void
    {
        $moduleKey = preg_replace('/[^a-zA-Z0-9_]/', '', Input::post('module_key', ''));
        $enabled   = (int) Input::post('enabled', '1');

        if ($moduleKey === '') {
            echo json_encode(['ok' => false, 'error' => 'Clau de mòdul invàlida']);
            return;
        }

        $json = ConfigManager::get('disabled_modules', '[]');
        $disabled = json_decode($json, true) ?: [];

        if ($enabled) {
            $disabled = array_values(array_filter($disabled, fn($k) => $k !== $moduleKey));
        } else {
            if (!in_array($moduleKey, $disabled, true)) {
                $disabled[] = $moduleKey;
            }
        }

        ConfigManager::set('disabled_modules', json_encode($disabled, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true, 'disabled' => $disabled]);
    }

    private function actionSaveAppMeta(): void
    {
        $appId          = (int) Input::post('app_id', '0');
        $name           = substr(strip_tags(Input::post('name', '')), 0, 80);
        $icon           = substr(strip_tags(Input::post('icon', '')), 0, 80);
        $defaultModule  = preg_replace('/[^a-z0-9_]/', '', Input::post('default_module', ''));
        $sortOrder      = (int) Input::post('sort_order', '0');

        if ($appId <= 0 || empty($name)) {
            echo json_encode(['ok' => false, 'error' => 'Dades invàlides']);
            return;
        }

        $this->db->execute(
            'UPDATE ib_apps SET name = ?, icon = ?, default_module = ?, sort_order = ? WHERE id = ?',
            [$name, $icon, $defaultModule, $sortOrder, $appId]
        );

        echo json_encode(['ok' => true, 'message' => 'App actualitzada']);
    }

    /**
     * Sync a physical module into ib_sys_mods + ib_sys_mod_attributes.
     *
     * Reads the PHP file to extract $fields, $humans, $components, $readonly,
     * $hidden, $validates, $searchFields, $showFields, $editFields, $abbrev,
     * $maxLengths, $orderBy, $orderDir, $perPage, $combos, setGroup() calls.
     * Also reads the actual DB table columns to discover all available fields.
     */
    private function actionSyncModule(): void
    {
        $key   = preg_replace('/[^a-z0-9_]/', '', Input::post('module_key', ''));
        $label = trim(Input::post('module_label', ''));
        $icon  = trim(Input::post('module_icon', 'fa-solid fa-database'));
        $table = trim(Input::post('module_table', ''));

        if ($key === '') {
            echo json_encode(['ok' => false, 'error' => 'Clau de mòdul invàlida']);
            return;
        }

        // Check if already exists
        $existing = $this->db->fetchOne('SELECT id FROM ib_sys_mods WHERE name = ?', [$key]);
        if ($existing) {
            $modId = (int)$existing['id'];
            // Still run field sync
            $this->syncModuleFields($modId, $key, $table);
            echo json_encode(['ok' => true, 'id' => $modId, 'action' => 'synced']);
            return;
        }

        // Try to extract full config from PHP file
        $phpConfig = $this->extractModuleConfig($key);

        // Create module entry with extracted config
        $modId = (int)$this->db->insert('ib_sys_mods', [
            'name'       => $key,
            'label'      => $phpConfig['title'] ?? $label ?: $key,
            'table_name' => $phpConfig['table'] ?? $table ?: ('ib_' . $key),
            'icon_class' => $phpConfig['icon'] ?? $icon,
            'order_by'   => isset($phpConfig['orderBy']) ? ($phpConfig['orderBy'] . ' ' . ($phpConfig['orderDir'] ?? 'ASC')) : null,
            'per_page'   => (int)($phpConfig['perPage'] ?? 25) ?: 25,
            'can_create'    => (int)($phpConfig['canAdd'] ?? 1),
            'can_edit'      => (int)($phpConfig['canEdit'] ?? 1),
            'can_delete'    => (int)($phpConfig['canDelete'] ?? 1),
            'can_export'    => (int)($phpConfig['canExport'] ?? 1),
            'can_duplicate' => (int)($phpConfig['canDuplicate'] ?? 0),
            'status'     => 'active',
        ]);

        // Sync fields
        $realTable = $phpConfig['table'] ?? $table ?: ('ib_' . $key);
        $this->syncModuleFields($modId, $key, $realTable, $phpConfig);

        echo json_encode(['ok' => true, 'id' => $modId, 'action' => 'created']);
    }

    /**
     * Extract module configuration from a physical PHP file by parsing its source.
     * Returns an associative array of all extractable properties.
     */
    private function extractModuleConfig(string $moduleKey): array
    {
        $modRoot = dirname(__DIR__, 2) . '/modules';
        $pascal  = implode('', array_map('ucfirst', explode('_', $moduleKey)));
        $prefix  = explode('_', $moduleKey)[0];

        $candidates = [
            $modRoot . '/' . $prefix . '/' . $pascal . '.php',
            $modRoot . '/' . $prefix . '/' . $moduleKey . '.php',
        ];

        $src = '';
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $src = file_get_contents($path);
                break;
            }
        }

        if ($src === '') {
            return [];
        }

        $config = [];

        // Extract simple string properties
        foreach (['table', 'title', 'icon', 'orderBy', 'orderDir'] as $prop) {
            $val = $this->extractProp($src, $prop);
            if ($val !== '') $config[$prop] = $val;
        }

        // Extract simple int/bool properties
        foreach (['perPage'] as $prop) {
            if (preg_match('/public\s+int\s+\$' . preg_quote($prop) . '\s*=\s*(\d+)/m', $src, $m)) {
                $config[$prop] = (int)$m[1];
            }
        }
        foreach (['canAdd', 'canEdit', 'canDelete', 'canExport', 'canDuplicate', 'canImport'] as $prop) {
            if (preg_match('/public\s+bool\s+\$' . preg_quote($prop) . '\s*=\s*(true|false)/mi', $src, $m)) {
                $config[$prop] = strtolower($m[1]) === 'true' ? 1 : 0;
            }
        }

        // Extract array properties
        foreach (['fields', 'editFields', 'showFields', 'searchFields', 'validates',
                   'readonly', 'hidden', 'detailFields', 'totalsFields', 'noWrap'] as $prop) {
            $arr = $this->extractArrayProp($src, $prop);
            if (!empty($arr)) $config[$prop] = $arr;
        }

        // Extract associative array properties
        foreach (['humans', 'components', 'abbrev', 'maxLengths', 'comments', 'classes'] as $prop) {
            $arr = $this->extractAssocProp($src, $prop);
            if (!empty($arr)) $config[$prop] = $arr;
        }

        // Extract setGroup() calls
        $groups = $this->extractGroups($src);
        if (!empty($groups)) $config['groups'] = $groups;

        return $config;
    }

    /**
     * Extract a PHP array property like: public array $fields = ['id', 'name', ...];
     */
    private function extractArrayProp(string $src, string $prop): array
    {
        if (preg_match('/public\s+array\s+\$' . preg_quote($prop) . '\s*=\s*\[([^\]]*)\]/ms', $src, $m)) {
            preg_match_all("/['\"]([^'\"]+)['\"]/", $m[1], $items);
            return $items[1] ?? [];
        }
        return [];
    }

    /**
     * Extract an associative array property like: public array $humans = ['key' => 'Label', ...];
     */
    private function extractAssocProp(string $src, string $prop): array
    {
        if (preg_match('/public\s+array\s+\$' . preg_quote($prop) . '\s*=\s*\[([^\]]*)\]/ms', $src, $m)) {
            $result = [];
            preg_match_all("/['\"]([^'\"]+)['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $m[1], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $result[$pair[1]] = $pair[2];
            }
            return $result;
        }
        return [];
    }

    /**
     * Extract setGroup() calls from the constructor.
     * Pattern: $this->setGroup('key', ['label' => '...', 'icon' => '...', 'fields' => [...]]);
     */
    private function extractGroups(string $src): array
    {
        $groups = [];
        if (preg_match_all("/\\\$this->setGroup\(\s*['\"]([^'\"]+)['\"],\s*\[([^\]]*\[[^\]]*\][^\]]*)\]/ms", $src, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $groupKey = $m[1];
                $body = $m[2];
                $label = '';
                $fields = [];
                if (preg_match("/['\"]label['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $body, $lm)) {
                    $label = $lm[1];
                }
                if (preg_match("/['\"]fields['\"]\s*=>\s*\[([^\]]*)\]/", $body, $fm)) {
                    preg_match_all("/['\"]([^'\"]+)['\"]/", $fm[1], $fItems);
                    $fields = $fItems[1] ?? [];
                }
                if (!empty($fields)) {
                    $groups[$groupKey] = ['label' => $label ?: $groupKey, 'fields' => $fields];
                }
            }
        }
        return $groups;
    }

    /**
     * Sync all fields for a module: reads DB columns + PHP config, creates/updates ib_sys_mod_attributes.
     */
    private function syncModuleFields(int $modId, string $moduleKey, string $tableName, ?array $phpConfig = null): void
    {
        if ($phpConfig === null) {
            $phpConfig = $this->extractModuleConfig($moduleKey);
        }

        // Get existing attributes for this module
        $existingAttrs = $this->db->fetchAll(
            'SELECT id, field_name FROM ib_sys_mod_attributes WHERE mod_id = ?',
            [$modId]
        );
        $existingByName = [];
        foreach ($existingAttrs as $ea) {
            $existingByName[$ea['field_name']] = (int)$ea['id'];
        }

        // Discover all columns from the actual DB table
        $dbColumns = [];
        try {
            $pdo = $this->db->pdo();
            $cols = $pdo->query("SHOW COLUMNS FROM `" . addslashes($tableName) . "`")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                $dbColumns[$col['Field']] = $col;
            }
        } catch (\Throwable) {
            // Table may not exist yet
        }

        // Build the master field list: PHP $fields first (preserves order), then any extra DB columns
        $phpFields     = $phpConfig['fields'] ?? [];
        $editFields    = array_flip($phpConfig['editFields'] ?? []);
        $showFields    = array_flip($phpConfig['showFields'] ?? []);
        $searchFields  = array_flip($phpConfig['searchFields'] ?? []);
        $validates     = array_flip($phpConfig['validates'] ?? []);
        $readonlySet   = array_flip($phpConfig['readonly'] ?? []);
        $hiddenSet     = array_flip($phpConfig['hidden'] ?? []);
        $totalsSet     = array_flip($phpConfig['totalsFields'] ?? []);
        $noWrapSet     = array_flip($phpConfig['noWrap'] ?? []);
        $humans        = $phpConfig['humans'] ?? [];
        $components    = $phpConfig['components'] ?? [];
        $abbrevMap     = $phpConfig['abbrev'] ?? [];
        $maxLengths    = $phpConfig['maxLengths'] ?? [];
        $comments      = $phpConfig['comments'] ?? [];
        $classes       = $phpConfig['classes'] ?? [];
        $groups        = $phpConfig['groups'] ?? [];

        // Build field->group map from groups config
        $fieldGroupMap = [];
        foreach ($groups as $grpKey => $grp) {
            foreach ($grp['fields'] as $gf) {
                $fieldGroupMap[$gf] = $grp['label'];
            }
        }

        // Merge: PHP fields + DB columns (skip 'id' and audit fields)
        $skipFields = ['id', 'created_at', 'updated_at', 'created_by', 'updated_by'];
        $allFields = [];

        // First: fields from PHP (preserves intended order)
        foreach ($phpFields as $f) {
            if (!in_array($f, $skipFields, true)) {
                $allFields[$f] = true;
            }
        }
        // Then: any DB column not already listed
        foreach (array_keys($dbColumns) as $colName) {
            if (!in_array($colName, $skipFields, true) && !isset($allFields[$colName])) {
                $allFields[$colName] = true;
            }
        }

        // Determine show_in_list: if $showFields is defined, use it; else if $fields is defined, use $fields
        $hasShowFields = !empty($phpConfig['showFields']);
        $hasEditFields = !empty($phpConfig['editFields']);

        $sortOrder = 0;
        $pdo = $this->db->pdo();

        foreach ($allFields as $fieldName => $_) {
            $sortOrder += 10;

            // Determine field type from component or DB column type
            $fieldType = 'text';
            if (isset($components[$fieldName])) {
                $fieldType = $this->componentToFieldType($components[$fieldName]);
            } elseif (isset($dbColumns[$fieldName])) {
                $fieldType = $this->dbTypeToFieldType($dbColumns[$fieldName]['Type']);
            }

            $isInFields    = in_array($fieldName, $phpFields, true);
            $showInList    = $hasShowFields ? isset($showFields[$fieldName]) : $isInFields;
            $showInEdit    = $hasEditFields ? isset($editFields[$fieldName]) : $isInFields;
            $isHidden      = isset($hiddenSet[$fieldName]);

            if ($isHidden) {
                $fieldType  = 'hidden';
                $showInList = false;
                $showInEdit = false;
            }

            $data = [
                'field_label'   => $humans[$fieldName] ?? ucfirst(str_replace('_', ' ', $fieldName)),
                'abbrev'        => $abbrevMap[$fieldName] ?? null,
                'field_type'    => $fieldType,
                'is_required'   => isset($validates[$fieldName]) ? 1 : 0,
                'is_searchable' => isset($searchFields[$fieldName]) ? 1 : 0,
                'is_readonly'   => isset($readonlySet[$fieldName]) ? 1 : 0,
                'show_in_list'  => $showInList ? 1 : 0,
                'show_in_edit'  => $showInEdit ? 1 : 0,
                'show_in_detail'=> 1,
                'sort_order'    => $sortOrder,
                'field_group'   => $fieldGroupMap[$fieldName] ?? null,
                'max_length'    => $maxLengths[$fieldName] ?? null,
                'no_wrap'       => isset($noWrapSet[$fieldName]) ? 1 : 0,
                'css_class'     => $classes[$fieldName] ?? null,
                'help_text'     => $comments[$fieldName] ?? null,
                'is_totals'     => isset($totalsSet[$fieldName]) ? 1 : 0,
            ];

            if (isset($existingByName[$fieldName])) {
                // Update existing — don't overwrite user customizations blindly,
                // only update fields that are still at default/empty
                // For now, just skip if already exists
                continue;
            }

            // Insert new
            $data['mod_id']     = $modId;
            $data['field_name'] = $fieldName;
            $this->db->insert('ib_sys_mod_attributes', $data);
        }
    }

    /**
     * Map a Module component name to a field_type for ib_sys_mod_attributes.
     */
    private function componentToFieldType(string $component): string
    {
        return match ($component) {
            'textarea'   => 'textarea',
            'html'       => 'html',
            'integer'    => 'integer',
            'decimal'    => 'decimal',
            'money'      => 'money',
            'percentage' => 'percentage',
            'date'       => 'date',
            'datetime'   => 'datetime',
            'time'       => 'time',
            'email'      => 'email',
            'url'        => 'url',
            'phone'      => 'phone',
            'color'      => 'color',
            'combo'      => 'combo',
            'multicombo' => 'combo',
            'checklist'  => 'checkbox',
            'radiolist'  => 'select',
            'picture'    => 'picture',
            'file'       => 'file',
            'rating'     => 'rating',
            'slug'       => 'slug',
            'json'       => 'json',
            'password'   => 'password',
            'status_icon'=> 'select',
            'xref'       => 'combo',
            default      => 'text',
        };
    }

    /**
     * Map a MySQL column type to a field_type.
     */
    private function dbTypeToFieldType(string $mysqlType): string
    {
        $type = strtolower($mysqlType);
        if (str_contains($type, 'int'))       return 'integer';
        if (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) return 'decimal';
        if ($type === 'date')                 return 'date';
        if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) return 'datetime';
        if ($type === 'time')                 return 'time';
        if (str_contains($type, 'text') || str_contains($type, 'longtext') || str_contains($type, 'mediumtext')) return 'textarea';
        if (str_contains($type, 'json'))      return 'json';
        if (str_contains($type, 'tinyint(1)')) return 'checkbox';
        if (str_contains($type, 'enum'))      return 'select';
        return 'text';
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    private function renderPage(): void
    {
        $apps         = $this->db->fetchAll('SELECT * FROM ib_apps ORDER BY sort_order ASC', []);
        $activeApp    = (int) Input::get('app_id', (string)($apps[0]['id'] ?? 0));
        $currentApp   = null;
        foreach ($apps as $a) {
            if ((int)$a['id'] === $activeApp) { $currentApp = $a; break; }
        }
        if (!$currentApp && !empty($apps)) { $currentApp = $apps[0]; $activeApp = (int)$currentApp['id']; }

        $availableModules = $this->discoverAllModules();
        $disabledJson = ConfigManager::get('disabled_modules', '[]');
        $disabledMods = json_decode($disabledJson, true) ?: [];

        // Build map of module key => ib_sys_mods.id for configure button
        $modRows = $this->db->fetchAll('SELECT id, name FROM ib_sys_mods', []);
        $modIdMap = [];
        foreach ($modRows as $row) {
            $modIdMap[$row['name']] = (int)$row['id'];
        }

        // Build flat map key => {name, icon, table} from available modules
        $modMetaMap = [];
        foreach ($availableModules as $mods) {
            foreach ($mods as $m) {
                $modMetaMap[$m['key']] = $m;
            }
        }
        $csrf = $this->csrf->field();
        $csrfToken = $this->csrf->token();

        // Build app tabs
        $tabsHtml = '';
        $routeApp = Router::currentApp() ?: 'system';
        foreach ($apps as $app) {
            $id      = (int)$app['id'];
            $active  = $id === $activeApp ? ' ib-tabs__nav-item--active' : '';
            $inactive = $app['is_active'] ? '' : ' mm-tab--inactive';
            $icon    = Output::e($app['icon'] ?? 'fa-solid fa-cubes');
            $name    = Output::e($app['name'] ?? $app['keyname']);
            $url     = Output::e('/' . $routeApp . '/sys_module_manager?app_id=' . $id);
            $tabsHtml .= "<a href=\"{$url}\" class=\"ib-tabs__nav-item{$active}{$inactive}\" data-app-id=\"{$id}\">"
                . "<i class=\"{$icon}\"></i> <span>{$name}</span></a>\n";
        }

        // Build module editor for current app
        $editorHtml = '';
        if ($currentApp) {
            $editorHtml = $this->renderAppEditor($currentApp, $availableModules, $csrf, $csrfToken, $disabledMods, $modIdMap, $modMetaMap);
        }

        // Available modules list (for the add panel)
        $availableJson = json_encode($availableModules, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
        $disabledJsonSafe = json_encode($disabledMods, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
        $modIdMapJson = json_encode($modIdMap, JSON_HEX_TAG | JSON_HEX_AMP);
        $modMetaMapJson = json_encode($modMetaMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

        echo $this->css();
        echo <<<HTML
        <div class="ib-browser">
            <div class="ib-browser__toolbar">
                <h2 class="ib-browser__title">
                    <i class="fa-solid fa-cubes" aria-hidden="true"></i>
                    Gestor de Mòduls i Aplicacions
                </h2>
                <div class="ib-browser__toolbar-right">
                    <button class="ib-btn ib-btn--primary ib-btn--sm" onclick="mmSaveAll()" id="btn-save-all">
                        <i class="fa-solid fa-floppy-disk"></i> Desar canvis
                    </button>
                </div>
            </div>

            <!-- App tabs -->
            <div class="ib-tabs">
                <nav class="ib-tabs__nav" id="mm-tabs">
                    {$tabsHtml}
                </nav>
            </div>

            <!-- Editor area -->
            <div class="mm-editor" id="mm-editor">
                {$editorHtml}
            </div>

            <div id="mm-toast" class="mm-toast" style="display:none"></div>
        </div>

        <script>
        window.MM_CFG = {
            csrf: '{$csrfToken}',
            available: {$availableJson},
            appId: {$activeApp},
            disabled: JSON.parse('{$disabledJsonSafe}'),
            modIdMap: {$modIdMapJson},
            modMeta: {$modMetaMapJson},
            routeApp: '{$routeApp}'
        };
        </script>
        <script>{$this->js()}</script>
        HTML;
    }

    private function renderAppEditor(array $app, array $availableModules, string $csrf, string $csrfToken, array $disabledMods = [], array $modIdMap = [], array $modMetaMap = []): string
    {
        $appId   = (int)$app['id'];
        $appName = Output::e($app['name'] ?? $app['keyname']);
        $appIcon = Output::e($app['icon'] ?? '');
        $defMod  = Output::e($app['default_module'] ?? '');
        $sortOrd = (int)($app['sort_order'] ?? 0);
        $isActive = (int)($app['is_active'] ?? 1);
        $toggleClass  = $isActive ? 'ib-btn--danger' : 'ib-btn--success';
        $toggleLabel  = $isActive ? '<i class="fa-solid fa-eye-slash"></i> Desactivar app' : '<i class="fa-solid fa-eye"></i> Activar app';
        $activeLabel  = $isActive ? '<span class="ib-badge ib-badge--success">Activa</span>' : '<span class="ib-badge ib-badge--neutral">Inactiva</span>';

        $modules = json_decode((string)($app['modules'] ?? '[]'), true) ?: [];

        // Build the groups editor
        $groupsHtml = '';
        foreach ($modules as $gi => $group) {
            $groupsHtml .= $this->renderGroupEditor($gi, $group, $disabledMods, $modIdMap, $modMetaMap);
        }

        // Build available modules list (not yet in this app)
        $usedKeys = [];
        foreach ($modules as $g) {
            foreach ((array)($g['modules'] ?? []) as $m) {
                $usedKeys[] = $m['key'];
            }
        }
        $unusedHtml = '';
        foreach ($availableModules as $prefix => $mods) {
            foreach ($mods as $mod) {
                if (in_array($mod['key'], $usedKeys, true)) continue;
                $key  = Output::e($mod['key']);
                $name = Output::e($mod['name']);
                $icon = Output::e($mod['icon']);
                $unusedHtml .= <<<HTML
                <div class="mm-avail-item" data-key="{$key}" data-name="{$name}" data-icon="{$icon}">
                    <i class="{$icon}"></i>
                    <span class="mm-avail-name">{$name}</span>
                    <code class="mm-avail-key">{$key}</code>
                    <button type="button" class="mm-avail-add" onclick="mmAddModule('{$key}', '{$name}', '{$icon}')">
                        <i class="fa-solid fa-plus"></i>
                    </button>
                </div>
                HTML;
            }
        }
        if (!$unusedHtml) {
            $unusedHtml = '<div class="mm-avail-empty"><i class="fa-solid fa-check-circle"></i> Tots els mòduls estan afegits</div>';
        }

        return <<<HTML
        <div class="mm-app-editor" data-app-id="{$appId}">
            <!-- App meta -->
            <div class="mm-app-meta">
                <div class="mm-app-meta-left">
                    <h2 class="mm-app-name">{$appName} {$activeLabel}</h2>
                </div>
                <div class="mm-app-meta-right">
                    <button type="button" class="ib-btn ib-btn--sm ib-btn--outline" onclick="mmToggleMetaForm()">
                        <i class="fa-solid fa-gear"></i> Configurar app
                    </button>
                    <button type="button" class="ib-btn ib-btn--sm {$toggleClass}" onclick="mmToggleApp({$appId})">
                        {$toggleLabel}
                    </button>
                </div>
            </div>

            <!-- App meta edit form (hidden by default) -->
            <div class="ib-card mm-meta-form" id="mm-meta-form" style="display:none">
                {$csrf}
                <input type="hidden" name="app_id" value="{$appId}">
                <div class="mm-meta-grid">
                    <div class="mm-meta-field">
                        <label>Nom de l'app</label>
                        <input type="text" id="meta-name" name="name" value="{$appName}" maxlength="80">
                    </div>
                    <div class="mm-meta-field">
                        <label>Icona (Font Awesome)</label>
                        <input type="text" id="meta-icon" name="icon" value="{$appIcon}" placeholder="fa-solid fa-cubes" maxlength="80">
                    </div>
                    <div class="mm-meta-field">
                        <label>Mòdul per defecte</label>
                        <input type="text" id="meta-default" name="default_module" value="{$defMod}" placeholder="nom_modul" maxlength="80">
                    </div>
                    <div class="mm-meta-field">
                        <label>Ordre</label>
                        <input type="number" id="meta-sort" name="sort_order" value="{$sortOrd}" min="0">
                    </div>
                </div>
                <button type="button" class="ib-btn ib-btn--sm ib-btn--primary" onclick="mmSaveAppMeta({$appId})">
                    <i class="fa-solid fa-floppy-disk"></i> Desar meta
                </button>
            </div>

            <!-- Two-column layout: groups editor + available modules -->
            <div class="mm-columns">
                <div class="mm-col-left">
                    <div class="mm-col-header">
                        <span><i class="fa-solid fa-list"></i> Estructura del menú</span>
                        <button type="button" class="ib-btn ib-btn--sm ib-btn--outline" onclick="mmAddGroup()">
                            <i class="fa-solid fa-folder-plus"></i> Nou grup
                        </button>
                    </div>
                    <div id="mm-groups" class="mm-groups">
                        {$groupsHtml}
                    </div>
                    <div class="mm-hint">
                        <i class="fa-solid fa-info-circle"></i>
                        Fes clic als botons ↑↓ per reordenar. Afegeix mòduls des del panell dret.
                    </div>
                </div>

                <div class="mm-col-right">
                    <div class="mm-col-header">
                        <span><i class="fa-solid fa-puzzle-piece"></i> Mòduls disponibles</span>
                        <input type="search" id="mm-avail-search" placeholder="Cercar..." oninput="mmFilterAvail(this.value)" class="mm-search">
                    </div>
                    <div id="mm-available" class="mm-available">
                        {$unusedHtml}
                    </div>
                </div>
            </div>
        </div>
        HTML;
    }

    private function renderGroupEditor(int $gi, array $group, array $disabledMods = [], array $modIdMap = [], array $modMetaMap = []): string
    {
        $groupName = Output::e($group['group'] ?? 'Grup ' . ($gi + 1));
        $groupIcon = Output::e($group['icon'] ?? 'fa-solid fa-folder');
        $collapsed = true;
        $disabledSet = array_flip($disabledMods);
        $routeApp = Router::currentApp() ?: 'system';

        $modsHtml = '';
        foreach ((array)($group['modules'] ?? []) as $mi => $mod) {
            $key  = Output::e($mod['key'] ?? '');
            $rawKey = $mod['key'] ?? '';
            $name = Output::e($mod['name'] ?? $mod['key']);
            $icon = Output::e($mod['icon'] ?? 'fa-solid fa-circle');
            $isDisabled = isset($disabledSet[$mod['key'] ?? '']);
            $disabledCls = $isDisabled ? ' mm-mod-item--disabled' : '';
            $eyeIcon = $isDisabled ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            $eyeTitle = $isDisabled ? 'Activar mòdul' : 'Desactivar mòdul';

            // Configure button: direct link if already in ib_sys_mods, otherwise sync first
            $configBtn = '';
            if (isset($modIdMap[$rawKey])) {
                $editUrl = Output::e("/{$routeApp}/sys_mod/{$modIdMap[$rawKey]}/edit");
                $configBtn = '<a href="' . $editUrl . '" class="mm-icon-btn mm-icon-btn--config" title="Configurar mòdul"><i class="fa-solid fa-gear"></i></a>';
            } else {
                $meta = $modMetaMap[$rawKey] ?? [];
                $jsLabel = Output::attr($meta['name'] ?? $rawKey);
                $jsIcon  = Output::attr($meta['icon'] ?? 'fa-solid fa-database');
                $jsTable = Output::attr($meta['table'] ?? '');
                $configBtn = '<button type="button" class="mm-icon-btn mm-icon-btn--config" onclick="mmSyncAndConfigure(\'' . $key . '\', \'' . $jsLabel . '\', \'' . $jsIcon . '\', \'' . $jsTable . '\')" title="Configurar mòdul"><i class="fa-solid fa-gear"></i></button>';
            }

            $modsHtml .= <<<HTML
            <div class="mm-mod-item{$disabledCls}" data-key="{$key}" data-group-idx="{$gi}" data-mod-idx="{$mi}">
                <div class="mm-mod-drag">⠿</div>
                <i class="mm-mod-icon {$icon}"></i>
                <span class="mm-mod-name">{$name}</span>
                <code class="mm-mod-key">{$key}</code>
                <div class="mm-mod-actions">
                    {$configBtn}
                    <button type="button" class="mm-icon-btn mm-icon-btn--toggle" onclick="mmToggleModuleVisibility('{$key}')" title="{$eyeTitle}">
                        <i class="{$eyeIcon}"></i>
                    </button>
                    <button type="button" class="mm-icon-btn" onclick="mmMoveModUp({$gi}, {$mi})" title="Amunt">↑</button>
                    <button type="button" class="mm-icon-btn" onclick="mmMoveModDown({$gi}, {$mi})" title="Avall">↓</button>
                    <button type="button" class="mm-icon-btn mm-icon-btn--del" onclick="mmRemoveMod({$gi}, {$mi})" title="Eliminar">×</button>
                </div>
            </div>
            HTML;
        }
        if (!$modsHtml) {
            $modsHtml = '<div class="mm-empty-group">Grup buit — afegeix mòduls des del panell dret</div>';
        }

        $collapsedCls = $collapsed ? ' collapsed' : '';

        return <<<HTML
        <div class="mm-group{$collapsedCls}" data-group-idx="{$gi}">
            <div class="mm-group-header">
                <button type="button" class="mm-icon-btn mm-group-toggle" onclick="mmToggleGroup({$gi})" title="Plegar/Desplegar">
                    <i class="fa-solid fa-chevron-down mm-chevron"></i>
                </button>
                <i class="mm-group-icon-preview {$groupIcon}"></i>
                <input type="text" class="mm-group-name-input" value="{$groupName}" placeholder="Nom del grup"
                       onchange="mmUpdateGroupName({$gi}, this.value)">
                <input type="text" class="mm-group-icon-input" value="{$groupIcon}" placeholder="fa-solid fa-folder"
                       onchange="mmUpdateGroupIcon({$gi}, this.value)">
                <div class="mm-group-actions">
                    <button type="button" class="mm-icon-btn" onclick="mmMoveGroupUp({$gi})" title="Grup amunt">↑</button>
                    <button type="button" class="mm-icon-btn" onclick="mmMoveGroupDown({$gi})" title="Grup avall">↓</button>
                    <button type="button" class="mm-icon-btn mm-icon-btn--del" onclick="mmDeleteGroup({$gi})" title="Eliminar grup">×</button>
                </div>
            </div>
            <div class="mm-group-body">
                {$modsHtml}
            </div>
        </div>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Module discovery
    // -------------------------------------------------------------------------

    /**
     * Scan modules directory and extract module metadata.
     * Returns: [ 'prefix' => [['key'=>..., 'name'=>..., 'icon'=>...], ...], ... ]
     */
    private function discoverAllModules(): array
    {
        $result  = [];
        $modRoot = dirname(__DIR__, 2) . '/modules';

        $dirs = glob($modRoot . '/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        foreach ($dirs as $dir) {
            $prefix = basename($dir);
            $files  = glob($dir . '/*.php') ?: [];
            sort($files);

            foreach ($files as $file) {
                $basename = basename($file, '.php');
                // Convert PascalCase to snake_case: "ErpInvoices" → "erp_invoices"
                $key = strtolower(preg_replace('/([A-Z])/', '_$1', $basename));
                $key = ltrim($key, '_');

                // Extract title and icon via regex (fast, no class loading)
                $src   = file_get_contents($file);
                $title = $this->extractProp($src, 'title') ?: $basename;
                $icon  = $this->extractProp($src, 'icon')  ?: 'fa-solid fa-circle';

                $table = $this->extractProp($src, 'table') ?: '';

                $result[$prefix][] = [
                    'key'   => $key,
                    'name'  => $title,
                    'icon'  => $icon,
                    'table' => $table,
                ];
            }
        }

        return $result;
    }

    /**
     * Extract a public string property from PHP source.
     * Matches: public string $title = 'some value';
     */
    private function extractProp(string $src, string $prop): string
    {
        if (preg_match(
            '/public\s+string\s+\$' . preg_quote($prop, '/') . '\s*=\s*[\'"]([^\'"]+)[\'"]/m',
            $src,
            $m
        )) {
            return trim($m[1]);
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // CSS
    // -------------------------------------------------------------------------

    private function css(): string
    {
        return <<<CSS
        <style>
        /* Module Manager — uses ib-browser / ib-tabs from intrabase.css for wrapper & tabs */
        .mm-tab--inactive { opacity: .5; }

        .mm-editor { padding: var(--ib-space-md); }

        .mm-app-meta {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: var(--ib-space-md); padding-bottom: var(--ib-space-sm);
            border-bottom: 1px solid var(--ib-border);
        }
        .mm-app-name { margin: 0; font-size: 1rem; font-weight: 600; display: flex; align-items: center; gap: var(--ib-space-xs); color: var(--ib-text); }
        .mm-app-meta-right { display: flex; gap: var(--ib-space-xs); }

        .mm-meta-form { padding: var(--ib-space-md); margin-bottom: var(--ib-space-md); }
        .mm-meta-grid { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: var(--ib-space-sm); margin-bottom: var(--ib-space-sm); }
        .mm-meta-field { display: flex; flex-direction: column; gap: 3px; }
        .mm-meta-field label { font-size: .8rem; font-weight: 500; color: var(--ib-text-muted); }
        .mm-meta-field input {
            padding: var(--ib-space-xs) var(--ib-space-sm); border: 1px solid var(--ib-border);
            border-radius: var(--ib-radius); background: var(--ib-surface); color: var(--ib-text); font-size: .875rem;
        }

        .mm-columns { display: grid; grid-template-columns: 1fr 320px; gap: var(--ib-space-md); }
        .mm-col-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: var(--ib-space-sm); padding-bottom: var(--ib-space-xs);
            border-bottom: 1px solid var(--ib-border);
            font-size: .875rem; font-weight: 600; color: var(--ib-text-muted);
        }
        .mm-search {
            padding: var(--ib-space-xs) var(--ib-space-sm); border: 1px solid var(--ib-border);
            border-radius: 9999px; background: var(--ib-surface); color: var(--ib-text); font-size: .8rem;
            width: 140px;
        }

        /* Groups */
        .mm-group {
            border: 1px solid var(--ib-border); border-radius: var(--ib-radius);
            margin-bottom: var(--ib-space-xs); overflow: hidden;
        }
        .mm-group-header {
            display: flex; align-items: center; gap: .4rem; padding: var(--ib-space-xs) var(--ib-space-sm);
            background: var(--ib-bg); cursor: default;
        }
        .mm-group.collapsed .mm-group-body { display: none; }
        .mm-group.collapsed .mm-chevron { transform: rotate(-90deg); }
        .mm-chevron { transition: transform .15s; }
        .mm-group-icon-preview { font-size: .9rem; width: 18px; color: var(--ib-primary); }
        .mm-group-name-input {
            flex: 1; padding: .3rem .5rem; border: 1px solid transparent; border-radius: var(--ib-radius);
            background: transparent; color: var(--ib-text); font-size: .875rem; font-weight: 600;
        }
        .mm-group-name-input:focus { outline: none; border-color: var(--ib-primary); background: var(--ib-surface); }
        .mm-group-icon-input {
            width: 180px; padding: .3rem .5rem; border: 1px solid transparent; border-radius: var(--ib-radius);
            background: transparent; color: var(--ib-text-muted); font-size: .78rem; font-family: monospace;
        }
        .mm-group-icon-input:focus { outline: none; border-color: var(--ib-border); background: var(--ib-surface); }
        .mm-group-actions { display: flex; gap: .25rem; margin-left: auto; }
        .mm-group-body { padding: var(--ib-space-xs); background: var(--ib-surface); }

        /* Modules inside group */
        .mm-mod-item {
            display: flex; align-items: center; gap: .5rem; padding: .4rem .6rem;
            border-radius: var(--ib-radius); margin-bottom: .25rem;
            border: 1px solid var(--ib-border); background: var(--ib-surface);
            font-size: .875rem;
        }
        .mm-mod-item:hover { border-color: var(--ib-primary); }
        .mm-mod-item--disabled { opacity: .45; }
        .mm-mod-item--disabled .mm-mod-name { text-decoration: line-through; }
        .mm-icon-btn--toggle { color: var(--ib-text-muted); }
        .mm-mod-item--disabled .mm-icon-btn--toggle { color: var(--ib-danger, #ef4444); }
        .mm-mod-drag { color: var(--ib-text-muted); cursor: grab; font-size: 1rem; flex-shrink: 0; user-select: none; -webkit-user-select: none; padding: 4px; }
        .mm-mod-drag:hover { color: var(--ib-text); }
        .mm-mod-drag:active { cursor: grabbing; }
        .mm-mod-item--dragging { opacity: 0.4; background: var(--ib-primary-light, #e8edf5) !important; }
        body.mm-dragging { user-select: none; -webkit-user-select: none; cursor: grabbing !important; }
        body.mm-dragging * { cursor: grabbing !important; }
        .mm-mod-icon { width: 16px; color: var(--ib-primary); flex-shrink: 0; font-size: .875rem; }
        .mm-mod-name { flex: 1; color: var(--ib-text); font-weight: 500; }
        .mm-mod-key { font-size: .75rem; color: var(--ib-text-muted); padding: .1rem .3rem; background: var(--ib-bg); border-radius: var(--ib-radius); }
        .mm-mod-actions { display: flex; gap: .2rem; }
        .mm-empty-group { color: var(--ib-text-muted); font-size: .875rem; padding: var(--ib-space-sm); text-align: center; font-style: italic; }

        /* Icon buttons */
        .mm-icon-btn {
            width: 26px; height: 26px; border-radius: var(--ib-radius); border: 1px solid var(--ib-border);
            background: var(--ib-bg); color: var(--ib-text-muted); cursor: pointer;
            font-size: .8rem; display: inline-flex; align-items: center; justify-content: center;
            transition: all var(--ib-transition);
        }
        .mm-icon-btn:hover { background: var(--ib-primary); color: #fff; border-color: var(--ib-primary); }
        .mm-icon-btn--del:hover { background: var(--ib-danger, #ef4444); border-color: var(--ib-danger, #ef4444); }
        .mm-icon-btn--config { color: var(--ib-primary, #3b82f6); text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .mm-icon-btn--config:hover { background: var(--ib-primary, #3b82f6); color: #fff; border-color: var(--ib-primary, #3b82f6); }
        a.mm-icon-btn--config { padding: 0 .35rem; }
        .mm-group-toggle { background: transparent; border-color: transparent; }
        .mm-group-toggle:hover { background: var(--ib-bg); }

        /* Available modules */
        .mm-available { max-height: 600px; overflow-y: auto; }
        .mm-avail-item {
            display: flex; align-items: center; gap: .45rem; padding: .4rem .6rem;
            border-radius: var(--ib-radius); margin-bottom: .2rem; border: 1px solid var(--ib-border);
            background: var(--ib-surface); font-size: .875rem; cursor: default;
            transition: border-color var(--ib-transition);
        }
        .mm-avail-item:hover { border-color: var(--ib-primary); }
        .mm-avail-item > i { width: 14px; color: var(--ib-primary); font-size: .875rem; flex-shrink: 0; }
        .mm-avail-name { flex: 1; color: var(--ib-text); font-weight: 500; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mm-avail-key { font-size: .75rem; color: var(--ib-text-muted); }
        .mm-avail-add {
            width: 22px; height: 22px; border-radius: var(--ib-radius); border: 1px solid var(--ib-primary);
            background: transparent; color: var(--ib-primary); cursor: pointer; font-size: .8rem;
            display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
            transition: all var(--ib-transition);
        }
        .mm-avail-add:hover { background: var(--ib-primary); color: #fff; }
        .mm-avail-item.mm-avail-added { opacity: .35; pointer-events: none; }
        .mm-avail-empty { color: var(--ib-text-muted); font-size: .875rem; padding: var(--ib-space-sm); text-align: center; }

        .mm-hint { font-size: .8rem; color: var(--ib-text-muted); margin-top: var(--ib-space-sm); display: flex; gap: .4rem; align-items: flex-start; }

        /* Toast */
        .mm-toast {
            position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999;
            padding: .65rem 1.2rem; border-radius: var(--ib-radius); font-size: .875rem;
            background: var(--ib-success, #10b981); color: #fff; box-shadow: var(--ib-shadow-lg, 0 4px 20px rgba(0,0,0,.3));
            animation: mmFadeIn .2s ease;
        }
        .mm-toast--error { background: var(--ib-danger, #ef4444); }
        @keyframes mmFadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

        @media (max-width: 900px) {
            .mm-columns { grid-template-columns: 1fr; }
            .mm-meta-grid { grid-template-columns: 1fr 1fr; }
        }
        </style>
        CSS;
    }

    // -------------------------------------------------------------------------
    // JavaScript
    // -------------------------------------------------------------------------

    private function js(): string
    {
        return <<<'JS'
        // Global state: clone of the current app's modules structure
        let mmState = [];

        // Load state from rendered HTML (parse groups and modules)
        function mmLoadState() {
            mmState = [];
            document.querySelectorAll('#mm-groups .mm-group').forEach(gEl => {
                const gi = parseInt(gEl.dataset.groupIdx);
                const nameInp = gEl.querySelector('.mm-group-name-input');
                const iconInp = gEl.querySelector('.mm-group-icon-input');
                const collapseToggle = gEl.querySelector('.mm-group-toggle');
                const mods = [];
                gEl.querySelectorAll('.mm-mod-item').forEach(mEl => {
                    mods.push({ key: mEl.dataset.key, name: mEl.querySelector('.mm-mod-name').textContent.trim(), icon: mEl.querySelector('.mm-mod-icon').className.replace('mm-mod-icon ', '').trim() });
                });
                mmState[gi] = {
                    group: nameInp ? nameInp.value : 'Grup',
                    icon: iconInp ? iconInp.value : 'fa-solid fa-folder',
                    collapsed: gEl.classList.contains('collapsed'),
                    modules: mods
                };
            });
        }

        function mmRender() {
            const container = document.getElementById('mm-groups');
            container.innerHTML = mmState.map((g, gi) => mmRenderGroup(g, gi)).join('');
            // Mark added modules in available panel
            mmMarkAdded();
        }

        function mmRenderGroup(g, gi) {
            const name = esc(g.group);
            const icon = esc(g.icon || 'fa-solid fa-folder');
            const collCls = g.collapsed ? ' collapsed' : '';
            const mods = (g.modules || []).map((m, mi) => mmRenderMod(m, gi, mi)).join('');
            const emptyMods = mods ? mods : '<div class="mm-empty-group">Grup buit — afegeix mòduls des del panell dret</div>';
            return `<div class="mm-group${collCls}" data-group-idx="${gi}">
                <div class="mm-group-header">
                    <button type="button" class="mm-icon-btn mm-group-toggle" onclick="mmToggleGroup(${gi})">
                        <i class="fa-solid fa-chevron-down mm-chevron"></i>
                    </button>
                    <i class="mm-group-icon-preview ${icon}"></i>
                    <input type="text" class="mm-group-name-input" value="${name}" placeholder="Nom del grup" onchange="mmUpdateGroupName(${gi}, this.value)">
                    <input type="text" class="mm-group-icon-input" value="${esc(g.icon||'')}" placeholder="fa-solid fa-folder" onchange="mmUpdateGroupIcon(${gi}, this.value)">
                    <div class="mm-group-actions">
                        <button type="button" class="mm-icon-btn" onclick="mmMoveGroupUp(${gi})" title="Grup amunt">↑</button>
                        <button type="button" class="mm-icon-btn" onclick="mmMoveGroupDown(${gi})" title="Grup avall">↓</button>
                        <button type="button" class="mm-icon-btn mm-icon-btn--del" onclick="mmDeleteGroup(${gi})">×</button>
                    </div>
                </div>
                <div class="mm-group-body">${emptyMods}</div>
            </div>`;
        }

        function mmRenderMod(m, gi, mi) {
            const key  = esc(m.key || '');
            const name = esc(m.name || m.key || '');
            const icon = esc(m.icon || 'fa-solid fa-circle');
            const isDis = MM_CFG.disabled.includes(m.key);
            const disCls = isDis ? ' mm-mod-item--disabled' : '';
            const eyeIcon = isDis ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            const eyeTitle = isDis ? 'Activar mòdul' : 'Desactivar mòdul';

            // Configure button
            let configBtn = '';
            if (MM_CFG.modIdMap[m.key]) {
                const editUrl = esc('/' + MM_CFG.routeApp + '/sys_mod/' + MM_CFG.modIdMap[m.key] + '/edit');
                configBtn = `<a href="${editUrl}" class="mm-icon-btn mm-icon-btn--config" title="Configurar mòdul"><i class="fa-solid fa-gear"></i></a>`;
            } else {
                const meta = MM_CFG.modMeta[m.key] || {};
                configBtn = `<button type="button" class="mm-icon-btn mm-icon-btn--config" onclick="mmSyncAndConfigure('${key}', '${esc(meta.name||m.name||key)}', '${esc(meta.icon||m.icon||'')}', '${esc(meta.table||'')}')" title="Configurar mòdul"><i class="fa-solid fa-gear"></i></button>`;
            }

            return `<div class="mm-mod-item${disCls}" data-key="${key}" data-group-idx="${gi}" data-mod-idx="${mi}">
                <div class="mm-mod-drag">⠿</div>
                <i class="mm-mod-icon ${icon}"></i>
                <span class="mm-mod-name">${name}</span>
                <code class="mm-mod-key">${key}</code>
                <div class="mm-mod-actions">
                    ${configBtn}
                    <button type="button" class="mm-icon-btn mm-icon-btn--toggle" onclick="mmToggleModuleVisibility('${key}')" title="${eyeTitle}">
                        <i class="${eyeIcon}"></i>
                    </button>
                    <button type="button" class="mm-icon-btn" onclick="mmMoveModUp(${gi}, ${mi})" title="Amunt">↑</button>
                    <button type="button" class="mm-icon-btn" onclick="mmMoveModDown(${gi}, ${mi})" title="Avall">↓</button>
                    <button type="button" class="mm-icon-btn mm-icon-btn--del" onclick="mmRemoveMod(${gi}, ${mi})">×</button>
                </div>
            </div>`;
        }

        function mmMarkAdded() {
            const usedKeys = new Set();
            mmState.forEach(g => (g.modules||[]).forEach(m => usedKeys.add(m.key)));
            document.querySelectorAll('#mm-available .mm-avail-item').forEach(el => {
                el.classList.toggle('mm-avail-added', usedKeys.has(el.dataset.key));
            });
        }

        // Actions
        function mmToggleGroup(gi) {
            if (!mmState[gi]) return;
            var opening = mmState[gi].collapsed;
            if (opening) {
                // Collapse all others
                mmState.forEach(function(g, i) { g.collapsed = (i !== gi); });
            } else {
                mmState[gi].collapsed = true;
            }
            mmRender();
        }
        function mmUpdateGroupName(gi, val) { if (mmState[gi]) { mmState[gi].group = val; mmSaveAll(); } }
        function mmUpdateGroupIcon(gi, val) {
            if (mmState[gi]) {
                mmState[gi].icon = val;
                const gEl = document.querySelector(`.mm-group[data-group-idx="${gi}"] .mm-group-icon-preview`);
                if (gEl) { gEl.className = 'mm-group-icon-preview ' + val; }
                mmSaveAll();
            }
        }
        function mmMoveGroupUp(gi) {
            if (gi <= 0 || !mmState[gi]) return;
            [mmState[gi-1], mmState[gi]] = [mmState[gi], mmState[gi-1]];
            mmRender();
        }
        function mmMoveGroupDown(gi) {
            if (gi >= mmState.length - 1) return;
            [mmState[gi], mmState[gi+1]] = [mmState[gi+1], mmState[gi]];
            mmRender();
        }
        function mmDeleteGroup(gi) {
            if (!confirm('Eliminar el grup "' + (mmState[gi]?.group || '') + '"?')) return;
            mmState.splice(gi, 1);
            mmRender();
        }
        function mmAddGroup() {
            const name = prompt('Nom del nou grup:', 'Nou Grup');
            if (!name) return;
            mmState.push({ group: name, icon: 'fa-solid fa-folder', collapsed: false, modules: [] });
            mmRender();
        }

        function mmMoveModUp(gi, mi) {
            const mods = mmState[gi]?.modules;
            if (!mods || mi <= 0) return;
            [mods[mi-1], mods[mi]] = [mods[mi], mods[mi-1]];
            mmRender();
        }
        function mmMoveModDown(gi, mi) {
            const mods = mmState[gi]?.modules;
            if (!mods || mi >= mods.length - 1) return;
            [mods[mi], mods[mi+1]] = [mods[mi+1], mods[mi]];
            mmRender();
        }
        function mmRemoveMod(gi, mi) {
            mmState[gi]?.modules?.splice(mi, 1);
            mmRender();
        }

        function mmAddModule(key, name, icon) {
            if (mmState.length === 0) {
                mmState.push({ group: 'General', icon: 'fa-solid fa-folder', collapsed: false, modules: [] });
            }
            // Check not already added
            const alreadyAdded = mmState.some(g => g.modules?.some(m => m.key === key));
            if (alreadyAdded) { mmToast('El mòdul ja està afegit', true); return; }
            // Add to the expanded group, or first group if all collapsed
            const openIdx = mmState.findIndex(g => !g.collapsed);
            const targetGroup = openIdx >= 0 ? mmState[openIdx] : mmState[0];
            targetGroup.modules = targetGroup.modules || [];
            targetGroup.modules.push({ key, name, icon });
            mmRender();
            mmToast('Mòdul afegit a "' + targetGroup.group + '"');
        }

        async function mmToggleModuleVisibility(key) {
            const isDis = MM_CFG.disabled.includes(key);
            const enabled = isDis ? 1 : 0;
            try {
                const r = await fetch(location.pathname + location.search, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'toggle_module',
                        csrf_token: MM_CFG.csrf,
                        module_key: key,
                        enabled: enabled
                    })
                });
                const j = await r.json();
                if (j.ok) {
                    MM_CFG.disabled = j.disabled || [];
                    mmRender();
                    mmToast(enabled ? 'Mòdul activat' : 'Mòdul desactivat');
                } else {
                    mmToast(j.error || 'Error', true);
                }
            } catch(e) {
                mmToast('Error de connexió', true);
            }
        }

        function mmFilterAvail(q) {
            q = q.toLowerCase();
            document.querySelectorAll('#mm-available .mm-avail-item').forEach(el => {
                const txt = el.textContent.toLowerCase();
                el.style.display = !q || txt.includes(q) ? '' : 'none';
            });
        }

        function mmToggleMetaForm() {
            const f = document.getElementById('mm-meta-form');
            f.style.display = f.style.display === 'none' ? '' : 'none';
        }

        async function mmSaveAll() {
            mmLoadState(); // sync inputs
            const appId = MM_CFG.appId;
            const btn   = document.getElementById('btn-save-all');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Desant...';
            try {
                const r = await fetch(location.pathname + location.search, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'save_app_modules',
                        csrf_token: MM_CFG.csrf,
                        app_id: appId,
                        modules: JSON.stringify(mmState)
                    })
                });
                const j = await r.json();
                if (j.ok) { mmToast(j.message || 'Desat!'); } else { mmToast(j.error || 'Error', true); }
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Desar canvis';
            }
        }

        async function mmToggleApp(appId) {
            const r = await fetch(location.pathname + location.search, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'toggle_app', csrf_token: MM_CFG.csrf, app_id: appId })
            });
            const j = await r.json();
            if (j.ok) { location.reload(); } else { mmToast(j.error || 'Error', true); }
        }

        async function mmSaveAppMeta(appId) {
            const form = document.getElementById('mm-meta-form');
            const r = await fetch(location.pathname + location.search, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'save_app_meta',
                    csrf_token: MM_CFG.csrf,
                    app_id: appId,
                    name: form.querySelector('#meta-name').value,
                    icon: form.querySelector('#meta-icon').value,
                    default_module: form.querySelector('#meta-default').value,
                    sort_order: form.querySelector('#meta-sort').value,
                })
            });
            const j = await r.json();
            if (j.ok) { mmToast('Meta desada!'); location.reload(); } else { mmToast(j.error||'Error', true); }
        }

        async function mmSyncAndConfigure(key, label, icon, table) {
            try {
                const r = await fetch(location.pathname + location.search, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'sync_module',
                        csrf_token: MM_CFG.csrf,
                        module_key: key,
                        module_label: label,
                        module_icon: icon,
                        module_table: table
                    })
                });
                const j = await r.json();
                if (j.ok && j.id) {
                    MM_CFG.modIdMap[key] = j.id;
                    window.location.href = '/' + MM_CFG.routeApp + '/sys_mod/' + j.id + '/edit';
                } else {
                    mmToast(j.error || 'Error sincronitzant mòdul', true);
                }
            } catch(e) {
                mmToast('Error de connexió', true);
            }
        }

        function mmToast(msg, isErr=false) {
            const t = document.getElementById('mm-toast');
            t.textContent = msg;
            t.className = 'mm-toast' + (isErr ? ' mm-toast--error' : '');
            t.style.display = '';
            setTimeout(() => { t.style.display = 'none'; }, 3000);
        }

        function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        // Initialize on load + drag reorder via general ibDragReorder
        document.addEventListener('DOMContentLoaded', () => {
            mmLoadState();
            mmMarkAdded();

            ibDragReorder({
                scope: '#mm-editor',
                container: '.mm-group-body',
                handleSelector: '.mm-mod-drag',
                itemSelector: '.mm-mod-item',
                draggingClass: 'mm-mod-item--dragging',
                bodyDraggingClass: 'mm-dragging',
                crossContainer: true,
                onReorder: function() {
                    mmLoadState();
                    mmMarkAdded();
                    mmSaveAll();
                }
            });
        });
        JS;
    }
}
