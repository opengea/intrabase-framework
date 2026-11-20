<?php

declare(strict_types=1);

namespace Intrabase\Tests\Unit\Security;

use Intrabase\Security\Input;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Input sanitization and validation helpers.
 *
 * @covers \Intrabase\Security\Input
 */
class InputTest extends TestCase
{
    // =========================================================================
    // sanitizeString
    // =========================================================================

    /**
     * @test
     * sanitizeString must strip all HTML tags from the input.
     */
    public function sanitizeStringStripsHtmlTags(): void
    {
        self::assertSame('Hello world', Input::sanitizeString('<b>Hello</b> <i>world</i>'));
        // strip_tags() removes tags but preserves text content inside them
        self::assertSame('alert(1)', Input::sanitizeString('<script>alert(1)</script>'));
        self::assertSame('', Input::sanitizeString('<script></script>'));
        self::assertSame('plain text', Input::sanitizeString('plain text'));
    }

    /**
     * @test
     * sanitizeString must trim leading and trailing whitespace.
     */
    public function sanitizeStringTrimsWhitespace(): void
    {
        self::assertSame('hello', Input::sanitizeString('  hello  '));
        self::assertSame('hello world', Input::sanitizeString("\t hello world \n"));
    }

    /**
     * @test
     * sanitizeString must preserve internal whitespace.
     */
    public function sanitizeStringPreservesInternalWhitespace(): void
    {
        self::assertSame('hello world', Input::sanitizeString('hello world'));
    }

    /**
     * @test
     * sanitizeString on empty string returns empty string.
     */
    public function sanitizeStringHandlesEmptyString(): void
    {
        self::assertSame('', Input::sanitizeString(''));
        self::assertSame('', Input::sanitizeString('   '));
    }

    // =========================================================================
    // sanitizeDate
    // =========================================================================

    /**
     * @test
     * sanitizeDate accepts valid ISO dates (YYYY-MM-DD).
     */
    public function sanitizeDateAcceptsValidDates(): void
    {
        self::assertSame('2024-01-15', Input::sanitizeDate('2024-01-15'));
        self::assertSame('2000-02-29', Input::sanitizeDate('2000-02-29')); // leap year
        self::assertSame('1999-12-31', Input::sanitizeDate('1999-12-31'));
    }

    /**
     * @test
     * sanitizeDate rejects invalid dates.
     */
    public function sanitizeDateRejectsInvalidDates(): void
    {
        // Wrong format
        self::assertSame('', Input::sanitizeDate('15/01/2024'));
        self::assertSame('', Input::sanitizeDate('2024-1-5'));
        self::assertSame('', Input::sanitizeDate('not-a-date'));
        self::assertSame('', Input::sanitizeDate(''));

        // Valid format but impossible dates
        self::assertSame('', Input::sanitizeDate('2023-02-29')); // 2023 is not a leap year
        self::assertSame('', Input::sanitizeDate('2024-13-01')); // month 13 doesn't exist
        self::assertSame('', Input::sanitizeDate('2024-00-15')); // month 0 doesn't exist
        self::assertSame('', Input::sanitizeDate('2024-06-31')); // June has 30 days
    }

    /**
     * @test
     * sanitizeDate trims surrounding whitespace before validating.
     */
    public function sanitizeDateTrimsWhitespace(): void
    {
        self::assertSame('2024-06-15', Input::sanitizeDate('  2024-06-15  '));
    }

    // =========================================================================
    // isEmail
    // =========================================================================

    /**
     * @test
     * isEmail returns true for valid email addresses.
     */
    public function isEmailReturnsTrueForValidEmails(): void
    {
        self::assertTrue(Input::isEmail('user@example.com'));
        self::assertTrue(Input::isEmail('user+tag@example.co.uk'));
        self::assertTrue(Input::isEmail('firstname.lastname@domain.org'));
        self::assertTrue(Input::isEmail('user123@sub.domain.com'));
    }

    /**
     * @test
     * isEmail returns false for invalid email addresses.
     */
    public function isEmailReturnsFalseForInvalidEmails(): void
    {
        self::assertFalse(Input::isEmail('not-an-email'));
        self::assertFalse(Input::isEmail('@domain.com'));
        self::assertFalse(Input::isEmail('user@'));
        self::assertFalse(Input::isEmail('user @domain.com')); // space inside
        self::assertFalse(Input::isEmail(''));
        self::assertFalse(Input::isEmail('user@domain'));      // no TLD
    }

    // =========================================================================
    // isUrl
    // =========================================================================

    /**
     * @test
     * isUrl returns true for valid http and https URLs.
     */
    public function isUrlReturnsTrueForHttpAndHttps(): void
    {
        self::assertTrue(Input::isUrl('http://example.com'));
        self::assertTrue(Input::isUrl('https://example.com'));
        self::assertTrue(Input::isUrl('https://example.com/path?query=1#fragment'));
        self::assertTrue(Input::isUrl('http://subdomain.example.co.uk/page'));
    }

    /**
     * @test
     * isUrl blocks javascript: URIs to prevent XSS.
     */
    public function isUrlBlocksJavascriptScheme(): void
    {
        self::assertFalse(Input::isUrl('javascript:alert(1)'));
        self::assertFalse(Input::isUrl('javascript://comment%0aalert(1)'));
        self::assertFalse(Input::isUrl('JAVASCRIPT:alert(1)'));
    }

    /**
     * @test
     * isUrl blocks ftp: and other non-web schemes.
     */
    public function isUrlBlocksFtpAndOtherSchemes(): void
    {
        self::assertFalse(Input::isUrl('ftp://files.example.com'));
        self::assertFalse(Input::isUrl('file:///etc/passwd'));
        self::assertFalse(Input::isUrl('data:text/html,<h1>XSS</h1>'));
        self::assertFalse(Input::isUrl('mailto:user@example.com'));
    }

    /**
     * @test
     * isUrl returns false for non-URLs.
     */
    public function isUrlReturnsFalseForNonUrls(): void
    {
        self::assertFalse(Input::isUrl(''));
        self::assertFalse(Input::isUrl('not a url'));
        self::assertFalse(Input::isUrl('/relative/path'));
    }

    // =========================================================================
    // validate — required rule
    // =========================================================================

    /**
     * @test
     * validate() returns an error for empty required fields.
     */
    public function validateRequiredFailsOnEmptyValue(): void
    {
        $_POST['name'] = '';

        $errors = Input::validate(['name' => ['required']]);

        self::assertArrayHasKey('name', $errors);
        self::assertNotEmpty($errors['name']);

        unset($_POST['name']);
    }

    /**
     * @test
     * validate() passes when a required field has a value.
     */
    public function validateRequiredPassesWhenValuePresent(): void
    {
        $_POST['name'] = 'John Doe';

        $errors = Input::validate(['name' => ['required']]);

        self::assertArrayNotHasKey('name', $errors);

        unset($_POST['name']);
    }

    // =========================================================================
    // validate — email rule
    // =========================================================================

    /**
     * @test
     * validate() returns an error for an invalid email.
     */
    public function validateEmailFailsForInvalidEmail(): void
    {
        $_POST['email'] = 'not-valid';

        $errors = Input::validate(['email' => ['email']]);

        self::assertArrayHasKey('email', $errors);

        unset($_POST['email']);
    }

    /**
     * @test
     * validate() passes for a valid email.
     */
    public function validateEmailPassesForValidEmail(): void
    {
        $_POST['email'] = 'user@example.com';

        $errors = Input::validate(['email' => ['email']]);

        self::assertArrayNotHasKey('email', $errors);

        unset($_POST['email']);
    }

    // =========================================================================
    // validate — max:N rule
    // =========================================================================

    /**
     * @test
     * validate() enforces maximum length.
     */
    public function validateMaxFailsWhenExceedingLimit(): void
    {
        $_POST['title'] = str_repeat('a', 256);

        $errors = Input::validate(['title' => ['max:255']]);

        self::assertArrayHasKey('title', $errors);

        unset($_POST['title']);
    }

    /**
     * @test
     * validate() passes when at or below maximum length.
     */
    public function validateMaxPassesWhenWithinLimit(): void
    {
        $_POST['title'] = str_repeat('a', 255);

        $errors = Input::validate(['title' => ['max:255']]);

        self::assertArrayNotHasKey('title', $errors);

        unset($_POST['title']);
    }

    // =========================================================================
    // validate — min:N rule
    // =========================================================================

    /**
     * @test
     * validate() enforces minimum length.
     */
    public function validateMinFailsBelowMinimum(): void
    {
        $_POST['password'] = 'abc';

        $errors = Input::validate(['password' => ['min:8']]);

        self::assertArrayHasKey('password', $errors);

        unset($_POST['password']);
    }

    /**
     * @test
     * validate() passes when at or above minimum length.
     */
    public function validateMinPassesAtMinimum(): void
    {
        $_POST['password'] = 'abcdefgh'; // 8 chars

        $errors = Input::validate(['password' => ['min:8']]);

        self::assertArrayNotHasKey('password', $errors);

        unset($_POST['password']);
    }

    // =========================================================================
    // validate — combined rules
    // =========================================================================

    /**
     * @test
     * required rule skips further checks when the field is empty
     * (prevents noisy duplicate errors).
     */
    public function validateRequiredSkipsFurtherRulesOnEmpty(): void
    {
        $_POST['email'] = '';

        $errors = Input::validate(['email' => ['required', 'email']]);

        // Should have exactly ONE error (required), not two
        self::assertCount(1, $errors['email']);

        unset($_POST['email']);
    }

    /**
     * @test
     * Multiple fields can be validated in a single call.
     */
    public function validateHandlesMultipleFieldsAtOnce(): void
    {
        $_POST['name']  = '';
        $_POST['email'] = 'bad-email';
        $_POST['bio']   = 'Hello';

        $errors = Input::validate([
            'name'  => ['required'],
            'email' => ['required', 'email'],
            'bio'   => ['max:1000'],
        ]);

        self::assertArrayHasKey('name', $errors);
        self::assertArrayHasKey('email', $errors);
        self::assertArrayNotHasKey('bio', $errors);

        unset($_POST['name'], $_POST['email'], $_POST['bio']);
    }
}
