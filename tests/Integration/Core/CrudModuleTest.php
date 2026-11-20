<?php

declare(strict_types=1);

namespace Intrabase\Tests\Integration\Core;

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
 * Integration tests for Module CRUD operations.
 *
 * Tests module metadata, validation logic, and CRUD methods
 * using a concrete minimal module stub with mocked database.
 *
 * @covers \Intrabase\Core\Module
 */
class CrudModuleTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    /** @var CSRF&MockObject */
    private CSRF $csrf;

    private Container $container;
    private MinimalModule $module;

    protected function setUp(): void
    {
        parent::setUp();

        Lang::init('en', dirname(__DIR__, 3) . '/lang');

        $this->db   = $this->createMock(Connection::class);
        $this->csrf = $this->createMock(CSRF::class);
        $this->csrf->method('token')->willReturn(str_repeat('a', 64));

        $this->container = new Container();
        $session = new Session();
        $this->container->instance(Session::class, $session);
        $this->container->instance(CSRF::class, $this->csrf);

        $this->module = new MinimalModule($this->db, $this->csrf, $this->container);
    }

    // =========================================================================
    // Module metadata
    // =========================================================================

    /** @test Module name is derived from class name in snake_case */
    public function moduleNameIsSnakeCase(): void
    {
        // MinimalModule → minimal_module
        self::assertSame('minimal_module', $this->module->getModuleName());
    }

    /** @test canAdd returns true by default */
    public function canAddReturnsTrueByDefault(): void
    {
        self::assertTrue($this->module->canAdd());
    }

    /** @test canEdit returns true by default */
    public function canEditReturnsTrueByDefault(): void
    {
        self::assertTrue($this->module->canEdit());
    }

    /** @test canDelete returns true by default */
    public function canDeleteReturnsTrueByDefault(): void
    {
        self::assertTrue($this->module->canDelete());
    }

    // =========================================================================
    // Fields definition
    // =========================================================================

    /** @test getFields returns the configured field list */
    public function getFieldsReturnsDefinedFields(): void
    {
        $fields = $this->module->getFields();

        self::assertIsArray($fields);
        self::assertCount(2, $fields);
        self::assertSame('name', $fields[0]['name']);
        self::assertSame('email', $fields[1]['name']);
    }

    /** @test getEditFields falls back to getFields when editFields is empty */
    public function getEditFieldsFallsBackToGetFields(): void
    {
        self::assertSame($this->module->getFields(), $this->module->getEditFields());
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /** @test validateData catches missing required field */
    public function validateDataCatchesMissingRequired(): void
    {
        $errors = $this->module->validateData([]);

        self::assertArrayHasKey('name', $errors);
        self::assertStringContainsString('required', $errors['name']);
    }

    /** @test validateData passes when all required fields are present */
    public function validateDataPassesWithRequiredFields(): void
    {
        $errors = $this->module->validateData(['name' => 'Test Record']);

        self::assertEmpty($errors);
    }

    /** @test validateData catches max_length rule violation */
    public function validateDataCatchesMaxLength(): void
    {
        $errors = $this->module->validateData([
            'name' => str_repeat('x', 256),
        ]);

        self::assertArrayHasKey('name', $errors);
        self::assertStringContainsString('255', $errors['name']);
    }

    /** @test validateData catches invalid email format */
    public function validateDataCatchesInvalidEmail(): void
    {
        $errors = $this->module->validateData([
            'name'  => 'Valid Name',
            'email' => 'not-an-email',
        ]);

        self::assertArrayHasKey('email', $errors);
        self::assertStringContainsString('email', strtolower($errors['email']));
    }

    /** @test validateData passes with valid email */
    public function validateDataPassesWithValidEmail(): void
    {
        $errors = $this->module->validateData([
            'name'  => 'Valid Name',
            'email' => 'valid@example.com',
        ]);

        self::assertEmpty($errors);
    }

    /** @test validateData ignores email rule when email field is empty */
    public function validateDataSkipsEmailRuleWhenEmpty(): void
    {
        $errors = $this->module->validateData([
            'name'  => 'Valid Name',
            'email' => '',
        ]);

        // email is not required, and empty value skips format validation
        self::assertArrayNotHasKey('email', $errors);
    }

    // =========================================================================
    // Pagination & ordering defaults
    // =========================================================================

    /** @test getPerPage returns a positive integer */
    public function getPerPageReturnsPositiveInteger(): void
    {
        self::assertGreaterThan(0, $this->module->getPerPage());
    }

    /** @test getOrderBy returns the primary key when orderBy is not set */
    public function getOrderByReturnsKeyWhenNotSet(): void
    {
        self::assertNotEmpty($this->module->getOrderBy());
    }

    /** @test getOrderDir returns ASC or DESC */
    public function getOrderDirReturnsAscOrDesc(): void
    {
        self::assertContains($this->module->getOrderDir(), ['ASC', 'DESC']);
    }

    // =========================================================================
    // CRUD — find()
    // =========================================================================

    /** @test find() returns null when record does not exist */
    public function findReturnsNullForMissingRecord(): void
    {
        $qbMock = $this->createMock(QueryBuilder::class);
        $qbMock->method('from')->willReturnSelf();
        $qbMock->method('where')->willReturnSelf();
        $qbMock->method('limit')->willReturnSelf();
        $qbMock->method('first')->willReturn(null);

        $this->db->method('query')->willReturn($qbMock);

        self::assertNull($this->module->find(999));
    }

    /** @test find() returns the record array when it exists */
    public function findReturnsRecordArray(): void
    {
        $record = ['id' => 1, 'name' => 'Test', 'email' => 'test@example.com'];

        $qbMock = $this->createMock(QueryBuilder::class);
        $qbMock->method('from')->willReturnSelf();
        $qbMock->method('where')->willReturnSelf();
        $qbMock->method('limit')->willReturnSelf();
        $qbMock->method('first')->willReturn($record);

        $this->db->method('query')->willReturn($qbMock);

        self::assertSame($record, $this->module->find(1));
    }

    // =========================================================================
    // CRUD — all()
    // =========================================================================

    /** @test all() returns empty array when table is empty */
    public function allReturnsEmptyArrayWhenNoRecords(): void
    {
        $qbMock = $this->createMock(QueryBuilder::class);
        $qbMock->method('from')->willReturnSelf();
        $qbMock->method('orderBy')->willReturnSelf();
        $qbMock->method('get')->willReturn([]);

        $this->db->method('query')->willReturn($qbMock);

        self::assertSame([], $this->module->all());
    }

    /** @test all() returns list of records */
    public function allReturnsRecordList(): void
    {
        $records = [
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
            ['id' => 2, 'name' => 'Bob',   'email' => 'bob@example.com'],
        ];

        $qbMock = $this->createMock(QueryBuilder::class);
        $qbMock->method('from')->willReturnSelf();
        $qbMock->method('orderBy')->willReturnSelf();
        $qbMock->method('get')->willReturn($records);

        $this->db->method('query')->willReturn($qbMock);

        self::assertCount(2, $this->module->all());
        self::assertSame('Alice', $this->module->all()[0]['name']);
    }

    // =========================================================================
    // CRUD — create() and update()
    // =========================================================================

    /** @test create() returns the new record ID */
    public function createReturnsNewRecordId(): void
    {
        $qbMock = $this->createMock(QueryBuilder::class);
        $qbMock->method('from')->willReturnSelf();
        $qbMock->method('where')->willReturnSelf();
        $qbMock->method('limit')->willReturnSelf();
        $qbMock->method('first')->willReturn(null);
        $qbMock->method('insert')->willReturn(42);

        // Also mock the audit log PDO call
        $pdoMock  = $this->createMock(\PDO::class);
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $pdoMock->method('prepare')->willReturn($stmtMock);
        $this->db->method('pdo')->willReturn($pdoMock);
        $this->db->method('query')->willReturn($qbMock);

        $newId = $this->module->create(['name' => 'New Record']);

        self::assertSame(42, $newId);
    }

    /** @test update() returns true on success */
    public function updateReturnsTrueOnSuccess(): void
    {
        $qbMock = $this->createMock(QueryBuilder::class);
        $qbMock->method('from')->willReturnSelf();
        $qbMock->method('where')->willReturnSelf();
        $qbMock->method('limit')->willReturnSelf();
        $qbMock->method('first')->willReturn(['id' => 1, 'name' => 'Old']);
        $qbMock->method('update')->willReturn(true);

        $pdoMock  = $this->createMock(\PDO::class);
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $pdoMock->method('prepare')->willReturn($stmtMock);
        $this->db->method('pdo')->willReturn($pdoMock);
        $this->db->method('query')->willReturn($qbMock);

        self::assertTrue($this->module->update(1, ['name' => 'Updated']));
    }

    // =========================================================================
    // CRUD — delete()
    // =========================================================================

    /** @test delete() returns true on success */
    public function deleteReturnsTrueOnSuccess(): void
    {
        $qbMock = $this->createMock(QueryBuilder::class);
        $qbMock->method('from')->willReturnSelf();
        $qbMock->method('where')->willReturnSelf();
        $qbMock->method('limit')->willReturnSelf();
        $qbMock->method('first')->willReturn(['id' => 1, 'name' => 'ToDelete']);
        $qbMock->method('delete')->willReturn(true);

        $pdoMock  = $this->createMock(\PDO::class);
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $pdoMock->method('prepare')->willReturn($stmtMock);
        $this->db->method('pdo')->willReturn($pdoMock);
        $this->db->method('query')->willReturn($qbMock);

        self::assertTrue($this->module->delete(1));
    }
}

/**
 * Minimal concrete Module for CRUD integration tests.
 * Uses validates + validators arrays as per the real Module API.
 */
class MinimalModule extends Module
{
    public string $table = 'ib_test_minimal';

    public array $fields = [
        ['name' => 'name',  'label' => 'Name'],
        ['name' => 'email', 'label' => 'Email'],
    ];

    public array $validates = ['name'];

    public array $validators = [
        'name'  => ['max:255'],
        'email' => ['email'],
    ];

    public array $humans = [
        'name'  => 'Name',
        'email' => 'Email',
    ];
}
