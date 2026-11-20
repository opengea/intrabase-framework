<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * sys_users — User Management Module
 *
 * Provides full CRUD for system users.
 * Passwords are hashed with bcrypt (never stored in plain text).
 * Group membership is managed via the ib_user_groups join table.
 */
class SysUsers extends Module
{
    public string $table = 'ib_users';
    public string $key   = 'id';
    public string $title = 'Users';
    public string $icon  = 'fa-solid fa-users';

    public array $fields = ['id', 'avatar', 'username', 'email', 'name', 'locale', 'active', 'last_login', 'created_at'];
    public array $editFields = ['avatar', 'username', 'email', 'name', 'password', 'locale', 'active', 'groups'];
    public array $showFields = ['avatar', 'username', 'email', 'name', 'locale', 'active', 'last_login'];
    public array $searchFields = ['username', 'email', 'name'];

    public array $humans = [
        'id'         => 'ID',
        'avatar'     => 'Photo',
        'username'   => 'Username',
        'email'      => 'Email',
        'name'       => 'Full Name',
        'password'   => 'Password',
        'locale'     => 'Language',
        'active'     => 'Active',
        'last_login' => 'Last Login',
        'created_at' => 'Created',
        'groups'     => 'Groups',
    ];

    public array $components = [
        'avatar'     => 'picture',
        'email'      => 'email',
        'password'   => 'password',
        'active'     => 'combo',
        'locale'     => 'combo',
        'last_login' => 'datetime',
        'created_at' => 'datetime',
        'groups'     => 'checklist',
    ];

    public array $componentOptions = [
        'avatar' => ['webpath' => '/uploads/avatars', 'thumb_size' => 32, 'edit_size' => 120, 'round' => true, 'hide_label' => true, 'display_field' => 'username'],
    ];

    public array $readonly = ['avatar', 'last_login', 'created_at'];

    public array $validates = ['username', 'email'];

    public array $maxLengths = [
        'username' => 64,
        'email'    => 255,
        'name'     => 255,
    ];

    public string $orderBy  = 'name';
    public bool $canDuplicate = false;

    public array $filterFields = [
        'active'     => 'select',
        'created_at' => 'date_range',
        'last_login' => 'date_range',
    ];

    public array $filterOptions = [
        'active' => ['1' => 'Active', '0' => 'Inactive'],
    ];

    /** Pending group IDs captured from the form POST, synced in onPostSave. */
    private array $pendingGroups = [];

    /** Plain-text password captured in onPreSave for reverse mailbox sync. */
    private ?string $pendingPlainPassword = null;

    public function __construct(...$args)
    {
        parent::__construct(...$args);

        // Combo: active status
        $this->combos['active'] = ['data' => ['1' => 'Active', '0' => 'Inactive']];

        // Combo: locale
        $this->combos['locale'] = ['data' => [
            'en' => 'English',
            'ca' => 'Català',
            'es' => 'Español',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'pt' => 'Português',
            'it' => 'Italiano',
        ]];

        // Checklist: groups (DB-backed)
        $this->combos['groups'] = [
            'table' => 'ib_groups',
            'key'   => 'id',
            'value' => 'name',
        ];

        // Group fields in editor
        $this->setGroup('account', [
            'label'  => 'Account',
            'icon'   => 'fa-solid fa-user',
            'fields' => ['avatar', 'username', 'email', 'name', 'password'],
        ]);
        $this->setGroup('preferences', [
            'label'  => 'Preferences',
            'icon'   => 'fa-solid fa-sliders',
            'fields' => ['locale', 'active'],
        ]);
        $this->setGroup('access', [
            'label'  => 'Access',
            'icon'   => 'fa-solid fa-shield-halved',
            'fields' => ['groups'],
        ]);
    }

    /**
     * Override find() to also load the user's group membership from ib_user_groups.
     * The groups are returned as a comma-separated string of group IDs,
     * which is what the checklist component expects.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $record = parent::find($id);
        if ($record !== null) {
            $rows = $this->db->fetchAll(
                'SELECT group_id FROM ib_user_groups WHERE user_id = :uid ORDER BY group_id',
                ['uid' => $id]
            );
            $record['groups'] = implode(',', array_column($rows, 'group_id'));
        }
        return $record;
    }

    /**
     * Override extractPostData() to handle the groups[] checkbox array separately.
     * The groups field must NOT be passed to the ib_users INSERT/UPDATE query.
     *
     * @return array<string, mixed>
     */
    protected function extractPostData(): array
    {
        // Temporarily exclude 'groups' so parent does not try to sanitize an array
        $orig = $this->editFields;
        $this->editFields = array_values(array_filter($orig, fn($f) => $f !== 'groups'));
        $data = parent::extractPostData();
        $this->editFields = $orig;

        // Capture groups checkboxes: sanitize to positive integers only
        $raw = $_POST['groups'] ?? [];
        $this->pendingGroups = is_array($raw)
            ? array_values(array_filter(array_map('intval', $raw), fn($v) => $v > 0))
            : [];

        return $data;
    }

    /**
     * Hash password before saving. Do NOT store plain text.
     * Called for both create and update. Password field is optional on update
     * (leave blank to keep the existing hash).
     *
     * @param array<string, mixed> $data
     */
    public function onPreSave(array &$data): bool
    {
        // Never persist the 'groups' virtual field to ib_users
        unset($data['groups']);

        if (!empty($data['password'])) {
            if (strlen($data['password']) < 8) {
                throw new \InvalidArgumentException('Password must be at least 8 characters.');
            }
            // Capture plain password for reverse mailbox sync before hashing
            $this->pendingPlainPassword = $data['password'];
            $data['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }
        unset($data['password']); // Never persist the plain-text field

        return true;
    }

    /**
     * Override create to enforce that new users must have a password.
     */
    public function create(array $data): int
    {
        if (empty(trim($data['password'] ?? ''))) {
            throw new \InvalidArgumentException('Password is required for new users (min. 8 characters).');
        }
        return parent::create($data);
    }

    /**
     * Sync the ib_user_groups join table after saving the user.
     * Deletes all existing memberships and re-inserts from $pendingGroups.
     *
     * @param int                  $id   Saved user primary key
     * @param array<string, mixed> $data Saved data (ib_users columns only)
     */
    public function onPostSave(int $id, array $data): void
    {
        // Delete existing group memberships for this user
        $this->db->query()->delete('ib_user_groups', 'user_id = :uid', ['uid' => $id]);

        // Re-insert the submitted groups
        foreach ($this->pendingGroups as $groupId) {
            $this->db->query()->insert('ib_user_groups', [
                'user_id'  => $id,
                'group_id' => $groupId,
            ]);
        }

        // Reverse sync: propagate password change to CRM mailbox and mail server
        if ($this->pendingPlainPassword !== null) {
            $this->syncPasswordToMailbox($id, $this->pendingPlainPassword);
            $this->pendingPlainPassword = null;
        }
    }

    /**
     * Tell the plugins that the password changed (UserPasswordChanged event).
     */
    private function syncPasswordToMailbox(int $userId, string $plainPassword): void
    {
        $user = $this->db->fetch('SELECT email FROM ib_users WHERE id = :id', ['id' => $userId]);
        if (!$user || empty($user['email'])) return;

        \Intrabase\Domain\Events\UserPasswordChanged::publish($userId, (string) $user['email'], $plainPassword);
    }
}
