<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Security\Session;
use Intrabase\Database\DB;

/**
 * sys_notification_templates — Plantilles de Notificació del Sistema
 *
 * Manages notification templates for system-generated messages.
 * Auto-generates template_code (NTPL-NNNN).
 * Supports multi-channel: email, SMS, push, in-app.
 * Variables placeholder list for merge fields.
 * Auto-sets created_by on creation.
 *
 * Table: ib_sys_notification_templates
 */
class SysNotificationTemplates extends Module
{
    public string $table    = 'ib_sys_notification_templates';
    public string $key      = 'id';
    public string $title    = 'Notification Templates';
    public string $icon     = 'fa-solid fa-bell';
    public string $orderBy  = 'event_type, channel';
    public string $orderDir = 'ASC';

    public array $fields = [
        'id',
        'template_code',
        'event_type',
        'channel',
        'subject',
        'is_active',
        'created_at',
    ];

    public array $showFields = [
        'template_code',
        'event_type',
        'channel',
        'subject',
        'body',
        'variables_list',
        'group_id',
        'is_active',
        'created_by',
        'created_at',
        'updated_at',
    ];

    public array $editFields = [
        'event_type',
        'channel',
        'subject',
        'body',
        'variables_list',
        'group_id',
        'is_active',
    ];

    public array $searchFields = ['template_code', 'subject', 'event_type'];

    public array $humans = [
        'template_code'  => 'Template Code',
        'event_type'     => 'Event Type',
        'channel'        => 'Channel',
        'subject'        => 'Subject',
        'body'           => 'Body',
        'variables_list' => 'Variables',
        'group_id'       => 'Group',
        'is_active'      => 'Active',
        'created_by'     => 'Created By',
        'created_at'     => 'Created',
        'updated_at'     => 'Updated',
    ];

    public array $components = [
        'event_type'     => 'select',
        'channel'        => 'select',
        'body'           => 'wysiwyg',
        'variables_list' => 'textarea',
        'group_id'       => 'combo',
        'is_active'      => 'checkbox',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    public array $selects = [
        'event_type' => [
            'user_signup'      => 'User Sign-up',
            'password_reset'   => 'Password Reset',
            'invoice_due'      => 'Invoice Due',
            'invoice_overdue'  => 'Invoice Overdue',
            'task_overdue'     => 'Task Overdue',
            'task_assigned'    => 'Task Assigned',
            'alert'            => 'System Alert',
            'digest'           => 'Daily Digest',
            'reminder'         => 'Reminder',
            'weekly_summary'   => 'Weekly Summary',
            'welcome'          => 'Welcome',
            'approval_request' => 'Approval Request',
            'approval_done'    => 'Approval Done',
            'other'            => 'Other',
        ],
        'channel' => [
            'email'  => 'Email',
            'sms'    => 'SMS',
            'push'   => 'Push Notification',
            'in_app' => 'In-App',
        ],
    ];

    public array $validates  = ['event_type', 'channel', 'subject', 'body'];
    public array $readonly   = ['template_code', 'created_by', 'created_at', 'updated_at'];

    public bool $canDuplicate = true;
    public bool $canExport    = true;

    public array $advancedFilters = [
        ['name' => 'event_type', 'label' => 'Event Type', 'type' => 'select'],
        ['name' => 'channel',    'label' => 'Channel',    'type' => 'select'],
        ['name' => 'is_active',  'label' => 'Active',     'type' => 'select',
         'options' => ['' => '— all —', '1' => 'Yes', '0' => 'No']],
    ];

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->defaultValues['event_type'] = 'alert';
        $this->defaultValues['channel']    = 'email';
        $this->defaultValues['is_active']  = '1';

        $this->combos['group_id'] = [
            'table'      => 'ib_groups',
            'key'        => 'id',
            'label'      => 'name',
            'orderBy'    => 'name ASC',
            'allowEmpty' => true,
        ];

        $this->advancedFilters[0]['options'] = $this->selects['event_type'];
        $this->advancedFilters[1]['options'] = $this->selects['channel'];

        $this->setGroup('identification', [
            'label'  => 'Identification',
            'icon'   => 'fa-solid fa-tag',
            'fields' => ['template_code', 'event_type', 'channel', 'is_active'],
        ]);
        $this->setGroup('content', [
            'label'  => 'Content',
            'icon'   => 'fa-solid fa-envelope',
            'fields' => ['subject', 'body'],
        ]);
        $this->setGroup('configuration', [
            'label'     => 'Configuration',
            'icon'      => 'fa-solid fa-gear',
            'fields'    => ['variables_list', 'group_id'],
            'collapsed' => true,
        ]);
    }

    public function onPreSave(array &$data): bool
    {
        $session  = $this->container->get(Session::class);
        $db       = $this->container->get(DB::class);
        $isCreate = !isset($data[$this->key]) || (int) $data[$this->key] === 0;

        if ($isCreate) {
            if (empty($data['created_by'])) {
                $data['created_by'] = $session->userId() ?: null;
            }
            if (empty($data['template_code'])) {
                $row  = $db->fetch(
                    "SELECT MAX(CAST(SUBSTRING_INDEX(template_code, '-', -1) AS UNSIGNED)) AS mx
                       FROM ib_sys_notification_templates WHERE template_code LIKE 'NTPL-%'"
                );
                $next = (int) ($row['mx'] ?? 0) + 1;
                $data['template_code'] = 'NTPL-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            }
        }

        return true;
    }
}
