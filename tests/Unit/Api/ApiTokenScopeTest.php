<?php

declare(strict_types=1);

namespace Intrabase\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Intrabase\Api\TokenScopes
 */
final class ApiTokenScopeTest extends TestCase
{
    public function testDavTokensAreRecognised(): void
    {
        self::assertTrue(\Intrabase\Api\TokenScopes::isDavOnly('dav'));
        self::assertTrue(\Intrabase\Api\TokenScopes::isDavOnly('read,dav'));
        self::assertTrue(\Intrabase\Api\TokenScopes::isDavOnly('["dav"]'));
        self::assertFalse(\Intrabase\Api\TokenScopes::isDavOnly('read,write'));
        self::assertFalse(\Intrabase\Api\TokenScopes::isDavOnly('davx'));
        self::assertFalse(\Intrabase\Api\TokenScopes::isDavOnly(''));
    }
}
