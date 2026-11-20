<?php

declare(strict_types=1);

namespace Intrabase\Database;

use Intrabase\Domain\Contracts\ConnectionInterface;

/**
 * PDO Database Connection Wrapper
 *
 * Provides a thin, secure wrapper around PDO:
 * - Automatic charset (utf8mb4)
 * - Strict error mode (exceptions always)
 * - Emulated prepares disabled (real prepared statements)
 * - Lazy connection (connects on first use)
 * - Named-parameter query builder via query()
 *
 * Implements ConnectionInterface so application code can depend on the
 * contract instead of this concrete class (dependency inversion).
 *
 * @package Intrabase\Database
 */
class Connection implements ConnectionInterface
{
    private ?\PDO $pdo = null;

    /** Primera connexió construïda (compat per a DB::getInstance()) */
    private static ?self $instance = null;

    public function __construct(
        private readonly string $host,
        private readonly string $name,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $charset = 'utf8mb4',
        private readonly int    $port = 3306
    ) {
        self::$instance ??= $this;
    }

    /**
     * Compat: mòduls generats criden DB::getInstance() en lloc de resoldre
     * la connexió pel container. Retorna la primera connexió construïda
     * (la principal de l'aplicació).
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Cap connexió construïda encara');
        }
        return self::$instance;
    }

    /**
     * Get the underlying PDO instance (lazy connect)
     *
     * @throws \PDOException On connection failure
     */
    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }

    /**
     * Return a new QueryBuilder bound to this connection.
     *
     * Compat: molts mòduls generats fan `$db->query("SELECT ...")->fetch()`
     * a l'estil PDO — si es passa SQL, el QueryBuilder queda en mode raw i
     * fetch()/fetchAll()/fetchColumn() executen aquesta consulta.
     */
    public function query(?string $sql = null, array $params = []): QueryBuilder
    {
        $qb = new QueryBuilder($this);
        return $sql !== null ? $qb->withRawSql($sql, $params) : $qb;
    }

    /**
     * Execute a raw SQL statement with bound parameters.
     *
     * NEVER pass user input as part of $sql. Use :placeholder params instead.
     *
     * @param  string               $sql    Parameterized SQL (e.g. "SELECT * FROM t WHERE id = :id")
     * @param  array<string, mixed> $params Named parameters
     * @return \PDOStatement
     * @throws \PDOException
     */
    public function execute(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            // Reconnecta i reintenta un sol cop si el servidor ha tancat la
            // connexió per inactivitat (wait_timeout): tasques de cron llargues
            // (p.ex. archive_pipeline, >1h) perdien la connexió i no podien
            // registrar mai el seu resultat. Mai dins d'una transacció: el
            // rollback implícit de la desconnexió ja n'ha perdut l'estat.
            if (!$this->isConnectionLost($e) || ($this->pdo?->inTransaction() ?? false)) {
                throw $e;
            }
            $this->pdo = null;
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }
    }

    /**
     * True si l'excepció ve d'una connexió tancada pel servidor
     * (MySQL 2006 "server has gone away" / 2013 "lost connection").
     */
    private function isConnectionLost(\PDOException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;
        if ($driverCode === 2006 || $driverCode === 2013) {
            return true;
        }
        return str_contains($e->getMessage(), 'server has gone away')
            || str_contains($e->getMessage(), 'Lost connection to MySQL server');
    }

    /**
     * Execute a SELECT and return all rows as associative arrays.
     *
     * @param  string               $sql
     * @param  array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Execute a SELECT and return the first row, or null.
     *
     * @param  string               $sql
     * @param  array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Alias for fetchOne() — backwards-compatibility.
     *
     * @param  string               $sql
     * @param  array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        return $this->fetchOne($sql, $params);
    }

    /**
     * Fetch a single scalar value from the first column of the first row.
     *
     * @param  string               $sql
     * @param  array<string, mixed> $params
     * @return mixed|null
     */
    public function fetchScalar(string $sql, array $params = []): mixed
    {
        $row = $this->fetchOne($sql, $params);
        return $row ? array_values($row)[0] : null;
    }

    // -------------------------------------------------------------------------
    // Compat: àlies usats per mòduls generats antics
    // -------------------------------------------------------------------------

    /** Àlies de fetchOne(). */
    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->fetchOne($sql, $params);
    }

    /** Àlies de fetchOne(). */
    public function selectOne(string $sql, array $params = []): ?array
    {
        return $this->fetchOne($sql, $params);
    }

    /** Àlies de fetchScalar(). */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        return $this->fetchScalar($sql, $params);
    }

    /** Passarel·la a PDO::prepare() per a codi d'estil PDO. */
    public function prepare(string $sql): \PDOStatement
    {
        return $this->pdo()->prepare($sql);
    }

    /** Passarel·la a PDO::quote(). Prefereix sempre paràmetres vinculats. */
    public function quote(string $value, int $type = \PDO::PARAM_STR): string|false
    {
        return $this->pdo()->quote($value, $type);
    }

    /**
     * INSERT a single row and return the last insert ID.
     *
     * @param  string               $table  Table name (validated internally)
     * @param  array<string, mixed> $data   Column => value pairs
     * @return int                          Last insert ID
     * @throws \InvalidArgumentException    If $table or column names are invalid
     */
    public function insert(string $table, array $data): int
    {
        $this->validateIdentifier($table);
        foreach (array_keys($data) as $col) {
            $this->validateIdentifier((string) $col);
        }

        $columns = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(fn($c) => '`' . $c . '`', $columns)),
            implode(', ', $placeholders)
        );

        $this->execute($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * UPDATE rows matching $where.
     *
     * @param  string               $table   Table name
     * @param  array<string, mixed> $data    Columns to update
     * @param  string               $where   Parameterized WHERE clause (e.g. "id = :id")
     * @param  array<string, mixed> $params  Parameters for WHERE clause
     * @return int                           Number of affected rows
     */
    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $this->validateIdentifier($table);
        foreach (array_keys($data) as $col) {
            $this->validateIdentifier((string) $col);
        }

        $sets = array_map(fn($c) => '`' . $c . '` = :__set_' . $c, array_keys($data));

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $sets),
            $where
        );

        // Prefix set params to avoid collision with where params
        $setParams = [];
        foreach ($data as $col => $val) {
            $setParams['__set_' . $col] = $val;
        }

        $stmt = $this->execute($sql, array_merge($setParams, $params));
        return $stmt->rowCount();
    }

    /**
     * DELETE rows matching $where.
     *
     * @param  string               $table  Table name
     * @param  string               $where  Parameterized WHERE clause
     * @param  array<string, mixed> $params
     * @return int                          Number of deleted rows
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $this->validateIdentifier($table);

        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, $where);
        return $this->execute($sql, $params)->rowCount();
    }

    /**
     * Wrap a callable in a database transaction.
     *
     * Automatically commits on success, rolls back on exception.
     *
     * @template T
     * @param  callable(): T $fn
     * @return T
     * @throws \Throwable Re-throws any exception after rollback
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Return list of column names for a table.
     *
     * @param  string   $table
     * @return string[]
     */
    public function columns(string $table): array
    {
        $this->validateIdentifier($table);
        $rows = $this->fetchAll("SHOW COLUMNS FROM `{$table}`");
        return array_column($rows, 'Field');
    }

    /**
     * Check if a table exists.
     */
    public function tableExists(string $table): bool
    {
        $result = $this->fetchScalar(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        );
        return (int) $result > 0;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function connect(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->name,
            $this->charset
        );

        $this->pdo = new \PDO($dsn, $this->user, $this->pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ]);
    }

    /**
     * Validate a SQL identifier (table/column name).
     *
     * Only allows letters, digits and underscores to prevent
     * SQL injection via identifier injection.
     *
     * @throws \InvalidArgumentException
     */
    private function validateIdentifier(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(
                "Invalid SQL identifier: '{$name}'"
            );
        }
    }
}
