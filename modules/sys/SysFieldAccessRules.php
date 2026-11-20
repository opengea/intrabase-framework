<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Security\Session;

/**
 * sys_field_access_rules — Field-Level Access Control Rules
 *
 * Defines which user groups can see or edit specific fields within specific
 * modules. Provides field-level security on top of module-level permissions.
 * Rules specify: module key, field name, group, and whether the field is
 * readable/writable for that group.
 *
 * Useful for hiding sensitive data (salaries, margins, personal data) from
 * certain roles while keeping the module accessible. GDPR compliance tool.
 *
 * Table: ib_sys_field_access_rules
 */
class SysFieldAccessRules extends Module
{
    public string $table    = 'ib_sys_field_access_rules';
    public string $key      = 'id';
    public string $title    = 'Field Access Rules';
    public string $icon     = 'fa-solid fa-key';
    public string $orderBy  = 'module_key';
    public string $orderDir = 'ASC';

    public bool $canDuplicate = true;
    public bool $canExport    = true;

    public array $fields = [
        'id',
        'module_key',
        'field_name',
        'group_name',
        'can_read',
        'can_write',
        'is_active',
        'created_at',
    ];

    public array $showFields = [
        'module_key',
        'field_name',
        'group_name',
        'can_read',
        'can_write',
        'hide_in_list',
        'mask_value',
        'is_active',
        'notes',
        'created_by',
        'created_at',
        'updated_at',
    ];

    public array $editFields = [
        'module_key',
        'field_name',
        'group_name',
        'can_read',
        'can_write',
        'hide_in_list',
        'mask_value',
        'is_active',
        'notes',
    ];

    public array $searchFields = ['module_key', 'field_name', 'group_name', 'notes'];

    public array $labels = [
        'module_key'   => 'Module Key',
        'field_name'   => 'Field Name',
        'group_name'   => 'User Group',
        'can_read'     => 'Can Read',
        'can_write'    => 'Can Write',
        'hide_in_list' => 'Hide in List View',
        'mask_value'   => 'Mask Value (show ***)',
        'is_active'    => 'Active',
        'notes'        => 'Notes',
        'created_by'   => 'Created By',
        'created_at'   => 'Created',
        'updated_at'   => 'Updated',
    ];

    public array $readonly = ['created_by', 'created_at', 'updated_at'];
    public array $hidden   = ['id', 'updated_at'];

    public array $types = [
        'group_name'   => 'select',
        'can_read'     => 'checkbox',
        'can_write'    => 'checkbox',
        'hide_in_list' => 'checkbox',
        'mask_value'   => 'checkbox',
        'is_active'    => 'checkbox',
        'notes'        => 'textarea',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public array $options = [
        'group_name' => [
            'admin'    => 'Admin',
            'staff'    => 'Staff',
            'readonly' => 'Read-Only',
            'manager'  => 'Manager',
            'hr'       => 'HR',
            'finance'  => 'Finance',
            'sales'    => 'Sales',
            'external' => 'External / Guest',
        ],
    ];

    public array $advancedFilters = [
        [
            'field'   => 'group_name',
            'label'   => 'User Group',
            'type'    => 'select',
            'options' => [
                ''         => '— all —',
                'admin'    => 'Admin',
                'staff'    => 'Staff',
                'readonly' => 'Read-Only',
                'manager'  => 'Manager',
                'hr'       => 'HR',
                'finance'  => 'Finance',
                'sales'    => 'Sales',
                'external' => 'External / Guest',
            ],
        ],
        [
            'field'   => 'is_active',
            'label'   => 'Active',
            'type'    => 'select',
            'options' => [
                ''  => '— all —',
                '1' => 'Active',
                '0' => 'Inactive',
            ],
        ],
        [
            'field'   => 'can_read',
            'label'   => 'Can Read',
            'type'    => 'select',
            'options' => [
                ''  => '— all —',
                '1' => 'Yes',
                '0' => 'No',
            ],
        ],
        [
            'field'   => 'can_write',
            'label'   => 'Can Write',
            'type'    => 'select',
            'options' => [
                ''  => '— all —',
                '1' => 'Yes',
                '0' => 'No',
            ],
        ],
    ];

    public array $descriptions = [
        'module_key'   => 'Module identifier, e.g. "erp_staff", "crm_clients"',
        'field_name'   => 'Field/column name, e.g. "salary", "margin_pct"',
        'mask_value'   => 'If enabled, the field value is shown as *** instead of the real value',
        'hide_in_list' => 'If enabled, the column is hidden in the list/grid view',
    ];

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->defaultValues['can_read']     = '1';
        $this->defaultValues['can_write']    = '0';
        $this->defaultValues['hide_in_list'] = '0';
        $this->defaultValues['mask_value']   = '0';
        $this->defaultValues['is_active']    = '1';
        $this->defaultValues['group_name']   = 'staff';

        $this->setGroup('rule', [
            'label'  => 'Rule Definition',
            'icon'   => 'fa-solid fa-key',
            'fields' => ['module_key', 'field_name', 'group_name', 'is_active'],
        ]);

        $this->setGroup('permissions', [
            'label'  => 'Permissions',
            'icon'   => 'fa-solid fa-lock',
            'fields' => ['can_read', 'can_write', 'hide_in_list', 'mask_value'],
        ]);

        $this->setGroup('meta', [
            'label'  => 'Notes',
            'icon'   => 'fa-solid fa-sticky-note',
            'fields' => ['notes'],
        ]);
    }

    public function onPreSave(array &$data): bool
    {
        $isNew = empty($data[$this->key]) || (int) $data[$this->key] === 0;

        if ($isNew) {
            $session = Session::getInstance();
            $user    = $session->user();
            $data['created_by'] = (int) ($user['id'] ?? 0) ?: null;
        }

        if (empty(trim((string) ($data['module_key'] ?? '')))) {
            throw new \RuntimeException('Module key is required.');
        }
        if (empty(trim((string) ($data['field_name'] ?? '')))) {
            throw new \RuntimeException('Field name is required.');
        }
        if (empty(trim((string) ($data['group_name'] ?? '')))) {
            throw new \RuntimeException('User group is required.');
        }

        // Normalise: lowercase, no spaces
        $data['module_key'] = strtolower(trim($data['module_key']));
        $data['field_name'] = strtolower(trim($data['field_name']));
        return true;
    }
}
