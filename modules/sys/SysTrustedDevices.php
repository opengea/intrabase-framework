<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Database\Connection;
use Intrabase\Security\CSRF;
use Intrabase\Core\Container;
use Intrabase\Security\Session;

/**
 * sys_trusted_devices — 2FA Trusted Devices Manager
 *
 * Shows devices trusted for 2FA skip (stored in ib_user_trusted_devices).
 * Each user sees only their own devices; admins (group 1) see all.
 * Records are created automatically by VerifyController when "remember device" is selected.
 * Devices can be revoked (deleted) — the browser cookie remains but the device
 * will be treated as unknown on next login attempt, forcing re-verification.
 *
 * Table: ib_user_trusted_devices
 */
class SysTrustedDevices extends Module
{
    public string $table    = 'ib_user_trusted_devices';

    /** Dades personals: la interficie web nomes mostra les files propies. */
    public bool $scopeUiByOwner = true;
    public ?string $apiScopeByUser   = 'user_id';

    public string $key      = 'id';
    public string $title    = 'Trusted Devices';
    public string $icon     = 'fa-solid fa-mobile-screen-button';
    public string $orderBy  = 'created_at';
    public string $orderDir = 'DESC';

    public array $fields = [
        'id',
        'user_id',
        'ip',
        'user_agent',
        'created_at',
        'expires_at',
    ];

    public array $editFields   = [];   // read-only — no editing
    public array $detailFields = [
        'id',
        'user_id',
        'ip',
        'user_agent',
        'created_at',
        'expires_at',
    ];

    public array $searchFields = ['ip', 'user_agent'];

    public array $humans = [
        'id'           => 'ID',
        'user_id'      => 'User',
        'ip'           => 'IP Address',
        'user_agent'   => 'Browser / Device',
        'created_at'   => 'Trusted Since',
        'expires_at'   => 'Expires At',
    ];

    public array $components = [
        'user_id'    => 'combo',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public array $readonly = ['user_id', 'ip', 'user_agent', 'created_at', 'expires_at'];

    public bool $canAdd    = false;
    public bool $canEdit   = false;
    public bool $canDelete = true;   // Revoking = deleting the trusted device record
    public bool $canExport = false;  // device_token must never be exported

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->addCombo('user_id', 'ib_users', 'id', 'name');

        // Scope: non-admin users see only their own devices
        $session  = $this->container->get(Session::class);
        $user     = $session->user();
        $groupIds = array_map('intval', (array)($user['group_ids'] ?? []));
        $isAdmin  = in_array(1, $groupIds, true);

        if (!$isAdmin) {
            $uid = (int)($user['id'] ?? 0);
            $this->baseWhere = 'user_id = ' . $uid;
        }
    }

    /**
     * Advanced filters for DataBrowser.
     *
     * @return array<string, array<string, mixed>>
     */
    public function advancedFilters(): array
    {
        return [
            'user_id'    => ['type' => 'text',       'label' => 'User ID'],
            'ip'         => ['type' => 'text',        'label' => 'IP Address'],
            'created_at' => ['type' => 'date_range',  'label' => 'Trusted Since'],
            'expires_at' => ['type' => 'date_range',  'label' => 'Expires At'],
        ];
    }
}
