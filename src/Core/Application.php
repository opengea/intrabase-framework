<?php

declare(strict_types=1);

namespace Intrabase\Core;

use Intrabase\Api\Router as ApiRouter;
use Intrabase\Core\Router;
use Intrabase\Database\Connection;
use Intrabase\Database\DB;
use Intrabase\Domain\Contracts\EventBusInterface;
use Intrabase\Infrastructure\Events\InMemoryEventBus;
use Intrabase\Security\Acl;
use Intrabase\Security\Auditor;
use Intrabase\Security\Session;
use Intrabase\Security\CSRF;
use Intrabase\Support\ConfigManager;
use Intrabase\Lang\Lang;
use Intrabase\Support\Logger;
use Intrabase\Support\LoginLogger;

/**
 * Intrabase Application Kernel
 *
 * Central bootstrap class responsible for:
 * - Configuration loading
 * - Multi-tenant domain resolution
 * - Session initialization with domain isolation
 * - Dependency injection container setup
 * - Request routing to modules
 *
 * @package Intrabase\Core
 */
class Application
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $config = [];

    /** @var array<string, mixed> */
    private array $tenantConfig = [];

    private ?Connection $db = null;
    private Container $container;
    private Session $session;
    private Logger $logger;

    private string $domain = '';
    private string $subdomain = '';
    private string $execMode = 'intranet';

    /** @var array<string, mixed> */
    private array $clientAccount = [];

    /** @var array<string, mixed> */
    private array $userAccount = [];

    private function __construct()
    {
        $this->container = new Container();
        $this->logger = new Logger();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Bootstrap the application
     *
     * @param string $configPath Path to configuration file
     * @throws \RuntimeException If configuration is invalid
     */
    public function bootstrap(string $configPath): void
    {
        $this->loadConfig($configPath);

        // Register global exception handler as early as possible (after config load)
        $debug = (bool) ($this->config['debug'] ?? false);
        set_exception_handler(function (\Throwable $e) use ($debug): void {
            (new ErrorHandler($debug))->render($e);
        });

        $tz = $this->config['timezone'] ?? 'UTC';
        date_default_timezone_set($tz);

        $this->resolveTenant();
        $this->initSession();
        $this->initDatabase();
        $this->bindSessionToTenant();
        $this->registerCoreServices();
        $this->registerDomainListeners();
        // Plugins register now, not on first routing: their event listeners
        // must also exist for kernel routes such as /login and the API.
        $this->routes();
    }

    /**
     * Like bootstrap(), without a PHP session: for front controllers that
     * authenticate every request themselves (public/dav.php). No session
     * cookie is read or sent, and nothing here takes the session lock.
     *
     * registerCoreServices()/registerDomainListeners() are intentionally
     * skipped: they bind Session/CSRF into the container (never resolved
     * here) and initialize ConfigManager/Auditor, none of which the DAV
     * front controller needs — it talks to getDb()/getTenantConfig()/
     * getConfig() directly.
     *
     * @param string $configPath Path to configuration file
     * @throws \RuntimeException If configuration is invalid
     */
    public function bootstrapStateless(string $configPath): void
    {
        $this->loadConfig($configPath);

        // Register global exception handler as early as possible (after config load)
        $debug = (bool) ($this->config['debug'] ?? false);
        set_exception_handler(function (\Throwable $e) use ($debug): void {
            (new ErrorHandler($debug))->render($e);
        });

        $tz = $this->config['timezone'] ?? 'UTC';
        date_default_timezone_set($tz);

        $this->resolveTenant();
        $this->initDatabase();
    }

    /**
     * Like bootstrap(), for the Claude connector (public/mcp.php, ADR 003):
     * no PHP session is started (no cookie read or sent), but a Session object
     * is bound in the container so the code that expects one (AppAccess,
     * modules, services) works. Mcp\McpContext fills $_SESSION in memory from
     * the Bearer token, with the same shape LoginController leaves; nothing of
     * it is ever persisted.
     *
     * @param string $configPath Path to configuration file
     * @throws \RuntimeException If configuration is invalid
     */
    public function bootstrapConnector(string $configPath): void
    {
        $this->loadConfig($configPath);

        $debug = (bool) ($this->config['debug'] ?? false);
        set_exception_handler(function (\Throwable $e) use ($debug): void {
            (new ErrorHandler($debug))->render($e);
        });

        $tz = $this->config['timezone'] ?? 'UTC';
        date_default_timezone_set($tz);

        $this->resolveTenant();
        $this->session = new Session();   // never start()ed
        $_SESSION = [];
        $this->initDatabase();
        $this->registerCoreServices();
        $this->registerDomainListeners();
    }

    /**
     * Load application configuration
     *
     * @param string $configPath Absolute path to config file
     * @throws \RuntimeException If config file is missing or invalid
     */
    private function loadConfig(string $configPath): void
    {
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Configuration file not found: {$configPath}");
        }

        $config = require $configPath;

        if (!is_array($config)) {
            throw new \RuntimeException("Configuration file must return an array");
        }

        $this->config = $config;
        $this->validateConfig();
    }

    /**
     * Validate required configuration keys
     */
    private function validateConfig(): void
    {
        $required = ['db_host', 'db_name', 'db_user', 'db_pass'];
        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new \RuntimeException("Missing required config key: {$key}");
            }
        }
    }

    /**
     * Resolve current tenant from HTTP_HOST
     *
     * Strategy (in order of specificity):
     * 1. Full hostname: intranet.example.com
     * 2. Base domain + subdomain: example.com + intranet
     * 3. Base domain only: example.com
     */
    private function resolveTenant(): void
    {
        $host = $this->sanitizeHost($_SERVER['HTTP_HOST'] ?? 'localhost');
        $parts = explode('.', $host);

        // Extract domain/subdomain
        if (count($parts) >= 3) {
            $this->subdomain = $parts[0];
            $this->domain = implode('.', array_slice($parts, 1));
        } else {
            $this->domain = $host;
            $this->subdomain = '';
        }

        $this->tenantConfig = $this->lookupTenant($host, $this->domain, $this->subdomain);
    }

    /**
     * Sanitize and validate hostname
     */
    private function sanitizeHost(string $host): string
    {
        // Remove port if present
        $host = preg_replace('/:\d+$/', '', $host);
        // Allow only valid hostname characters
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $host);
        return strtolower($host);
    }

    /**
     * Lookup tenant configuration from database using prepared statements
     *
     * @return array<string, mixed>
     */
    private function lookupTenant(string $fullHost, string $domain, string $subdomain): array
    {
        try {
            $pdo = $this->getMasterConnection();

            // Strategy 1: Full hostname match
            $stmt = $pdo->prepare(
                "SELECT * FROM ib_tenants WHERE domain = ? AND status = 'active' LIMIT 1"
            );
            $stmt->execute([$fullHost]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }

            // Strategy 2: Domain + subdomain match
            if ($subdomain) {
                $stmt = $pdo->prepare(
                    "SELECT * FROM ib_tenants WHERE domain = ? AND subdomain = ? AND status = 'active' LIMIT 1"
                );
                $stmt->execute([$domain, $subdomain]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            }

            // Strategy 3: Base domain match
            $stmt = $pdo->prepare(
                "SELECT * FROM ib_tenants WHERE domain = ? AND (subdomain IS NULL OR subdomain = '') AND status = 'active' LIMIT 1"
            );
            $stmt->execute([$domain]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }

        } catch (\PDOException $e) {
            $this->logger->error('Tenant lookup failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Initialize session with tenant-specific configuration
     */
    private function initSession(): void
    {
        // Fitxers públics de la Galeria del Mailing: sense sessió (ni galeta, ni capçaleres no-cache,
        // ni bloqueig de sessió). Mateix patró que bootstrapStateless().
        if (str_starts_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/mailing/a/')) {
            $this->session = new Session();   // never start()ed
            $_SESSION = [];
            return;
        }

        $sessionName = $this->tenantConfig['session_name'] ?? ($this->config['session_name'] ?? 'IBSESSID');
        $cookieDomain = $this->tenantConfig['cookie_domain'] ?? '';
        if ($cookieDomain === '' && $this->subdomain !== '' && $this->domain !== '') {
            $cookieDomain = $this->subdomain . '.' . $this->domain;
        } elseif ($cookieDomain === '') {
            $cookieDomain = $this->domain;
        }

        // config('session_timeout'): idle seconds before the session expires
        // (default 7 days); the cookie lives at least as long.
        $idle = max(300, (int) ($this->config['session_timeout'] ?? 604800));
        $this->session = new Session($sessionName, $cookieDomain, [
            'lifetime' => max($idle, 2592000),
            'secure' => $this->isHttps() || !empty($this->config['force_https']),
            'httponly' => true,
            'samesite' => 'Lax',
        ], $idle);

        $this->session->start();
    }

    /**
     * Tie the PHP session to this tenant (Security\Session::bindScope()): all
     * tenants of a host share the session storage, and a session of another
     * tenant must never be accepted here by renaming its cookie.
     */
    private function bindSessionToTenant(): void
    {
        $scope = (string) ($this->tenantConfig['db_name'] ?? $this->config['db_name'] ?? '');
        if ($scope === '') {
            return;
        }
        $this->session->bindScope($scope, function (array $user): bool {
            $id    = (int) ($user['id'] ?? 0);
            $email = strtolower(trim((string) ($user['email'] ?? '')));
            if ($id <= 0 || $email === '') {
                return false;
            }
            $row = $this->db->fetchOne('SELECT email FROM ib_users WHERE id = :id', ['id' => $id]);
            return $row !== null && strtolower(trim((string) $row['email'])) === $email;
        });
    }

    /**
     * Initialize tenant database connection
     */
    private function initDatabase(): void
    {
        $dbName = $this->tenantConfig['db_name'] ?? $this->config['db_name'];

        $this->db = new Connection(
            host: $this->config['db_host'],
            name: $dbName,
            user: $this->config['db_user'],
            pass: $this->config['db_pass'],
            charset: 'utf8mb4',
            port: (int) ($this->config['db_port'] ?? 3306)
        );
    }

    /**
     * Register core services in the container
     */
    private function registerCoreServices(): void
    {
        $this->container->bind(Connection::class, fn() => $this->db);
        $this->container->bind(DB::class, fn() => $this->db);
        // Àlies de compatibilitat: mòduls generats resolen Core\Database
        $this->container->bind(\Intrabase\Core\Database::class, fn() => $this->db);
        $this->container->bind(Session::class, fn() => $this->session);
        $this->container->bind(CSRF::class, fn() => new CSRF($this->session));
        $this->container->bind(Logger::class, fn() => $this->logger);
        $this->container->bind(\Intrabase\Security\Crypto::class, fn() => \Intrabase\Security\Crypto::fromConfig($this->config));
        $this->container->bind(ModuleLoader::class, fn() => new ModuleLoader(
            $this->domain,
            $this->subdomain,
            $this->container
        ));

        // Configure tenant data path for client-specific overrides
        // Priority: client data dir > intrabase defaults
        // Structure: /var/www/vhosts/{domain}/subdomains/{subdomain}/httpdocs/data/
        $tenantDataPath = $this->getTenantDataPath();
        if ($tenantDataPath !== null) {
            $loader = $this->container->get(ModuleLoader::class);
            $modDir = $tenantDataPath . '/mod';
            if (is_dir($modDir)) {
                $loader->setTenantModulesDir($modDir);
            }

            // Register tenant-specific components
            \Intrabase\Components\ComponentRegistry::setTenantComponentsDir(
                $tenantDataPath . '/components'
            );

            // Register tenant-specific templates dir
            \Intrabase\View\TemplateResolver::setTenantDir($tenantDataPath . '/tpl');
        }

        // View classes (no-arg constructors — auto-resolvable but pre-register for clarity)
        $this->container->bind(\Intrabase\View\DataBrowser::class,  fn() => new \Intrabase\View\DataBrowser());
        $this->container->bind(\Intrabase\View\DataEditor::class,   fn() => new \Intrabase\View\DataEditor());
        $this->container->bind(\Intrabase\View\DataDetailer::class, fn() => new \Intrabase\View\DataDetailer());

        // Domain event bus (in-process, synchronous). Listeners are wired
        // in registerDomainListeners() which runs right after this method.
        $this->container->bind(EventBusInterface::class, function () {
            return new InMemoryEventBus($this->logger);
        });
    }

    /**
     * Wire domain-event listeners.
     *
     * Keep the list short and explicit. A listener registered here runs
     * synchronously, in-process, for every matching event. If it grows
     * large, split into per-context listener bundles.
     */
    private function registerDomainListeners(): void
    {
        // Plugins subscribe their own listeners to the EventBusInterface service.

        // Initialize the config manager (lazy-loads ib_config on first get())
        ConfigManager::init($this->db);

        // Initialize the audit logger
        Auditor::init($this->db, $this->session);
    }

    /**
     * Handle the current HTTP request.
     *
     * Order: path rewrites, kernel public routes, plugin public routes,
     * authentication gate, app-access gate, kernel authenticated routes,
     * plugin authenticated routes and, last, the generic module dispatcher.
     */
    public function handle(): void
    {
        try {
            $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

            // Optional canonical API host: /v1/* is served as /api/v1/*.
            if (str_starts_with($path, '/v1/') || $path === '/v1') {
                $newPath = '/api' . $path;
                $_SERVER['REQUEST_URI'] = $newPath . (
                    !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''
                );
                $path = $newPath;
            }

            // Tenant short routes (Support\TenantRoutes): the long form is
            // redirected to the short one on GET, so the short URL is canonical.
            $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
            if ($method === 'GET') {
                $canonical = \Intrabase\Support\TenantRoutes::canonical($path);
                if ($canonical !== null && $canonical !== $path) {
                    header('Location: ' . $canonical . $qs, true, 301);
                    return;
                }
            }
            $rewritten = \Intrabase\Support\TenantRoutes::rewrite($path);
            if ($rewritten !== $path) {
                $_SERVER['REQUEST_URI'] = $rewritten . $qs;
                $path = $rewritten;
            }

            // Translations for pre-auth pages (login, password recovery...).
            // handleIntranetRequest() re-initializes with the user's locale.
            if (!Lang::isInitialized()) {
                Lang::init($this->detectPreAuthLocale(), dirname(__DIR__, 2) . '/lang');
            }

            // REST API: token or session authentication, handled by the API router.
            if (str_starts_with($path, '/api/')) {
                $this->handleApiRequest();
                return;
            }

            $loginPath = $this->config['login_path'] ?? '/login';
            $public = [
                $loginPath                => fn() => $this->handleLoginRequest(),
                '/logout'                 => fn() => $this->handleLogoutRequest(),
                '/login/verify/resend'    => fn() => $this->handleVerifyResendRequest(),
                '/login/verify'           => fn() => $this->handleVerifyRequest(),
                '/login/sessions'         => fn() => $this->handleSessionConflictRequest(),
                '/forgot-password'        => fn() => $this->handleForgotPasswordRequest(),
                '/reset-password'         => fn() => $this->handleResetPasswordRequest(),
                '/app-icon'               => fn() => $this->handleAppIcon(),
                '/app-favicon'            => fn() => $this->handleAppFavicon(),
                '/manifest.webmanifest'   => fn() => $this->handleManifest(),
            ];
            foreach ($public as $route => $run) {
                if ($path === $route || $path === $route . '/') {
                    $run();
                    return;
                }
            }

            // Routes contributed by plugins that do not need a session.
            if ($this->routes()->dispatch(RouteRegistry::PUBLIC, $path, $method)) {
                return;
            }

            // Everything else requires a session. fetch/XHR callers get a
            // machine-readable 401 instead of a redirect to the login page.
            if (!$this->session->isAuthenticated()) {
                if ($this->isXhrRequest($path)) {
                    $this->respondSessionExpired();
                    return;
                }
                $this->redirectToLogin();
                return;
            }

            // Per-person application access (UserAppAccess): none = out, read = GET only.
            if ($this->enforceUserAppGate($path)) {
                return;
            }

            // Members of config('require_totp_groups') (default: admin) first enrol
            // an authenticator app on /profile.
            if ($this->enforceRequiredTotp($path)) {
                return;
            }

            // Return to the page that was requested before logging in (LoginReturn, 15 min).
            if ($path === '/' && $method === 'GET') {
                $ret = $this->session->get(\Intrabase\Support\LoginReturn::SESSION_KEY);
                if (is_array($ret)) {
                    $this->session->forget(\Intrabase\Support\LoginReturn::SESSION_KEY);
                    $retPath = \Intrabase\Support\LoginReturn::fromRequest((string) ($ret['p'] ?? ''), 'GET', null);
                    if ($retPath !== null && (time() - (int) ($ret['t'] ?? 0)) < 900) {
                        header('Location: ' . $retPath, true, 302);
                        return;
                    }
                }
            }

            // /{appKey} → /{appKey}/{default module}, unless a plugin owns that segment.
            $trimmedPath = trim($path, '/');
            $reserved = array_merge(['dashboard', 'download', 'notifications', 'user'], $this->routes()->reservedSegments());
            if ($trimmedPath !== '' && !in_array($trimmedPath, $reserved, true) && !str_contains($trimmedPath, '/')) {
                $stmt = $this->db->pdo()->prepare(
                    'SELECT default_module FROM ib_apps WHERE keyname = ? AND is_active = 1 LIMIT 1'
                );
                $stmt->execute([$trimmedPath]);
                $defaultMod = $stmt->fetchColumn();
                if ($defaultMod) {
                    $shortMod = str_starts_with($defaultMod, $trimmedPath . '_')
                        ? substr($defaultMod, strlen($trimmedPath) + 1)
                        : $defaultMod;
                    $target = \Intrabase\Support\TenantRoutes::shorten($trimmedPath, $defaultMod)
                        ?? '/' . $trimmedPath . '/' . $shortMod;
                    header('Location: ' . $target, true, 302);
                    return;
                }
            }

            // The user's own profile: name, language, password, TOTP, trusted devices.
            if ($path === '/profile' || $path === '/profile/') {
                $this->userAccount = $this->session->user() ?? [];
                Lang::init((string) ($this->userAccount['locale'] ?? 'en'), dirname(__DIR__, 2) . '/lang');
                $this->initModAppMap();
                (new \Intrabase\Auth\ProfileController($this->db, $this->session, new CSRF($this->session), $this->config))->handle();
                return;
            }

            // Authenticated file downloads (uploads of file/picture fields).
            if ($path === '/download' || $path === '/download/') {
                (new \Intrabase\Auth\DownloadController())->handle();
                return;
            }

            // Notification bell: badge, list, mark as read.
            if (str_starts_with($path, '/notifications/')) {
                (new \Intrabase\Auth\NotificationController($this->db, $this->session, new CSRF($this->session)))->handle();
                return;
            }

            // Routes contributed by plugins that need a session.
            if ($this->routes()->dispatch(RouteRegistry::AUTH, $path, $method)) {
                return;
            }

            // Old long URLs → short form: /app/app_mod → /app/mod (308 keeps a POST body).
            if (preg_match('#^/([a-z_]+)/\1_([a-z_]+)(/.*)?$#', $path, $rm)
                && \Intrabase\Support\TenantRoutes::shorten($rm[1], $rm[1] . '_' . $rm[2]) === null) {
                $shortPath = '/' . $rm[1] . '/' . $rm[2] . ($rm[3] ?? '');
                if (($_SERVER['QUERY_STRING'] ?? '') !== '') {
                    $shortPath .= '?' . $_SERVER['QUERY_STRING'];
                }
                header('Location: ' . $shortPath, true, $method === 'POST' ? 308 : 301);
                exit;
            }

            // User settings endpoints write on behalf of the user: CSRF like any
            // other form (intrabase.js adds X-CSRF-TOKEN to same-origin fetches).
            if (str_starts_with($path, '/user/') && !in_array($method, ['GET', 'HEAD'], true)
                && !(new CSRF($this->session))->validateToken()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
                return;
            }
            if ($path === '/user/theme' && $method === 'POST') {
                $this->handleUserTheme();
                return;
            }
            if ($path === '/user/switch-entity' && $method === 'POST') {
                $this->handleSwitchEntity();
                return;
            }
            if (str_starts_with($path, '/user/quick-actions')) {
                $this->handleQuickActions();
                return;
            }

            $this->handleIntranetRequest();

        } catch (\Throwable $e) {
            $this->handleError($e);
        }
    }

    private ?RouteRegistry $routeRegistry = null;

    /**
     * Routes contributed by the plugins in config('plugins'). Built on first
     * use: each plugin class must implement PluginInterface.
     */
    public function routes(): RouteRegistry
    {
        if ($this->routeRegistry !== null) {
            return $this->routeRegistry;
        }
        $this->routeRegistry = new RouteRegistry();
        \Intrabase\View\LayoutExtensions::addUserMenuItem('/profile', 'fa-solid fa-id-card', 'my_profile');
        foreach ((array) ($this->config['plugins'] ?? []) as $class) {
            if (!is_string($class) || !class_exists($class)) {
                $this->logger->error('Plugin not found', ['class' => $class]);
                continue;
            }
            $plugin = new $class();
            if (!$plugin instanceof PluginInterface) {
                $this->logger->error('Plugin does not implement PluginInterface', ['class' => $class]);
                continue;
            }
            $plugin->register($this, $this->routeRegistry);
        }
        return $this->routeRegistry;
    }

    private function handleLoginRequest(): void
    {
        $login = new \Intrabase\Auth\LoginController(
            $this->db,
            $this->session,
            new CSRF($this->session),
            $this->tenantConfig,
        );
        $login->handle();
    }

    /**
     * Dispatch to VerifyController (2FA step)
     */
    private function handleVerifyRequest(): void
    {
        $verify = new \Intrabase\Auth\VerifyController(
            $this->db,
            $this->session,
            new CSRF($this->session),
            $this->tenantConfig,
        );
        $verify->handle();
    }

    /**
     * Dispatch to VerifyController resend (re-send 2FA code)
     */
    private function handleVerifyResendRequest(): void
    {
        $verify = new \Intrabase\Auth\VerifyController(
            $this->db,
            $this->session,
            new CSRF($this->session),
            $this->tenantConfig,
        );
        $verify->handleResend();
    }

    /**
     * Dispatch to SessionConflictController
     */
    private function handleSessionConflictRequest(): void
    {
        $ctrl = new \Intrabase\Auth\SessionConflictController(
            $this->db,
            $this->session,
            new CSRF($this->session),
        );
        $ctrl->handle();
    }

    /**
     * Dispatch to ForgotPasswordController
     */
    private function handleForgotPasswordRequest(): void
    {
        $ctrl = new \Intrabase\Auth\ForgotPasswordController(
            $this->db,
            $this->session,
            new CSRF($this->session),
        );
        $ctrl->handle();
    }

    /**
     * Dispatch to ResetPasswordController
     */
    private function handleResetPasswordRequest(): void
    {
        $ctrl = new \Intrabase\Auth\ResetPasswordController(
            $this->db,
            $this->session,
            new CSRF($this->session),
        );
        $ctrl->handle();
    }

    /**
     * Route to the user profile page
     */
    /**
     * Serve authenticated file downloads from /var/intrabase/uploads/
     */
    private function handleDownloadRequest(): void
    {
        $controller = new \Intrabase\Auth\DownloadController();
        $controller->handle();
    }

    private function handleNotificationRequest(): void
    {
        $ctrl = new \Intrabase\Auth\NotificationController(
            $this->db,
            $this->session,
            new CSRF($this->session),
        );
        $ctrl->handle();
    }

    private function handleLogoutRequest(): void
    {
        // Only a POST with the CSRF token logs out: a GET link (an <img> on
        // any page) must not be able to end someone's session. A GET shows a
        // confirmation button instead.
        $csrf = new CSRF($this->session);
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' || !$csrf->validateToken()) {
            if (!$this->session->isAuthenticated()) {
                header('Location: ' . ($this->config['login_path'] ?? '/login'), true, 302);
                exit;
            }
            $field = $csrf->field();
            $label = htmlspecialchars(__('logout'), ENT_QUOTES, 'UTF-8');
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<title>' . $label . '</title></head><body style="font-family:system-ui,sans-serif;display:flex;'
               . 'min-height:100vh;align-items:center;justify-content:center;margin:0">'
               . '<form method="post" action="/logout">' . $field
               . '<button type="submit" style="font-size:1rem;padding:.6rem 1.4rem">' . $label . '</button></form></body></html>';
            exit;
        }

        // Remove session tracking record before destroying the session
        $this->removeSessionRecord();

        // Log logout event and revoke trusted device (before session is destroyed)
        if ($this->session->isAuthenticated()) {
            $uid = $this->session->userId();
            $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            LoginLogger::log($this->db, 'logout', $uid, $ip);

            // Revoke the trusted device of this browser (and clear its cookie)
            if ($uid !== null) {
                \Intrabase\Support\TrustedDevice::forget($this->db, (int) $uid);
            }
        }

        $this->session->logout();
        $loginPath = $this->config['login_path'] ?? '/login';
        header('Location: ' . $loginPath, true, 302);
        exit;
    }

    /**
     * Route to the API layer
     */
    private function handleApiRequest(): void
    {
        $loader = $this->container->get(ModuleLoader::class);

        $router = new ApiRouter(
            db:            $this->db,
            logger:        $this->logger,
            loader:        $loader,
            clientAccount: $this->clientAccount,
            userAccount:   $this->userAccount,
        );

        $router->dispatch();
    }

    /**
     * Route authenticated intranet request to module
     */
    private function handleManifest(): void
    {
        $appKey = preg_replace('/[^a-z0-9_-]/', '', $_GET['app'] ?? '');
        $title = trim($_GET['t'] ?? '');
        $name = $title ?: \Intrabase\Support\Branding::name();

        // Map app key to start URL — special cases first, fallback to /{appKey}
        $appStartUrls = [
            'planner' => '/planner/planner_calendar',
        ];
        $startUrl = $appKey ? ($appStartUrls[$appKey] ?? '/' . $appKey) : '/';

        // Use dynamic app-favicon with badge for PWA icons
        $icon180 = $appKey
            ? "/app-favicon?app={$appKey}&s=180&badge=1"
            : '/assets/img/apple-touch-icon-base.png';
        $icon192 = $appKey
            ? "/app-favicon?app={$appKey}&s=192&badge=1"
            : '/assets/img/logo-192.png';
        $icon512 = $appKey
            ? "/app-favicon?app={$appKey}&s=512&badge=1"
            : '/assets/img/logo-512.png?v=3';

        // Marca de tenant: la seva icona `-touch.png` mana a totes les mides
        // del manifest (mateixa regla que el favicon, 28/08/2026).
        $touch = \Intrabase\Support\Branding::appleTouchIcon();
        if ($touch !== null) {
            $icon180 = $icon192 = $icon512 = $touch;
        }

        $icons = [
            ['src' => $icon180, 'sizes' => '180x180', 'type' => 'image/png'],
            ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png'],
        ];
        // Android launchers crop icons to their own shape; without a maskable
        // one they shrink ours onto a white plate.
        if ($appKey && $touch === null) {
            foreach ([192, 512] as $s) {
                $icons[] = ['src' => "/app-favicon?app={$appKey}&s={$s}&maskable=1",
                            'sizes' => "{$s}x{$s}", 'type' => 'image/png', 'purpose' => 'maskable'];
            }
        }

        $manifest = [
            // One id per app, so Android installs Mail, Planner… as separate
            // apps rather than treating them as one (they all share scope /).
            'id' => $appKey ? '/?pwa=' . $appKey : '/',
            'name' => $name,
            'short_name' => $name,
            'start_url' => $startUrl,
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0d1117',
            'theme_color' => '#000000',
            'icons' => $icons,
        ];

        header('Content-Type: application/manifest+json');
        header('Cache-Control: no-cache');
        echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function handleAppIcon(): void
    {
        $iconCode = (int)($_GET['ic'] ?? 0);
        $size = (int)($_GET['s'] ?? 180);
        if (!in_array($size, [180, 192, 512])) $size = 180;

        $imgDir = __DIR__ . '/../../public/assets/img/';
        $baseMap = [180 => 'apple-touch-icon-base.png', 192 => 'logo-192.png', 512 => 'logo-512.png'];
        $basePath = $imgDir . $baseMap[$size];
        $fontPath = __DIR__ . '/../../public/assets/fonts/fa-solid-900.ttf';

        // If no icon code or missing files, serve base icon
        if (!$iconCode || !file_exists($basePath) || !file_exists($fontPath)) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            readfile($basePath);
            return;
        }

        $primaryColor = '#31b8b2';
        try {
            $row = $this->db->fetchOne(
                'SELECT primary_color FROM ib_sys_theme_settings WHERE scope = ? AND primary_color IS NOT NULL LIMIT 1',
                ['global']
            );
            if ($row && !empty($row['primary_color'])) {
                $primaryColor = $row['primary_color'];
            }
        } catch (\Throwable $e) {}

        $iconChar = mb_chr($iconCode, 'UTF-8');

        // Scale badge proportionally
        $badgeSize = (int)($size * 0.32);
        $fontSize = (int)($badgeSize * 0.52);
        $margin = (int)($size * 0.02);
        $strokeW = max(2, (int)($size * 0.012));
        $half = (int)($badgeSize / 2);

        $tmpBase = tempnam(sys_get_temp_dir(), 'ib_icon_');
        $tmp = $tmpBase . '.png';
        @unlink($tmpBase);   // tempnam() creates this file; we only want its unique name
        $tmpTxt = tempnam(sys_get_temp_dir(), 'ib_txt_');
        file_put_contents($tmpTxt, $iconChar);

        $escapedColor = escapeshellarg($primaryColor);
        $escapedFont = escapeshellarg($fontPath);
        $escapedBase = escapeshellarg($basePath);
        $escapedTxt = escapeshellarg('@' . $tmpTxt);

        $cmd = "convert {$escapedBase} "
             . "\\( -size {$badgeSize}x{$badgeSize} xc:none "
             . "-fill {$escapedColor} -draw 'circle {$half},{$half} {$half},1' "
             . "-fill white -font {$escapedFont} -pointsize {$fontSize} -gravity center -annotate +0+0 {$escapedTxt} "
             . "-strokewidth {$strokeW} -stroke white -fill none -draw 'circle {$half},{$half} {$half},2' \\) "
             . "-gravity SouthEast -geometry +{$margin}+{$margin} -composite "
             . escapeshellarg($tmp);

        exec($cmd . ' 2>&1', $output, $ret);
        @unlink($tmpTxt);

        if ($ret === 0 && file_exists($tmp)) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            readfile($tmp);
            @unlink($tmp);
        } else {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            readfile($basePath);
            @unlink($tmp);
        }
    }

    private function handleAppFavicon(): void
    {
        $appKey = preg_replace('/[^a-z0-9_-]/', '', $_GET['app'] ?? '');
        $size = (int)($_GET['s'] ?? 32);
        $allowed = [32, 180, 192, 512];
        if (!in_array($size, $allowed)) $size = 32;
        $withBadge = (int)($_GET['badge'] ?? 0) === 1;
        // Android "maskable" icon: full-bleed square, glyph inside the 80% safe
        // circle, no badge — the launcher crops it to its own shape.
        $maskable = (int)($_GET['maskable'] ?? 0) === 1;

        $fontPath = __DIR__ . '/../../public/assets/fonts/fa-solid-900.ttf';
        $logoSvg = __DIR__ . '/../../public/assets/img/logo.svg';
        // var/cache is the tree the web server can write (apache on some hosts, www-data on
        // the VM). The old cache/ was root-owned and cache/icons never existed, so
        // convert failed and every app got the generic fallback icon (30/10/2026).
        $cacheDir = __DIR__ . '/../../var/cache/icons';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

        // FA icon unicode per app key
        $iconMap = [
            'contacts'     => 0xf2b9, // address-book
            'mail'         => 0xf0e0, // envelope
            'mailing'      => 0xf1d8, // paper-plane
            'chat'         => 0xf086, // comments
            'planner'      => 0xf274, // calendar-check
            'crm'          => 0xe4e2, // circle-nodes
            'erp'          => 0xf1ec, // calculator
            'library'      => 0xf15c, // file-lines
            'sites'        => 0xf0a1, // bullhorn
            'ecommerce'    => 0xf07a, // cart-shopping
            'rag'          => 0xf5dc, // brain
            'isp'          => 0xf233, // server
            'cook'         => 0xf2e7, // utensils
            'crowdfunding' => 0xf4d8, // seedling
            'director'     => 0xf14e, // compass
            'system'       => 0xf013, // gear
            'settings'     => 0xf1de, // sliders
            'dashboard'    => 0xf3fd, // tachometer-alt
            'drive'        => 0xf0a0, // hdd / hard-drive
        ];

        $iconCode = $iconMap[$appKey] ?? 0;

        // Fallback: serve static favicon/logo
        if (!$iconCode || !file_exists($fontPath)) {
            $fallback = __DIR__ . '/../../public/assets/img/'
                . ($size <= 32 ? 'favicon-32.png' : 'apple-touch-icon-base.png');
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            readfile($fallback);
            return;
        }

        // Get client primary color from ib_config (same source as CSS --ib-primary)
        $primaryColor = '#31b8b2';
        try {
            $row = $this->db->fetchOne(
                "SELECT config_value FROM ib_config WHERE config_key = 'primary_color' LIMIT 1"
            );
            if ($row && !empty($row['config_value']) && preg_match('/^#[0-9a-fA-F]{3,6}$/', $row['config_value'])) {
                $primaryColor = $row['config_value'];
            }
        } catch (\Throwable $e) {}

        // Cache key: app_size_badge_color
        $colorHash = substr(md5($primaryColor), 0, 8);
        $cacheKey = "{$appKey}_{$size}_" . ($maskable ? 'm' : ($withBadge ? '1' : '0')) . "_{$colorHash}";
        $cachePath = $cacheDir . '/' . $cacheKey . '.png';

        // Serve cached version if available
        if (file_exists($cachePath) && (time() - filemtime($cachePath)) < 86400) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            readfile($cachePath);
            return;
        }

        $iconChar = mb_chr($iconCode, 'UTF-8');
        $rr = $size - 1;
        $radius = (int)($size * 0.22);
        $fontSize = (int)($size * 0.50);
        $tmpTxt = tempnam('/tmp', 'ib_txt_');
        file_put_contents($tmpTxt, $iconChar);

        $escapedColor = escapeshellarg($primaryColor);
        $escapedFont = escapeshellarg($fontPath);
        $escapedTxt = escapeshellarg('@' . $tmpTxt);
        $escapedOut = escapeshellarg($cachePath);

        // Desktop (no badge): transparent bg + icon in primary color
        // Mobile (with badge): rounded rectangle bg + white icon
        if ($maskable) {
            $fsMask = (int)($size * 0.36);
            $cmd = "convert -size {$size}x{$size} xc:{$escapedColor} "
                 . "-fill white -font {$escapedFont} -pointsize {$fsMask} "
                 . "-gravity center -annotate +0+0 {$escapedTxt} ";
        } elseif (!$withBadge) {
            $fsDsk = (int)($size * 0.85);
            $cmd = "convert -size {$size}x{$size} xc:none "
                 . "-fill {$escapedColor} -font {$escapedFont} -pointsize {$fsDsk} "
                 . "-gravity center -annotate +0+0 {$escapedTxt} ";
        } else {
            $cmd = "convert -size {$size}x{$size} xc:none "
                 . "-fill {$escapedColor} "
                 . "-draw 'roundrectangle 0,0,{$rr},{$rr},{$radius},{$radius}' "
                 . "-fill white -font {$escapedFont} -pointsize {$fontSize} "
                 . "-gravity center -annotate +0+0 {$escapedTxt} ";
        }

        // Mobile badge: black circle with Intrabase logo at bottom-right
        if ($withBadge && !$maskable && file_exists($logoSvg)) {
            $badgeSize = (int)($size * 0.38);
            $logoInner = (int)($badgeSize * 0.58);
            $half = (int)($badgeSize / 2);
            $margin = (int)($size * 0.00);
            $strokeW = max(1, (int)($size * 0.016));
            $escapedLogo = escapeshellarg($logoSvg);

            // Create a temporary white-filled logo PNG from SVG
            $tmpLogoBase = tempnam(sys_get_temp_dir(), 'ib_logo_');
            $tmpLogo = $tmpLogoBase . '.png';
            @unlink($tmpLogoBase);
            $escapedLogo = escapeshellarg($logoSvg);
            $escapedTmpLogo = escapeshellarg($tmpLogo);
            // Rasterize SVG at higher res then resize, fill white
            exec("convert -background none -density 300 {$escapedLogo} -resize {$logoInner}x{$logoInner} "
               . "-fill white -colorize 100 {$escapedTmpLogo} 2>&1");

            $badgeRr = $badgeSize - 1;
            $badgeRadius = (int)($badgeSize * 0.25);
            $logoOffset = (int)(($badgeSize - $logoInner) / 2) + $margin;
            $cmd .= "\\( -size {$badgeSize}x{$badgeSize} xc:none "
                  . "-fill black -draw 'roundrectangle 0,0,{$badgeRr},{$badgeRr},{$badgeRadius},{$badgeRadius}' \\) "
                  . "-gravity SouthEast -geometry +{$margin}+{$margin} -composite "
                  . "\\( {$escapedTmpLogo} \\) "
                  . "-gravity SouthEast "
                  . "-geometry +{$logoOffset}+{$logoOffset} "
                  . "-composite ";
        }

        $cmd .= $escapedOut;

        exec($cmd . ' 2>&1', $output, $ret);
        @unlink($tmpTxt);
        if (isset($tmpLogo)) @unlink($tmpLogo);

        if ($ret === 0 && file_exists($cachePath)) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            readfile($cachePath);
        } else {
            // Fallback on error
            $fallback = __DIR__ . '/../../public/assets/img/'
                . ($size <= 32 ? 'favicon-32.png' : 'apple-touch-icon-base.png');
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            readfile($fallback);
        }
    }

    private function handleUserTheme(): void
    {
        header('Content-Type: application/json');
        $userId = $this->session->userId();
        if (!$userId) {
            http_response_code(401);
            echo '{"error":"Not authenticated"}';
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $theme = ($input['theme'] ?? '') === 'light' ? 'light' : 'dark';
        $this->db->execute('UPDATE ib_users SET theme = ? WHERE id = ?', [$theme, $userId]);
        // Update session too
        $user = $this->session->user();
        $user['theme'] = $theme;
        $_SESSION['_ib_user'] = $user;
        echo '{"ok":true,"theme":"' . $theme . '"}';
    }

    /**
     * Switch the user's active entity.
     */
    private function handleSwitchEntity(): void
    {
        header('Content-Type: application/json');
        $userId = $this->session->userId();
        if (!$userId) {
            http_response_code(401);
            echo '{"error":"Not authenticated"}';
            return;
        }

        $input    = json_decode(file_get_contents('php://input'), true);
        $entityId = isset($input['entity_id']) ? (int) $input['entity_id'] : 0;

        if ($entityId === 0) {
            // Clear active entity (personal mode)
            $this->session->set('_ib_active_entity', null);
            echo '{"ok":true,"entity_id":null}';
            return;
        }

        // Verify user belongs to this entity
        $row = $this->db->fetchOne(
            'SELECT ue.id, e.name, e.logo, e.color, e.type FROM ib_user_entities ue
             JOIN ib_obj_entities e ON e.id = ue.entity_id
             WHERE ue.user_id = :uid AND ue.entity_id = :eid',
            ['uid' => $userId, 'eid' => $entityId]
        );

        if (!$row) {
            http_response_code(403);
            echo '{"error":"Not a member of this entity"}';
            return;
        }

        $this->session->set('_ib_active_entity', [
            'id'    => $entityId,
            'name'  => $row['name'],
            'logo'  => $row['logo'] ?? '',
            'color' => $row['color'] ?? '',
            'type'  => $row['type'] ?? '',
        ]);

        echo json_encode(['ok' => true, 'entity_id' => $entityId, 'name' => $row['name']]);
    }

    /**
     * GET/POST/DELETE /user/quick-actions — Manage user quick action shortcuts
     */
    private function handleQuickActions(): void
    {
        header('Content-Type: application/json');
        $userId = $this->session->userId();
        if (!$userId) {
            http_response_code(401);
            echo '{"error":"Not authenticated"}';
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'];

        // GET — list user's quick actions
        if ($method === 'GET') {
            $rows = $this->db->fetchAll(
                'SELECT id, label, icon, url, position FROM ib_user_quick_actions
                 WHERE user_id = ? ORDER BY position ASC, id ASC',
                [$userId]
            );
            echo json_encode(['ok' => true, 'actions' => $rows]);
            return;
        }

        // POST — add or reorder quick actions
        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);

            // Reorder mode
            if (isset($input['reorder']) && is_array($input['reorder'])) {
                foreach ($input['reorder'] as $pos => $id) {
                    $this->db->execute(
                        'UPDATE ib_user_quick_actions SET position = ? WHERE id = ? AND user_id = ?',
                        [(int) $pos, (int) $id, $userId]
                    );
                }
                echo '{"ok":true}';
                return;
            }

            // Add new quick action
            $label = trim((string)($input['label'] ?? ''));
            $icon  = trim((string)($input['icon'] ?? 'fa-solid fa-link'));
            $url   = trim((string)($input['url'] ?? ''));

            if ($label === '' || $url === '') {
                http_response_code(400);
                echo '{"error":"label and url are required"}';
                return;
            }

            // Max 10 quick actions per user
            $count = (int)$this->db->fetchOne(
                'SELECT COUNT(*) AS c FROM ib_user_quick_actions WHERE user_id = ?',
                [$userId]
            )['c'];
            if ($count >= 10) {
                http_response_code(400);
                echo '{"error":"Maximum 10 quick actions reached"}';
                return;
            }

            // Check duplicate URL
            $existing = $this->db->fetchOne(
                'SELECT id FROM ib_user_quick_actions WHERE user_id = ? AND url = ?',
                [$userId, $url]
            );
            if ($existing) {
                http_response_code(409);
                echo '{"error":"Already exists","id":' . (int)$existing['id'] . '}';
                return;
            }

            $nextPos = (int)($this->db->fetchOne(
                'SELECT COALESCE(MAX(position),0)+1 AS p FROM ib_user_quick_actions WHERE user_id = ?',
                [$userId]
            )['p'] ?? 0);

            $newId = $this->db->insert('ib_user_quick_actions', [
                'user_id'  => $userId,
                'label'    => $label,
                'icon'     => $icon,
                'url'      => $url,
                'position' => $nextPos,
            ]);
            echo json_encode(['ok' => true, 'id' => $newId]);
            return;
        }

        // DELETE — remove a quick action
        if ($method === 'DELETE') {
            $input = json_decode(file_get_contents('php://input'), true);
            $id = (int)($input['id'] ?? 0);

            if (!$id) {
                // Delete by URL
                $url = trim((string)($input['url'] ?? ''));
                if ($url !== '') {
                    $this->db->execute(
                        'DELETE FROM ib_user_quick_actions WHERE user_id = ? AND url = ?',
                        [$userId, $url]
                    );
                    echo '{"ok":true}';
                    return;
                }
                http_response_code(400);
                echo '{"error":"id or url required"}';
                return;
            }

            $this->db->execute(
                'DELETE FROM ib_user_quick_actions WHERE id = ? AND user_id = ?',
                [$id, $userId]
            );
            echo '{"ok":true}';
            return;
        }

        http_response_code(405);
        echo '{"error":"Method not allowed"}';
    }

    private function handleIntranetRequest(): void
    {
        $this->userAccount = $this->session->user() ?? [];

        // Initialize UI translation system with user's locale
        $locale = (string) ($this->userAccount['locale'] ?? 'en');
        Lang::init($locale, dirname(__DIR__, 2) . '/lang');

        // Parse structured route and set it in Router (e.g. /system/sys_users/5/edit)
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Resolve /{appKey} to /{appKey}/{default_module} without redirect
        $trimmed = trim($requestPath, '/');
        if ($trimmed !== '' && $trimmed !== 'dashboard' && !str_contains($trimmed, '/')) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT default_module FROM ib_apps WHERE keyname = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$trimmed]);
            $defaultMod = $stmt->fetchColumn();
            if ($defaultMod) {
                $requestPath = '/' . $trimmed . '/' . $defaultMod;
            }
        }

        // URL aliases: short URLs -> real module names
        $route = Router::parse($requestPath);
        if ($route !== null) {
            Router::setCurrent($route);
        }

        // Build mod→app map so Router::url() can infer app from mod name
        $this->initModAppMap();

        // Track this session in ib_sessions (throttled: update at most every 5 min)
        $this->trackSession();

        $loader = $this->container->get(ModuleLoader::class);
        $moduleName = $this->getRequestedModule();

        // Auto-expand short module URLs: /{app}/{name} -> /{app}/{app}_{name}
        // e.g. /planner/calendar -> /planner/planner_calendar
        if ($moduleName && !$loader->exists($moduleName)) {
            $pathParts = explode('/', trim($requestPath, '/'));
            if (count($pathParts) >= 2) {
                $expandedMod = $pathParts[0] . '_' . $pathParts[1];
                if ($loader->exists($expandedMod)) {
                    $pathParts[1] = $expandedMod;
                    $requestPath = '/' . implode('/', $pathParts);
                    $route = Router::parse($requestPath);
                    if ($route !== null) {
                        Router::setCurrent($route);
                    }
                    $moduleName = $expandedMod;
                }
            }
        }

        // L'app de la URL no és del perfil actiu (p. ex. s'ha canviat de perfil sent a
        // /invoicing, que només té l'entitat amb dades d'emissor): al tauler, no a
        // l'error del mòdul.
        if ($moduleName && $route !== null) {
            $hiddenApp = $this->appHiddenForProfile((string) $route['app'], $requestPath);
            if ($hiddenApp !== null) {
                $this->session->flash('info', __('app_not_in_profile', $hiddenApp));
                header('Location: /', true, 302);
                exit;
            }
        }

        if ($moduleName) {
            // ACL check: verify user has access to this module
            $acl        = new Acl();
            $userId     = (int) ($this->userAccount['id'] ?? 0);
            $userGroups = $acl->getUserGroups($userId, $this->db);

            // Permisos decidits des de /account (grup de l'empresa o excepcions): passen per davant
            // dels grups del sistema (el nivell «none»/«read» ja l'ha tallat enforceUserAppGate).
            // Only for the app that really owns the module (never the URL segment alone,
            // which the client chooses) and never for system modules.
            $appKeyAcl = Router::currentApp();
            $ownerApp  = $acl->ownerApp($moduleName, $this->db);
            $byAccount = $ownerApp !== null && $ownerApp === $appKeyAcl && $ownerApp !== 'system'
                && !str_starts_with($moduleName, 'sys_')
                && \Intrabase\Support\UserAppAccess::managed($this->db, $userId, \Intrabase\Support\EntityPlan::activeEntityId($this->session));
            if (!$byAccount && !$acl->canAccess($moduleName, $userGroups, $this->db)) {
                $this->handleAccessDenied();
                return;
            }

            $module = $loader->load($moduleName);
            $module->setClientAccount($this->clientAccount);
            $module->setUserAccount($this->userAccount);
            $module->execute();
        } else {
            // Dashboard views (/ or /dashboard) — always full-width, no sidebar
            $this->showDashboard(true);
        }
    }

    /**
     * Nom de l'app si la URL és d'una app activa que el perfil actiu no té
     * (AppAccess::availableForProfile: pla de l'entitat, Facturació sense dades
     * d'emissor, mòduls del tenant…); null si es pot seguir. Els grups no hi
     * compten. Només per a navegacions GET del navegador: les crides d'API/XHR i
     * els POST segueixen com abans (cada mòdul fa el seu tall).
     */
    /**
     * Talla les peticions a una app que la persona no té (o només en lectura) a l'empresa activa.
     * L'app es dedueix del primer segment de la ruta (/mailing/…, /soc/…, /drive/…).
     * Retorna true si ja ha respost.
     */
    private function enforceUserAppGate(string $path): bool
    {
        $seg = strtolower((string) (explode('/', trim($path, '/'))[0] ?? ''));
        if ($seg === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $seg)
            || in_array($seg, ['account', 'preferences', 'profile', 'api', 'login', 'logout', 'user', 'system', 'settings'], true)) {
            return false;
        }
        try {
            $level = \Intrabase\Support\UserAppAccess::currentLevel($this->db, $this->session, $seg);
        } catch (\Throwable) {
            return false;
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $blocked = $level === 'none' || ($level === 'read' && !in_array($method, ['GET', 'HEAD'], true));
        if (!$blocked) {
            return false;
        }
        $this->userAccount = $this->session->user() ?? [];
        Lang::init((string) ($this->userAccount['locale'] ?? 'en'), dirname(__DIR__, 2) . '/lang');
        $msg = __($level === 'none' ? 'user_app_no_access' : 'user_app_read_only');
        if ($method === 'GET' && !$this->isXhrRequest($path)) {
            $this->session->flash('error', $msg);
            header('Location: /', true, 302);
            return true;
        }
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'success' => false, 'error' => $msg]);
        return true;
    }

    private function appHiddenForProfile(string $appKey, string $path): ?string
    {
        if ($appKey === '' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
            || $this->isXhrRequest($path) || !$this->session->isAuthenticated()) {
            return null;
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT keyname, name, catalog_slug FROM ib_apps WHERE keyname = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$appKey]);
            $app = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$app) {
                return null;   // no és una app (rutes de sistema, àlies…)
            }
            return \Intrabase\Support\AppAccess::availableForProfile($this->db, $this->session, $app)
                ? null
                : __((string) $app['name']);
        } catch (\Throwable $e) {
            error_log('[Application] appHiddenForProfile ' . $appKey . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Show 403 Access Denied response
     */
    private function handleAccessDenied(): void
    {
        http_response_code(403);
        $layout = new \Intrabase\View\Layout(
            $this->session,
            new CSRF($this->session),
            $this->db,
        );
        $layout->render(
            '<div class="ib-alert ib-alert--danger"><strong>Access Denied</strong> — You do not have permission to access this module.</div>',
            ['title' => 'Access Denied']
        );
    }

    /**
     * Upsert a session record in ib_sessions.
     *
     * On first call (new session), inserts a full record.
     * Subsequent calls update last_activity — throttled to every 5 minutes
     * to avoid excessive writes.
     *
     * Failures are silently swallowed: tracking must never break the main flow.
     */
    /**
     * IPs de confiança: no es registren a ib_sessions ni es bloquegen
     * per detecció de conflicte de sessió.
     *
     * @var string[]
     */
    public static function isTrustedIp(string $ip): bool
    {
        // config('trusted_ips'): addresses (e.g. an office NAT) that never
        // trigger the session-conflict check. Empty by default.
        return in_array($ip, (array) (self::getInstance()->config['trusted_ips'] ?? []), true);
    }

    private function trackSession(): void
    {
        try {
            $sessionId = session_id();
            if (empty($sessionId) || empty($this->userAccount['id'])) {
                return;
            }

            // Com a molt un upsert per minut i sessió (21/10/2026). Abans cada
            // petició feia l'INSERT ... ON DUPLICATE KEY, que bloqueja la fila
            // encara que no canviï res; amb la BD carregada esperava >30 s
            // tenint el fitxer de sessió bloquejat, i totes les altres
            // peticions de l'usuari feien cua (pool PHP ple). last_activity ja
            // només avançava cada 5 min, o sigui que la presència no hi perd.
            $trackedAt = (int) ($_SESSION['_ib_session_tracked_at'] ?? 0);
            $trackedKey = $sessionId . '#' . (int) $this->userAccount['id'];
            if ($trackedAt > time() - 60 && ($_SESSION['_ib_session_tracked_key'] ?? '') === $trackedKey) {
                return;
            }
            $_SESSION['_ib_session_tracked_at']  = time();
            $_SESSION['_ib_session_tracked_key'] = $trackedKey;

            $userId   = (int) $this->userAccount['id'];
            $username = (string) ($this->userAccount['email'] ?? $this->userAccount['name'] ?? '');
            $ip       = $this->resolveClientIp();
            // NOTE: Trusted IPs used to be skipped here entirely, but that made
            // users on a shared trusted IP (e.g. the office NAT) never appear in
            // ib_sessions, so chat presence read them as permanently offline.
            // Session-conflict blocking is already independently guarded by
            // isTrustedIp() at the findOtherSessions() call sites (and trusted
            // rows are filtered out of the conflict list there), so recording
            // these sessions is safe and lets presence + admin panels see them.
            $ua       = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            $now      = date('Y-m-d H:i:s');

            $this->db->execute(
                'INSERT INTO ib_sessions
                     (session_id, user_id, username, ip_address, user_agent, created_at, last_activity, last_login)
                 VALUES
                     (:sid, :uid, :uname, :ip, :ua, :now, :now2, :now4)
                 ON DUPLICATE KEY UPDATE
                     last_activity = IF(last_activity < DATE_SUB(NOW(), INTERVAL 5 MINUTE), :now3, last_activity)',
                [
                    'sid'   => $sessionId,
                    'uid'   => $userId,
                    'uname' => $username,
                    'ip'    => $ip,
                    'ua'    => $ua,
                    'now'   => $now,
                    'now2'  => $now,
                    'now3'  => $now,
                    'now4'  => $now,
                ]
            );

            // Probabilistic cleanup: prune sessions idle for more than 7 days
            if (random_int(1, 50) === 1) {
                $this->db->execute(
                    "DELETE FROM ib_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 7 DAY)"
                );
            }
        } catch (\Throwable) {
            // Session tracking failures must never interrupt the main request
        }
    }

    /**
     * Remove the current session's tracking record from ib_sessions.
     * Called on logout so the session disappears from the admin view immediately.
     */
    private function removeSessionRecord(): void
    {
        try {
            $sessionId = session_id();
            if (!empty($sessionId)) {
                $this->db->execute(
                    'DELETE FROM ib_sessions WHERE session_id = :sid',
                    ['sid' => $sessionId]
                );
            }
        } catch (\Throwable) {
            // Non-critical
        }
    }

    /**
     * Resolve the client's IP address from request headers.
     * Respects X-Forwarded-For set by trusted reverse proxies.
     */
    private function resolveClientIp(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            $candidate = trim(explode(',', $forwarded)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return $candidate;
            }
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false) {
            return $remoteAddr;
        }

        return '0.0.0.0';
    }

    /**
     * Get requested module from validated request parameters
     */
    private function getRequestedModule(): ?string
    {
        // Prefer structured route (e.g. /system/sys_users)
        $routeMod = Router::currentMod();
        if ($routeMod !== '') {
            return $routeMod;
        }
        // Fallback: legacy ?mod= query string
        $mod = $_GET['mod'] ?? '';
        $mod = preg_replace('/[^a-zA-Z0-9_\-]/', '', $mod);
        return $mod ?: null;
    }

    /**
     * Build and register the mod→app map so Router::url() can infer app names.
     * Loads all apps with their module lists from the DB.
     */
    private function initModAppMap(): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;

        try {
            $pdo  = $this->db->pdo();
            $stmt = $pdo->query(
                "SELECT keyname, modules FROM ib_apps WHERE is_active = 1"
            );
            $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

            $map = [];
            foreach ($rows as $row) {
                $appKey = (string)($row['keyname'] ?? '');
                if ($appKey === '') {
                    continue;
                }
                $modules = json_decode((string)($row['modules'] ?? '[]'), true);
                if (!is_array($modules)) {
                    continue;
                }
                // modules is array of groups, each group has "modules" key
                foreach ($modules as $group) {
                    $mods = $group['modules'] ?? [];
                    if (!is_array($mods)) {
                        continue;
                    }
                    foreach ($mods as $mod) {
                        $key = (string)($mod['key'] ?? '');
                        if ($key !== '' && !isset($map[$key])) {
                            $map[$key] = $appKey;
                        }
                    }
                }
            }

            Router::setModAppMap($map);
        } catch (\Throwable) {
            // Non-critical: fall back to legacy URLs
        }
    }

    /**
     * Show application dashboard
     */
    private function showDashboard(bool $isDashboard = true): void
    {
        $dashboardClass = $this->config['dashboard_class'] ?? \Intrabase\View\Dashboard::class;
        $dashboard = new $dashboardClass($this->session, $this->db);
        $content   = $dashboard->render();

        $layout = new \Intrabase\View\Layout(
            $this->session,
            new CSRF($this->session),
            $this->db,
        );
        $layout->render($content, [
            'title'       => $this->config['name'] ?? 'Intrabase',
            'isDashboard' => $isDashboard,
        ]);
    }

    /**
     * Redirect to login page
     */
    /**
     * Whether the request comes from fetch()/XHR rather than a browser
     * navigation. Those must never be answered with a redirect to the login
     * page — see the auth gate in handle().
     */
    private function isXhrRequest(string $path): bool
    {
        // App API surfaces: /drive/api/…, /mail/api/…, /chat/api/…, /api/…
        if (str_starts_with($path, '/api/') || preg_match('#^/[A-Za-z0-9_-]+/api(/|$)#', $path)) {
            return true;
        }
        if (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0) {
            return true;
        }
        // Fetch metadata: 'empty' is exactly fetch()/XHR — navigations are
        // 'document', and <script>/<img>/<iframe> have their own values.
        if (strcasecmp($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '', 'empty') === 0) {
            return true;
        }
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    /**
     * Two-step verification with an authenticator app is mandatory for the
     * groups in config('require_totp_groups') (default ['admin']: whoever can
     * change users and permissions). Until they enrol, such users can only use
     * /profile (where the QR code is) and log out. An e-mail code would not do:
     * on many installs the mailbox itself lives inside Intrabase.
     *
     * @return bool true if the request was answered
     */
    private function enforceRequiredTotp(string $path): bool
    {
        if ($this->session->get('_ib_totp_ok') === true) {
            return false;
        }
        foreach (['/profile', '/logout', '/notifications/', '/user/', '/api/', '/login', '/download'] as $open) {
            if (str_starts_with($path, $open)) {
                return false;
            }
        }
        $userId = (int) ($this->session->userId() ?? 0);
        if ($userId <= 0) {
            return false;
        }
        try {
            $required = (new \Intrabase\Auth\ProfileController($this->db, $this->session, new CSRF($this->session), $this->config))
                ->totpRequired($userId);
            $enabled = (int) ($this->db->fetchOne('SELECT totp_enabled FROM ib_users WHERE id = :id', ['id' => $userId])['totp_enabled'] ?? 0) === 1;
        } catch (\Throwable $e) {
            $this->logger->error('enforceRequiredTotp failed', ['error' => $e->getMessage()]);
            return false;
        }
        if (!$required || $enabled) {
            $this->session->set('_ib_totp_ok', true);
            return false;
        }
        if ($this->isXhrRequest($path)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'totp_required', 'redirect' => '/profile#security']);
            return true;
        }
        $this->session->flash('info', __('totp_required_admin'));
        header('Location: /profile#security', true, 302);
        return true;
    }

    private function respondSessionExpired(): void
    {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Ib-Session: expired');
        echo json_encode([
            'ok'        => false,
            'error'     => 'session_expired',
            'login_url' => $this->config['login_path'] ?? '/login',
        ]);
        exit;
    }

    private function redirectToLogin(): void
    {
        // Remember the requested page to return to it after logging in.
        $ret = \Intrabase\Support\LoginReturn::fromRequest(
            (string) ($_SERVER['REQUEST_URI'] ?? ''),
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? (string) $_SERVER['HTTP_SEC_FETCH_DEST'] : null
        );
        if ($ret !== null) {
            $this->session->set(\Intrabase\Support\LoginReturn::SESSION_KEY, ['p' => $ret, 't' => time()]);
        }

        $loginPath = $this->config['login_path'] ?? '/login';
        header('Location: ' . $loginPath, true, 302);
        exit;
    }

    /**
     * Handle application errors with styled error page.
     */
    private function handleError(\Throwable $e): void
    {
        $this->logger->error('Application error', [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]);

        $debug = (bool) ($this->config['debug'] ?? false);
        (new ErrorHandler($debug))->render($e);
    }

    /**
     * Get a master database connection for tenant lookups
     */
    public function getMasterConnection(): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config['db_host'],
            (int) ($this->config['db_port'] ?? 3306),
            $this->config['db_name']
        );

        $pdo = new \PDO($dsn, $this->config['db_user'], $this->config['db_pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    }

    /**
     * Detect locale for pre-auth pages from ?lang= param or ib_lang cookie.
     * If ?lang= is present, sets the ib_lang cookie for subsequent requests.
     */
    private function detectPreAuthLocale(): string
    {
        $validLocales = ['ca', 'en', 'es', 'eu'];

        // Check ?lang= query parameter first
        $langParam = $_GET['lang'] ?? '';
        if ($langParam !== '' && in_array($langParam, $validLocales, true)) {
            // Set cookie for 1 year, secure, httponly, samesite=Lax
            setcookie('ib_lang', $langParam, [
                'expires'  => time() + 86400 * 365,
                'path'     => '/',
                'secure'   => $this->isHttps(),
                'httponly'  => false,
                'samesite' => 'Lax',
            ]);
            return $langParam;
        }

        // Fall back to cookie
        $cookieLang = $_COOKIE['ib_lang'] ?? '';
        if ($cookieLang !== '' && in_array($cookieLang, $validLocales, true)) {
            return $cookieLang;
        }

        // Fall back to tenant default language
        $tenantLang = $this->tenantConfig['default_lang'] ?? '';
        if ($tenantLang !== '' && in_array($tenantLang, $validLocales, true)) {
            return $tenantLang;
        }

        return 'en';
    }

    /**
     * Detect if request is HTTPS
     */
    private function isHttps(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            ($_SERVER['SERVER_PORT'] ?? 80) == 443 ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );
    }

    // -------------------------------------------------------------------------
    // Public getters
    // -------------------------------------------------------------------------

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getDb(): ?Connection
    {
        return $this->db;
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getSubdomain(): string
    {
        return $this->subdomain;
    }

    /** @return array<string, mixed> */
    public function getTenantConfig(): array
    {
        return $this->tenantConfig;
    }

    /**
     * Get the tenant-specific data directory path.
     *
     * config('tenant_data_path') is a template with {domain}, {subdomain} and
     * {host} placeholders, e.g. '/srv/intrabase/tenants/{host}'. Not set = no
     * per-tenant overrides.
     *
     * Expected subdirectories:
     *   mod/          — Module overrides (PHP files)
     *   components/   — Component overrides (PHP files)
     *   tpl/          — Template overrides
     *     panels/     — Panel overrides
     *
     * @return string|null  Path if it exists, null otherwise
     */
    public function getTenantDataPath(): ?string
    {
        $template = (string) ($this->config['tenant_data_path'] ?? '');
        if ($template === '' || $this->domain === '') {
            return null;
        }

        $host = $this->subdomain !== '' ? $this->subdomain . '.' . $this->domain : $this->domain;
        $path = strtr($template, [
            '{domain}'    => $this->domain,
            '{subdomain}' => $this->subdomain,
            '{host}'      => $host,
        ]);

        return is_dir($path) ? $path : null;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Canonical public host, without port: the host registered for the tenant
     * in ib_tenants (the one this request was resolved to), or the host of
     * config('url') on a single-tenant install.
     *
     * NEVER the Host header: links built from it go out in e-mails (password
     * reset, notifications), and an attacker who sends a request with a Host
     * of their own would get the victim's reset token on their server.
     */
    public function publicHost(): string
    {
        $domain = strtolower(trim((string) ($this->tenantConfig['domain'] ?? '')));
        if ($domain !== '' && preg_match('/^[a-z0-9.-]+$/', $domain)) {
            $sub = strtolower(trim((string) ($this->tenantConfig['subdomain'] ?? '')));
            return ($sub !== '' && preg_match('/^[a-z0-9-]+$/', $sub) && !str_starts_with($domain, $sub . '.'))
                ? $sub . '.' . $domain
                : $domain;
        }
        $fromUrl = (string) (parse_url((string) ($this->config['url'] ?? ''), PHP_URL_HOST) ?: '');
        return $fromUrl !== '' ? strtolower($fromUrl) : 'localhost';
    }

    /**
     * Absolute base for links that leave the app (e-mail, notifications):
     * publicHost() with the scheme and port of config('url') (https by default).
     */
    public function publicBaseUrl(): string
    {
        $url    = (string) ($this->config['url'] ?? '');
        $scheme = parse_url($url, PHP_URL_SCHEME) === 'http' ? 'http' : 'https';
        $port   = (int) (parse_url($url, PHP_URL_PORT) ?? 0);
        $sameHost = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? '')) === $this->publicHost();
        return $scheme . '://' . $this->publicHost() . ($port > 0 && $sameHost ? ':' . $port : '');
    }
}
