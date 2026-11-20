<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Database\Connection;
use Intrabase\Security\CSRF;
use Intrabase\Core\Container;

/**
 * sys_login_log — Login / Logout Event History
 *
 * Read-only viewer of the `ib_sys_login_log` table.
 * Records are written automatically by:
 *   - LoginController  : login (success), failed, 2fa_sent
 *   - VerifyController : 2fa_verified
 *   - Application      : logout
 *
 * No records can be created, edited, or deleted via this module.
 *
 * Table: ib_sys_login_log
 */
class SysLoginLog extends Module
{
    public string $table    = 'ib_sys_login_log';
    public ?string $apiScopeByUser   = 'user_id';

    public string $key      = 'id';
    public string $title    = 'Login Log';
    public string $icon     = 'fa-solid fa-right-to-bracket';
    public string $orderBy  = 'id';
    public string $orderDir = 'DESC';

    public array $fields = [
        'id',
        'user_id',
        'email',
        'event_type',
        'ip',
        'user_agent',
        'created_at',
    ];

    public array $editFields   = [];
    public array $detailFields = [
        'id',
        'user_id',
        'email',
        'event_type',
        'ip',
        'user_agent',
        'created_at',
    ];

    public array $searchFields = ['ip', 'email', 'user_agent'];

    public array $humans = [
        'id'         => 'ID',
        'user_id'    => 'User',
        'email'      => 'Email',
        'event_type' => 'Event',
        'ip'         => 'IP Address',
        'user_agent' => 'User Agent',
        'created_at' => 'Date / Time',
    ];

    public array $components = [
        'user_id'    => 'combo',
        'event_type' => 'combo',
    ];

    public bool $canAdd    = false;
    public bool $canEdit   = false;
    public bool $canDelete = false;
    public bool $canExport = true;

    public function __construct(Connection $db, CSRF $csrf, Container $container)
    {
        parent::__construct($db, $csrf, $container);

        $this->addCombo('user_id', 'ib_users', 'id', 'name');

        $this->combos['event_type'] = [
            'data' => [
                ''             => '— all —',
                'login'        => 'Login',
                'logout'       => 'Logout',
                'failed'       => 'Failed',
                '2fa_sent'     => '2FA Sent',
                '2fa_verified' => '2FA Verified',
            ],
        ];
    }

    /**
     * Advanced filters for the DataBrowser.
     *
     * @return array<string, array<string, mixed>>
     */
    public function advancedFilters(): array
    {
        return [
            'event_type' => [
                'type'    => 'select',
                'label'   => 'Event',
                'options' => [
                    ''             => '— all —',
                    'login'        => 'Login',
                    'logout'       => 'Logout',
                    'failed'       => 'Failed',
                    '2fa_sent'     => '2FA Sent',
                    '2fa_verified' => '2FA Verified',
                ],
            ],
            'user_id'    => ['type' => 'text',       'label' => 'User ID'],
            'ip'         => ['type' => 'text',        'label' => 'IP Address'],
            'email'      => ['type' => 'text',        'label' => 'Email'],
            'created_at' => ['type' => 'date_range',  'label' => 'Date'],
        ];
    }
}
