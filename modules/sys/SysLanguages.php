<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * sys_languages — Language Codes Management
 *
 * Manages the language codes available in the system (ISO 639-1 / IETF BCP 47).
 * Each language has a code, display name, native name, default flag, active flag
 * and a display sort order.
 *
 * Table: ib_sys_languages
 */
class SysLanguages extends Module
{
    public string $table = 'ib_sys_languages';
    public string $key   = 'id';
    public string $title = 'Languages';
    public string $icon  = 'fa-solid fa-globe';

    public array $fields = [
        'id',
        'code',
        'name',
        'native_name',
        'is_default',
        'is_active',
        'sort_order',
        'created_at',
    ];

    public array $showFields = [
        'code',
        'name',
        'native_name',
        'is_default',
        'is_active',
        'sort_order',
        'created_at',
        'updated_at',
    ];

    public array $editFields = [
        'code',
        'name',
        'native_name',
        'is_default',
        'is_active',
        'sort_order',
    ];

    public array $searchFields = ['code', 'name', 'native_name'];

    public array $humans = [
        'id'          => 'ID',
        'code'        => 'Code',
        'name'        => 'Name',
        'native_name' => 'Native Name',
        'is_default'  => 'Default',
        'is_active'   => 'Active',
        'sort_order'  => 'Sort Order',
        'created_at'  => 'Created',
        'updated_at'  => 'Updated',
    ];

    public array $components = [
        'is_default'  => 'checkbox',
        'is_active'   => 'checkbox',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public array $validates  = ['code', 'name'];
    public array $readonly   = ['created_at', 'updated_at'];
    public array $hidden     = ['updated_at'];

    public string $orderBy  = 'sort_order';
    public string $orderDir = 'ASC';

    public bool $canDuplicate = true;
    public bool $canExport    = true;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->defaultValues['is_default'] = '0';
        $this->defaultValues['is_active']  = '1';
        $this->defaultValues['sort_order'] = '0';

        $this->setGroup('identity', [
            'label'  => 'Language Identity',
            'icon'   => 'fa-solid fa-globe',
            'fields' => ['code', 'name', 'native_name'],
        ]);

        $this->setGroup('settings', [
            'label'  => 'Settings',
            'icon'   => 'fa-solid fa-gear',
            'fields' => ['is_default', 'is_active', 'sort_order'],
        ]);
    }

    /**
     * When setting a language as default, unset all others.
     */
    public function onPreSave(array &$data): bool
    {
        if (!empty($data['is_default']) && (int) $data['is_default'] === 1) {
            $currentId = (int) ($data[$this->key] ?? 0);
            $this->db->execute(
                'UPDATE ib_sys_languages SET is_default = 0 WHERE id != :id',
                ['id' => $currentId]
            );
        }
        return true;
    }
}
