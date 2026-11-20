<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * sys_config — Tenant Configuration Key-Value Store
 *
 * Manages application-level configuration parameters stored in `ib_config`.
 *
 * Each row is a named key with a text value and an optional section grouping.
 * Keys are unique. Sections are used for visual organization only.
 *
 * Access restricted to admin group.
 */
class SysConfig extends Module
{
    public string $table = 'ib_config';
    public string $key   = 'id';
    public string $title = 'Configuration';
    public string $icon  = 'fa-solid fa-sliders';

    public array $fields      = ['id', 'section', 'config_key', 'config_value', 'description', 'updated_at'];
    public array $editFields  = ['section', 'config_key', 'config_value', 'description'];
    public array $searchFields = ['config_key', 'description', 'section'];

    public array $humans = [
        'id'           => 'ID',
        'section'      => 'Section',
        'config_key'   => 'Key',
        'config_value' => 'Value',
        'description'  => 'Description',
        'updated_at'   => 'Last Updated',
    ];

    public array $components = [
        'section'      => 'combo',
        'description'  => 'textarea',
    ];

    public array $validates = ['config_key', 'section'];

    public string $orderBy  = 'config_key';

    public bool $canDuplicate = true;
    public bool $canExport    = true;

    public array $readonly = ['updated_at'];
    public array $hidden   = ['id'];

    public function __construct(...$args)
    {
        parent::__construct(...$args);

        $this->combos['section'] = [
            'data' => [
                'general'  => 'General',
                'security' => 'Security',
                'files'    => 'Files',
                'mail'     => 'Mail',
                'ui'       => 'UI',
            ],
        ];

        $this->defaultValues['section'] = 'general';
    }
}
