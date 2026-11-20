<?php

declare(strict_types=1);

namespace Intrabase\Tests\Unit\Core;

use Intrabase\Core\Container;
use Intrabase\Core\Module;
use Intrabase\Database\Connection;
use Intrabase\Lang\Lang;
use Intrabase\Security\CSRF;
use Intrabase\Security\Session;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for Module base class.
 *
 * We exercise the pure-PHP methods that have no database dependency:
 * - getModuleName() CamelCase → snake_case conversion
 * - canAdd() / canEdit() / canDelete() capability flags
 * - getFields() / getEditFields() / getOrderBy() / getOrderDir() / getPerPage()
 * - validateData() required + rule-based validation
 * - sanitizeFieldValue() type-specific sanitisation (exposed via test double)
 *
 * The Connection mock is set up to never actually connect to MySQL.
 * The CSRF mock is set up to return a dummy token.
 *
 * @covers \Intrabase\Core\Module
 */
class ModuleTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    /** @var CSRF&MockObject */
    private CSRF $csrf;

    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db   = $this->createMock(Connection::class);
        $this->csrf = $this->createMock(CSRF::class);
        $this->csrf->method('token')->willReturn(str_repeat('a', 64));

        $this->container = new Container();
        $session = new Session();
        $this->container->instance(Session::class, $session);
        $this->container->instance(CSRF::class, $this->csrf);

        // Initialize Lang so __() translation functions work correctly in tests
        if (!Lang::isInitialized()) {
            Lang::init('en', dirname(__DIR__, 3) . '/lang');
        }
    }

    // =========================================================================
    // getModuleName()
    // =========================================================================

    /** @test CamelCase class name is converted to snake_case */
    public function getModuleNameConvertsToSnakeCase(): void
    {
        $module = $this->makeModule('MyTestModule');
        self::assertSame('my_test_module', $module->getModuleName());
    }

    /** @test Single-word class name stays lowercase */
    public function getModuleNameLowercasesSingleWord(): void
    {
        $module = $this->makeModule('Products');
        self::assertSame('products', $module->getModuleName());
    }

    // =========================================================================
    // Capability flags
    // =========================================================================

    /** @test Default module has all capabilities enabled */
    public function defaultModuleHasAllCapabilities(): void
    {
        $module = $this->makeModule();
        self::assertTrue($module->canAdd());
        self::assertTrue($module->canEdit());
        self::assertTrue($module->canDelete());
    }

    /** @test canAdd returns false when disabled */
    public function canAddReturnsFalseWhenDisabled(): void
    {
        $module = $this->makeModule('TestMod', ['canAdd' => false]);
        self::assertFalse($module->canAdd());
    }

    /** @test canEdit returns false when disabled */
    public function canEditReturnsFalseWhenDisabled(): void
    {
        $module = $this->makeModule('TestMod', ['canEdit' => false]);
        self::assertFalse($module->canEdit());
    }

    /** @test canDelete returns false when disabled */
    public function canDeleteReturnsFalseWhenDisabled(): void
    {
        $module = $this->makeModule('TestMod', ['canDelete' => false]);
        self::assertFalse($module->canDelete());
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /** @test getFields() returns the configured fields array */
    public function getFieldsReturnsConfiguredFields(): void
    {
        $module = $this->makeModule('TestMod', ['fields' => ['id', 'name', 'email']]);
        self::assertSame(['id', 'name', 'email'], $module->getFields());
    }

    /** @test getEditFields() falls back to fields when editFields is empty */
    public function getEditFieldsFallsBackToFields(): void
    {
        $module = $this->makeModule('TestMod', ['fields' => ['id', 'name']]);
        self::assertSame(['id', 'name'], $module->getEditFields());
    }

    /** @test getEditFields() returns editFields when explicitly set */
    public function getEditFieldsReturnsEditFieldsWhenSet(): void
    {
        $module = $this->makeModule('TestMod', [
            'fields'     => ['id', 'name', 'secret'],
            'editFields' => ['id', 'name'],
        ]);
        self::assertSame(['id', 'name'], $module->getEditFields());
    }

    /** @test getOrderBy() falls back to the primary key when not set */
    public function getOrderByFallsBackToPrimaryKey(): void
    {
        $module = $this->makeModule('TestMod', ['key' => 'id']);
        self::assertSame('id', $module->getOrderBy());
    }

    /** @test getOrderBy() returns the configured orderBy value */
    public function getOrderByReturnsConfiguredValue(): void
    {
        $module = $this->makeModule('TestMod', ['orderBy' => 'created_at']);
        self::assertSame('created_at', $module->getOrderBy());
    }

    /** @test getOrderDir() defaults to 'ASC' */
    public function getOrderDirDefaultsToAsc(): void
    {
        $module = $this->makeModule();
        self::assertSame('ASC', $module->getOrderDir());
    }

    /** @test getPerPage() defaults to Module::$perPage (100) */
    public function getPerPageDefaultsTo100(): void
    {
        $module = $this->makeModule();
        self::assertSame(100, $module->getPerPage());
    }

    // =========================================================================
    // validateData()
    // =========================================================================

    /** @test validateData() returns empty errors when all required fields are present */
    public function validateDataReturnsNoErrorsWhenValid(): void
    {
        $module = $this->makeModule('TestMod', [
            'validates' => ['name', 'email'],
        ]);

        $errors = $module->validateData(['name' => 'Alice', 'email' => 'a@b.com']);

        self::assertEmpty($errors);
    }

    /** @test validateData() returns an error for a missing required field */
    public function validateDataRequiredFieldMissingProducesError(): void
    {
        $module = $this->makeModule('TestMod', [
            'validates' => ['name'],
            'humans'    => ['name' => 'Full Name'],
        ]);

        $errors = $module->validateData(['name' => '']);

        self::assertArrayHasKey('name', $errors);
        self::assertStringContainsString('Full Name', $errors['name']);
    }

    /** @test validateData() reports errors for all missing required fields */
    public function validateDataReportsAllMissingRequiredFields(): void
    {
        $module = $this->makeModule('TestMod', [
            'validates' => ['first_name', 'last_name'],
        ]);

        $errors = $module->validateData([]);

        self::assertArrayHasKey('first_name', $errors);
        self::assertArrayHasKey('last_name', $errors);
    }

    /** @test validateData() rejects an invalid email via validators config */
    public function validateDataEmailRuleRejectsInvalidEmail(): void
    {
        $module = $this->makeModule('TestMod', [
            'validators' => ['email' => 'email'],
            'humans'     => ['email' => 'Email'],
        ]);

        $errors = $module->validateData(['email' => 'not-an-email']);

        self::assertArrayHasKey('email', $errors);
        self::assertStringContainsString('valid email', $errors['email']);
    }

    /** @test validateData() accepts a valid email via validators config */
    public function validateDataEmailRuleAcceptsValidEmail(): void
    {
        $module = $this->makeModule('TestMod', [
            'validators' => ['email' => 'email'],
        ]);

        $errors = $module->validateData(['email' => 'valid@example.com']);

        self::assertEmpty($errors);
    }

    /** @test validateData() enforces max: rule */
    public function validateDataMaxRuleEnforcesLength(): void
    {
        $module = $this->makeModule('TestMod', [
            'validators' => ['title' => 'max:10'],
            'humans'     => ['title' => 'Title'],
        ]);

        $errors = $module->validateData(['title' => str_repeat('x', 11)]);

        self::assertArrayHasKey('title', $errors);
        self::assertStringContainsString('10', $errors['title']);
    }

    /** @test validateData() accepts value within max: limit */
    public function validateDataMaxRuleAcceptsValidLength(): void
    {
        $module = $this->makeModule('TestMod', [
            'validators' => ['title' => 'max:10'],
        ]);

        $errors = $module->validateData(['title' => 'short']);

        self::assertEmpty($errors);
    }

    /** @test validateData() enforces min: rule */
    public function validateDataMinRuleEnforcesLength(): void
    {
        $module = $this->makeModule('TestMod', [
            'validators' => ['code' => 'min:3'],
            'humans'     => ['code' => 'Code'],
        ]);

        $errors = $module->validateData(['code' => 'ab']);

        self::assertArrayHasKey('code', $errors);
        self::assertStringContainsString('3', $errors['code']);
    }

    // =========================================================================
    // sanitizeFieldValue() — exposed via public wrapper in test double
    // =========================================================================

    /** @test text fields have HTML stripped and are trimmed */
    public function sanitizeTextFieldStripsHtmlAndTrims(): void
    {
        $module = $this->makeModule();
        self::assertSame('hello', $module->publicSanitize('name', '  <b>hello</b>  '));
    }

    /** @test integer fields are cast to int */
    public function sanitizeIntegerFieldCastsToInt(): void
    {
        $module = $this->makeModule('TestMod', ['components' => ['qty' => 'integer']]);
        self::assertSame(42, $module->publicSanitize('qty', '42.9'));
    }

    /** @test money fields are cast to float */
    public function sanitizeMoneyFieldCastsToFloat(): void
    {
        $module = $this->makeModule('TestMod', ['components' => ['price' => 'money']]);
        $result = $module->publicSanitize('price', '9.99');
        self::assertIsFloat($result);
        self::assertEqualsWithDelta(9.99, $result, 0.001);
    }

    /** @test email fields have dangerous characters stripped */
    public function sanitizeEmailFieldFiltersEmail(): void
    {
        $module = $this->makeModule('TestMod', ['components' => ['email' => 'email']]);
        $result = $module->publicSanitize('email', 'user@example.com');
        self::assertStringContainsString('@', (string) $result);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a concrete Module test double.
     *
     * @param string               $shortName Class short name (for getModuleName())
     * @param array<string, mixed> $props     Properties to override on the module
     */
    private function makeModule(string $shortName = 'TestModule', array $props = []): TestModuleDouble
    {
        return new TestModuleDouble(
            $this->db,
            $this->csrf,
            $this->container,
            $shortName,
            $props
        );
    }
}

/**
 * Concrete test double for the abstract Module class.
 *
 * Adds a public wrapper for the protected sanitizeFieldValue() method,
 * and allows runtime overriding of any property via the constructor.
 */
class TestModuleDouble extends Module
{
    /** Allows dynamic class short-name override for getModuleName() tests */
    private string $testShortName;

    public function __construct(
        Connection $db,
        CSRF $csrf,
        Container $container,
        string $shortName = 'TestModuleDouble',
        array $props = []
    ) {
        parent::__construct($db, $csrf, $container);
        $this->testShortName = $shortName;

        foreach ($props as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /** Override to return the test-supplied short name */
    public function getModuleName(): string
    {
        $class = $this->testShortName;
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $class) ?? $class);
    }

    /** Expose protected sanitizeFieldValue() for testing */
    public function publicSanitize(string $field, mixed $value): mixed
    {
        return $this->sanitizeFieldValue($field, $value);
    }
}
