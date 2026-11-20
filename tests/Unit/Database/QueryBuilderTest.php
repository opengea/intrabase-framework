<?php

declare(strict_types=1);

namespace Intrabase\Tests\Unit\Database;

use Intrabase\Database\Connection;
use Intrabase\Database\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QueryBuilder using an SQLite in-memory database.
 *
 * Using SQLite (:memory:) keeps the tests fast, hermetic, and free from
 * any external MySQL dependency.  The QueryBuilder generates ANSI SQL that
 * is understood by both MySQL and SQLite, so coverage is representative.
 *
 * @covers \Intrabase\Database\QueryBuilder
 * @covers \Intrabase\Database\Connection
 */
class QueryBuilderTest extends TestCase
{
    private \PDO $pdo;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a real in-memory SQLite PDO connection
        $this->pdo = new \PDO('sqlite::memory:', options: [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        // Create a Connection that wraps our SQLite PDO
        $this->db = $this->createSqliteConnection();

        // Bootstrap test tables
        $this->createSchema();
        $this->seedData();
    }

    // =========================================================================
    // from() / where() / get()
    // =========================================================================

    /**
     * @test
     * from()->get() without WHERE returns all rows.
     */
    public function fromGetReturnsAllRows(): void
    {
        $rows = $this->db->query()->from('ib_users')->get();

        self::assertCount(3, $rows);
    }

    /**
     * @test
     * from()->where()->get() filters rows correctly.
     */
    public function whereFiltersRows(): void
    {
        $rows = $this->db->query()
            ->from('ib_users')
            ->where('active = :active', ['active' => 1])
            ->get();

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame(1, (int) $row['active']);
        }
    }

    /**
     * @test
     * Multiple where() calls are ANDed together.
     */
    public function multipleWheresAreAnded(): void
    {
        $rows = $this->db->query()
            ->from('ib_users')
            ->where('active = :active', ['active' => 1])
            ->where('role = :role', ['role' => 'editor'])
            ->get();

        self::assertCount(1, $rows);
        self::assertSame('editor', $rows[0]['role']);
    }

    /**
     * @test
     * first() returns only the first matching row.
     */
    public function firstReturnsOnlyOneRow(): void
    {
        $row = $this->db->query()
            ->from('ib_users')
            ->where('active = :active', ['active' => 1])
            ->orderBy('id', 'ASC')
            ->first();

        self::assertNotNull($row);
        self::assertArrayHasKey('id', $row);
    }

    /**
     * @test
     * first() returns null when no rows match.
     */
    public function firstReturnsNullWhenNoMatch(): void
    {
        $row = $this->db->query()
            ->from('ib_users')
            ->where('id = :id', ['id' => 9999])
            ->first();

        self::assertNull($row);
    }

    // =========================================================================
    // count()
    // =========================================================================

    /**
     * @test
     * count() returns the correct number of rows without a WHERE clause.
     */
    public function countReturnsCorrectTotal(): void
    {
        $count = $this->db->query()->from('ib_users')->count();

        self::assertSame(3, $count);
    }

    /**
     * @test
     * count() respects WHERE conditions.
     */
    public function countRespectsWhereCondition(): void
    {
        $count = $this->db->query()
            ->from('ib_users')
            ->where('active = :active', ['active' => 0])
            ->count();

        self::assertSame(1, $count);
    }

    /**
     * @test
     * count() returns 0 when no rows match.
     */
    public function countReturnsZeroWhenNoMatch(): void
    {
        $count = $this->db->query()
            ->from('ib_users')
            ->where('id = :id', ['id' => 9999])
            ->count();

        self::assertSame(0, $count);
    }

    // =========================================================================
    // orderBy() — direction validation
    // =========================================================================

    /**
     * @test
     * orderBy() with 'ASC' returns rows in ascending order.
     */
    public function orderByAscSortsAscending(): void
    {
        $rows = $this->db->query()
            ->from('ib_users')
            ->orderBy('id', 'ASC')
            ->get();

        $ids = array_column($rows, 'id');
        $sorted = $ids;
        sort($sorted);

        self::assertSame($sorted, array_map('intval', $ids));
    }

    /**
     * @test
     * orderBy() with 'DESC' returns rows in descending order.
     */
    public function orderByDescSortsDescending(): void
    {
        $rows = $this->db->query()
            ->from('ib_users')
            ->orderBy('id', 'DESC')
            ->get();

        $ids = array_map('intval', array_column($rows, 'id'));

        for ($i = 0; $i < count($ids) - 1; $i++) {
            self::assertGreaterThanOrEqual($ids[$i + 1], $ids[$i]);
        }
    }

    /**
     * @test
     * orderBy() with an invalid direction defaults to ASC (no exception).
     */
    public function orderByInvalidDirectionDefaultsToAsc(): void
    {
        // Should not throw — invalid direction coerced to ASC
        $rows = $this->db->query()
            ->from('ib_users')
            ->orderBy('id', 'RANDOM_INJECTION; DROP TABLE ib_users --')
            ->get();

        self::assertCount(3, $rows, 'Table must still exist after direction injection attempt');
    }

    // =========================================================================
    // paginate()
    // =========================================================================

    /**
     * @test
     * paginate() returns the expected structure with correct keys.
     */
    public function paginateReturnsCorrectStructure(): void
    {
        $result = $this->db->query()
            ->from('ib_users')
            ->paginate(1, 2);

        self::assertArrayHasKey('data', $result);
        self::assertArrayHasKey('total', $result);
        self::assertArrayHasKey('page', $result);
        self::assertArrayHasKey('per_page', $result);
        self::assertArrayHasKey('pages', $result);
    }

    /**
     * @test
     * paginate() returns correct total, page and pages counts.
     */
    public function paginateReturnsCorrectCounts(): void
    {
        $result = $this->db->query()
            ->from('ib_users')
            ->paginate(1, 2);

        self::assertSame(3, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertSame(2, $result['per_page']);
        self::assertSame(2, $result['pages']); // ceil(3/2) = 2
    }

    /**
     * @test
     * paginate() page 1 returns at most $perPage records.
     */
    public function paginatePage1ReturnsCorrectRecords(): void
    {
        $result = $this->db->query()
            ->from('ib_users')
            ->orderBy('id', 'ASC')
            ->paginate(1, 2);

        self::assertCount(2, $result['data']);
    }

    /**
     * @test
     * paginate() last page may have fewer records than perPage.
     */
    public function paginateLastPageHasRemainingRecords(): void
    {
        $result = $this->db->query()
            ->from('ib_users')
            ->orderBy('id', 'ASC')
            ->paginate(2, 2);

        self::assertCount(1, $result['data']);
    }

    /**
     * @test
     * paginate() with page <= 0 is clamped to page 1.
     */
    public function paginateClampsBadPageToOne(): void
    {
        $result = $this->db->query()
            ->from('ib_users')
            ->paginate(0, 10);

        self::assertSame(1, $result['page']);
    }

    // =========================================================================
    // insert()
    // =========================================================================

    /**
     * @test
     * insert() persists a row and returns a positive last insert ID.
     */
    public function insertPersistsRowAndReturnsId(): void
    {
        $id = $this->db->query()->insert('ib_users', [
            'name'   => 'New User',
            'email'  => 'new@example.com',
            'role'   => 'viewer',
            'active' => 1,
        ]);

        self::assertGreaterThan(0, $id);

        $row = $this->db->query()
            ->from('ib_users')
            ->where('id = :id', ['id' => $id])
            ->first();

        self::assertNotNull($row);
        self::assertSame('New User', $row['name']);
    }

    /**
     * @test
     * Direct Connection::insert() also returns correct last insert ID.
     */
    public function connectionInsertReturnsLastInsertId(): void
    {
        $id = $this->db->insert('ib_users', [
            'name'   => 'Direct Insert',
            'email'  => 'direct@example.com',
            'role'   => 'admin',
            'active' => 1,
        ]);

        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);
    }

    // =========================================================================
    // update()
    // =========================================================================

    /**
     * @test
     * update() modifies existing rows and is reflected in subsequent queries.
     */
    public function updateModifiesExistingRow(): void
    {
        // Fetch the first active user
        $row = $this->db->query()
            ->from('ib_users')
            ->where('active = :active', ['active' => 1])
            ->orderBy('id', 'ASC')
            ->first();

        self::assertNotNull($row);
        $id = (int) $row['id'];

        $this->db->query()->update(
            'ib_users',
            ['name' => 'Updated Name'],
            'id = :id',
            ['id' => $id]
        );

        $updated = $this->db->query()
            ->from('ib_users')
            ->where('id = :id', ['id' => $id])
            ->first();

        self::assertSame('Updated Name', $updated['name']);
    }

    /**
     * @test
     * Direct Connection::update() returns affected row count.
     */
    public function connectionUpdateReturnsAffectedRowCount(): void
    {
        $count = $this->db->update(
            'ib_users',
            ['active' => 0],
            'active = :active',
            ['active' => 1]
        );

        self::assertSame(2, $count); // 2 active users in seed data
    }

    // =========================================================================
    // exists()
    // =========================================================================

    /**
     * @test
     * exists() returns true when matching rows exist.
     */
    public function existsReturnsTrueWhenMatchFound(): void
    {
        $found = $this->db->query()
            ->from('ib_users')
            ->where('role = :role', ['role' => 'admin'])
            ->exists();

        self::assertTrue($found);
    }

    /**
     * @test
     * exists() returns false when no rows match.
     */
    public function existsReturnsFalseWhenNoMatch(): void
    {
        $found = $this->db->query()
            ->from('ib_users')
            ->where('role = :role', ['role' => 'superuser'])
            ->exists();

        self::assertFalse($found);
    }

    // =========================================================================
    // select()
    // =========================================================================

    /**
     * @test
     * select() limits the returned columns.
     */
    public function selectLimitsReturnedColumns(): void
    {
        $rows = $this->db->query()
            ->from('ib_users')
            ->select(['id', 'name'])
            ->get();

        self::assertArrayHasKey('id', $rows[0]);
        self::assertArrayHasKey('name', $rows[0]);
        self::assertArrayNotHasKey('email', $rows[0]);
        self::assertArrayNotHasKey('role', $rows[0]);
    }

    // =========================================================================
    // Immutability (clone-based fluent interface)
    // =========================================================================

    /**
     * @test
     * The QueryBuilder is immutable: calling where() does not modify the original.
     */
    public function queryBuilderIsImmutable(): void
    {
        $base     = $this->db->query()->from('ib_users');
        $filtered = $base->where('active = :active', ['active' => 0]);

        self::assertNotSame($base, $filtered, 'where() must return a new instance');
        self::assertCount(3, $base->get(),     'Original builder must be unaffected');
        self::assertCount(1, $filtered->get(), 'Filtered builder must apply the WHERE');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a Connection object that delegates to our in-memory SQLite PDO.
     * We subclass anonymously to override pdo() without modifying production code.
     */
    private function createSqliteConnection(): Connection
    {
        $pdo = $this->pdo;

        return new class($pdo) extends Connection {
            public function __construct(private readonly \PDO $sqlitePdo)
            {
                // Skip parent constructor — no MySQL credentials needed
                parent::__construct('localhost', 'test', 'root', '');
            }

            public function pdo(): \PDO
            {
                return $this->sqlitePdo;
            }
        };
    }

    private function createSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE ib_users (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                name   TEXT    NOT NULL,
                email  TEXT    NOT NULL,
                role   TEXT    NOT NULL DEFAULT "viewer",
                active INTEGER NOT NULL DEFAULT 1
            )
        ');
    }

    private function seedData(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ib_users (name, email, role, active) VALUES (:name, :email, :role, :active)'
        );

        $stmt->execute(['name' => 'Alice Admin',   'email' => 'alice@example.com',  'role' => 'admin',  'active' => 1]);
        $stmt->execute(['name' => 'Bob Editor',    'email' => 'bob@example.com',    'role' => 'editor', 'active' => 1]);
        $stmt->execute(['name' => 'Carol Inactive','email' => 'carol@example.com',  'role' => 'viewer', 'active' => 0]);
    }
}
