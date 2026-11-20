<?php

declare(strict_types=1);

namespace Intrabase\Tests\Unit\Core;

use Intrabase\Core\Container;
use Intrabase\Core\Module;
use Intrabase\Database\Connection;
use Intrabase\Database\QueryBuilder;
use Intrabase\Lang\Lang;
use Intrabase\Security\CSRF;
use Intrabase\Security\Session;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the $apiScopeByEntity / $apiScopeByUser opt-in mechanism
 * of Module::apiList().
 *
 * These are the guardrails for cross-tenant isolation on the /api/v1/*
 * surface — see audit-module-scope.sh for the whole-project view.
 *
 * @covers \Intrabase\Core\Module
 */
final class ModuleApiScopeTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    /** @var CSRF&MockObject */
    private CSRF $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        Lang::init('en', dirname(__DIR__, 3) . '/lang');

        $this->db   = $this->createMock(Connection::class);
        $this->csrf = $this->createMock(CSRF::class);
        $this->csrf->method('token')->willReturn(str_repeat('a', 64));
    }

    public function testRejectsUnsafeIdentifierOnEntityScope(): void
    {
        $module = $this->makeModuleWithScope(
            entityCol: 'entity_id; DROP TABLE ib_users; --',
        );
        $module->setUserAccount(['id' => 1, 'entity_id' => 7]);

        $this->db->method('query')->willReturn($this->stubQb());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\$apiScopeByEntity/');

        $module->apiList();
    }

    public function testRejectsUnsafeIdentifierOnUserScope(): void
    {
        $module = $this->makeModuleWithScope(
            userCol: 'user_id) OR 1=1',
        );
        $module->setUserAccount(['id' => 1]);

        $this->db->method('query')->willReturn($this->stubQb());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\$apiScopeByUser/');

        $module->apiList();
    }

    public function testEntityScopeAddsNullSafeWhereClause(): void
    {
        $captured = ['where' => [], 'params' => []];

        // Scope predicates go through where() — parameterized, and the
        // returned clone must be reassigned (QueryBuilder is immutable).
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured, $qb) {
                $captured['where'][]  = $sql;
                $captured['params'][] = $params;
                return $qb;
            },
        );
        $qb->method('whereRaw')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('paginate')->willReturn([
            'data' => [], 'total' => 0, 'page' => 1,
            'per_page' => 25, 'pages' => 0,
        ]);

        $this->db->method('query')->willReturn($qb);

        $module = $this->makeModuleWithScope(entityCol: 'entity_id');
        $module->setUserAccount(['id' => 1, 'entity_id' => 42]);

        $module->apiList();

        // Null-safe equality operator is mandatory: <=> matches NULL = NULL.
        $found = false;
        foreach ($captured['where'] as $i => $w) {
            if (str_contains($w, 'entity_id <=> :_api_scope_eid')
                && ($captured['params'][$i]['_api_scope_eid'] ?? null) === 42
            ) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expected null-safe WHERE on entity_id with :_api_scope_eid=42');
    }

    public function testUserScopeAddsEqualityWhereClause(): void
    {
        $captured = ['where' => [], 'params' => []];

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured, $qb) {
                $captured['where'][]  = $sql;
                $captured['params'][] = $params;
                return $qb;
            },
        );
        $qb->method('whereRaw')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('paginate')->willReturn([
            'data' => [], 'total' => 0, 'page' => 1,
            'per_page' => 25, 'pages' => 0,
        ]);

        $this->db->method('query')->willReturn($qb);

        $module = $this->makeModuleWithScope(userCol: 'created_by');
        $module->setUserAccount(['id' => 7]);

        $module->apiList();

        $found = false;
        foreach ($captured['where'] as $i => $w) {
            if (str_contains($w, 'created_by = :_api_scope_uid')
                && ($captured['params'][$i]['_api_scope_uid'] ?? null) === 7
            ) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expected WHERE on created_by with :_api_scope_uid=7');
    }

    public function testNoScopeDeclaredLeavesQueryUntouched(): void
    {
        $whereRawCalls = 0;
        $whereCalls    = 0;

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('from')->willReturnSelf();
        $qb->method('whereRaw')->willReturnCallback(
            function () use (&$whereRawCalls, $qb) {
                $whereRawCalls++;
                return $qb;
            },
        );
        $qb->method('where')->willReturnCallback(
            function () use (&$whereCalls, $qb) {
                $whereCalls++;
                return $qb;
            },
        );
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('paginate')->willReturn([
            'data' => [], 'total' => 0, 'page' => 1,
            'per_page' => 25, 'pages' => 0,
        ]);

        $this->db->method('query')->willReturn($qb);

        // No scope declared (null / null), and no baseWhere either
        $module = $this->makeModuleWithScope();
        $module->setUserAccount(['id' => 1, 'entity_id' => 1]);

        $module->apiList();

        self::assertSame(0, $whereRawCalls, 'No scope declared → no whereRaw calls');
        self::assertSame(0, $whereCalls, 'No scope declared → no where calls');
    }

    public function testFindScopedAppliesBothScopePredicates(): void
    {
        $captured = ['where' => [], 'params' => []];

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('from')->willReturnSelf();
        $qb->method('limit')->willReturnSelf();
        $qb->method('where')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured, $qb) {
                $captured['where'][]  = $sql;
                $captured['params'][] = $params;
                return $qb;
            },
        );
        $qb->method('first')->willReturn(null);

        $this->db->method('query')->willReturn($qb);

        $module = $this->makeModuleWithScope(entityCol: 'entity_id', userCol: 'user_id');
        $module->setUserAccount(['id' => 7, 'entity_id' => 42]);

        self::assertNull($module->findScoped(99));

        $joined = implode(' AND ', $captured['where']);
        self::assertStringContainsString('entity_id <=> :_api_scope_eid', $joined);
        self::assertStringContainsString('user_id = :_api_scope_uid', $joined);

        $params = array_merge(...($captured['params'] ?: [[]]));
        self::assertSame(42, $params['_api_scope_eid'] ?? null);
        self::assertSame(7, $params['_api_scope_uid'] ?? null);
    }

    public function testFindScopedWithoutScopeMatchesPlainFind(): void
    {
        $captured = ['where' => []];

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('from')->willReturnSelf();
        $qb->method('limit')->willReturnSelf();
        $qb->method('where')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured, $qb) {
                $captured['where'][] = $sql;
                return $qb;
            },
        );
        $qb->method('first')->willReturn(['id' => 5]);

        $this->db->method('query')->willReturn($qb);

        $module = $this->makeModuleWithScope();
        $module->setUserAccount(['id' => 7, 'entity_id' => 42]);

        self::assertSame(['id' => 5], $module->findScoped(5));
        // Only the primary-key predicate — no scope columns declared
        self::assertCount(1, $captured['where']);
        self::assertStringContainsString('= :id', $captured['where'][0]);
    }

    public function testShareWithinEntityDropsUserScopeWhenEntityIsActive(): void
    {
        $captured = $this->captureFindScoped(share: true, account: ['id' => 7, 'entity_id' => 42]);

        $joined = implode(' AND ', $captured['where']);
        self::assertStringContainsString('entity_id <=> :_api_scope_eid', $joined);
        self::assertStringNotContainsString('user_id', $joined, 'Shared rows: any user of the entity');
    }

    public function testShareWithinEntityKeepsUserScopeInPersonalScope(): void
    {
        $captured = $this->captureFindScoped(share: true, account: ['id' => 7, 'entity_id' => null]);

        $joined = implode(' AND ', $captured['where']);
        self::assertStringContainsString('user_id = :_api_scope_uid', $joined, 'Personal rows stay private');
    }

    public function testShareWithinEntityPredicateBindsBothParams(): void
    {
        $module = $this->makeModuleWithScope(entityCol: 'entity_id', userCol: 'user_id', share: true);
        $module->setUserAccount(['id' => 7, 'entity_id' => 42]);

        ['sql' => $sql, 'params' => $params] = $module->scopePredicate();

        self::assertCount(1, $sql);
        self::assertStringContainsString('entity_id = :_scope_eid', $sql[0]);
        self::assertStringContainsString('entity_id IS NULL AND user_id = :_scope_uid', $sql[0]);
        self::assertSame(['_scope_eid' => 42, '_scope_uid' => 7], $params);
    }

    /** @return array{where: string[], params: array<int, array<string, mixed>>} */
    private function captureFindScoped(bool $share, array $account): array
    {
        $captured = ['where' => [], 'params' => []];

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('from')->willReturnSelf();
        $qb->method('limit')->willReturnSelf();
        $qb->method('where')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured, $qb) {
                $captured['where'][]  = $sql;
                $captured['params'][] = $params;
                return $qb;
            },
        );
        $qb->method('first')->willReturn(null);
        $this->db->method('query')->willReturn($qb);

        $module = $this->makeModuleWithScope(entityCol: 'entity_id', userCol: 'user_id', share: $share);
        $module->setUserAccount($account);
        $module->findScoped(99);

        return $captured;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeModuleWithScope(
        ?string $entityCol = null,
        ?string $userCol = null,
        bool $share = false,
    ): Module {
        $container = new Container();
        $session   = new Session();
        $container->instance(Session::class, $session);
        $container->instance(CSRF::class, $this->csrf);

        return new class ($this->db, $this->csrf, $container, $entityCol, $userCol, $share) extends Module {
            public string $table = 'ib_test_scope';
            public array $fields = [['name' => 'id'], ['name' => 'name']];

            public function __construct(
                Connection $db,
                CSRF $csrf,
                Container $container,
                ?string $entityCol,
                ?string $userCol,
                bool $share,
            ) {
                $this->apiScopeByEntity  = $entityCol;
                $this->apiScopeByUser    = $userCol;
                $this->shareWithinEntity = $share;
                parent::__construct($db, $csrf, $container);
            }
        };
    }

    private function stubQb(): QueryBuilder
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('from')->willReturnSelf();
        $qb->method('whereRaw')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('paginate')->willReturn([
            'data' => [], 'total' => 0, 'page' => 1,
            'per_page' => 25, 'pages' => 0,
        ]);
        return $qb;
    }
}
