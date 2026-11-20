<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * sys_tenants — Multi-Tenant Management
 *
 * Manages tenant configurations in a multi-tenant setup.
 * Each tenant has its own domain/subdomain, database,
 * appearance, modules, and access settings.
 *
 * Table: ib_tenants
 */
class SysTenants extends Module
{
    public string $table    = 'ib_tenants';
    public string $key      = 'id';
    public string $title    = 'Tenants';
    public string $icon     = 'fa-solid fa-building';
    public string $orderBy  = 'domain';
    public string $orderDir = 'ASC';

    /** Temporary storage for admin password during save */
    private string $pendingAdminPassword = '';

    public array $fields = [
        'id',
        'client_name',
        'domain',
        'subdomain',
        'status',
        'max_users',
        'created_at',
    ];

    public array $showFields = [
        // General
        'client_name', 'domain', 'subdomain', 'status', 'default_lang',
        'business_model', 'logo', 'notes',
        // Access
        'admin_username', 'admin_email', 'max_users', 'api_key',
        'session_name', 'cookie_domain',
        // Database
        'db_name', 'db_host', 'db_port', 'db_user',
        // Appearance
        'theme', 'primary_color', 'secondary_color', 'text_color', 'bg_image',
        // Features
        'modules', 'autorun_app', 'ai_assistant', 'debug_mode',
        // Linking
        'client_id', 'contract_id', 'hosting_id',
        // Meta
        'created_at', 'updated_at',
    ];

    public array $editFields = [
        // General
        'client_name', 'domain', 'subdomain', 'status', 'default_lang',
        'business_model', 'logo', 'notes',
        // Access
        'admin_username', 'admin_email', 'admin_password', 'max_users', 'api_key',
        'session_name', 'cookie_domain',
        // Database
        'db_name', 'db_host', 'db_port', 'db_user', 'db_pass',
        // Appearance
        'theme', 'primary_color', 'secondary_color', 'text_color', 'bg_image',
        // Features
        'modules', 'autorun_app', 'ai_assistant', 'debug_mode',
        // Linking
        'client_id', 'contract_id', 'hosting_id',
    ];

    public array $searchFields = ['client_name', 'domain', 'subdomain', 'db_name', 'admin_email'];

    public array $humans = [
        'id'              => 'ID',
        'client_name'     => 'Client',
        'domain'          => 'Domain',
        'subdomain'       => 'Subdomain',
        'status'          => 'Status',
        'default_lang'    => 'Language',
        'business_model'  => 'Business Model',
        'logo'            => 'Logo',
        'notes'           => 'Notes',
        'admin_username'  => 'Admin User',
        'admin_email'     => 'Admin Email',
        'admin_password'  => 'Admin Password',
        'max_users'       => 'Max Users',
        'api_key'         => 'API Key',
        'session_name'    => 'Session Name',
        'cookie_domain'   => 'Cookie Domain',
        'db_name'         => 'Database',
        'db_host'         => 'DB Host',
        'db_port'         => 'DB Port',
        'db_user'         => 'DB User',
        'db_pass'         => 'DB Password',
        'theme'           => 'Theme',
        'primary_color'   => 'Primary Color',
        'secondary_color' => 'Secondary Color',
        'text_color'      => 'Text Color',
        'bg_image'        => 'Background Image',
        'modules'         => 'Modules',
        'autorun_app'     => 'Autorun App',
        'ai_assistant'    => 'AI Assistant',
        'debug_mode'      => 'Debug Mode',
        'client_id'       => 'Client',
        'contract_id'     => 'Contract ID',
        'hosting_id'      => 'Hosting ID',
        'created_at'      => 'Created',
        'updated_at'      => 'Updated',
    ];

    public array $components = [
        'status'          => 'select',
        'default_lang'    => 'select',
        'business_model'  => 'select',
        'logo'            => 'picture',
        'notes'           => 'textarea',
        'admin_password'  => 'password',
        'db_pass'         => 'password',
        'primary_color'   => 'color',
        'secondary_color' => 'color',
        'text_color'      => 'color',
        'modules'         => 'checklist',
        'ai_assistant'    => 'toggle',
        'debug_mode'      => 'toggle',
        'max_users'       => 'number',
        'client_id'       => 'relation_autocomplete',
        'contract_id'     => 'number',
        'hosting_id'      => 'number',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public array $selects = [
        'status' => [
            'active'    => 'Active',
            'inactive'  => 'Inactive',
            'suspended' => 'Suspended',
        ],
        'default_lang' => [
            'ca' => 'Catala',
            'es' => 'Castellano',
            'en' => 'English',
            'fr' => 'Francais',
            'de' => 'Deutsch',
            'pt' => 'Portugues',
            'it' => 'Italiano',
            'eu' => 'Euskara',
        ],
        'business_model' => [
            ''         => '-- select --',
            'saas'     => 'SaaS',
            'onprem'   => 'On-Premise',
            'trial'    => 'Trial',
            'internal' => 'Internal',
        ],
    ];

    public array $validates = ['domain', 'session_name'];
    public array $readonly  = ['created_at', 'updated_at'];
    public array $hidden    = ['updated_at'];

    public bool $canDuplicate = false;
    public bool $canExport    = true;

    public array $advancedFilters = [
        [
            'field'   => 'status',
            'label'   => 'Status',
            'type'    => 'select',
            'options' => [
                ''          => '-- all --',
                'active'    => 'Active',
                'inactive'  => 'Inactive',
                'suspended' => 'Suspended',
            ],
        ],
        [
            'field'   => 'business_model',
            'label'   => 'Business Model',
            'type'    => 'select',
            'options' => [
                ''         => '-- all --',
                'saas'     => 'SaaS',
                'onprem'   => 'On-Premise',
                'trial'    => 'Trial',
                'internal' => 'Internal',
            ],
        ],
    ];

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->componentOptions['admin_password'] = ['show_value' => true, 'show_confirm' => false];
        $this->componentOptions['db_pass']        = ['show_value' => true, 'show_confirm' => false];

        // Load available apps for the modules checklist
        try {
            $apps = $this->db->fetchAll(
                "SELECT keyname, name FROM ib_apps WHERE is_active = 1 ORDER BY sort_order, name"
            );
            $appOptions = [];
            foreach ($apps as $app) {
                $appOptions[$app['keyname']] = $app['name'];
            }
            $this->combos['modules'] = ['data' => $appOptions];
        } catch (\Throwable) {
            $this->combos['modules'] = ['data' => []];
        }

        $this->defaultValues['status']       = 'active';
        $this->defaultValues['session_name'] = 'IBSESSID';
        $this->defaultValues['default_lang'] = 'ca';
        $this->defaultValues['max_users']    = -1;
        $this->defaultValues['db_host']      = '127.0.0.1';
        $this->defaultValues['db_port']      = '3306';
        $this->defaultValues['ai_assistant'] = 1;
        $this->defaultValues['debug_mode']   = 0;
        $this->defaultValues['text_color']   = '666666';

        // Tab 1: General
        $this->setGroup('general', [
            'label'  => 'General',
            'icon'   => 'fa-solid fa-globe',
            'fields' => ['client_name', 'domain', 'subdomain', 'status', 'default_lang', 'business_model', 'logo', 'notes'],
        ]);

        // Tab 2: Access
        $this->setGroup('access', [
            'label'  => 'Access',
            'icon'   => 'fa-solid fa-key',
            'fields' => ['admin_username', 'admin_email', 'admin_password', 'max_users', 'api_key', 'session_name', 'cookie_domain'],
        ]);

        // Tab 3: Database
        $this->setGroup('database', [
            'label'  => 'Database',
            'icon'   => 'fa-solid fa-database',
            'fields' => ['db_name', 'db_host', 'db_port', 'db_user', 'db_pass'],
        ]);

        // Tab 4: Appearance
        $this->setGroup('appearance', [
            'label'  => 'Appearance',
            'icon'   => 'fa-solid fa-palette',
            'fields' => ['theme', 'primary_color', 'secondary_color', 'text_color', 'bg_image'],
        ]);

        // Tab 5: Features
        $this->setGroup('features', [
            'label'  => 'Features',
            'icon'   => 'fa-solid fa-puzzle-piece',
            'fields' => ['modules', 'autorun_app', 'ai_assistant', 'debug_mode'],
        ]);

        // Tab 6: Linking
        $this->setGroup('linking', [
            'label'    => 'CRM Linking',
            'icon'     => 'fa-solid fa-link',
            'fields'   => ['client_id', 'contract_id', 'hosting_id'],
            'collapsed' => true,
        ]);
    }

    /**
     * Validate and normalize data before saving.
     */
    public function onPreSave(array &$data): bool
    {
        // Capture admin_password for syncing to tenant DB
        $this->pendingAdminPassword = trim($data['admin_password'] ?? '');

        if (!empty($data['domain'])) {
            $domain = strtolower(trim($data['domain']));
            $domain = preg_replace('#^https?://#', '', $domain);
            $domain = rtrim($domain, '/');
            $data['domain'] = $domain;
        }

        if (!empty($data['session_name'])) {
            $data['session_name'] = preg_replace('/[^A-Za-z0-9_]/', '', $data['session_name']);
            if (empty($data['session_name'])) {
                $data['session_name'] = 'IBSESSID';
            }
        }

        // Ensure UNIQUE-indexed api_key stores NULL instead of empty string
        if (array_key_exists('api_key', $data) && trim((string) $data['api_key']) === '') {
            $data['api_key'] = null;
        }

        return true;
    }

    /**
     * After saving, sync admin password to the tenant's database.
     */
    public function onPostSave(int $id, array $data): void
    {
        if ($this->pendingAdminPassword === '') {
            return;
        }

        $tenant = $this->find($id);
        if (!$tenant) {
            return;
        }

        $dbName  = $tenant['db_name'] ?? '';
        $dbHost  = $tenant['db_host'] ?? '127.0.0.1';
        $dbPort  = $tenant['db_port'] ?? '3306';
        $dbUser  = $tenant['db_user'] ?? '';
        $dbPass  = $tenant['db_pass'] ?? '';
        $adminEmail = $tenant['admin_email'] ?? '';

        if ($dbName === '' || $adminEmail === '') {
            error_log("[SysTenants] Cannot sync admin password for tenant #{$id}: missing db_name or admin_email");
            return;
        }

        // Use main DB credentials as fallback
        if ($dbUser === '') {
            $config = \Intrabase\Core\Application::getInstance()->getConfig();
            $dbUser = $config['db_user'] ?? '';
            $dbPass = $config['db_pass'] ?? '';
        }

        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new \PDO($dsn, $dbUser, $dbPass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $hash = password_hash($this->pendingAdminPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $adminUsername = $tenant['admin_username'] ?? 'admin';
            $clientName    = $tenant['client_name'] ?? 'Admin';

            // Check if admin user exists
            $stmt = $pdo->prepare('SELECT id FROM ib_users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $adminEmail]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            $userId = null;

            if ($existing) {
                // Update password
                $userId = (int) $existing['id'];
                $pdo->prepare('UPDATE ib_users SET password_hash = :hash WHERE id = :id')
                    ->execute([':hash' => $hash, ':id' => $userId]);
            } else {
                // Create admin user
                $pdo->prepare(
                    'INSERT INTO ib_users (username, email, password_hash, name, active, locale, theme)
                     VALUES (:user, :email, :hash, :name, 1, :locale, :theme)'
                )->execute([
                    ':user'   => $adminUsername,
                    ':email'  => $adminEmail,
                    ':hash'   => $hash,
                    ':name'   => $clientName,
                    ':locale' => $tenant['default_lang'] ?? 'ca',
                    ':theme'  => 'dark',
                ]);
                $userId = (int) $pdo->lastInsertId();
            }

            // Ensure admin group exists and user belongs to it
            if ($userId > 0) {
                $this->ensureAdminGroup($pdo, $userId);
            }
        } catch (\Throwable $e) {
            error_log("[SysTenants] Failed to sync admin for tenant #{$id}: " . $e->getMessage());
        }

        $this->pendingAdminPassword = '';
    }

    /**
     * Ensure the admin group exists in the tenant DB and the user belongs to it.
     */
    private function ensureAdminGroup(\PDO $pdo, int $userId): void
    {
        // Create default groups if they don't exist
        $existing = $pdo->query("SELECT id FROM ib_groups WHERE name = 'admin' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$existing) {
            $pdo->exec("INSERT IGNORE INTO ib_groups (id, name, description) VALUES
                (1, 'admin', 'Full system access'),
                (2, 'staff', 'Regular staff access'),
                (3, 'readonly', 'Read-only access')");
            $adminGroupId = 1;
        } else {
            $adminGroupId = (int) $existing['id'];
        }

        // Assign user to admin group if not already
        $stmt = $pdo->prepare('SELECT 1 FROM ib_user_groups WHERE user_id = :uid AND group_id = :gid LIMIT 1');
        $stmt->execute([':uid' => $userId, ':gid' => $adminGroupId]);
        if (!$stmt->fetch()) {
            $pdo->prepare('INSERT INTO ib_user_groups (user_id, group_id) VALUES (:uid, :gid)')
                ->execute([':uid' => $userId, ':gid' => $adminGroupId]);
        }
    }
}
