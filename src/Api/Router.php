<?php

declare(strict_types=1);

namespace Intrabase\Api;

use Intrabase\Core\ModuleLoader;
use Intrabase\Database\Connection;
use Intrabase\Support\Logger;

/**
 * API Router
 *
 * Handles routing for /api/v1/* requests.
 *
 * Supported path patterns:
 *   /api/v1/                    -> API root / health
 *   /api/v1/{module}            -> module list/create
 *   /api/v1/{module}/{id}       -> module get/update/delete
 *   /api/v1/auth/token          -> token management (future)
 *
 * All requests are routed to ModuleApiController.
 *
 * @package Intrabase\Api
 */
class Router
{
    private ModuleApiController $moduleController;
    private DocsController       $docsController;
    private Connection $db;
    private Logger $logger;

    /** @var array<string, mixed> */
    private array $clientAccount;

    /** @var array<string, mixed> */
    private array $userAccount;

    /**
     * @param array<string, mixed> $clientAccount
     * @param array<string, mixed> $userAccount
     */
    public function __construct(
        Connection $db,
        Logger $logger,
        ModuleLoader $loader,
        array $clientAccount = [],
        array $userAccount = []
    ) {
        $this->db             = $db;
        $this->logger         = $logger;
        $this->clientAccount  = $clientAccount;
        $this->userAccount    = $userAccount;
        $this->moduleController = new ModuleApiController($db, $logger, $loader);
        $this->docsController   = new DocsController($db, $logger, $loader);
    }

    /**
     * Dispatch the current request
     */
    public function dispatch(): void
    {
        // Parse the request
        $request = new Request();

        // Common headers for all API responses
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex');
        }

        $path = $request->path();

        // Root endpoint -> health check (unauthenticated)
        if (preg_match('#^/api/v1/?$#', $path)) {
            $this->handleRoot();
            return;
        }

        // /api/v1/openapi[.json] — OpenAPI 3.0 spec (unauthenticated read).
        // Served openly so Swagger UI / Redoc can load it without a token.
        // Running actual API calls still requires Bearer auth.
        if (preg_match('#^/api/v1/openapi(\.json)?/?$#i', $path)) {
            $this->docsController->openapi($this->clientAccount, $this->userAccount);
            return;
        }

        // /api/v1/explorer — Swagger UI (unauthenticated HTML page).
        if (preg_match('#^/api/v1/explorer/?$#i', $path)) {
            $this->docsController->explorer($this->clientAccount, $this->userAccount);
            return;
        }

        // /api/v1/docs[/{module}] -- API documentation (authenticated)
        if (preg_match('#^/api/v1/docs(/|$)#i', $path)) {
            $this->docsController->handle($this->clientAccount, $this->userAccount);
            return;
        }

        // All other /api/v1/* -> module controller
        if (preg_match('#^/api/v1/#', $path)) {
            $this->moduleController->handle($this->clientAccount, $this->userAccount);
            return;
        }

        // Unknown version
        Response::notFound('API endpoint');
    }

    /**
     * GET /api/v1/ — Health check / API info
     */
    private function handleRoot(): void
    {
        $request = new Request();

        if (!$request->isGet()) {
            Response::methodNotAllowed($request->method());
        }

        Response::ok([
            'name'    => 'Intrabase API',
            'version' => 'v1',
            'status'  => 'ok',
        ]);
    }
}
