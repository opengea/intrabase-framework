<?php

declare(strict_types=1);

namespace Intrabase\Api;

use Intrabase\Core\ModuleLoader;
use Intrabase\Database\Connection;
use Intrabase\Security\Acl;
use Intrabase\Support\Logger;

/**
 * DocsController
 *
 * Auto-generates API documentation from module metadata.
 *
 * Routes:
 *   GET /api/v1/docs            → list all available modules with basic info
 *   GET /api/v1/docs/{module}   → detailed info for a specific module
 *
 * Requires a valid Bearer token with 'read' scope.
 *
 * @package Intrabase\Api
 */
class DocsController extends ApiController
{
    private ModuleLoader $loader;

    public function __construct(Connection $db, Logger $logger, ModuleLoader $loader)
    {
        parent::__construct($db, $logger);
        $this->loader = $loader;
    }

    /**
     * Dispatch the docs request
     *
     * @param array<string, mixed> $clientAccount
     * @param array<string, mixed> $userAccount
     */
    public function handle(array $clientAccount, array $userAccount): void
    {
        $this->cors();
        $this->authenticate();
        $this->setCorsOrigin();
        $this->requireScope('read');

        if (!$this->request->isGet()) {
            Response::methodNotAllowed($this->request->method());
        }

        // /api/v1/docs/{module} — single module detail
        $moduleName = $this->request->segment(1); // segment(0)=docs, segment(1)=module_name
        if ($moduleName !== null && $moduleName !== '') {
            $this->docsSingle($moduleName, $clientAccount, $userAccount);
            return;
        }

        // /api/v1/docs — full listing
        $this->docsIndex($clientAccount, $userAccount);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/docs — full module listing
    // -------------------------------------------------------------------------

    private function docsIndex(array $clientAccount, array $userAccount): void
    {
        $available = $this->loader->available();
        sort($available);
        $available = array_values(array_filter($available, fn(string $mod): bool => $this->tokenMayDocument($mod)));

        $modules = [];
        foreach ($available as $name) {
            $modules[] = $this->buildModuleSummary($name, $clientAccount, $userAccount);
        }

        Response::ok([
            'api'     => 'Intrabase REST API',
            'version' => 'v1',
            'base_url' => $this->baseUrl() . $this->apiPrefix(),
            'auth'    => [
                'type'   => 'Bearer',
                'header' => 'Authorization: Bearer <token>',
                'scopes' => ['read', 'write', 'delete', 'admin'],
                'token_types' => [
                    'user'    => 'Personal Access Token — acts on behalf of the user who created it. User ACL and token scopes both apply.',
                    'service' => 'Service account — no principal user. Only token scopes and module whitelist apply.',
                ],
                'module_whitelist' => [
                    'description' => 'Optional comma-separated list of module names the token can reach. NULL = no restriction beyond scope + ACL.',
                    'example'     => 'crm_clients,erp_orders',
                ],
                'tenant_scope' => [
                    'description' => 'Each token is bound to an entity (ib_api_tokens.entity_id). Listing endpoints filter by that entity when the module declares $apiScopeByEntity.',
                ],
            ],
            'pagination' => [
                'params'   => ['page', 'per_page'],
                'defaults' => ['page' => 1, 'per_page' => 25],
                'max_per_page' => 200,
            ],
            'search' => ['param' => 'q'],
            'sort'   => ['params' => ['sort', 'dir'], 'dir_values' => ['ASC', 'DESC']],
            'modules_count' => count($modules),
            'modules' => $modules,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/docs/{module} — single module detail
    // -------------------------------------------------------------------------

    /**
     * Whether the current token may see this module in the docs: the token's
     * module whitelist (ADR 001 / Decision 4) and, for 'user' tokens, the
     * user's ACL (Decision 2). Used by the index and by the single-module page.
     */
    private function tokenMayDocument(string $mod): bool
    {
        // Same rule as ModuleApiController: system modules need the 'admin'
        // scope, and API tokens are never handled through a bearer token.
        if (str_starts_with($mod, 'sys_')) {
            $viaSession = ($this->tokenData['auth_method'] ?? '') === 'session';
            if ($mod === 'sys_api_tokens' && !$viaSession) {
                return false;
            }
            $scopes = TokenScopes::parse((string) ($this->tokenData['scopes'] ?? ''));
            if (!in_array('admin', $scopes, true)) {
                return false;
            }
        }
        $whitelist = trim((string) ($this->tokenData['modules'] ?? ''));
        if ($whitelist !== '') {
            $allowed = array_values(array_filter(array_map('trim', explode(',', $whitelist))));
            if (!in_array($mod, $allowed, true)) {
                return false;
            }
        }
        $tokenType   = (string) ($this->tokenData['token_type'] ?? 'user');
        $tokenUserId = (int) ($this->tokenData['created_by'] ?? 0);
        if ($tokenType === 'user' && $tokenUserId > 0) {
            $this->aclCache ??= new Acl();
            $groups = $this->aclCache->getUserGroups($tokenUserId, $this->db);
            return $this->aclCache->canAccess($mod, $groups, $this->db);
        }
        return true;
    }

    private ?Acl $aclCache = null;

    private function docsSingle(string $name, array $clientAccount, array $userAccount): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            Response::notFound("Module '{$name}'");
        }
        // Same gate as the index: a module outside the token's reach is a 404,
        // so its table and fields are not described to the caller.
        if (!$this->tokenMayDocument($name)) {
            Response::notFound("Module '{$name}'");
        }

        try {
            $module = $this->loader->load($name);
        } catch (\Throwable) {
            Response::notFound("Module '{$name}'");
        }

        $module->setClientAccount($clientAccount);
        $module->setUserAccount($userAccount);

        $base = $this->baseUrl() . $this->apiPrefix() . '/' . $name;

        $info = [
            'module'    => $name,
            'table'     => $module->table,
            'title'     => $module->title ?: $name,
            'primary_key' => $module->key,
            'fields'    => $module->fields,
            'permissions' => [
                'browse'    => $module->canBrowse,
                'create'    => $module->canAdd,
                'update'    => $module->canEdit,
                'delete'    => $module->canDelete,
                'export'    => $module->canExport,
            ],
            'endpoints' => $this->buildEndpoints($base, $name, $module),
            'examples'  => $this->buildExamples($base, $name, $module),
        ];

        Response::ok($info);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a lightweight summary for the module index listing.
     *
     * We intentionally don't load every module class (too slow for 200+ modules).
     * Instead we extract basic info: name, group, endpoints from the name alone.
     */
    private function buildModuleSummary(
        string $name,
        array $clientAccount,
        array $userAccount
    ): array {
        $base   = $this->baseUrl() . $this->apiPrefix() . '/' . $name;
        $prefix = strstr($name, '_', true) ?: $name;

        return [
            'name'    => $name,
            'group'   => $prefix,
            'url'     => $base,
            'detail_url' => $this->baseUrl() . $this->apiPrefix() . '/docs/' . $name,
            'endpoints' => [
                'list'   => 'GET    ' . $base,
                'get'    => 'GET    ' . $base . '/{id}',
                'create' => 'POST   ' . $base,
                'update' => 'PUT    ' . $base . '/{id}',
                'patch'  => 'PATCH  ' . $base . '/{id}',
                'delete' => 'DELETE ' . $base . '/{id}',
            ],
        ];
    }

    /**
     * Build the endpoints array for a specific loaded module.
     *
     * @return array<string, mixed>
     */
    private function buildEndpoints(string $base, string $name, \Intrabase\Core\Module $module): array
    {
        $endpoints = [];

        $endpoints[] = [
            'method'      => 'GET',
            'path'        => '/api/v1/' . $name,
            'description' => 'List records (paginated)',
            'query_params' => [
                'page'     => 'Page number (default: 1)',
                'per_page' => 'Records per page (default: 25, max: 200)',
                'q'        => 'Full-text search',
                'sort'     => 'Sort field (' . implode('|', array_slice($module->fields, 0, 5)) . '...)',
                'dir'      => 'Sort direction (ASC|DESC)',
            ],
            'requires_scope' => 'read',
        ];

        $endpoints[] = [
            'method'      => 'GET',
            'path'        => '/api/v1/' . $name . '/{id}',
            'description' => 'Get single record by primary key',
            'requires_scope' => 'read',
        ];

        if ($module->canAdd) {
            $endpoints[] = [
                'method'      => 'POST',
                'path'        => '/api/v1/' . $name,
                'description' => 'Create a new record',
                'body'        => 'JSON object with field values',
                'requires_scope' => 'write',
            ];
        }

        if ($module->canEdit) {
            $endpoints[] = [
                'method'      => 'PUT',
                'path'        => '/api/v1/' . $name . '/{id}',
                'description' => 'Full update of a record',
                'body'        => 'JSON object with all field values',
                'requires_scope' => 'write',
            ];
            $endpoints[] = [
                'method'      => 'PATCH',
                'path'        => '/api/v1/' . $name . '/{id}',
                'description' => 'Partial update of a record',
                'body'        => 'JSON object with fields to update',
                'requires_scope' => 'write',
            ];
        }

        if ($module->canDelete) {
            $endpoints[] = [
                'method'      => 'DELETE',
                'path'        => '/api/v1/' . $name . '/{id}',
                'description' => 'Delete a record',
                'requires_scope' => 'delete',
            ];
        }

        return $endpoints;
    }

    /**
     * Build request/response examples for the module.
     *
     * @return array<string, mixed>
     */
    private function buildExamples(string $base, string $name, \Intrabase\Core\Module $module): array
    {
        return [
            'list_request' => [
                'curl' => 'curl -H "Authorization: Bearer <token>" "'
                    . $base . '?page=1&per_page=10"',
            ],
            'list_response' => [
                'data'  => [['id' => 1, '...' => '...']],
                'meta'  => [
                    'total'       => 100,
                    'page'        => 1,
                    'per_page'    => 10,
                    'total_pages' => 10,
                ],
            ],
            'create_request' => $module->canAdd ? [
                'curl' => 'curl -X POST -H "Authorization: Bearer <token>" '
                    . '-H "Content-Type: application/json" '
                    . '-d \'{"field": "value"}\' "'
                    . $base . '"',
            ] : null,
        ];
    }

    /**
     * GET /api/v1/openapi.json — OpenAPI 3.0 spec (no auth required).
     *
     * Generated from the same module metadata as /docs. Exposed
     * unauthenticated so Swagger UI / Redoc can render the spec; the
     * endpoints described still require Bearer auth to be invoked.
     *
     * @param array<string, mixed> $clientAccount
     * @param array<string, mixed> $userAccount
     */
    public function openapi(array $clientAccount, array $userAccount): void
    {
        $this->cors();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Access-Control-Allow-Origin: *');
        }

        $modules = $this->loader->available();
        sort($modules);

        // Paths: one pair per module — /{module} (list/create) and
        // /{module}/{id} (get/update/delete). Parameters are shared.
        $paths = [];
        foreach ($modules as $name) {
            $paths["/$name"]        = $this->openApiCollectionPath($name);
            $paths["/$name/{id}"]   = $this->openApiItemPath($name);
        }

        // Mailing app: richer CRUD descriptions + non-CRUD action sub-routes
        // (ADR 001 amendment). Only applied when the modules are present.
        $this->enrichMailingDocs($paths);

        $spec = [
            'openapi' => '3.0.3',
            'info'    => [
                'title'       => 'Intrabase REST API',
                'description' => 'Multi-tenant REST API for Intrabase modules. '
                               . 'Authenticated via Bearer tokens scoped to a tenant entity.',
                'version'     => 'v1',
                'contact'     => ['url' => 'https://github.com/opengea/intrabase-framework'],
                'license'     => ['name' => 'AGPL-3.0-or-later'],
            ],
            'servers' => [[
                'url'         => $this->baseUrl() . $this->apiPrefix(),
                'description' => 'Current host',
            ]],
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type'         => 'http',
                        'scheme'       => 'bearer',
                        'description'  => 'Bearer token issued via the Intrabase admin UI. '
                                        . 'Tokens are scoped to a tenant entity and may be '
                                        . 'restricted to a module whitelist.',
                    ],
                ],
                'parameters' => [
                    'Page'     => ['name' => 'page',     'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    'PerPage'  => ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 25]],
                    'Search'   => ['name' => 'q',        'in' => 'query', 'schema' => ['type' => 'string'],   'description' => 'Free-text search across searchable fields.'],
                    'Sort'     => ['name' => 'sort',     'in' => 'query', 'schema' => ['type' => 'string']],
                    'Dir'      => ['name' => 'dir',      'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['ASC', 'DESC']]],
                ],
                'responses' => [
                    'Unauthorized' => [
                        'description' => 'Missing or invalid Bearer token',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiError']]],
                    ],
                    'Forbidden'    => [
                        'description' => 'Token lacks required scope or module is outside its whitelist',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiError']]],
                    ],
                    'NotFound'     => [
                        'description' => 'Module or record does not exist',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiError']]],
                    ],
                    'ValidationError' => [
                        'description' => 'The request payload failed validation (e.g. malformed scheduled_at, missing From address, no recipients)',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiError']]],
                    ],
                    'Conflict' => [
                        'description' => 'The campaign is not in a state that allows the action (e.g. resuming a campaign that is not paused)',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiError']]],
                    ],
                    'QuotaExceeded' => [
                        'description' => 'The send would exceed the entity\'s monthly plan quota. The error details carry {remaining, limit, requested, plan}. No message is sent — quota is a soft, up-front block (ADR 001 D3).',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/QuotaError']]],
                    ],
                ],
                'schemas' => [
                    'ApiError' => [
                        'type' => 'object',
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => false],
                            'error'   => [
                                'type' => 'object',
                                'properties' => [
                                    'code'    => ['type' => 'string', 'example' => 'FORBIDDEN'],
                                    'message' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                    'Paginated' => [
                        'type' => 'object',
                        'properties' => [
                            'success'    => ['type' => 'boolean', 'example' => true],
                            'data'       => ['type' => 'array', 'items' => ['type' => 'object']],
                            'pagination' => [
                                'type' => 'object',
                                'properties' => [
                                    'total'    => ['type' => 'integer'],
                                    'page'     => ['type' => 'integer'],
                                    'per_page' => ['type' => 'integer'],
                                    'pages'    => ['type' => 'integer'],
                                ],
                            ],
                        ],
                    ],
                    'Record' => [
                        'type' => 'object',
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => true],
                            'data'    => ['type' => 'object'],
                        ],
                    ],
                    'ActionResult' => [
                        'type' => 'object',
                        'description' => 'Envelope returned by a campaign action. `data` carries the affected id and the resulting status.',
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => true],
                            'data'    => [
                                'type' => 'object',
                                'properties' => [
                                    'id'           => ['type' => 'integer', 'example' => 42],
                                    'status'       => ['type' => 'string', 'example' => 'sending'],
                                    'total'        => ['type' => 'integer', 'description' => 'Recipients expanded (send only)', 'example' => 350],
                                    'scheduled_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'example' => '2026-08-01 09:00:00'],
                                ],
                            ],
                        ],
                    ],
                    'QuotaError' => [
                        'type' => 'object',
                        'description' => 'ApiError whose details describe the exceeded monthly quota.',
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => false],
                            'error'   => [
                                'type' => 'object',
                                'properties' => [
                                    'code'    => ['type' => 'string', 'example' => 'QUOTA_EXCEEDED'],
                                    'message' => ['type' => 'string', 'example' => 'Monthly send quota exceeded for the current plan.'],
                                    'details' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'remaining' => ['type' => 'integer', 'example' => 120],
                                            'limit'     => ['type' => 'integer', 'example' => 500],
                                            'requested' => ['type' => 'integer', 'example' => 350],
                                            'plan'      => ['type' => 'string', 'example' => 'free'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'paths' => $paths,
        ];

        echo json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    private function openApiCollectionPath(string $module): array
    {
        $tag = $module;
        return [
            'get' => [
                'summary'     => "List {$module}",
                'operationId' => "list_{$module}",
                'tags'        => [$tag],
                'parameters'  => [
                    ['$ref' => '#/components/parameters/Page'],
                    ['$ref' => '#/components/parameters/PerPage'],
                    ['$ref' => '#/components/parameters/Search'],
                    ['$ref' => '#/components/parameters/Sort'],
                    ['$ref' => '#/components/parameters/Dir'],
                ],
                'responses' => [
                    '200' => ['description' => 'OK',           'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Paginated']]]],
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                ],
            ],
            'post' => [
                'summary'     => "Create {$module}",
                'operationId' => "create_{$module}",
                'tags'        => [$tag],
                'requestBody' => [
                    'required' => true,
                    'content'  => ['application/json' => ['schema' => ['type' => 'object']]],
                ],
                'responses' => [
                    '201' => ['description' => 'Created', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Record']]]],
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '422' => ['description' => 'Validation error'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function openApiItemPath(string $module): array
    {
        $idParam = [
            'name'     => 'id',
            'in'       => 'path',
            'required' => true,
            'schema'   => ['type' => 'integer'],
        ];
        $tag = $module;
        $bodyOptional = [
            'required' => true,
            'content'  => ['application/json' => ['schema' => ['type' => 'object']]],
        ];
        return [
            'parameters' => [$idParam],
            'get'    => [
                'summary' => "Get {$module}",      'operationId' => "get_{$module}", 'tags' => [$tag],
                'responses' => [
                    '200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Record']]]],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                ],
            ],
            'put'    => [
                'summary' => "Replace {$module}",  'operationId' => "replace_{$module}", 'tags' => [$tag],
                'requestBody' => $bodyOptional,
                'responses' => [
                    '200' => ['description' => 'Updated', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Record']]]],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                ],
            ],
            'patch'  => [
                'summary' => "Update {$module}",   'operationId' => "patch_{$module}", 'tags' => [$tag],
                'requestBody' => $bodyOptional,
                'responses' => [
                    '200' => ['description' => 'Updated', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Record']]]],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                ],
            ],
            'delete' => [
                'summary' => "Delete {$module}",   'operationId' => "delete_{$module}", 'tags' => [$tag],
                'responses' => [
                    '204' => ['description' => 'Deleted'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Mailing app — rich descriptions + non-CRUD action sub-routes
    // (ADR 001 amendment)
    // -------------------------------------------------------------------------

    /**
     * Enrich the OpenAPI paths for the Mailing app: richer descriptions on the
     * two data modules and the non-CRUD campaign action sub-routes. No-op when
     * those modules are not exposed to this host.
     *
     * @param array<string, mixed> $paths  Mutated in place.
     */
    private function enrichMailingDocs(array &$paths): void
    {
        $descriptions = [
            'mailing_campaigns' =>
                'A mass-mailing campaign. Key fields: `name`, `subject`, `from_name`, '
                . '`from_email`, `reply_to`, `preview_text`, `body_html` (sanitised rich '
                . 'HTML), `template_id` (optional wrapper template whose `{{body}}` is '
                . 'replaced by the campaign body), `recipient_filter` (JSON, see below). '
                . 'Lifecycle: `draft → scheduled → sending → sent`; plus `paused`, '
                . '`aborted`, `failed`. State is driven by the action sub-routes '
                . '(`/{id}/send`, `/{id}/schedule`, …), NOT by editing `status` via PUT. '
                . 'recipient_filter JSON shape: '
                . '`{"all":bool, "labels":int[], "favorites":bool, "search":string, "emails":string[], "languages":string[]}` '
                . '— a contact is included if it matches ANY populated criterion; '
                . '`{"all":true}` targets every contact of the entity. '
                . '`languages` (optional) RESTRICTS that set (AND) to contacts whose `language` is one of the '
                . 'given ISO 639-1 codes (`[a-z]{2}`), `"_none"` meaning contacts without a language; '
                . 'absent/empty = no restriction; direct `emails` are never filtered. Any other value → '
                . '422 `mailing_err_languages_invalid`. The contact language is available as `{{contact.language}}`.',
            'mailing_templates' =>
                'A reusable HTML template for campaigns. Key fields: `name`, `html` '
                . '(sanitised), `source` (`own` | `imported` | `system`). The `html` may '
                . 'contain a `{{body}}` placeholder; when a campaign references the '
                . 'template, the campaign body is substituted there (otherwise appended). '
                . '`source=system` templates are read-only and shared across entities.',
        ];

        foreach ($descriptions as $mod => $desc) {
            // The mailing modules are real PHP modules (modules/mailing/*.php) but
            // ModuleLoader::available() derives their name from the PascalCase file
            // and does not surface the canonical snake_case name, so the generic
            // loop above never builds their paths. Inject the CRUD paths here (the
            // builders only need the module name) so the spec documents them, then
            // decorate with the rich description. Guarded by has() so hosts without
            // the module get nothing.
            if (!$this->loader->has($mod)) {
                continue;
            }
            if (!isset($paths["/$mod"])) {
                $paths["/$mod"] = $this->openApiCollectionPath($mod);
            }
            if (!isset($paths["/$mod/{id}"])) {
                $paths["/$mod/{id}"] = $this->openApiItemPath($mod);
            }

            $paths["/$mod"]['description'] = $desc;
            if (isset($paths["/$mod"]['get'])) {
                $paths["/$mod"]['get']['description'] = $desc;
            }
            if (isset($paths["/$mod/{id}"]['get'])) {
                $paths["/$mod/{id}"]['get']['description'] = $desc;
            }
        }

        // Action sub-routes only make sense when the campaigns module is present.
        if (!$this->loader->has('mailing_campaigns')) {
            return;
        }
        foreach ($this->mailingActionPaths() as $p => $item) {
            $paths[$p] = $item;
        }
    }

    /**
     * OpenAPI path items for /mailing_campaigns/{id}/{action}.
     *
     * @return array<string, mixed>
     */
    private function mailingActionPaths(): array
    {
        $base    = $this->baseUrl() . $this->apiPrefix();
        $idParam = [
            'name' => 'id', 'in' => 'path', 'required' => true,
            'schema' => ['type' => 'integer'], 'description' => 'Campaign id',
        ];

        // Common error responses.
        $unauthorized = ['$ref' => '#/components/responses/Unauthorized'];
        $forbidden    = ['$ref' => '#/components/responses/Forbidden'];
        $notFound     = ['$ref' => '#/components/responses/NotFound'];
        $validation   = ['$ref' => '#/components/responses/ValidationError'];
        $conflict     = ['$ref' => '#/components/responses/Conflict'];

        // send/schedule share a 409 that can be a state conflict OR a quota block.
        $conflictOrQuota = [
            'description' => 'Either the campaign is not in a sendable/schedulable state '
                . '(`code: CONFLICT`) or the send would exceed the monthly plan quota '
                . '(`code: QUOTA_EXCEEDED`, with `details` carrying remaining/limit/requested/plan).',
            'content' => ['application/json' => ['schema' => ['oneOf' => [
                ['$ref' => '#/components/schemas/QuotaError'],
                ['$ref' => '#/components/schemas/ApiError'],
            ]]]],
        ];

        $okAction = static function (array $dataExample): array {
            return [
                'description' => 'OK',
                'content' => ['application/json' => [
                    'schema'  => ['$ref' => '#/components/schemas/ActionResult'],
                    'example' => ['success' => true, 'data' => $dataExample],
                ]],
            ];
        };

        return [
            "/mailing_campaigns/{id}/send" => [
                'parameters' => [$idParam],
                'post' => [
                    'summary'     => 'Send a campaign now',
                    'operationId' => 'mailing_campaigns_send',
                    'tags'        => ['mailing_campaigns'],
                    'description' =>
                        "Expands the campaign audience from its `recipient_filter` and starts "
                        . "sending immediately (`status → sending`). The monthly plan quota is "
                        . "checked up-front (ADR 001 D3): if the recipient count would exceed the "
                        . "remaining quota the send is refused with **409 QUOTA_EXCEEDED** and "
                        . "nothing is sent. Requires a valid `from_email` and ≥1 recipient.\n\n"
                        . "```sh\ncurl -X POST \\\n  -H \"Authorization: Bearer \$TOKEN\" \\\n  "
                        . "$base/mailing_campaigns/42/send\n```",
                    'responses' => [
                        '200' => $okAction(['id' => 42, 'status' => 'sending', 'total' => 350]),
                        '401' => $unauthorized, '403' => $forbidden, '404' => $notFound,
                        '409' => $conflictOrQuota, '422' => $validation,
                    ],
                ],
            ],
            "/mailing_campaigns/{id}/status" => [
                'parameters' => [$idParam],
                'get' => [
                    'summary'     => 'Get campaign status + recipient counters',
                    'operationId' => 'mailing_campaigns_status',
                    'tags'        => ['mailing_campaigns'],
                    'description' =>
                        "Returns the campaign snapshot (status, timestamps, totals) plus live "
                        . "recipient counters grouped by delivery status — the shape the "
                        . "dashboard polls while a campaign is sending.\n\n"
                        . "```sh\ncurl -H \"Authorization: Bearer \$TOKEN\" \\\n  "
                        . "$base/mailing_campaigns/42/status\n```",
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => ['application/json' => [
                                'schema'  => ['$ref' => '#/components/schemas/Record'],
                                'example' => ['success' => true, 'data' => [
                                    'campaign' => [
                                        'id' => 42, 'name' => 'Spring newsletter', 'status' => 'sending',
                                        'scheduled_at' => null, 'started_at' => '2026-07-22 10:00:00',
                                        'finished_at' => null, 'total_recipients' => 350,
                                        'total_sent' => 120, 'total_unsubscribed' => 1,
                                    ],
                                    'recipients' => [
                                        'pending' => 230, 'sending' => 0, 'sent' => 120,
                                        'failed' => 0, 'unsubscribed' => 1,
                                    ],
                                ]],
                            ]],
                        ],
                        '401' => $unauthorized, '403' => $forbidden, '404' => $notFound,
                    ],
                ],
            ],
            "/mailing_campaigns/{id}/pause" => [
                'parameters' => [$idParam],
                'post' => [
                    'summary'     => 'Pause a sending campaign',
                    'operationId' => 'mailing_campaigns_pause',
                    'tags'        => ['mailing_campaigns'],
                    'description' =>
                        "Transitions `sending → paused`. The queue worker stops claiming batches "
                        . "for this campaign until it is resumed. 409 CONFLICT if it is not "
                        . "currently sending.\n\n"
                        . "```sh\ncurl -X POST -H \"Authorization: Bearer \$TOKEN\" \\\n  "
                        . "$base/mailing_campaigns/42/pause\n```",
                    'responses' => [
                        '200' => $okAction(['id' => 42, 'status' => 'paused']),
                        '401' => $unauthorized, '403' => $forbidden, '404' => $notFound, '409' => $conflict,
                    ],
                ],
            ],
            "/mailing_campaigns/{id}/resume" => [
                'parameters' => [$idParam],
                'post' => [
                    'summary'     => 'Resume a paused campaign',
                    'operationId' => 'mailing_campaigns_resume',
                    'tags'        => ['mailing_campaigns'],
                    'description' =>
                        "Transitions `paused → sending`. 409 CONFLICT if it is not paused.\n\n"
                        . "```sh\ncurl -X POST -H \"Authorization: Bearer \$TOKEN\" \\\n  "
                        . "$base/mailing_campaigns/42/resume\n```",
                    'responses' => [
                        '200' => $okAction(['id' => 42, 'status' => 'sending']),
                        '401' => $unauthorized, '403' => $forbidden, '404' => $notFound, '409' => $conflict,
                    ],
                ],
            ],
            "/mailing_campaigns/{id}/abort" => [
                'parameters' => [$idParam],
                'post' => [
                    'summary'     => 'Abort a campaign (final)',
                    'operationId' => 'mailing_campaigns_abort',
                    'tags'        => ['mailing_campaigns'],
                    'description' =>
                        "Transitions `sending|paused → aborted` and stamps `finished_at`. Aborted "
                        . "is terminal — already-sent messages are not recalled. 409 CONFLICT if "
                        . "the campaign is neither sending nor paused.\n\n"
                        . "```sh\ncurl -X POST -H \"Authorization: Bearer \$TOKEN\" \\\n  "
                        . "$base/mailing_campaigns/42/abort\n```",
                    'responses' => [
                        '200' => $okAction(['id' => 42, 'status' => 'aborted']),
                        '401' => $unauthorized, '403' => $forbidden, '404' => $notFound, '409' => $conflict,
                    ],
                ],
            ],
            "/mailing_campaigns/{id}/schedule" => [
                'parameters' => [$idParam],
                'post' => [
                    'summary'     => 'Schedule a future send',
                    'operationId' => 'mailing_campaigns_schedule',
                    'tags'        => ['mailing_campaigns'],
                    'description' =>
                        "Arms a future send (`draft|scheduled → scheduled`). `scheduled_at` is "
                        . "`YYYY-MM-DD HH:MM[:SS]` in the server clock (Europe/Madrid) and must be "
                        . "in the future. Requires a subject, a valid `from_email` and ≥1 recipient; "
                        . "the monthly quota is checked the same way as `send`. The queue worker "
                        . "promotes the campaign when due.\n\n"
                        . "```sh\ncurl -X POST \\\n  -H \"Authorization: Bearer \$TOKEN\" \\\n  "
                        . "-H \"Content-Type: application/json\" \\\n  "
                        . "-d '{\"scheduled_at\":\"2026-08-01 09:00\"}' \\\n  "
                        . "$base/mailing_campaigns/42/schedule\n```",
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['scheduled_at'],
                                'properties' => [
                                    'scheduled_at' => [
                                        'type' => 'string',
                                        'description' => 'Future datetime, YYYY-MM-DD HH:MM[:SS], server clock.',
                                        'example' => '2026-08-01 09:00',
                                    ],
                                ],
                            ],
                            'example' => ['scheduled_at' => '2026-08-01 09:00'],
                        ]],
                    ],
                    'responses' => [
                        '200' => $okAction(['id' => 42, 'status' => 'scheduled', 'scheduled_at' => '2026-08-01 09:00:00']),
                        '401' => $unauthorized, '403' => $forbidden, '404' => $notFound,
                        '409' => $conflictOrQuota, '422' => $validation,
                    ],
                ],
            ],
            "/mailing_campaigns/{id}/cancel-schedule" => [
                'parameters' => [$idParam],
                'post' => [
                    'summary'     => 'Cancel a scheduled send',
                    'operationId' => 'mailing_campaigns_cancel_schedule',
                    'tags'        => ['mailing_campaigns'],
                    'description' =>
                        "Transitions `scheduled → draft` and clears `scheduled_at`. 409 CONFLICT "
                        . "if the campaign is not scheduled.\n\n"
                        . "```sh\ncurl -X POST -H \"Authorization: Bearer \$TOKEN\" \\\n  "
                        . "$base/mailing_campaigns/42/cancel-schedule\n```",
                    'responses' => [
                        '200' => $okAction(['id' => 42, 'status' => 'draft']),
                        '401' => $unauthorized, '403' => $forbidden, '404' => $notFound, '409' => $conflict,
                    ],
                ],
            ],
        ];
    }

    /**
     * GET /api/v1/explorer — Swagger UI bundled from a CDN.
     *
     * Unauthenticated HTML page. Embeds Swagger UI which fetches
     * /api/v1/openapi.json at load. Tokens are still required to
     * execute requests from the explorer ("Authorize" button).
     */
    public function explorer(array $clientAccount, array $userAccount): void
    {
        $this->cors();
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
        }

        $specUrl = $this->apiPrefix() . '/openapi.json';

        // On tenant hosts, offer a shortcut to the token admin UI. Not on the
        // dedicated API host (config 'api_host'), which serves no dashboard.
        $manageBtn = '';
        if (!$this->onApiHost()) {
            $manageBtn = <<<HTML
<a href="/system/sys_api_tokens" class="ib-manage-tokens-btn">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;">
        <circle cx="8" cy="15" r="4"/>
        <path d="M10.85 12.15 19 4"/>
        <path d="m18 5 2 2"/>
        <path d="m15 8 2 2"/>
    </svg>
    Manage tokens
</a>
HTML;
        }

        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Intrabase REST API — Explorer</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
  <style>
    body { margin: 0; background: #fafafa; }
    .topbar { display: none; }
    .ib-manage-tokens-btn {
        position: fixed;
        top: 12px;
        right: 16px;
        z-index: 9999;
        display: inline-flex;
        align-items: center;
        padding: 8px 14px;
        background: #1b1b1b;
        color: #fff;
        font: 600 13px/1 -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        border: 1px solid #2b2b2b;
        border-radius: 6px;
        text-decoration: none;
        box-shadow: 0 2px 8px rgba(0,0,0,.15);
        transition: background .15s ease;
    }
    .ib-manage-tokens-btn:hover { background: #000; }
  </style>
</head>
<body>
  {$manageBtn}
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js" crossorigin></script>
  <script>
    window.addEventListener('load', function () {
      window.ui = SwaggerUIBundle({
        url: '{$specUrl}',
        dom_id: '#swagger-ui',
        deepLinking: true,
        persistAuthorization: true,
        tryItOutEnabled: true,
        displayRequestDuration: true,
      });
    });
  </script>
</body>
</html>
HTML;
        exit;
    }

    /** Canonical base URL, never the Host header (see Application::publicHost()). */
    private function baseUrl(): string
    {
        return \Intrabase\Core\Application::getInstance()->publicBaseUrl();
    }

    /**
     * Canonical API prefix for the current Host.
     *
     * On the dedicated API host (config 'api_host', e.g. api.example.org)
     * the external path is /v1/*; Application maps it to /api/v1/*.
     * On tenant hosts the external path is /api/v1/*.
     */
    private function apiPrefix(): string
    {
        return $this->onApiHost() ? '/v1' : '/api/v1';
    }

    private function onApiHost(): bool
    {
        $apiHost = strtolower((string) (\Intrabase\Core\Application::getInstance()->getConfig()['api_host'] ?? ''));
        return $apiHost !== '' && strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) === $apiHost;
    }
}
