<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;

/**
 * CronRunner — scheduled task executor.
 *
 * Runs the tasks defined in `ib_cron_tasks` when their cron schedule is due.
 * Called once per minute from bin/cron.php.
 *
 * Kernel task types:
 *   - cleanup_sessions : remove sessions idle for more than 24 hours
 *   - cleanup_audit    : prune audit log entries older than 90 days
 *   - cleanup_cron_runs: prune the run history older than 60 days
 *
 * Plugins add their own with CronRunner::registerTask('type', fn(Connection $db): string => ...).
 * The callable returns a human-readable summary; throwing marks the run as failed.
 */
class CronRunner
{
    /** How long ib_cron_runs keeps history. */
    private const CRON_RUNS_RETENTION_DAYS = 60;

    /** @var array<string, callable(Connection, array<string, mixed>): string> */
    private static array $tasks = [];

    public function __construct(
        private readonly Connection $db,
        private ?string $dbName = null,
    ) {
    }

    /**
     * @param callable(Connection, array<string, mixed>): string $handler
     */
    public static function registerTask(string $type, callable $handler): void
    {
        self::$tasks[$type] = $handler;
    }

    /**
     * Check all enabled tasks and run any that are due.
     *
     * Each task holds a non-blocking flock while it runs, so a pass that starts
     * while the previous one is still busy skips only that task.
     *
     * @return int Number of tasks executed
     */
    public function runDueTasks(): int
    {
        $tasks = $this->db->fetchAll('SELECT * FROM ib_cron_tasks WHERE enabled = 1');

        // When the pass started, not when each task's turn came: a slow task
        // must not push the others past their minute.
        $now = new \DateTimeImmutable('now');
        $ran = 0;
        foreach ($tasks as $task) {
            if (!$this->isDue((string) $task['schedule'], $task['last_run_at'], $now)) {
                continue;
            }
            $lock = $this->taskLock((int) $task['id']);
            if ($lock === false) {
                echo '[' . date('Y-m-d H:i:s') . '] [' . $this->dbName() . '] task #' . (int) $task['id']
                    . ' (' . $task['task_type'] . ") skipped: previous run still in progress\n";
                continue;
            }
            try {
                $this->runTask($task);
                $ran++;
            } finally {
                if (is_resource($lock)) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        }

        return $ran;
    }

    /**
     * Force-run a specific task by ID, regardless of schedule.
     *
     * @return array{status: string, output: string}
     */
    public function runTaskById(int $taskId): array
    {
        $task = $this->db->fetchOne('SELECT * FROM ib_cron_tasks WHERE id = :id', ['id' => $taskId]);
        if ($task === null) {
            return ['status' => 'failed', 'output' => "Task {$taskId} not found."];
        }
        return $this->runTask($task);
    }

    /** Name of the database this runner works on. */
    public function dbName(): string
    {
        if ($this->dbName === null || $this->dbName === '') {
            try {
                $row = $this->db->fetchOne('SELECT DATABASE() AS db');
                $this->dbName = (string) ($row['db'] ?? '');
            } catch (\Throwable) {
                $this->dbName = '';
            }
        }
        return $this->dbName !== '' ? $this->dbName : 'db';
    }

    /**
     * @param array<string, mixed> $task Row from ib_cron_tasks
     * @return array{status: string, output: string}
     */
    private function runTask(array $task): array
    {
        $startedAt = date('Y-m-d H:i:s');
        $runId = $this->db->insert('ib_cron_runs', [
            'task_id'    => (int) $task['id'],
            'started_at' => $startedAt,
            'status'     => 'running',
        ]);

        try {
            $output = $this->dispatch((string) $task['task_type'], $task);
            $status = 'completed';
        } catch (\Throwable $e) {
            $output = 'ERROR: ' . $e->getMessage();
            $status = 'failed';
        }

        $this->db->update(
            'ib_cron_runs',
            ['finished_at' => date('Y-m-d H:i:s'), 'status' => $status, 'output' => mb_substr($output, 0, 65535)],
            'id = :id',
            ['id' => $runId]
        );
        $this->db->update(
            'ib_cron_tasks',
            ['last_run_at' => $startedAt, 'last_status' => $status],
            'id = :id',
            ['id' => (int) $task['id']]
        );

        return ['status' => $status, 'output' => $output];
    }

    /**
     * @param array<string, mixed> $task
     */
    private function dispatch(string $type, array $task): string
    {
        if (isset(self::$tasks[$type])) {
            return (string) (self::$tasks[$type])($this->db, $task);
        }

        return match ($type) {
            'cleanup_sessions'  => $this->deleted(
                'DELETE FROM ib_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 24 HOUR)',
                'expired session(s)'
            ),
            'cleanup_audit'     => $this->deleted(
                'DELETE FROM ib_audit WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)',
                'audit log entry/ies older than 90 days'
            ),
            'cleanup_cron_runs' => $this->deleted(
                'DELETE FROM ib_cron_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL ' . self::CRON_RUNS_RETENTION_DAYS . ' DAY)',
                'cron run(s) older than ' . self::CRON_RUNS_RETENTION_DAYS . ' days'
            ),
            default => throw new \RuntimeException("Unknown task type: '{$type}'"),
        };
    }

    private function deleted(string $sql, string $what): string
    {
        $n = $this->db->execute($sql)->rowCount();
        return "Removed {$n} {$what}.";
    }

    /**
     * Per-database, per-task anti-overlap lock.
     *
     * @return resource|false|null resource = held; false = another process
     *         holds it (skip the task); null = no lock file (run anyway)
     */
    private function taskLock(int $taskId)
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '', $this->dbName());
        $fh = @fopen(sys_get_temp_dir() . '/ib-cron-' . $name . '-task-' . $taskId . '.lock', 'c');
        if ($fh === false) {
            return null;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return false;
        }
        return $fh;
    }

    /**
     * Whether a 5-field cron expression (*, N, N-M, N/S, N,M) is due at $now.
     */
    public function isDue(string $schedule, mixed $lastRunAt, \DateTimeImmutable $now): bool
    {
        // Never twice within the same minute.
        if (is_string($lastRunAt) && $lastRunAt !== '') {
            try {
                if ((new \DateTimeImmutable($lastRunAt))->format('Y-m-d H:i') === $now->format('Y-m-d H:i')) {
                    return false;
                }
            } catch (\Throwable) {
                // Unparseable last run: ignore it.
            }
        }

        $parts = preg_split('/\s+/', trim($schedule));
        if ($parts === false || count($parts) !== 5) {
            return false;
        }
        [$minute, $hour, $dom, $month, $dow] = $parts;

        return $this->matchField($minute, (int) $now->format('i'))
            && $this->matchField($hour, (int) $now->format('G'))
            && $this->matchField($dom, (int) $now->format('j'))
            && $this->matchField($month, (int) $now->format('n'))
            && $this->matchField($dow, (int) $now->format('w'));
    }

    private function matchField(string $field, int $value): bool
    {
        if ($field === '*') {
            return true;
        }
        if (str_contains($field, ',')) {
            foreach (explode(',', $field) as $part) {
                if ($this->matchField(trim($part), $value)) {
                    return true;
                }
            }
            return false;
        }
        if (str_contains($field, '/')) {
            [$range, $step] = explode('/', $field, 2);
            $step = (int) $step;
            if ($step <= 0) {
                return false;
            }
            if ($range === '*') {
                return $value % $step === 0;
            }
            return is_numeric($range) && $value >= (int) $range && ($value - (int) $range) % $step === 0;
        }
        if (str_contains($field, '-')) {
            [$from, $to] = explode('-', $field, 2);
            return $value >= (int) $from && $value <= (int) $to;
        }
        return is_numeric($field) && $value === (int) $field;
    }
}
