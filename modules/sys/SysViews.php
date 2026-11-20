<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Security\Session;

/**
 * sys_views — Custom Module Views
 *
 * Manages saved custom views for any module: filter presets,
 * visible columns, default sort order, and sharing settings.
 * Views can be personal or shared across all users.
 *
 * Auto-sets created_by on creation.
 *
 * Table: ib_sys_views
 */
class SysViews extends Module
{
    public string $table = 'ib_sys_views';
    public string $key   = 'id';
    public string $title = 'Module Views';
    public string $icon  = 'fa-solid fa-eye';

    public array $fields = [
        'id',
        'view_name',
        'mod_name',
        'view_type',
        'is_default',
        'is_shared',
        'sort_order',
        'created_by',
    ];

    public array $editFields = [
        'view_name',
        'mod_name',
        'view_type',
        'description',
        'where_clause',
        'filters_json',
        'visible_columns',
        'order_by',
        'order_dir',
        'sort_order',
        'group_id',
        'is_default',
        'is_shared',
    ];

    public array $searchFields = ['view_name', 'mod_name', 'description'];

    public array $humans = [
        'id'             => 'ID',
        'view_name'      => 'View Name',
        'mod_name'       => 'Module',
        'view_type'      => 'View Type',
        'description'    => 'Description',
        'where_clause'   => 'WHERE Clause',
        'filters_json'   => 'Filters (JSON)',
        'visible_columns'=> 'Visible Columns',
        'order_by'       => 'Order By',
        'order_dir'      => 'Order Direction',
        'sort_order'     => 'Sort Order',
        'group_id'       => 'Group Access',
        'is_default'     => 'Default',
        'is_shared'      => 'Shared',
        'created_by'     => 'Created By',
        'created_at'     => 'Created',
        'updated_at'     => 'Updated',
    ];

    public array $components = [
        'view_type'       => 'combo',
        'description'     => 'textarea',
        'where_clause'    => 'textarea',
        'filters_json'    => 'textarea',
        'visible_columns' => 'textarea',
        'sort_order'      => 'integer',
        'order_dir'       => 'combo',
        'group_id'        => 'combo',
        'is_default'      => 'combo',
        'is_shared'       => 'combo',
        'created_by'      => 'combo',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public array $comments = [
        'mod_name'        => 'Module slug, e.g. erp_invoices',
        'view_type'       => 'primary = main filter, secondary = combinable filter',
        'where_clause'    => 'SQL WHERE condition, e.g. status = \'cobrat\'',
        'filters_json'    => 'JSON filtres per defecte',
        'visible_columns' => 'Columnes visibles, separades per comes',
        'sort_order'      => 'Display order (lower = first)',
    ];

    public array $validates  = ['view_name', 'mod_name'];
    public array $readonly   = ['created_by', 'created_at', 'updated_at'];
    public array $hidden     = ['created_by', 'updated_at'];

    public string $orderBy  = 'view_name';
    public string $orderDir = 'ASC';

    public bool $canDuplicate = true;
    public bool $canExport    = true;

    public array $filterFields = [
        'mod_name'  => 'text',
        'view_type' => 'select',
        'is_shared' => 'select',
    ];

    public array $filterOptions = [
        'view_type' => [
            'primary'   => 'Primary',
            'secondary' => 'Secondary',
        ],
        'is_shared' => [
            '1' => 'Shared',
            '0' => 'Private',
        ],
    ];

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->defaultValues['is_shared']  = '1';
        $this->defaultValues['is_default'] = '0';
        $this->defaultValues['order_dir']  = 'ASC';
        $this->defaultValues['view_type']  = 'primary';
        $this->defaultValues['sort_order'] = '0';

        $this->combos['view_type'] = [
            'data' => [
                'primary'   => 'Primary',
                'secondary' => 'Secondary',
            ],
        ];

        $this->combos['order_dir'] = [
            'data' => [
                'ASC'  => 'ASC',
                'DESC' => 'DESC',
            ],
        ];

        $this->combos['group_id'] = [
            'table'      => 'ib_groups',
            'key'        => 'id',
            'label'      => 'name',
            'orderBy'    => 'name ASC',
            'allowEmpty' => true,
        ];

        $this->combos['is_default'] = [
            'data' => [
                '1' => 'Default',
                '0' => 'No',
            ],
        ];

        $this->combos['is_shared'] = [
            'data' => [
                '1' => 'Shared',
                '0' => 'Private',
            ],
        ];

        $this->combos['created_by'] = [
            'table'      => 'ib_users',
            'key'        => 'id',
            'label'      => 'name',
            'orderBy'    => 'name ASC',
            'allowEmpty' => true,
        ];

        $this->setGroup('view', [
            'label'  => 'View',
            'icon'   => 'fa-solid fa-eye',
            'fields' => ['view_name', 'mod_name', 'view_type', 'description'],
        ]);

        $this->setGroup('filter', [
            'label'  => 'Filter & Columns',
            'icon'   => 'fa-solid fa-filter',
            'fields' => ['where_clause', 'filters_json', 'visible_columns', 'order_by', 'order_dir'],
        ]);

        $this->setGroup('settings', [
            'label'  => 'Settings',
            'icon'   => 'fa-solid fa-gear',
            'fields' => ['sort_order', 'group_id', 'is_default', 'is_shared'],
        ]);
    }

    /**
     * Auto-set created_by on create.
     */
    public function onPreSave(array &$data): bool
    {
        $isCreate = !isset($data[$this->key]) || (int) $data[$this->key] === 0;

        if ($isCreate && empty($data['created_by'])) {
            $session = $this->container->get(Session::class);
            $data['created_by'] = $session->userId() ?: null;
        }

        return true;
    }
}
