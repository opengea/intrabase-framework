<?php

declare(strict_types=1);

namespace Intrabase\Tests\Unit\Security;

use Intrabase\Security\Output;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Output escaping and encoding helpers.
 *
 * @covers \Intrabase\Security\Output
 */
class OutputTest extends TestCase
{
    // =========================================================================
    // Output::e() — HTML escaping
    // =========================================================================

    /**
     * @test
     * e() must escape the < character.
     */
    public function eEscapesLessThan(): void
    {
        self::assertSame('&lt;script&gt;', Output::e('<script>'));
    }

    /**
     * @test
     * e() must escape the > character.
     */
    public function eEscapesGreaterThan(): void
    {
        self::assertSame('1 &gt; 0', Output::e('1 > 0'));
    }

    /**
     * @test
     * e() must escape double quotes.
     */
    public function eEscapesDoubleQuotes(): void
    {
        self::assertSame('say &quot;hello&quot;', Output::e('say "hello"'));
    }

    /**
     * @test
     * e() must escape single quotes.
     */
    public function eEscapesSingleQuotes(): void
    {
        self::assertSame('it&#039;s', Output::e("it's"));
    }

    /**
     * @test
     * e() must escape ampersands.
     */
    public function eEscapesAmpersand(): void
    {
        self::assertSame('cats &amp; dogs', Output::e('cats & dogs'));
    }

    /**
     * @test
     * e() must handle all special characters in a combined string.
     */
    public function eEscapesAllSpecialCharacters(): void
    {
        $input    = '<a href="page" onclick=\'alert("XSS")\'>Click & go</a>';
        $expected = '&lt;a href=&quot;page&quot; onclick=&#039;alert(&quot;XSS&quot;)&#039;&gt;Click &amp; go&lt;/a&gt;';

        self::assertSame($expected, Output::e($input));
    }

    /**
     * @test
     * e() with a safe string must return it unchanged.
     */
    public function eDoesNotAlterSafeStrings(): void
    {
        self::assertSame('Hello, world!', Output::e('Hello, world!'));
    }

    /**
     * @test
     * e() accepts non-string scalars (cast to string).
     */
    public function eHandlesNonStringInput(): void
    {
        self::assertSame('42', Output::e(42));
        self::assertSame('3.14', Output::e(3.14));
        self::assertSame('1', Output::e(true));
        self::assertSame('', Output::e(false));
    }

    // =========================================================================
    // Output::url() — URL sanitization
    // =========================================================================

    /**
     * @test
     * url() returns '#' for javascript: URIs.
     */
    public function urlReturnsFallbackForJavascriptScheme(): void
    {
        self::assertSame('#', Output::url('javascript:alert(1)'));
        self::assertSame('#', Output::url('javascript://comment%0aalert(1)'));
        self::assertSame('#', Output::url('JAVASCRIPT:void(0)'));
    }

    /**
     * @test
     * url() returns '#' for data: URIs.
     */
    public function urlReturnsFallbackForDataUris(): void
    {
        self::assertSame('#', Output::url('data:text/html,<h1>XSS</h1>'));
        self::assertSame('#', Output::url('data:image/svg+xml,<svg onload=alert(1)>'));
    }

    /**
     * @test
     * url() allows http URLs.
     */
    public function urlAllowsHttpUrls(): void
    {
        $url = Output::url('http://example.com/page');

        self::assertStringContainsString('http://example.com/page', $url);
        self::assertNotSame('#', $url);
    }

    /**
     * @test
     * url() allows https URLs.
     */
    public function urlAllowsHttpsUrls(): void
    {
        $url = Output::url('https://example.com/path?q=1');

        self::assertStringContainsString('https://example.com/path', $url);
        self::assertNotSame('#', $url);
    }

    /**
     * @test
     * url() allows relative paths starting with '/'.
     */
    public function urlAllowsAbsoluteRelativePaths(): void
    {
        $url = Output::url('/some/relative/path');

        self::assertStringContainsString('/some/relative/path', $url);
        self::assertNotSame('#', $url);
    }

    /**
     * @test
     * url() allows paths starting with './'.
     */
    public function urlAllowsDotSlashRelativePaths(): void
    {
        $url = Output::url('./relative/path');

        self::assertStringContainsString('./relative/path', $url);
        self::assertNotSame('#', $url);
    }

    /**
     * @test
     * url() allows parent-directory relative paths.
     */
    public function urlAllowsParentRelativePaths(): void
    {
        $url = Output::url('../up/one/level');

        self::assertStringContainsString('../up/one/level', $url);
        self::assertNotSame('#', $url);
    }

    // =========================================================================
    // Output::date() — date formatting
    // =========================================================================

    /**
     * @test
     * date() returns empty string for '0000-00-00'.
     */
    public function dateReturnsEmptyForZeroDate(): void
    {
        self::assertSame('', Output::date('0000-00-00'));
    }

    /**
     * @test
     * date() returns empty string for '0000-00-00 00:00:00'.
     */
    public function dateReturnsEmptyForZeroDatetime(): void
    {
        self::assertSame('', Output::date('0000-00-00 00:00:00'));
    }

    /**
     * @test
     * date() returns empty string for an empty value.
     */
    public function dateReturnsEmptyForEmptyString(): void
    {
        self::assertSame('', Output::date(''));
    }

    /**
     * @test
     * date() returns empty string for a completely invalid date string.
     */
    public function dateReturnsEmptyForInvalidDate(): void
    {
        self::assertSame('', Output::date('not-a-date'));
    }

    /**
     * @test
     * date() formats a valid date with the default d/m/Y format.
     */
    public function dateFormatsValidDateWithDefaultFormat(): void
    {
        self::assertSame('15/06/2024', Output::date('2024-06-15'));
    }

    /**
     * @test
     * date() respects a custom format string.
     */
    public function dateRespectsCustomFormat(): void
    {
        self::assertSame('2024-06-15', Output::date('2024-06-15', 'Y-m-d'));
        self::assertSame('June 15, 2024', Output::date('2024-06-15', 'F j, Y'));
    }

    // =========================================================================
    // Output::js() — JavaScript encoding
    // =========================================================================

    /**
     * @test
     * js() encodes an array to a JSON literal safe for inline scripts.
     */
    public function jsEncodesArrayCorrectly(): void
    {
        $result = Output::js(['key' => 'value', 'num' => 42]);

        $decoded = json_decode($result, true);

        self::assertIsArray($decoded);
        self::assertSame('value', $decoded['key']);
        self::assertSame(42, $decoded['num']);
    }

    /**
     * @test
     * js() encodes a plain string correctly.
     */
    public function jsEncodesStringCorrectly(): void
    {
        $result = Output::js('hello world');

        self::assertSame('"hello world"', $result);
    }

    /**
     * @test
     * js() encodes integers and booleans correctly.
     */
    public function jsEncodesScalars(): void
    {
        self::assertSame('42', Output::js(42));
        self::assertSame('true', Output::js(true));
        self::assertSame('false', Output::js(false));
        self::assertSame('null', Output::js(null));
    }

    /**
     * @test
     * js() must use JSON_HEX_TAG to escape < and > to prevent </script> injection.
     */
    public function jsEscapesAngleBrackets(): void
    {
        $result = Output::js('<script>alert(1)</script>');

        // JSON_HEX_TAG encodes < as \u003C and > as \u003E
        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('\u003C', $result);
        self::assertStringContainsString('\u003E', $result);
    }

    /**
     * @test
     * js() must use JSON_HEX_AMP to escape & to prevent premature entity parsing.
     */
    public function jsEscapesAmpersands(): void
    {
        $result = Output::js('cats & dogs');

        self::assertStringNotContainsString('& ', $result);
        self::assertStringContainsString('\u0026', $result);
    }

    /**
     * @test
     * js() must use JSON_HEX_APOS and JSON_HEX_QUOT to escape quotes.
     */
    public function jsEscapesQuotes(): void
    {
        $result = Output::js('He said "hello" and she\'s fine');

        self::assertStringNotContainsString('"hello"', $result);
        self::assertStringContainsString('\u0022', $result);
        self::assertStringContainsString('\u0027', $result);
    }
}
