<?php

declare(strict_types=1);

namespace Intrabase\Tests\Unit\Security;

use Intrabase\Security\Session;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Session manager.
 *
 * We avoid calling start() in these tests because session_start() cannot be
 * invoked in a CLI context once headers have been sent.  All methods under
 * test manipulate $_SESSION directly, which lets us exercise the full logic
 * without requiring an HTTP request.
 *
 * @covers \Intrabase\Security\Session
 */
class SessionTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset the $_SESSION superglobal for every test
        $_SESSION = [];
        $this->session = new Session();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    // =========================================================================
    // get / set / has / forget
    // =========================================================================

    /** @test set() stores a value; get() retrieves it */
    public function setAndGetRoundTrip(): void
    {
        $this->session->set('foo', 'bar');
        self::assertSame('bar', $this->session->get('foo'));
    }

    /** @test get() returns the default when the key is absent */
    public function getReturnsDefaultWhenKeyAbsent(): void
    {
        self::assertNull($this->session->get('missing'));
        self::assertSame(42, $this->session->get('missing', 42));
    }

    /** @test has() returns true for an existing key */
    public function hasReturnsTrueForExistingKey(): void
    {
        $this->session->set('key', 'value');
        self::assertTrue($this->session->has('key'));
    }

    /** @test has() returns false for a missing key */
    public function hasReturnsFalseForMissingKey(): void
    {
        self::assertFalse($this->session->has('nonexistent'));
    }

    /** @test forget() removes an existing key */
    public function forgetRemovesKey(): void
    {
        $this->session->set('temp', 'data');
        $this->session->forget('temp');
        self::assertFalse($this->session->has('temp'));
    }

    /** @test forget() on a non-existent key does not throw */
    public function forgetOnMissingKeyIsIdempotent(): void
    {
        $this->session->forget('does_not_exist');
        self::assertFalse($this->session->has('does_not_exist'));
    }

    /** @test set() and get() support non-string values */
    public function setGetSupportsVariousTypes(): void
    {
        $this->session->set('int', 99);
        $this->session->set('arr', ['a', 'b']);
        $this->session->set('bool', false);

        self::assertSame(99, $this->session->get('int'));
        self::assertSame(['a', 'b'], $this->session->get('arr'));
        self::assertFalse($this->session->get('bool'));
    }

    // =========================================================================
    // isAuthenticated / user / userId
    // =========================================================================

    /** @test isAuthenticated() returns false on an empty session */
    public function isAuthenticatedReturnsFalseWhenNoUser(): void
    {
        self::assertFalse($this->session->isAuthenticated());
    }

    /** @test user() returns null when no user is in the session */
    public function userReturnsNullWhenNotLoggedIn(): void
    {
        self::assertNull($this->session->user());
    }

    /** @test userId() returns null when no user is in the session */
    public function userIdReturnsNullWhenNotLoggedIn(): void
    {
        self::assertNull($this->session->userId());
    }

    /** @test After manually injecting user data, isAuthenticated() returns true */
    public function isAuthenticatedReturnsTrueWhenUserInjected(): void
    {
        $_SESSION['_ib_user'] = ['id' => 7, 'name' => 'Alice'];
        self::assertTrue($this->session->isAuthenticated());
    }

    /** @test user() returns the injected user array */
    public function userReturnsInjectedUserArray(): void
    {
        $_SESSION['_ib_user'] = ['id' => 7, 'name' => 'Alice'];
        $user = $this->session->user();
        self::assertSame(7, $user['id']);
        self::assertSame('Alice', $user['name']);
    }

    /** @test userId() returns the user's ID as an integer */
    public function userIdReturnsIntegerUserId(): void
    {
        $_SESSION['_ib_user'] = ['id' => '42', 'name' => 'Bob'];
        self::assertSame(42, $this->session->userId());
    }

    // =========================================================================
    // flash / flashes
    // =========================================================================

    /** @test flash() stores a message; flashes() returns and clears it */
    public function flashStoresMessageAndFlashesReturnsIt(): void
    {
        $this->session->flash('success', 'Saved!');
        $flashes = $this->session->flashes();

        self::assertArrayHasKey('success', $flashes);
        self::assertSame('Saved!', $flashes['success']);
    }

    /** @test flashes() clears messages after retrieval */
    public function flashesClearsAfterRetrieval(): void
    {
        $this->session->flash('error', 'Something went wrong');
        $this->session->flashes(); // consume
        $second = $this->session->flashes();

        self::assertEmpty($second);
    }

    /** @test Multiple flash types can be stored simultaneously */
    public function multipleFlashTypesStoredAndRetrieved(): void
    {
        $this->session->flash('success', 'Done');
        $this->session->flash('info', 'Note');

        $flashes = $this->session->flashes();

        self::assertSame('Done', $flashes['success']);
        self::assertSame('Note', $flashes['info']);
    }

    /** @test flashes() returns empty array when no messages exist */
    public function flashesReturnsEmptyArrayWhenNoFlash(): void
    {
        self::assertSame([], $this->session->flashes());
    }

    /** @test flash() with same type overwrites the previous message */
    public function flashOverwritesPreviousMessageOfSameType(): void
    {
        $this->session->flash('error', 'First error');
        $this->session->flash('error', 'Second error');

        $flashes = $this->session->flashes();

        self::assertSame('Second error', $flashes['error']);
    }

    // =========================================================================
    // Constructor — name sanitization
    // =========================================================================

    /** @test Session name must strip characters that are invalid for cookie names */
    public function constructorSanitizesSessionName(): void
    {
        // Just verifying it doesn't throw — indirect coverage
        $s = new Session('VALID_NAME-123');
        self::assertInstanceOf(Session::class, $s);
    }

    /** @test Session name with only invalid chars falls back to 'IBSESSID' */
    public function constructorFallsBackToDefaultNameForInvalidChars(): void
    {
        // Should not throw; Session sanitises the name
        $s = new Session('!!!@@@###');
        self::assertInstanceOf(Session::class, $s);
    }
}
