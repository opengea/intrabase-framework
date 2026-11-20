<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Security\Input;
use Intrabase\Security\Output;

/**
 * sys_api_tokens — API Token management
 *
 * Allows administrators to create, view, and revoke API access tokens.
 *
 * Security model:
 * - The raw token is shown ONCE on creation and never stored
 * - Only the SHA-256 hash is persisted in ib_api_tokens
 * - Revoked tokens cannot be re-activated (create a new one)
 *
 * @package Intrabase\Modules\Sys
 */
class SysApiTokens extends Module
{
    public string $table  = 'ib_api_tokens';
    public ?string $apiScopeByEntity = 'entity_id';

    public string $key    = 'id';
    public string $title  = 'API Tokens';
    public string $icon   = 'fa-solid fa-key';

    public array $fields = [
        'name', 'scopes', 'allowed_origins', 'rate_limit',
        'status', 'expires_at', 'last_used_at', 'created_at',
    ];

    public array $editFields = [
        'name', 'scopes', 'allowed_origins', 'rate_limit', 'expires_at',
    ];

    public array $searchFields = ['name', 'scopes'];

    public array $humans = [
        'name'            => 'Token name',
        'scopes'          => 'Scopes',
        'allowed_origins' => 'Allowed origins',
        'rate_limit'      => 'Rate limit (req/min)',
        'status'          => 'Status',
        'expires_at'      => 'Expires at',
        'last_used_at'    => 'Last used',
        'created_at'      => 'Created',
    ];

    public array $components = [
        'name'            => 'text',
        'scopes'          => 'combo',
        'allowed_origins' => 'text',
        'rate_limit'      => 'integer',
        'status'          => 'status_icon',
        'expires_at'      => 'datetime',
        'last_used_at'    => 'datetime',
        'created_at'      => 'datetime',
    ];

    public array $readonly = ['last_used_at', 'created_at'];

    public array $validates = ['name', 'scopes', 'rate_limit'];

    public array $defaultValues = [
        'scopes'          => 'read',
        'allowed_origins' => '*',
        'rate_limit'      => 100,
    ];

    public bool  $canDelete    = false; // Tokens are revoked, not deleted
    public bool  $canDuplicate = false;
    public string $orderBy     = 'created_at';
    public string $orderDir    = 'DESC';

    public function __construct(
        \Intrabase\Database\Connection $db,
        \Intrabase\Security\CSRF $csrf,
        \Intrabase\Core\Container $container
    ) {
        parent::__construct($db, $csrf, $container);

        // Scope options
        $this->combos['scopes'] = [
            'options' => [
                'read'             => 'read — Read-only',
                'read,write'       => 'read,write — Read & write',
                'read,write,delete'=> 'read,write,delete — Full CRUD',
                'admin'            => 'admin — Full access',
            ],
        ];

        $this->setGroup('token', [
            'label'  => 'Token configuration',
            'icon'   => 'fa-solid fa-key',
            'fields' => ['name', 'scopes', 'rate_limit', 'expires_at'],
        ]);

        $this->setGroup('access', [
            'label'  => 'Access control',
            'icon'   => 'fa-solid fa-shield-halved',
            'fields' => ['allowed_origins'],
        ]);
    }

    /**
     * Override execute to inject token generation on create
     */
    public function execute(): void
    {
        $action = Input::get('_', 'db');

        if ($action === 'sv') {
            $this->handleSaveWithTokenGen();
            return;
        }

        if ($action === 'revoke') {
            $this->handleRevoke();
            return;
        }

        parent::execute();
    }

    /**
     * On create: generate a raw token, hash it, store the hash.
     * Show the raw token once in a flash message.
     */
    private function handleSaveWithTokenGen(): void
    {
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }

        $id = Input::postInt('id', 0);

        // Only generate a new token on creation (not update)
        if ($id > 0) {
            parent::execute();
            return;
        }

        $data = $this->extractPostData();

        // Generate cryptographically secure 48-byte token → 96 hex chars
        try {
            $rawToken = bin2hex(random_bytes(48));
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to generate secure token']);
            return;
        }

        $data['token_hash'] = hash('sha256', $rawToken);
        $data['status']     = 'active';
        $data['created_by'] = $this->userAccount['id'] ?? null;

        if (!$this->onPreSave($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Save cancelled by hook']);
            return;
        }

        $newId = $this->db->query()->insert($this->table, $data);
        $this->onPostSave($newId, $data);

        // Store the raw token in session flash to show exactly once
        $session = $this->container->get(\Intrabase\Security\Session::class);
        $session->flash('api_token_created', $rawToken);
        $session->flash('api_token_name', $data['name'] ?? 'New token');

        header('Location: ?mod=sys_api_tokens&_=e&id=' . $newId . '&token_created=1');
        exit;
    }

    /**
     * POST ?mod=sys_api_tokens&_=revoke — Set token status to 'revoked'
     */
    private function handleRevoke(): void
    {
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }

        $id = Input::postInt('id', 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid ID']);
            return;
        }

        // Same rules as editing: edit permission, and the token must be within
        // the caller's scope (entity), like the list and the API. Otherwise a
        // 404 with no write: guessing ids must not revoke other people's tokens.
        if (!$this->canEdit || $this->userReadOnly || $this->findScoped($id) === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        $this->db->execute(
            "UPDATE {$this->table} SET status = 'revoked' WHERE id = ?",
            [$id]
        );

        header('Location: ?mod=sys_api_tokens&revoked=1');
        exit;
    }

    /**
     * Show the raw token flash message above the editor
     */
    public function onEdit(int $id): void
    {
        if ($id > 0 && Input::get('token_created') === '1') {
            $session = $this->container->get(\Intrabase\Security\Session::class);
            $token = $session->flashes()['api_token_created'] ?? null;
            $name  = $session->flashes()['api_token_name'] ?? '';

            if ($token) {
                // This will be rendered by Layout.php flash message system
                $session->flash('success',
                    "Token '{$name}' created. Copy it now — it will NEVER be shown again: " .
                    "<code style='font-family:monospace;word-break:break-all'>{$token}</code>"
                );
            }
        }
    }
}
