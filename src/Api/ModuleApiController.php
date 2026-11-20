<?php

declare(strict_types=1);

namespace Intrabase\Api;

use Intrabase\Core\Module;
use Intrabase\Core\ModuleLoader;
use Intrabase\Database\Connection;
use Intrabase\Security\Acl;
use Intrabase\Support\Logger;

/**
 * Generic CRUD API controller for any intrabase module
 *
 * Routes:
 *   GET    /api/v1/{module}           → list (paginated, filterable)
 *   GET    /api/v1/{module}/{id}      → get single record
 *   POST   /api/v1/{module}           → create
 *   PUT    /api/v1/{module}/{id}      → full update
 *   PATCH  /api/v1/{module}/{id}      → partial update
 *   DELETE /api/v1/{module}/{id}      → delete
 *
 * @package Intrabase\Api
 */
class ModuleApiController extends ApiController
{
    private ModuleLoader $loader;

    public function __construct(Connection $db, Logger $logger, ModuleLoader $loader)
    {
        parent::__construct($db, $logger);
        $this->loader = $loader;
    }

    /**
     * Entry point: authenticate, route, dispatch
     *
     * @param array<string, mixed> $clientAccount
     * @param array<string, mixed> $userAccount
     */
    public function handle(array $clientAccount, array $userAccount): void
    {
        $this->cors();
        $this->authenticate();
        $this->setCorsOrigin();

        $moduleName = $this->request->segment(0);
        if (!$moduleName || !preg_match('/^[a-z][a-z0-9_]*$/', $moduleName)) {
            Response::notFound('Module');
        }

        // Fine-grained whitelist (ADR 001 / Decision 4)
        // A token may restrict itself to a subset of modules. If `modules`
        // is set on the token, any module NOT listed is forbidden — even
        // if the user would otherwise have ACL access.
        $this->enforceModuleWhitelist($moduleName);

        // System modules (users, groups, permissions, settings...) change who
        // can do what: through a token they need the explicit 'admin' scope.
        // API tokens themselves are never managed with a token, only from the
        // user interface: a token could otherwise widen its own scopes.
        if (str_starts_with($moduleName, 'sys_')) {
            $viaSession = ($this->tokenData['auth_method'] ?? '') === 'session';
            if ($moduleName === 'sys_api_tokens' && !$viaSession) {
                Response::forbidden('API tokens are managed from the user interface, not through the API');
            }
            $this->requireScope('admin');
        }

        $idSegment = $this->request->segment(1);
        $id = ($idSegment !== null && ctype_digit($idSegment)) ? (int)$idSegment : null;

        // Load module
        try {
            $module = $this->loader->load($moduleName);
        } catch (\InvalidArgumentException) {
            Response::notFound("Module '{$moduleName}'");
        }

        // ACL — applied for 'user' tokens only (ADR 001 / Decision 2).
        // 'service' tokens rely on scope + module-whitelist alone, no
        // per-user ACL (because there's no principal user behind them).
        $tokenType   = (string) ($this->tokenData['token_type'] ?? 'user');
        $tokenUserId = (int) ($this->tokenData['created_by'] ?? 0);
        if ($tokenType === 'user' && $tokenUserId > 0) {
            $acl = new Acl();
            $userGroups = $acl->getUserGroups($tokenUserId, $this->db);
            if (!$acl->canAccess($moduleName, $userGroups, $this->db)) {
                Response::forbidden("Access denied to module '{$moduleName}'");
            }
        }

        // Enrich userAccount from the token so Module::apiList() scope works
        // (Application::handleApiRequest() passes []; token carries the truth).
        $userAccount = $this->buildUserAccountFromToken($userAccount);

        $module->setClientAccount($clientAccount);
        $module->setUserAccount($userAccount);

        // Granular permissions of the principal user (token owner or session
        // user), as the web interface applies them: ib_permissions per group
        // and the per-person read-only level of the app (UserAppAccess).
        $this->applyPrincipalPermissions($module, $moduleName);

        // Non-CRUD REST action sub-routes: /api/v1/{module}/{id}/{action}
        // (ADR 001 amendment). Intercepted here, AFTER the shared pipeline
        // (auth, module whitelist, ACL, token→userAccount) so the delegated
        // action controller inherits that context and only adds verb/scope +
        // business logic. Modules opt in via ACTION_CONTROLLERS; today only
        // mailing_campaigns is wired.
        $action = $this->request->segment(2);
        if ($action !== null && $action !== '') {
            $this->dispatchAction($module, $moduleName, $id, $action);
            return;
        }

        $method = $this->request->method();

        match (true) {
            $method === 'GET'    && $id === null => $this->requireScope('read')   && $this->index($module),
            $method === 'GET'    && $id !== null => $this->requireScope('read')   && $this->show($module, $id),
            $method === 'POST'   && $id === null => $this->requireScope('write')  && $this->create($module),
            $method === 'PUT'    && $id !== null => $this->requireScope('write')  && $this->update($module, $id, false),
            $method === 'PATCH'  && $id !== null => $this->requireScope('write')  && $this->update($module, $id, true),
            $method === 'DELETE' && $id !== null => $this->requireScope('delete') && $this->destroy($module, $id),
            default                              => Response::methodNotAllowed($method),
        };
    }

    // -------------------------------------------------------------------------
    // Action handlers
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/{module} — List records
     */
    private function index(Module $module): bool
    {
        $page    = $this->request->queryInt('page', 1);
        $perPage = min($this->request->queryInt('per_page', $module->getPerPage()), 200);
        $search  = $this->request->query('q', '');
        $orderBy = $this->request->query('sort', $module->getOrderBy());
        $orderDir = strtoupper($this->request->query('dir', $module->getOrderDir()) ?? 'ASC');

        // Validate sort field against module's field list
        $allowedFields = $module->getFields();
        if (!in_array($orderBy, $allowedFields, true)) {
            $orderBy = $module->getOrderBy();
        }

        // Only ASC / DESC
        if (!in_array($orderDir, ['ASC', 'DESC'], true)) {
            $orderDir = 'ASC';
        }

        // Build filter from query params (only recognized fields)
        $filters = [];
        foreach ($allowedFields as $field) {
            $val = $this->request->query($field);
            if ($val !== null) {
                $filters[$field] = $val;
            }
        }

        $result = $module->apiList(
            page:     $page,
            perPage:  $perPage,
            search:   is_string($search) ? $search : '',
            orderBy:  $orderBy,
            orderDir: $orderDir,
            filters:  $filters,
        );

        Response::paginated(array_map(fn($row) => $this->redact($module, $row), $result['data']), [
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
            'pages'    => $result['pages'],
        ]);
    }

    /**
     * GET /api/v1/{module}/{id} — Get single record
     */
    private function show(Module $module, int $id): bool
    {
        // findScoped applies $apiScopeBy* — out-of-scope ids 404 (never 403)
        $record = $module->findScoped($id);

        if (!$record) {
            Response::notFound(ucfirst($module->getModuleName()) . " #{$id}");
        }

        Response::ok($this->redact($module, $record));
    }

    /**
     * POST /api/v1/{module} — Create record
     */
    private function create(Module $module): bool
    {
        if (!$module->canAdd()) {
            Response::forbidden('Module does not allow creating records via API');
        }

        $data = $this->filterInputData($module, $this->request->allBody());

        $errors = $module->validateData($data);
        if ($errors) {
            Response::unprocessable($errors);
        }

        try {
            $id = $module->create($data);
        } catch (\Throwable $e) {
            $this->logger->error('API create failed', [
                'module' => $module->getModuleName(),
                'error'  => $e->getMessage(),
            ]);
            Response::serverError('Failed to create record');
        }

        $created = $module->find($id);
        Response::ok($this->redact($module, $created), [], 201);
    }

    /**
     * PUT/PATCH /api/v1/{module}/{id} — Update record
     */
    private function update(Module $module, int $id, bool $partial): bool
    {
        if (!$module->canEdit()) {
            Response::forbidden('Module does not allow updating records via API');
        }

        $existing = $module->findScoped($id);
        if (!$existing) {
            Response::notFound(ucfirst($module->getModuleName()) . " #{$id}");
        }

        $input = $this->filterInputData($module, $this->request->allBody());

        if ($partial) {
            // PATCH: merge with existing, only update sent fields
            $data = array_merge($existing, $input);
        } else {
            // PUT: full replacement of editable fields
            $data = $input;
        }

        $errors = $module->validateData($data);
        if ($errors) {
            Response::unprocessable($errors);
        }

        // Write only what the caller sent, never the read-only fields — the same
        // rule as the form (Module::handleSave). The merged row is for validation
        // only: writing it back round-trips every column through sanitizeData,
        // and on the KMS views that corrupts formatted text (29/10/2026, bank
        // transaction 26146: "31.746,99" → 31.746, creation_date → NULL).
        $write = array_diff_key($partial ? $input : $data, array_flip($module->readonly));

        try {
            if ($write !== []) {
                $module->update($id, $write);
            }
        } catch (\Throwable $e) {
            $this->logger->error('API update failed', [
                'module' => $module->getModuleName(),
                'id'     => $id,
                'error'  => $e->getMessage(),
            ]);
            Response::serverError('Failed to update record');
        }

        Response::ok($this->redact($module, $module->findScoped($id)));
    }

    /**
     * DELETE /api/v1/{module}/{id} — Delete record
     */
    private function destroy(Module $module, int $id): bool
    {
        if (!$module->canDelete()) {
            Response::forbidden('Module does not allow deleting records via API');
        }

        $existing = $module->findScoped($id);
        if (!$existing) {
            Response::notFound(ucfirst($module->getModuleName()) . " #{$id}");
        }

        try {
            $module->delete($id);
        } catch (\Throwable $e) {
            $this->logger->error('API delete failed', [
                'module' => $module->getModuleName(),
                'id'     => $id,
                'error'  => $e->getMessage(),
            ]);
            Response::serverError('Failed to delete record');
        }

        Response::ok(null, [], 204);
    }

    // -------------------------------------------------------------------------
    // Non-CRUD action sub-routes (ADR 001 amendment)
    // -------------------------------------------------------------------------

    /**
     * Registry of modules exposing non-CRUD REST actions at
     * /api/v1/{module}/{id}/{action}. Reusable pattern; only mailing_campaigns
     * is registered for now.
     *
     * @var array<string, class-string>
     */
    private const ACTION_CONTROLLERS = [
        'mailing_campaigns' => MailingActionsController::class,
    ];

    /**
     * Delegate a /{module}/{id}/{action} request to the module's registered
     * action controller. The generic CRUD pipeline has already authenticated
     * and scoped the request; the action controller reuses that context (it
     * receives requireScope() as a proxy and re-loads the record via
     * Module::findScoped, applying the same entity scope as show/update).
     *
     * Unregistered modules → 404 (the CRUD dispatcher never reaches here for
     * them because there is no third path segment on CRUD routes).
     */
    private function dispatchAction(Module $module, string $moduleName, ?int $id, string $action): void
    {
        $controllerClass = self::ACTION_CONTROLLERS[$moduleName] ?? null;
        if ($controllerClass === null) {
            Response::notFound("Actions for module '{$moduleName}'");
        }

        $controller = new $controllerClass($this->db, $this->logger);
        $controller->dispatchAction(
            $module,
            $id,
            $action,
            fn (string $scope): bool => $this->requireScope($scope),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Remove keys not in the module's editable fields list
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function filterInputData(Module $module, array $input): array
    {
        $editFields = $module->getEditFields();
        return array_intersect_key($input, array_flip($editFields));
    }

    /**
     * Enforce the token's module whitelist (ADR 001 / Decision 4).
     *
     * A NULL / empty `modules` column means "no restriction". A
     * comma-separated list restricts access to exactly those modules.
     */
    private function enforceModuleWhitelist(string $moduleName): void
    {
        $whitelist = trim((string) ($this->tokenData['modules'] ?? ''));
        if ($whitelist === '') {
            return; // No whitelist → no restriction
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', $whitelist))));
        if (!in_array($moduleName, $allowed, true)) {
            Response::forbidden(
                "Token does not include '{$moduleName}' in its module whitelist"
            );
        }
    }

    /**
     * Build a minimal userAccount array from the token payload.
     *
     * Application::handleApiRequest() passes an empty array for API
     * requests because sessions don't apply. The entity_id lives
     * directly on the token (ADR 001 / Decision 3), and the user
     * identity is created_by for token_type='user'.
     *
     * @param  array<string, mixed> $current  What the caller already had
     * @return array<string, mixed>
     */
    private function buildUserAccountFromToken(array $current): array
    {
        if (!empty($current['id'])) {
            // Caller already supplied richer data — don't clobber
            return $current;
        }

        $userId = (int) ($this->tokenData['created_by'] ?? 0);
        $out    = [];

        if ($userId > 0) {
            $row = $this->db->fetchOne(
                'SELECT id, email, name FROM ib_users WHERE id = :id LIMIT 1',
                ['id' => $userId]
            );
            $out = $row ?: ['id' => $userId];
        }

        // entity_id on the token is authoritative, even for service tokens
        if (array_key_exists('entity_id', $this->tokenData)
            && $this->tokenData['entity_id'] !== null
        ) {
            $out['entity_id'] = (int) $this->tokenData['entity_id'];
        }

        return $out;
    }

    /**
     * Apply the principal user's group permissions and read-only level to the
     * module flags (canAdd/canEdit/canDelete), which create/update/destroy check.
     * Service tokens have no principal: their scopes and whitelist apply alone.
     */
    private function applyPrincipalPermissions(Module $module, string $moduleName): void
    {
        $tokenType = (string) ($this->tokenData['token_type'] ?? 'user');
        $userId    = (int) ($this->tokenData['created_by'] ?? 0);
        if ($tokenType !== 'user' || $userId <= 0) {
            return;
        }
        $groupIds = array_map('intval', array_column(
            $this->db->fetchAll('SELECT group_id FROM ib_user_groups WHERE user_id = :uid', ['uid' => $userId]),
            'group_id'
        ));
        if ($groupIds !== []) {
            \Intrabase\Security\Permission::applyToModule($module, $groupIds, $moduleName, $this->db);
        }
        $app = (new Acl())->ownerApp($moduleName, $this->db);
        $entityId = (int) ($this->tokenData['entity_id'] ?? 0);
        if ($app !== null && $entityId > 0) {
            $level = \Intrabase\Support\UserAppAccess::level($this->db, $userId, $entityId, $app);
            if ($level === 'none') {
                Response::forbidden("Access denied to module '{$moduleName}'");
            }
            if ($level === 'read') {
                $module->canAdd = $module->canEdit = $module->canDelete = false;
            }
        }
    }

    /**
     * Drop credential columns (password hashes, TOTP secrets, SMTP passwords,
     * token hashes...) from a record before it leaves the API.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function redact(Module $module, ?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (array_keys($row) as $field) {
            if ($module->isSensitiveField((string) $field)) {
                unset($row[$field]);
            }
        }
        return $row;
    }

}
