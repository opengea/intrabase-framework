<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * sys_mod_actions — Custom Row Actions for Dynamic Modules (low-code)
 *
 * Defines custom actions that appear on each row of a dynamic module's browser.
 * Supports link, post, get and modal action types.
 * The url_pattern may contain {id} as a placeholder for the record's primary key.
 *
 * Table: ib_sys_mod_actions
 */
class SysModActions extends Module
{
    public string $table = 'ib_sys_mod_actions';
    public string $key   = 'id';
    public string $title = 'Module Actions';
    public string $icon  = 'fa-solid fa-bolt';

    public array $fields = [
        'id',
        'action_label',
        'action_name',
        'action_type',
        'mod_id',
        'is_active',
    ];

    public array $editFields = [
        'mod_id',
        'action_name',
        'action_label',
        'action_type',
        'icon_class',
        'url_pattern',
        'confirm_message',
        'target',
        'sort_order',
        'is_active',
    ];

    public array $searchFields = ['action_name', 'action_label'];

    public array $humans = [
        'id'              => 'ID',
        'mod_id'          => 'Module',
        'action_name'     => 'Action Name',
        'action_label'    => 'Action Label',
        'action_type'     => 'Action Type',
        'icon_class'      => 'Icon Class',
        'url_pattern'     => 'URL Pattern',
        'confirm_message' => 'Confirm Message',
        'target'          => 'Target',
        'sort_order'      => 'Sort Order',
        'is_active'       => 'Active',
        'created_at'      => 'Created',
        'updated_at'      => 'Updated',
    ];

    public array $components = [
        'mod_id'          => 'combo',
        'action_type'     => 'combo',
        'target'          => 'combo',
        'is_active'       => 'combo',
        'sort_order'      => 'integer',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public array $comments = [
        'url_pattern' => 'URL pattern with {id} placeholder',
    ];

    public array $validates = ['mod_id', 'action_name', 'action_label'];
    public array $readonly  = ['created_at', 'updated_at'];

    public string $orderBy  = 'sort_order';
    public string $orderDir = 'ASC';

    public bool $canDuplicate = true;
    public bool $canExport    = true;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->defaultValues['action_type'] = 'link';
        $this->defaultValues['icon_class']  = 'fa-solid fa-bolt';
        $this->defaultValues['target']      = '_self';
        $this->defaultValues['sort_order']  = '0';
        $this->defaultValues['is_active']   = '1';

        $this->combos['mod_id'] = [
            'table'      => 'ib_sys_mods',
            'key'        => 'id',
            'label'      => 'label',
            'orderBy'    => 'label ASC',
            'allowEmpty' => false,
        ];

        $actionTypes = [
            'link'  => 'link',
            'post'  => 'post',
            'get'   => 'get',
            'modal' => 'modal',
        ];

        $this->combos['action_type'] = ['data' => $actionTypes];

        $this->combos['target'] = [
            'data' => [
                '_self'  => '_self',
                '_blank' => '_blank',
            ],
        ];

        $this->combos['is_active'] = [
            'data' => [
                '1' => 'Yes',
                '0' => 'No',
            ],
        ];

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
                'field'   => 'action_type',
                'label'   => 'Action Type',
                'type'    => 'select',
                'options' => $actionTypes,
            ],
        ];

        $this->setGroup('identity', [
            'label'  => 'Identity',
            'icon'   => 'fa-solid fa-bolt',
            'fields' => ['mod_id', 'action_name', 'action_label', 'action_type', 'icon_class'],
        ]);

        $this->setGroup('behaviour', [
            'label'  => 'Behaviour',
            'icon'   => 'fa-solid fa-sliders',
            'fields' => ['url_pattern', 'confirm_message', 'target', 'sort_order', 'is_active'],
        ]);
    }
}
