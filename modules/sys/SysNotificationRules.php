<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * SysNotificationRules — Regles de Notificació del Sistema
 *
 * UI d'administració de les regles que processa NotificationEngine
 * (src/Support/NotificationEngine.php): esdeveniment disparador per mòdul,
 * condicions opcionals sobre un camp, canal (in-app/email) i destinatari.
 *
 * Table: ib_sys_notification_rules
 */
class SysNotificationRules extends Module
{
    public string $table    = 'ib_sys_notification_rules';
    public string $key      = 'id';
    public string $title    = 'Regles de Notificació';
    public string $icon     = 'fas fa-bell';
    public string $orderBy  = 'sort_order';
    public string $orderDir = 'ASC';

    public bool $canDuplicate = true;
    public bool $canExport    = true;

    public array $fields = [
        'rule_name',
        'module_key',
        'trigger_event',
        'notification_type',
        'target_type',
        'is_active',
    ];

    public array $showFields = [
        'rule_name',
        'description',
        'module_key',
        'trigger_event',
        'trigger_field',
        'trigger_value',
        'notification_type',
        'template_id',
        'target_type',
        'target_user_id',
        'target_group_id',
        'delay_minutes',
        'is_active',
        'sort_order',
        'notes',
        'created_at',
        'updated_at',
    ];

    public array $editFields = [
        'rule_name',
        'description',
        'module_key',
        'trigger_event',
        'trigger_field',
        'trigger_value',
        'notification_type',
        'template_id',
        'target_type',
        'target_user_id',
        'target_group_id',
        'delay_minutes',
        'is_active',
        'sort_order',
        'notes',
    ];

    public array $searchFields = ['rule_name', 'module_key', 'trigger_field'];

    public array $humans = [
        'rule_name'         => 'Nom de la Regla',
        'description'       => 'Descripció',
        'module_key'        => 'Mòdul',
        'trigger_event'     => 'Esdeveniment Disparador',
        'trigger_field'     => 'Camp Condició',
        'trigger_value'     => 'Valor Condició',
        'notification_type' => 'Canal',
        'template_id'       => 'Plantilla',
        'target_type'       => 'Destinatari',
        'target_user_id'    => 'Usuari Destinatari',
        'target_group_id'   => 'Grup Destinatari',
        'delay_minutes'     => 'Retard (minuts)',
        'is_active'         => 'Activa',
        'sort_order'        => 'Ordre',
        'notes'             => 'Notes',
        'created_at'        => 'Creat',
        'updated_at'        => 'Actualitzat',
    ];

    public array $components = [
        'rule_name'         => 'text',
        'description'       => 'textarea',
        'module_key'        => 'text',
        'trigger_event'     => 'combo',
        'trigger_field'     => 'text',
        'trigger_value'     => 'text',
        'notification_type' => 'combo',
        'template_id'       => 'combo',
        'target_type'       => 'combo',
        'target_user_id'    => 'combo',
        'target_group_id'   => 'combo',
        'delay_minutes'     => 'integer',
        'sort_order'        => 'integer',
        'notes'             => 'textarea',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    public array $bools = ['is_active'];

    public array $combos = [
        'trigger_event' => [
            'list' => [
                'record_created'        => 'Registre Creat',
                'record_updated'        => 'Registre Actualitzat',
                'status_changed'        => 'Estat Canviat',
                'due_date_approaching'  => 'Venciment Proper',
                'field_value_changed'   => 'Valor de Camp Canviat',
                'overdue'               => 'Vençut',
                'custom'                => 'Personalitzat',
            ],
        ],
        'notification_type' => [
            'list' => [
                'in_app' => 'A l\'aplicació',
                'email'  => 'Email',
                'both'   => 'Tots dos',
            ],
        ],
        'target_type' => [
            'list' => [
                'creator'     => 'Creador del registre',
                'owner_field' => 'Propietari (camp owner)',
                'user'        => 'Usuari concret',
                'group'       => 'Grup',
            ],
        ],
    ];

    public array $validates  = ['rule_name', 'module_key', 'trigger_event'];
    public array $readonly   = ['created_at', 'updated_at'];

    public array $advancedFilters = [
        [
            'field'   => 'trigger_event',
            'label'   => 'Esdeveniment Disparador',
            'type'    => 'select',
            'options' => [
                'record_created'        => 'Registre Creat',
                'record_updated'        => 'Registre Actualitzat',
                'status_changed'        => 'Estat Canviat',
                'due_date_approaching'  => 'Venciment Proper',
                'field_value_changed'   => 'Valor de Camp Canviat',
                'overdue'               => 'Vençut',
                'custom'                => 'Personalitzat',
            ],
        ],
        [
            'field'   => 'notification_type',
            'label'   => 'Canal',
            'type'    => 'select',
            'options' => [
                'in_app' => 'A l\'aplicació',
                'email'  => 'Email',
                'both'   => 'Tots dos',
            ],
        ],
        [
            'field'   => 'is_active',
            'label'   => 'Activa',
            'type'    => 'select',
            'options' => ['1' => 'Activa', '0' => 'Inactiva'],
        ],
    ];

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->defaultValues['trigger_event']     = 'record_created';
        $this->defaultValues['notification_type'] = 'in_app';
        $this->defaultValues['target_type']       = 'creator';
        $this->defaultValues['delay_minutes']     = 0;
        $this->defaultValues['is_active']         = 1;
        $this->defaultValues['sort_order']        = 0;

        $this->combos['template_id'] = [
            'table'      => 'ib_sys_notification_templates',
            'key'        => 'id',
            'label'      => 'name',
            'orderBy'    => 'name ASC',
            'allowEmpty' => true,
        ];
        $this->combos['target_user_id'] = [
            'table'      => 'ib_users',
            'key'        => 'id',
            'label'      => 'name',
            'orderBy'    => 'name ASC',
            'allowEmpty' => true,
        ];
        $this->combos['target_group_id'] = [
            'table'      => 'ib_groups',
            'key'        => 'id',
            'label'      => 'name',
            'orderBy'    => 'name ASC',
            'allowEmpty' => true,
        ];
    }
}
