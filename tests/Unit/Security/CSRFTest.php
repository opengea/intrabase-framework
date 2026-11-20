<?php

declare(strict_types=1);

namespace Intrabase\Tests\Unit\Security;

use Intrabase\Security\CSRF;
use Intrabase\Security\Session;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for CSRF token generation, validation and regeneration.
 *
 * Uses a mock Session to isolate CSRF from the real PHP session.
 *
 * @covers \Intrabase\Security\CSRF
 */
class CSRFTest extends TestCase
{
    /** @var Session&MockObject */
    private Session $session;

    private CSRF $csrf;

    /** In-memory storage backing the mock session */
    private array $sessionStore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = $this->createMock(Session::class);

        // Wire up the mock to use an in-memory store
        $this->session
            ->method('get')
            ->willReturnCallback(function (string $key, mixed $default = null): mixed {
                return $this->sessionStore[$key] ?? $default;
            });

        $this->session
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value): void {
                $this->sessionStore[$key] = $value;
            });

        $this->csrf = new CSRF($this->session);
    }

    // =========================================================================
    // Token generation
    // =========================================================================

    /**
     * @test
     * A freshly generated token must be a 64-character lowercase hex string.
     */
    public function tokenIsA64CharHexString(): void
    {
        $token = $this->csrf->token();

        self::assertSame(64, strlen($token), 'Token must be exactly 64 characters (32 bytes hex-encoded)');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token, 'Token must be lowercase hex');
    }

    /**
     * @test
     * Calling token() twice must return the same value (idempotent within a request).
     */
    public function tokenIsIdempotent(): void
    {
        $first  = $this->csrf->token();
        $second = $this->csrf->token();

        self::assertSame($first, $second, 'token() must return the same token on repeated calls');
    }

    /**
     * @test
     * Each new CSRF instance (i.e. each fresh session) produces a unique token.
     */
    public function differentSessionsProduceDifferentTokens(): void
    {
        // Second isolated session
        $store2   = [];
        $session2 = $this->createMock(Session::class);
        $session2->method('get')->willReturnCallback(fn($k, $d = null) => $store2[$k] ?? $d);
        $session2->method('set')->willReturnCallback(function ($k, $v) use (&$store2) { $store2[$k] = $v; });

        $csrf2 = new CSRF($session2);

        self::assertNotSame($this->csrf->token(), $csrf2->token(), 'Different sessions must produce different tokens');
    }

    // =========================================================================
    // Validation — correct token
    // =========================================================================

    /**
     * @test
     * validateToken() returns true when the submitted POST token matches the session token.
     */
    public function validateTokenReturnsTrueForCorrectPostToken(): void
    {
        $token = $this->csrf->token();

        // Simulate a POST submission
        $_POST['_token'] = $token;

        self::assertTrue($this->csrf->validateToken());

        unset($_POST['_token']);
    }

    /**
     * @test
     * validateToken() returns true when the token is supplied via the X-CSRF-TOKEN header.
     */
    public function validateTokenReturnsTrueForCorrectHeader(): void
    {
        $token = $this->csrf->token();

        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        self::assertTrue($this->csrf->validateToken());

        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    // =========================================================================
    // Validation — wrong / missing token
    // =========================================================================

    /**
     * @test
     * validateToken() returns false when no token is submitted.
     */
    public function validateTokenReturnsFalseWhenNoTokenSubmitted(): void
    {
        $this->csrf->token(); // ensure a session token exists

        // Ensure superglobals are clean
        unset($_POST['_token'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        self::assertFalse($this->csrf->validateToken());
    }

    /**
     * @test
     * validateToken() returns false for an incorrect (tampered) token.
     */
    public function validateTokenReturnsFalseForWrongToken(): void
    {
        $this->csrf->token();

        $_POST['_token'] = str_repeat('0', 64); // wrong token

        self::assertFalse($this->csrf->validateToken());

        unset($_POST['_token']);
    }

    /**
     * @test
     * validateToken() returns false when there is no token in the session yet.
     */
    public function validateTokenReturnsFalseWhenSessionHasNoToken(): void
    {
        // Do NOT call token() — session is empty
        $_POST['_token'] = str_repeat('a', 64);

        self::assertFalse($this->csrf->validateToken());

        unset($_POST['_token']);
    }

    /**
     * @test
     * validateToken() must not be vulnerable to timing attacks.
     * hash_equals() is used internally; this test just verifies that a
     * slightly-off token (same length, one character different) is rejected.
     */
    public function validateTokenRejectsNearlyCorrectToken(): void
    {
        $token = $this->csrf->token();

        // Flip the last character
        $tampered = substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a');

        $_POST['_token'] = $tampered;

        self::assertFalse($this->csrf->validateToken());

        unset($_POST['_token']);
    }

    // =========================================================================
    // Token regeneration
    // =========================================================================

    /**
     * @test
     * regenerate() must produce a new token that is different from the previous one.
     * (There is an astronomically small probability of collision — acceptable.)
     */
    public function regenerateProducesANewToken(): void
    {
        $before = $this->csrf->token();
        $this->csrf->regenerate();
        $after = $this->csrf->token();

        self::assertNotSame($before, $after, 'regenerate() must replace the old token');
    }

    /**
     * @test
     * After regeneration the old token must no longer validate.
     */
    public function oldTokenIsInvalidAfterRegeneration(): void
    {
        $oldToken = $this->csrf->token();
        $this->csrf->regenerate();

        $_POST['_token'] = $oldToken;

        self::assertFalse($this->csrf->validateToken(), 'Old token must be invalid after regeneration');

        unset($_POST['_token']);
    }

    /**
     * @test
     * After regeneration the new token must validate.
     */
    public function newTokenIsValidAfterRegeneration(): void
    {
        $this->csrf->token(); // create initial token
        $this->csrf->regenerate();

        $newToken = $this->csrf->token();
        $_POST['_token'] = $newToken;

        self::assertTrue($this->csrf->validateToken(), 'New token must be valid after regeneration');

        unset($_POST['_token']);
    }

    // =========================================================================
    // HTML helpers
    // =========================================================================

    /**
     * @test
     * field() must render a hidden input containing the token.
     */
    public function fieldRendersHiddenInput(): void
    {
        $token = $this->csrf->token();
        $html  = $this->csrf->field();

        self::assertStringContainsString('type="hidden"', $html);
        self::assertStringContainsString('name="_token"', $html);
        self::assertStringContainsString('value="' . $token . '"', $html);
    }

    /**
     * @test
     * meta() must render a <meta> tag with name="csrf-token".
     */
    public function metaRendersMetaTag(): void
    {
        $token = $this->csrf->token();
        $html  = $this->csrf->meta();

        self::assertStringContainsString('<meta', $html);
        self::assertStringContainsString('name="csrf-token"', $html);
        self::assertStringContainsString('content="' . $token . '"', $html);
    }
}
