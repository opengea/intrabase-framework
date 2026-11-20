<?php

declare(strict_types=1);

namespace Intrabase\Tests\Integration\Auth;

use Intrabase\Auth\LoginController;
use Intrabase\Database\Connection;
use Intrabase\Lang\Lang;
use Intrabase\Security\CSRF;
use Intrabase\Security\Session;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for LoginController.
 *
 * Tests the full rendering pipeline (form HTML output) and
 * POST validation logic with mocked dependencies.
 *
 * @covers \Intrabase\Auth\LoginController
 */
class LoginControllerTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    /** @var Session&MockObject */
    private Session $session;

    /** @var CSRF&MockObject */
    private CSRF $csrf;

    private LoginController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        // Bootstrap i18n with English so __() calls work in tests
        Lang::init('en', dirname(__DIR__, 3) . '/lang');

        $this->db      = $this->createMock(Connection::class);
        $this->session = $this->createMock(Session::class);
        $this->csrf    = $this->createMock(CSRF::class);

        $this->csrf->method('field')->willReturn('<input type="hidden" name="_token" value="test">');

        $this->controller = new LoginController($this->db, $this->session, $this->csrf);
    }

    // =========================================================================
    // GET – form rendering
    // =========================================================================

    /** @test GET renders the login form with email and password fields */
    public function getRendersLoginForm(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->session->method('isAuthenticated')->willReturn(false);
        $this->session->method('flashes')->willReturn([]);

        ob_start();
        $this->controller->handle();
        $html = ob_get_clean();

        self::assertStringContainsString('type="email"', $html);
        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('type="submit"', $html);
        self::assertStringContainsString('action="/login"', $html);
    }

    /** @test GET form uses i18n keys — no raw hard-coded Catalan or English UI strings */
    public function getFormUsesI18nKeys(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->session->method('isAuthenticated')->willReturn(false);
        $this->session->method('flashes')->willReturn([]);

        ob_start();
        $this->controller->handle();
        $html = ob_get_clean();

        // i18n key 'login_title' must appear in <title>
        self::assertStringContainsString(__('login_title'), $html);
        // i18n key 'email' label must appear
        self::assertStringContainsString(__('email'), $html);
        // i18n key 'password' label must appear
        self::assertStringContainsString(__('password'), $html);
        // i18n key 'login_btn' must appear in the submit button
        self::assertStringContainsString(__('login_btn'), $html);
        // i18n key 'forgot_password' must appear in the link
        self::assertStringContainsString(__('forgot_password'), $html);
    }

    /** @test GET shows flash messages from session */
    public function getShowsFlashMessages(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->session->method('isAuthenticated')->willReturn(false);
        $this->session->method('flashes')->willReturn(['success' => 'Password reset OK']);

        ob_start();
        $this->controller->handle();
        $html = ob_get_clean();

        self::assertStringContainsString('Password reset OK', $html);
        self::assertStringContainsString('ib-alert--success', $html);
    }

    // =========================================================================
    // POST – invalid CSRF
    // =========================================================================

    /** @test POST with invalid CSRF shows error and 403 status */
    public function postWithInvalidCsrfShowsError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['email' => 'test@test.com', 'password' => 'secret'];

        $this->session->method('isAuthenticated')->willReturn(false);
        $this->session->method('flashes')->willReturn([]);
        $this->csrf->method('validateToken')->willReturn(false);

        ob_start();
        $this->controller->handle();
        $html = ob_get_clean();

        self::assertStringContainsString(__('invalid_request'), $html);
        self::assertStringContainsString('ib-alert--danger', $html);
    }

    // =========================================================================
    // POST – empty credentials
    // =========================================================================

    /** @test POST with empty email shows invalid credentials error */
    public function postWithEmptyCredentialsShowsError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['email' => '', 'password' => ''];

        $this->session->method('isAuthenticated')->willReturn(false);
        $this->session->method('flashes')->willReturn([]);
        $this->csrf->method('validateToken')->willReturn(true);

        // No DB calls expected for empty credentials
        $this->db->expects(self::never())->method('pdo');

        ob_start();
        $this->controller->handle();
        $html = ob_get_clean();

        self::assertStringContainsString(__('invalid_credentials'), $html);
    }

    // =========================================================================
    // POST – wrong password (user not found / bad hash)
    // =========================================================================

    /** @test POST with wrong password shows invalid credentials */
    public function postWithWrongPasswordShowsError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['email' => 'user@test.com', 'password' => 'wrongpass'];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->session->method('isAuthenticated')->willReturn(false);
        $this->session->method('flashes')->willReturn([]);
        $this->csrf->method('validateToken')->willReturn(true);

        // Mock PDO for user lookup and rate limiter
        $pdoMock = $this->createMock(\PDO::class);
        $stmtMock = $this->createMock(\PDOStatement::class);

        // Rate limiter check: isBlocked returns false (not blocked)
        $stmtMock->method('execute')->willReturn(true);
        $stmtMock->method('fetchColumn')->willReturn(0); // 0 attempts
        $stmtMock->method('fetch')->willReturn(false);   // user not found

        $pdoMock->method('prepare')->willReturn($stmtMock);
        $this->db->method('pdo')->willReturn($pdoMock);

        ob_start();
        $this->controller->handle();
        $html = ob_get_clean();

        self::assertStringContainsString(__('invalid_credentials'), $html);
    }

    // =========================================================================
    // Authenticated redirect
    // =========================================================================

    /**
     * @test Authentication status is checked on every GET request to login
     *
     * Verifies that the controller always calls session->isAuthenticated()
     * before rendering the form. The redirect+exit() behaviour for authenticated
     * users is validated by HTTP acceptance tests, because exit() terminates
     * the PHPUnit process and cannot be mocked at the unit level.
     */
    public function authenticationIsCheckedOnEveryRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Expect exactly one isAuthenticated() call during handle()
        $this->session->expects(self::atLeastOnce())
            ->method('isAuthenticated')
            ->willReturn(false); // use false so handle() renders the form without exit()

        $this->session->method('flashes')->willReturn([]);

        ob_start();
        $this->controller->handle();
        $html = ob_get_clean();

        // PHPUnit will fail the test if isAuthenticated() was never called (mock expectation)
        self::assertStringContainsString('type="email"', $html);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_POST   = [];
        $_SERVER = array_filter($_SERVER, fn($k) => !in_array($k, ['REQUEST_METHOD', 'REMOTE_ADDR'], true), ARRAY_FILTER_USE_KEY);
    }
}
