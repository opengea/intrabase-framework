<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Security\Session;
use Intrabase\Database\DB;

/**
 * sys_two_factor_auth — Two-Factor Authentication Settings
 *
 * Manages 2FA configuration per user.
 * Supports TOTP, SMS, email and backup codes methods.
 * Auto-sets enabled_at on first enable.
 * Masks sensitive fields (secret, backup_codes) in display.
 * canDelete=false for security; canExport=false for sensitive data.
 *
 * Table: ib_sys_two_factor_auth
 */
class SysTwoFactorAuth extends Module
{
    public string $table    = 'ib_sys_two_factor_auth';

    /** Dades personals: la interficie web nomes mostra les files propies. */
    public bool $scopeUiByOwner = true;
    public ?string $apiScopeByUser   = 'user_id';

    public string $key      = 'id';
    public string $title    = '2FA Settings';
    public string $icon     = 'fa-solid fa-mobile-screen-button';
    public string $orderBy  = 'user_id';
    public string $orderDir = 'ASC';

    public array $fields = [
        'id',
        'user_id',
        'method',
        'is_enabled',
        'enabled_at',
        'last_used_at',
        'fail_count',
        'locked_until',
    ];

    public array $showFields = [
        'user_id',
        'method',
        'is_enabled',
        'enabled_at',
        'last_used_at',
        'fail_count',
        'locked_until',
        'phone_number',
        'created_at',
        'updated_at',
    ];

    public array $editFields = [
        'user_id',
        'method',
        'is_enabled',
        'phone_number',
    ];

    public array $searchFields = ['user_id'];

    public array $humans = [
        'user_id'      => 'User',
        'method'       => 'Method',
        'secret'       => 'TOTP Secret',
        'phone_number' => 'Phone Number (SMS)',
        'backup_codes' => 'Backup Codes',
        'is_enabled'   => 'Enabled',
        'enabled_at'   => 'Enabled At',
        'last_used_at' => 'Last Used',
        'fail_count'   => 'Failed Attempts',
        'locked_until' => 'Locked Until',
        'created_at'   => 'Created',
        'updated_at'   => 'Updated',
    ];

    public array $components = [
        'user_id'      => 'combo',
        'method'       => 'select',
        'is_enabled'   => 'checkbox',
        'phone_number' => 'text',
        'enabled_at'   => 'datetime',
        'last_used_at' => 'datetime',
        'locked_until' => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public array $selects = [
        'method' => [
            'totp'         => 'TOTP (Authenticator App)',
            'sms'          => 'SMS',
            'email'        => 'Email',
            'backup_codes' => 'Backup Codes',
        ],
    ];

    public array $combos = [
        'user_id' => [
            'table'      => 'ib_users',
            'key'        => 'id',
            'label'      => 'username',
            'orderBy'    => 'username ASC',
            'allowEmpty' => false,
        ],
    ];

    public array $validates    = ['user_id', 'method'];
    public array $readonly     = ['enabled_at', 'last_used_at', 'fail_count', 'locked_until', 'created_at', 'updated_at'];
    public bool  $canAdd       = true;
    public bool  $canEdit      = true;
    public bool  $canDelete    = false;
    public bool  $canDuplicate = false;
    public bool  $canExport    = false;

    public array $advancedFilters = [
        ['name' => 'method',     'label' => 'Method',  'type' => 'select'],
        ['name' => 'is_enabled', 'label' => 'Enabled', 'type' => 'bool'],
    ];

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->defaultValues['method']     = 'totp';
        $this->defaultValues['is_enabled'] = '0';
        $this->defaultValues['fail_count'] = '0';

        $this->advancedFilters[0]['options'] = $this->selects['method'];

        $this->setGroup('config', [
            'label'  => '2FA Configuration',
            'icon'   => 'fa-solid fa-mobile-screen-button',
            'fields' => ['user_id', 'method', 'is_enabled', 'phone_number'],
        ]);
        $this->setGroup('status', [
            'label'  => 'Status & Activity',
            'icon'   => 'fa-solid fa-shield-halved',
            'fields' => ['enabled_at', 'last_used_at', 'fail_count', 'locked_until'],
        ]);
        $this->setGroup('meta', [
            'label'     => 'Timestamps',
            'icon'      => 'fa-solid fa-clock',
            'fields'    => ['created_at', 'updated_at'],
            'collapsed' => true,
        ]);
    }

    public function onPreSave(array &$data): bool
    {
        $db       = $this->container->get(DB::class);
        $isCreate = !isset($data[$this->key]) || (int) $data[$this->key] === 0;

        // Validate: if enabling, must have a method configured
        if (isset($data['is_enabled']) && (bool) $data['is_enabled']) {
            $method = $data['method'] ?? '';
            if (empty($method)) {
                throw new \RuntimeException('Cannot enable 2FA without selecting a method.');
            }
            if ($method === 'sms' && empty($data['phone_number'])) {
                throw new \RuntimeException('SMS method requires a phone number.');
            }
        }

        // Auto-set enabled_at on first enable
        if (isset($data['is_enabled']) && (bool) $data['is_enabled']) {
            if (!$isCreate) {
                $existing = $db->fetch(
                    "SELECT is_enabled, enabled_at FROM ib_sys_two_factor_auth WHERE id = :id",
                    ['id' => (int) $data[$this->key]]
                );
                // Only set enabled_at if this is the first time being enabled
                if (empty($existing['enabled_at']) && !(bool) ($existing['is_enabled'] ?? false)) {
                    $data['enabled_at'] = date('Y-m-d H:i:s');
                }
            } else {
                $data['enabled_at'] = date('Y-m-d H:i:s');
            }
        }

        // Never store plain-text secrets — mask in display (actual secret set via separate secure flow)
        // Remove secret and backup_codes from direct form saves for security
        unset($data['secret'], $data['backup_codes']);

        return true;
    }
}
