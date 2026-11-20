<?php

declare(strict_types=1);

namespace Intrabase\Domain\Contracts;

/**
 * ConnectionInterface — abstract persistence boundary
 *
 * Dependency-inversion contract: application and domain code must depend
 * on this interface, never on a concrete driver (PDO, mysqli, etc.).
 *
 * The concrete implementation lives in Infrastructure (PdoConnection, or
 * the legacy Intrabase\Database\Connection that implements this interface).
 *
 * Deliberate non-goals:
 * - Does NOT expose the raw PDO handle. Callers that need it must depend
 *   on the concrete class for now; those are leaks to migrate over time.
 *
 * @package Intrabase\Domain\Contracts
 */
interface ConnectionInterface
{
    /**
     * Execute a parameterized SQL statement.
     *
     * @param  array<string, mixed> $params  Named parameters
     */
    public function execute(string $sql, array $params = []): \PDOStatement;

    /**
     * Fetch all rows as associative arrays.
     *
     * @param  array<string, mixed>                $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * Fetch the first row, or null if no rows match.
     *
     * @param  array<string, mixed>        $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array;

    /**
     * Alias for fetchOne() — kept for backwards compatibility.
     *
     * @param  array<string, mixed>        $params
     * @return array<string, mixed>|null
     */
    public function fetch(string $sql, array $params = []): ?array;

    /**
     * Fetch a single scalar value from the first column of the first row.
     *
     * @param  array<string, mixed> $params
     */
    public function fetchScalar(string $sql, array $params = []): mixed;

    /**
     * Insert a single row and return the new primary key.
     *
     * @param  array<string, mixed> $data
     */
    public function insert(string $table, array $data): int;

    /**
     * Update rows matching $where. Returns affected row count.
     *
     * @param  array<string, mixed> $data
     * @param  array<string, mixed> $params
     */
    public function update(string $table, array $data, string $where, array $params = []): int;

    /**
     * Delete rows matching $where. Returns affected row count.
     *
     * @param  array<string, mixed> $params
     */
    public function delete(string $table, string $where, array $params = []): int;

    /**
     * Run $fn inside a transaction. Commits on success, rolls back on exception.
     *
     * @template T
     * @param  callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed;

    /**
     * Return the column names for a table.
     *
     * @return string[]
     */
    public function columns(string $table): array;

    /**
     * Check whether a table exists in the current database.
     */
    public function tableExists(string $table): bool;
}
