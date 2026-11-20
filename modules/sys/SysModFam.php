<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * sys_mod_fam — Module Families (low-code dynamic modules)
 *
 * Groups dynamic modules into logical families for organisation.
 * Each family has a name, an icon, a sort order, and an active flag.
 *
 * Table: ib_sys_mod_fam
 */
class SysModFam extends Module
{
    public string $table = 'ib_sys_mod_fam';
    public string $key   = 'id';
    public string $title = 'Module Families';
    public string $icon  = 'fa-solid fa-layer-group';

    public array $fields = [
        'id',
        'name',
        'icon_class',
        'sort_order',
        'is_active',
    ];

    public array $editFields = [
        'name',
        'icon_class',
        'sort_order',
        'is_active',
    ];

    public array $searchFields = ['name'];

    public array $humans = [
        'id'         => 'ID',
        'name'       => 'Name',
        'icon_class' => 'Icon Class',
        'sort_order' => 'Sort Order',
        'is_active'  => 'Active',
        'created_at' => 'Created',
        'updated_at' => 'Updated',
    ];

    public array $components = [
        'sort_order' => 'integer',
        'is_active'  => 'combo',
    ];

    public array $validates = ['name'];

    public string $orderBy  = 'sort_order';
    public string $orderDir = 'ASC';

    public bool $canDuplicate = true;
    public bool $canExport    = true;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->defaultValues['is_active']  = '1';
        $this->defaultValues['icon_class'] = 'fa-solid fa-layer-group';

        $this->combos['is_active'] = [
            'data' => [
                '1' => 'Active',
                '0' => 'Inactive',
            ],
        ];

        $this->setGroup('details', [
            'label'  => 'Family Details',
            'icon'   => 'fa-solid fa-layer-group',
            'fields' => ['name', 'icon_class', 'sort_order', 'is_active'],
        ]);
    }
}
