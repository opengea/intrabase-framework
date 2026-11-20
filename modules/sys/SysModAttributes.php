<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * sys_mod_attributes — Attributes / Fields of Dynamic Modules (low-code)
 *
 * Defines the columns/fields for each dynamic module (sys_mod).
 * Controls field type, label, validation, visibility in list, etc.
 *
 * onPreSave: auto-slugifies field_name (lowercase + underscores).
 *
 * Table: ib_sys_mod_attributes
 */
class SysModAttributes extends Module
{
    public string $table = 'ib_sys_mod_attributes';
    public string $key   = 'id';
    public string $title = 'Module Attributes';
    public string $icon  = 'fa-solid fa-table-columns';

    public array $fields = [
        'id',
        'field_name',
        'field_label',
        'field_type',
        'is_required',
        'sort_order',
    ];

    public array $editFields = [
        'mod_id',
        'field_name',
        'field_label',
        'field_type',
        'field_options',
        'default_value',
        'is_required',
        'is_searchable',
        'show_in_list',
        'sort_order',
        'validation_regex',
        'notes',
    ];

    public array $searchFields = ['field_name', 'field_label'];

    public array $humans = [
        'id'               => 'ID',
        'mod_id'           => 'Module',
        'field_name'       => 'Field Name',
        'field_label'      => 'Field Label',
        'field_type'       => 'Field Type',
        'field_options'    => 'Options',
        'default_value'    => 'Default Value',
        'is_required'      => 'Required',
        'is_searchable'    => 'Searchable',
        'show_in_list'     => 'Show in List',
        'sort_order'       => 'Sort Order',
        'validation_regex' => 'Validation Regex',
        'notes'            => 'Notes',
        'created_at'       => 'Created',
        'updated_at'       => 'Updated',
    ];

    public array $components = [
        'mod_id'           => 'combo',
        'field_type'       => 'combo',
        'field_options'    => 'textarea',
        'is_required'      => 'combo',
        'is_searchable'    => 'combo',
        'show_in_list'     => 'combo',
        'sort_order'       => 'integer',
        'notes'            => 'textarea',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public array $comments = [
        'field_options' => 'Options for select/combo, one per line: value=Label',
    ];

    public array $validates = ['mod_id', 'field_name', 'field_label'];
    public array $readonly  = ['created_at', 'updated_at'];

    public string $orderBy  = 'sort_order';
    public string $orderDir = 'ASC';

    public bool $canDuplicate = true;
    public bool $canExport    = true;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->defaultValues['field_type']    = 'text';
        $this->defaultValues['is_required']   = '0';
        $this->defaultValues['is_searchable'] = '0';
        $this->defaultValues['show_in_list']  = '0';
        $this->defaultValues['sort_order']    = '0';

        $this->combos['mod_id'] = [
            'table'      => 'ib_sys_mods',
            'key'        => 'id',
            'label'      => 'label',
            'orderBy'    => 'label ASC',
            'allowEmpty' => false,
        ];

        $fieldTypes = [
            'text'     => 'text',
            'textarea' => 'textarea',
            'number'   => 'number',
            'date'     => 'date',
            'datetime' => 'datetime',
            'select'   => 'select',
            'combo'    => 'combo',
            'checkbox' => 'checkbox',
            'email'    => 'email',
            'url'      => 'url',
            'html'     => 'html',
            'file'     => 'file',
            'hidden'   => 'hidden',
        ];

        $this->combos['field_type'] = ['data' => $fieldTypes];

        $yesNo = ['1' => 'Yes', '0' => 'No'];

        $this->combos['is_required']   = ['data' => $yesNo];
        $this->combos['is_searchable'] = ['data' => $yesNo];
        $this->combos['show_in_list']  = ['data' => $yesNo];

        $this->advancedFilters = [
            [
                'field'  => 'mod_id',
                'label'  => 'Module',
                'type'   => 'combo',
                'source' => [
                    'table'   => 'ib_sys_mods',
                    'key'     => 'id',
                    'label'   => 'label',
                    'orderBy' => 'label ASC',
                ],
            ],
            [
                'field'   => 'field_type',
                'label'   => 'Field Type',
                'type'    => 'select',
                'options' => $fieldTypes,
            ],
        ];

        $this->setGroup('identity', [
            'label'  => 'Identity',
            'icon'   => 'fa-solid fa-table-columns',
            'fields' => ['mod_id', 'field_name', 'field_label', 'field_type'],
        ]);

        $this->setGroup('options', [
            'label'  => 'Options & Default',
            'icon'   => 'fa-solid fa-list',
            'fields' => ['field_options', 'default_value'],
        ]);

        $this->setGroup('behaviour', [
            'label'  => 'Behaviour',
            'icon'   => 'fa-solid fa-sliders',
            'fields' => ['is_required', 'is_searchable', 'show_in_list', 'sort_order', 'validation_regex', 'notes'],
        ]);
    }

    /**
     * Auto-slugify field_name on every save (lowercase + underscores only).
     */
    public function onPreSave(array &$data): bool
    {
        if (!empty($data['field_name'])) {
            $slug = strtolower((string) $data['field_name']);
            $slug = preg_replace('/[\s\-]+/', '_', $slug);
            $slug = (string) preg_replace('/[^a-z0-9_]/', '', $slug);
            $data['field_name'] = $slug;
        }

        return true;
    }
}
