<?php
declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * SysApps – Application Groups Manager
 *
 * Manages app groups that organise modules into navigation sections.
 * Each app group contains a list of modules (JSON) displayed in the sidebar.
 */
class SysApps extends Module
{
    public string $table   = 'ib_apps';
    public string $key     = 'id';
    public string $title   = 'Applications';
    public string $icon    = 'fa-solid fa-table-cells-large';
    public bool   $canDuplicate = true;
    public bool   $canExport    = true;
    public bool   $canReorder   = true;

    public array $fields = [
        'id', 'keyname', 'name', 'icon', 'default_module', 'sort_order',
        'is_active', 'sidebar_collapsed', 'display_location', 'group_access', 'created_at',
    ];

    public array $editFields = [
        'keyname', 'name', 'icon', 'modules', 'default_module', 'sort_order',
        'is_active', 'sidebar_collapsed', 'display_location', 'group_access',
    ];

    public array $humans = [
        'keyname'            => 'Key Name',
        'name'               => 'Name',
        'icon'               => 'Icon',
        'modules'            => 'Modules (JSON)',
        'default_module'     => 'Default Module',
        'sort_order'         => 'Sort Order',
        'is_active'          => 'Active',
        'sidebar_collapsed'  => 'Sidebar Collapsed',
        'display_location'   => 'Display Location',
        'group_access'       => 'Group Access',
        'created_at'         => 'Created',
    ];

    public array $components = [
        'is_active'          => 'combo',
        'sidebar_collapsed'  => 'combo',
        'display_location'   => 'combo',
        'created_at'         => 'datetime',
        'modules'            => 'json',
    ];

    public array $readonly     = ['created_at'];
    public array $validates    = ['keyname', 'name'];
    public array $searchFields = ['keyname', 'name', 'icon', 'default_module', 'display_location', 'group_access'];
    public string $orderBy     = 'sort_order ASC';

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->combos['is_active'] = [
            'data' => ['1' => 'Yes', '0' => 'No'],
        ];
        $this->combos['sidebar_collapsed'] = [
            'data' => ['0' => 'No', '1' => 'Yes'],
        ];
        $this->combos['display_location'] = [
            'data' => ['none' => 'None', 'appbar' => 'App Bar', 'header' => 'Header'],
        ];
    }

    /**
     * Auto-generate keyname from name if not provided.
     * Validate modules JSON.
     */
    public function onPreSave(array &$data): bool
    {
        // Auto-generate keyname from name if empty
        if (empty($data['keyname']) && !empty($data['name'])) {
            $data['keyname'] = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $data['name']));
            $data['keyname'] = trim($data['keyname'], '_');
        }

        // Force lowercase keyname
        if (!empty($data['keyname'])) {
            $data['keyname'] = strtolower(preg_replace('/[^a-z0-9_]/', '_', $data['keyname']));
        }

        // Validate modules JSON if provided
        if (!empty($data['modules']) && is_string($data['modules'])) {
            $decoded = json_decode($data['modules'], true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                // Invalid JSON – store as empty array
                $data['modules'] = '[]';
            }
        } elseif (empty($data['modules'])) {
            $data['modules'] = '[]';
        }

        // Set default sort_order if not provided
        if (!isset($data['sort_order']) || $data['sort_order'] === '') {
            $data['sort_order'] = 0;
        }

        return true;
    }

    /**
     * Override detail view to show modules as a formatted table.
     */
    public function renderDetailExtra(array $record): string
    {
        $modules = [];
        if (!empty($record['modules'])) {
            $modules = json_decode((string)$record['modules'], true) ?: [];
        }

        if ($modules === []) {
            return '';
        }

        $rows = '';
        foreach ($modules as $i => $mod) {
            $key  = htmlspecialchars((string)($mod['key']  ?? ''), ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars((string)($mod['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $icon = htmlspecialchars((string)($mod['icon'] ?? ''), ENT_QUOTES, 'UTF-8');
            $num  = $i + 1;
            $rows .= <<<HTML
            <tr>
                <td>{$num}</td>
                <td><code>{$key}</code></td>
                <td>{$name}</td>
                <td><i class="{$icon}"></i> <small class="ib-text-muted">{$icon}</small></td>
                <td><a class="ib-btn ib-btn--sm ib-btn--secondary" href="?mod={$key}">{$name} →</a></td>
            </tr>
            HTML;
        }

        $count = count($modules);

        return <<<HTML
        <div class="ib-panel" style="margin-top:1.5rem;">
            <div class="ib-panel__header">
                <h3 class="ib-panel__title">
                    <i class="fa-solid fa-cubes" aria-hidden="true"></i>
                    Modules ({$count})
                </h3>
            </div>
            <div class="ib-panel__body ib-panel__body--flush">
                <table class="ib-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Key</th>
                            <th>Name</th>
                            <th>Icon</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        </div>
        HTML;
    }
}
