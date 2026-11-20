<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * sys_notification_channels — Canals de Notificació del Sistema
 *
 * Manages notification channels (email, SMS, Slack, Teams, Telegram,
 * webhook, push, in-app). Stores connection config as JSON.
 * Auto-unsets is_default_for_type on other channels of the same type.
 * canDuplicate=false, canExport=false (contains credentials).
 * Auto-generates NCHANN-NNNN codes.
 *
 * Table: ib_sys_notification_channels
 */
class SysNotificationChannels extends Module
{
    public string $table    = 'ib_sys_notification_channels';
    public string $key      = 'id';
    public string $title    = 'Canals de Notificació';
    public string $icon     = 'fa-solid fa-satellite-dish';
    public string $orderBy  = 'channel_name';
    public string $orderDir = 'ASC';

    public bool $canDuplicate = false;
    public bool $canExport    = false;

    public array $fields = [
        'channel_code',
        'channel_name',
        'channel_type',
        'is_default_for_type',
        'is_enabled',
        'last_tested_at',
        'last_test_status',
        'created_at',
    ];

    public array $showFields = [
        'channel_code',
        'channel_name',
        'channel_type',
        'config_json',
        'test_recipient',
        'is_default_for_type',
        'is_enabled',
        'last_tested_at',
        'last_test_status',
        'notes',
        'created_at',
        'updated_at',
    ];

    public array $editFields = [
        'channel_name',
        'channel_type',
        'config_json',
        'test_recipient',
        'is_default_for_type',
        'is_enabled',
        'notes',
    ];

    public array $required = ['channel_name', 'channel_type'];

    public array $humans = [
        'channel_code'        => 'Codi',
        'channel_name'        => 'Nom del Canal',
        'channel_type'        => 'Tipus de Canal',
        'config_json'         => 'Configuració (JSON)',
        'test_recipient'      => 'Destinatari de Prova',
        'is_default_for_type' => 'Canal per Defecte del Tipus',
        'is_enabled'          => 'Habilitat',
        'last_tested_at'      => 'Darrera Prova',
        'last_test_status'    => 'Estat Darrera Prova',
        'notes'               => 'Notes',
        'created_at'          => 'Creat el',
        'updated_at'          => 'Actualitzat el',
    ];

    public array $components = [
        'channel_type'     => 'select',
        'config_json'      => 'code',
        'last_tested_at'   => 'datetime',
        'last_test_status' => 'select',
        'notes'            => 'textarea',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public array $selects = [
        'channel_type' => [
            'email'   => 'Email',
            'sms'     => 'SMS',
            'slack'   => 'Slack',
            'teams'   => 'Microsoft Teams',
            'telegram'=> 'Telegram',
            'webhook' => 'Webhook',
            'push'    => 'Push Notification',
            'in_app'  => 'In-App',
        ],
        'last_test_status' => [
            'success' => 'Correcte',
            'failed'  => 'Fallat',
            'pending' => 'Pendent',
        ],
    ];

    public array $bools = ['is_default_for_type', 'is_enabled'];

    public array $readOnly = ['channel_code', 'last_tested_at', 'last_test_status', 'created_at', 'updated_at'];

    public array $filterFields = [
        'channel_type' => 'select',
        'is_enabled'   => 'select',
    ];

    public array $filterOptions = [
        'channel_type' => [
            'email'    => 'Email',
            'sms'      => 'SMS',
            'slack'    => 'Slack',
            'teams'    => 'Microsoft Teams',
            'telegram' => 'Telegram',
            'webhook'  => 'Webhook',
            'push'     => 'Push',
            'in_app'   => 'In-App',
        ],
        'is_enabled' => [
            '1' => 'Habilitat',
            '0' => 'Deshabilitat',
        ],
    ];

    public array $searchFields = ['channel_code', 'channel_name'];

    public array $statusBadges = [
        'success' => 'success',
        'failed'  => 'danger',
        'pending' => 'warning',
    ];

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->defaultValues['is_enabled']          = 1;
        $this->defaultValues['is_default_for_type'] = 0;
        $this->defaultValues['channel_type']        = 'email';
        $this->defaultValues['config_json']         = '{}';

        $this->setGroup('general', [
            'label'  => 'Canal',
            'icon'   => 'fa-solid fa-satellite-dish',
            'fields' => ['channel_code', 'channel_name', 'channel_type', 'is_enabled', 'is_default_for_type'],
        ]);

        $this->setGroup('config_group', [
            'label'  => 'Configuració de Connexió',
            'icon'   => 'fa-solid fa-gears',
            'fields' => ['config_json', 'test_recipient'],
        ]);

        $this->setGroup('test_group', [
            'label'     => 'Estat de Proves',
            'icon'      => 'fa-solid fa-vial',
            'fields'    => ['last_tested_at', 'last_test_status'],
            'collapsed' => true,
        ]);

        $this->setGroup('notes_group', [
            'label'     => 'Notes',
            'icon'      => 'fa-solid fa-note-sticky',
            'fields'    => ['notes'],
            'collapsed' => true,
        ]);
    }

    public function onPreSave(array &$data): bool
    {
        $isNew = empty($data[$this->key]) || (int)$data[$this->key] === 0;

        if ($isNew) {
            $data['channel_code'] = $this->nextSeq('NCHANN', 'channel_code', 4);
        }

        $name = trim((string)($data['channel_name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('El nom del canal és obligatori.');
        }

        $type = (string)($data['channel_type'] ?? '');
        $validTypes = ['email','sms','slack','teams','telegram','webhook','push','in_app'];
        if (!in_array($type, $validTypes, true)) {
            throw new \RuntimeException('Tipus de canal no vàlid.');
        }

        // Validate JSON config
        if (!empty($data['config_json'])) {
            $decoded = json_decode((string)$data['config_json'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('La configuració JSON no és vàlida: ' . json_last_error_msg());
            }
        }

        // Auto-unset is_default_for_type on other channels of the same type
        if (!empty($data['is_default_for_type'])) {
            $currentId = (int)($data[$this->key] ?? 0);
            $stmt = $this->db->pdo()->prepare(
                "UPDATE {$this->table} SET is_default_for_type = 0
                 WHERE channel_type = ? AND id != ?"
            );
            $stmt->execute([$type, $currentId]);
        }

        return true;
    }
}
