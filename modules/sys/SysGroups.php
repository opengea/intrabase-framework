<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * sys_groups — Role/Group Management Module
 */
class SysGroups extends Module
{
    public string $table = 'ib_groups';
    public string $key   = 'id';
    public string $title = 'Groups';
    public string $icon  = 'fa-solid fa-shield-halved';

    public array $fields      = ['id', 'name', 'description', 'created_at'];
    public array $editFields  = ['name', 'description'];
    public array $searchFields = ['name', 'description'];

    public array $humans = [
        'id'          => 'ID',
        'name'        => 'Group Name',
        'description' => 'Description',
        'created_at'  => 'Created',
    ];

    public array $components = [
        'description' => 'textarea',
        'created_at'  => 'datetime',
    ];

    public array $readonly = ['created_at'];
    public array $validates = ['name'];

    public array $maxLengths = [
        'name' => 64,
    ];

    public string $orderBy = 'name';
}
