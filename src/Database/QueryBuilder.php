<?php

declare(strict_types=1);

namespace Intrabase\Database;

/**
 * Fluent, safe SQL Query Builder
 *
 * Builds parameterized SELECT/INSERT/UPDATE/DELETE statements.
 * ALL user-supplied values are bound as parameters — never interpolated.
 *
 * Usage example:
 *
 *   $rows = $db->query()
 *       ->from('ib_users')
 *       ->select(['id', 'name', 'email'])
 *       ->where('active = :active', ['active' => 1])
 *       ->where('role = :role', ['role' => 'admin'])
 *       ->orderBy('name', 'ASC')
 *       ->limit(10)
 *       ->get();
 *
 * @package Intrabase\Database
 */
class QueryBuilder
{
    private string $table = '';

    /** @var string[] */
    private array $columns = ['*'];

    /** @var string[] */
    private array $wheres = [];

    /** @var string[] */
    private array $rawWheres = [];

    /** @var array<string, mixed> */
    private array $params = [];

    private ?string $orderByCol = null;
    private string  $orderByDir = 'ASC';
    /** ORDER BY en SQL cru: només el fixa el codi (Module::$orderBySql), mai l'usuari. */
    private ?string $orderByRawSql = null;
    private ?int    $limitVal   = null;
    private ?int    $offsetVal  = null;

    /** @var string[] */
    private array $joins = [];

    /** SQL cru (mode compat per a $db->query("SELECT ...")->fetch()) */
    private ?string $rawSql = null;

    /** @var array<string, mixed> */
    private array $rawParams = [];

    public function __construct(private readonly Connection $db) {}

    /**
     * Compat: posa el builder en mode SQL cru — get()/first()/fetch()/
     * fetchAll()/fetchColumn() executen aquesta consulta tal qual.
     */
    public function withRawSql(string $sql, array $params = []): static
    {
        $clone = clone $this;
        $clone->rawSql    = $sql;
        $clone->rawParams = $params;
        return $clone;
    }

    // =========================================================================
    // FROM / SELECT
    // =========================================================================

    /**
     * Set the table to query from.
     */
    public function from(string $table): static
    {
        $clone = clone $this;
        $clone->table = $table;
        return $clone;
    }

    /**
     * Specify columns to select.
     *
     * @param  string[] $columns
     */
    public function select(array $columns): static
    {
        $clone = clone $this;
        $clone->columns = $columns;
        return $clone;
    }

    // =========================================================================
    // WHERE
    // =========================================================================

    /**
     * Add a parameterized WHERE condition.
     *
     * @param  string               $condition  SQL fragment with named placeholders (e.g. "id = :id")
     * @param  array<string, mixed> $params     Bound values
     */
    public function where(string $condition, array $params = []): static
    {
        $clone = clone $this;
        $clone->wheres[]  = $condition;
        $clone->params    = array_merge($clone->params, $params);
        return $clone;
    }

    /**
     * Add a raw WHERE condition (no parameter binding).
     *
     * CAUTION: only use with trusted, developer-controlled values.
     * Never pass user input here.
     */
    public function whereRaw(string $condition): static
    {
        $clone = clone $this;
        $clone->rawWheres[] = $condition;
        return $clone;
    }

    // =========================================================================
    // ORDER / LIMIT / OFFSET
    // =========================================================================

    /**
     * @param  string $direction 'ASC' or 'DESC'
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $clone = clone $this;
        $clone->orderByCol = $column;
        $clone->orderByDir = $direction;
        return $clone;
    }

    /**
     * ORDER BY amb una expressió SQL (p. ex. FIELD(...)). NOMÉS per a SQL escrit al
     * codi: no hi passis mai res que vingui de la petició. Substitueix orderBy().
     */
    public function orderByRaw(string $sql): static
    {
        $clone = clone $this;
        $clone->orderByRawSql = trim($sql) !== '' ? $sql : null;
        $clone->orderByCol    = null;
        return $clone;
    }

    public function limit(int $limit): static
    {
        $clone = clone $this;
        $clone->limitVal = max(1, $limit);
        return $clone;
    }

    public function offset(int $offset): static
    {
        $clone = clone $this;
        $clone->offsetVal = max(0, $offset);
        return $clone;
    }

    // =========================================================================
    // JOIN
    // =========================================================================

    /**
     * Add an INNER JOIN.
     *
     * @param string $table     Table to join
     * @param string $condition ON condition (developer-controlled)
     */
    public function join(string $table, string $condition): static
    {
        $clone = clone $this;
        $clone->joins[] = "INNER JOIN `{$table}` ON {$condition}";
        return $clone;
    }

    /**
     * Add a LEFT JOIN.
     */
    public function leftJoin(string $table, string $condition): static
    {
        $clone = clone $this;
        $clone->joins[] = "LEFT JOIN `{$table}` ON {$condition}";
        return $clone;
    }

    // =========================================================================
    // Fetch helpers
    // =========================================================================

    /**
     * Execute SELECT and return all rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        if ($this->rawSql !== null) {
            return $this->db->fetchAll($this->rawSql, $this->rawParams);
        }
        ['sql' => $sql, 'params' => $params] = $this->buildSelect();
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Execute SELECT and return the first row, or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        if ($this->rawSql !== null) {
            return $this->db->fetchOne($this->rawSql, $this->rawParams);
        }
        ['sql' => $sql, 'params' => $params] = $this->limit(1)->buildSelect();
        return $this->db->fetchOne($sql, $params);
    }

    // -------------------------------------------------------------------------
    // Compat: àlies d'estil PDO usats per mòduls generats antics
    // -------------------------------------------------------------------------

    /** Àlies de first(); el mode de fetch de PDO s'ignora (sempre assoc). */
    public function fetch(mixed $mode = null): ?array
    {
        return $this->first();
    }

    /** Àlies de get(); el mode de fetch de PDO s'ignora (sempre assoc). */
    public function fetchAll(mixed $mode = null): array
    {
        return $this->get();
    }

    /** Primera columna de la primera fila; false si no hi ha files (com PDO). */
    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->first();
        if ($row === null) {
            return false;
        }
        return array_values($row)[$column] ?? false;
    }

    /**
     * Count matching rows.
     */
    public function count(): int
    {
        $clone = clone $this;
        $clone->columns    = ['COUNT(*) AS cnt'];
        $clone->orderByCol = null;
        $clone->orderByRawSql = null;
        $clone->limitVal   = null;
        $clone->offsetVal  = null;

        ['sql' => $sql, 'params' => $params] = $clone->buildSelect();
        return (int) $this->db->fetchScalar($sql, $params);
    }

    /**
     * Check if any matching rows exist.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    // =========================================================================
    // Write operations
    // =========================================================================

    /**
     * Execute an INSERT.
     *
     * @param  string               $table
     * @param  array<string, mixed> $data
     * @return int                         Last insert ID
     */
    public function insert(string $table, array $data): int
    {
        return $this->db->insert($table, $data);
    }

    /**
     * Execute an UPDATE.
     *
     * @param  string               $table
     * @param  array<string, mixed> $data
     * @param  string               $where  Parameterized WHERE clause
     * @param  array<string, mixed> $params
     * @return bool
     */
    public function update(string $table, array $data, string $where, array $params = []): bool
    {
        return $this->db->update($table, $data, $where, $params) >= 0;
    }

    /**
     * Execute a DELETE.
     *
     * @param  string               $table
     * @param  string               $where  Parameterized WHERE clause
     * @param  array<string, mixed> $params
     * @return bool
     */
    public function delete(string $table, string $where, array $params = []): bool
    {
        return $this->db->delete($table, $where, $params) >= 0;
    }

    // =========================================================================
    // Pagination helper
    // =========================================================================

    /**
     * Paginate results.
     *
     * @return array{
     *   data: array<int, array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   pages: int
     * }
     */
    public function paginate(int $page = 1, int $perPage = 25): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(1000, $perPage));

        $total = $this->count();
        $data  = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    // =========================================================================
    // Build helpers
    // =========================================================================

    /**
     * Build the SELECT SQL and merged params.
     *
     * @return array{sql: string, params: array<string, mixed>}
     */
    /**
     * Quote a simple column identifier with backticks.
     * Skips wildcards, raw expressions (parens/spaces), qualified names and already-quoted identifiers.
     */
    private function quoteCol(string $col): string
    {
        $col = trim($col);
        if ($col === '*' || str_contains($col, '(') || str_contains($col, ' ') || str_contains($col, '`')) {
            return $col;
        }
        if (str_contains($col, '.')) {
            [$t, $c] = explode('.', $col, 2);
            return "`{$t}`.`{$c}`";
        }
        return "`{$col}`";
    }

    private function buildSelect(): array
    {
        if (empty($this->table)) {
            throw new \LogicException('No table specified — call from() first');
        }

        $cols = implode(', ', array_map([$this, 'quoteCol'], $this->columns));
        $sql  = "SELECT {$cols} FROM `{$this->table}`";

        foreach ($this->joins as $join) {
            $sql .= ' ' . $join;
        }

        $allWheres = array_merge($this->wheres, $this->rawWheres);
        if ($allWheres) {
            $sql .= ' WHERE ' . implode(' AND ', $allWheres);
        }

        if ($this->orderByRawSql !== null) {
            $sql .= ' ORDER BY ' . $this->orderByRawSql;
        } elseif ($this->orderByCol !== null) {
            // Support multi-column ORDER BY (e.g. 'sort_order, territory_name')
            // Each column is sanitized to prevent injection
            $cols = array_map(function(string $part): string {
                $part = trim($part);
                // Extract optional per-column direction
                if (preg_match('/^([a-zA-Z0-9_.]+)\s+(ASC|DESC)$/i', $part, $dm)) {
                    return preg_replace('/[^a-zA-Z0-9_.]/', '', $dm[1]) . ' ' . strtoupper($dm[2]);
                }
                return preg_replace('/[^a-zA-Z0-9_.]/', '', $part);
            }, explode(',', $this->orderByCol));
            $colSql = implode(', ', array_filter($cols));
            if ($colSql !== '') {
                $sql .= " ORDER BY {$colSql} {$this->orderByDir}";
            }
        }

        if ($this->limitVal !== null) {
            $sql .= " LIMIT {$this->limitVal}";
        }

        if ($this->offsetVal !== null) {
            $sql .= " OFFSET {$this->offsetVal}";
        }

        return ['sql' => $sql, 'params' => $this->params];
    }
}
