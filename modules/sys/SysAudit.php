<?php
declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

class SysAudit extends Module {
    public string $table  = 'ib_audit';
    public ?string $apiScopeByUser   = 'user_id';

    public string $key    = 'id';
    public string $title  = 'Audit Log';
    public string $icon   = 'fa-solid fa-clock-rotate-left';

    public bool $canAdd    = false;
    public bool $canEdit   = false;
    public bool $canDelete = false;

    public array $fields = [
        'id',
        'user_id',
        'action',
        'table_name',
        'record_id',
        'ip_address',
        'created_at',
    ];

    public array $editFields = [];

    public array $humans = [
        'user_id'    => 'User',
        'action'     => 'Action',
        'table_name' => 'Table',
        'record_id'  => 'Record ID',
        'ip_address' => 'IP Address',
        'created_at' => 'Date / Time',
    ];

    public array $components = [
        'user_id'    => 'combo',
        'created_at' => 'datetime',
    ];

    public array $readonly = [
        'id',
        'user_id',
        'action',
        'table_name',
        'record_id',
        'ip_address',
        'created_at',
    ];

    public array $validates = [];

    public string $orderBy = 'created_at DESC';

    public string $baseWhere = '';

    public array $searchFields = [
        'action',
        'table_name',
        'ip_address',
    ];

    public function __construct(mixed ...$args) {
        parent::__construct(...$args);

        $this->combos['user_id'] = [
            'table'   => 'ib_users',
            'key'     => 'id',
            'label'   => 'name',
            'orderBy' => 'name ASC',
        ];
    }
}
