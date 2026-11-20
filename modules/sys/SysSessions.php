<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * sys_sessions — Active Session Manager
 *
 * Displays all currently active user sessions tracked in ib_sessions.
 * Administrators can revoke (delete) any session, forcing an immediate
 * logout for that user on their next request.
 *
 * Session records are populated by Application on each authenticated request
 * (throttled to every 5 minutes to reduce write pressure).
 */
class SysSessions extends Module
{
    public string $table = 'ib_sessions';

    /** Dades personals: la interficie web nomes mostra les files propies. */
    public bool $scopeUiByOwner = true;
    public ?string $apiScopeByUser   = 'user_id';

    public string $key   = 'id';
    public string $title = 'Active Sessions';
    public string $icon  = 'fa-solid fa-key';

    public array $fields      = ['id', 'username', 'ip_address', 'user_agent', 'created_at', 'last_activity'];
    public array $showFields  = ['username', 'ip_address', 'created_at', 'last_activity', 'user_agent'];
    public array $searchFields = ['username', 'ip_address'];

    public array $humans = [
        'id'            => 'ID',
        'session_id'    => 'Session Token',
        'user_id'       => 'User ID',
        'username'      => 'User',
        'ip_address'    => 'IP Address',
        'user_agent'    => 'Browser / Client',
        'created_at'    => 'Login At',
        'last_activity' => 'Last Activity',
    ];

    public array $components = [
        'created_at'    => 'datetime',
        'last_activity' => 'datetime',
    ];

    public array $readonly = ['id', 'session_id', 'user_id', 'username', 'ip_address', 'user_agent', 'created_at', 'last_activity'];

    public string $orderBy  = 'last_activity';
    public string $orderDir = 'DESC';

    public bool $canAdd       = false;
    public bool $canEdit      = false;
    public bool $canDelete    = true;   // Revoke = delete
    public bool $canExport    = false;
    public bool $canDuplicate = false;
    public bool $showKey      = false;

    /**
     * Before deleting: destroy the PHP session file so the user is
     * immediately forced out on their next request.
     */
    public function onPreDelete(int $id): bool
    {
        $record = $this->db->fetchOne(
            'SELECT session_id FROM ib_sessions WHERE id = :id',
            ['id' => $id]
        );

        if ($record !== null && !empty($record['session_id'])) {
            $this->destroyPhpSession($record['session_id']);
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Destroy a PHP session file given the raw session ID.
     *
     * Validates the session ID format before attempting filesystem access.
     * Silently ignores missing or unwritable files.
     */
    private function destroyPhpSession(string $sessionId): void
    {
        // Session IDs must be alphanumeric (PHP default generator)
        if (!preg_match('/^[a-zA-Z0-9,\-]{1,128}$/', $sessionId)) {
            return;
        }

        $savePath = rtrim(session_save_path() ?: sys_get_temp_dir(), '/');
        $file     = $savePath . '/sess_' . $sessionId;

        if (is_file($file)) {
            @unlink($file);
        }
    }
}
