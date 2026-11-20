<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Security\Input;
use Intrabase\Security\Output;
use Intrabase\Security\Session;
use Intrabase\Support\CronRunner;

/**
 * sys_cron — Scheduled Task Manager
 *
 * Manages recurring background tasks defined in `ib_cron_tasks`.
 * Supports viewing task definitions, run history, and manual task execution.
 *
 * To enable automatic execution, add to system crontab:
 *   * * * * * apache php8 /usr/local/intrabase/bin/cron.php >> /var/log/intrabase/cron.log 2>&1
 *
 * Built-in task types:
 *   - backup            : Full gzip database dump
 *   - cleanup_sessions  : Remove sessions idle > 24 hours
 *   - cleanup_audit     : Prune audit entries older than 90 days
 *
 * Access restricted to admin group.
 */
class SysCron extends Module
{
    public string $table = 'ib_cron_tasks';
    public string $key   = 'id';
    public string $title = 'Scheduled Tasks';
    public string $icon  = 'fa-solid fa-clock';

    public array $fields      = ['id', 'name', 'schedule', 'task_type', 'enabled', 'last_run_at', 'last_status'];
    public array $editFields  = ['name', 'description', 'schedule', 'task_type', 'enabled'];
    public array $searchFields = ['name', 'task_type'];

    public array $humans = [
        'id'          => 'ID',
        'name'        => 'Task Name',
        'description' => 'Description',
        'schedule'    => 'Cron Schedule',
        'task_type'   => 'Type',
        'enabled'     => 'Active',
        'last_run_at' => 'Last Run',
        'last_status' => 'Last Status',
    ];

    public array $components = [
        'task_type'   => 'combo',
        'enabled'     => 'checklist',
        'description' => 'textarea',
        'last_run_at' => 'datetime',
    ];

    public array $validates  = ['name', 'schedule', 'task_type'];
    public array $readonly   = ['id', 'last_run_at', 'last_status'];
    public string $orderBy   = 'name';

    public bool $canDuplicate = true;
    public bool $canExport    = false;

    public function __construct(...$args)
    {
        parent::__construct(...$args);

        $this->combos['task_type'] = [
            'data' => [
                'backup'           => 'Database Backup',
                'cleanup_sessions' => 'Cleanup Sessions',
                'cleanup_audit'    => 'Cleanup Audit Log',
            ],
        ];

        $this->combos['enabled'] = ['data' => [1 => 'Active']];
        $this->defaultValues['enabled'] = 1;

        $this->comments['schedule'] = 'Cron format: minute hour day-of-month month day-of-week (e.g. "0 3 * * *" = daily at 03:00)';

        $this->customRowActions = [
            [
                'label'   => 'Run Now',
                'icon'    => 'fa-solid fa-play',
                'class'   => 'ib-btn--success',
                'action'  => 'rn',
                'method'  => 'post',
                'confirm' => 'Run this task now?',
            ],
            [
                'label'  => 'History',
                'icon'   => 'fa-solid fa-history',
                'class'  => 'ib-btn--secondary',
                'action' => 'hist',
                'method' => 'get',
            ],
        ];
    }

    /**
     * Override execute() to handle custom 'rn' (run now) and 'hist' (history) actions.
     */
    public function execute(): void
    {
        $action = Input::get('_', 'db');

        $this->applyPermissions();

        if (!$this->canBrowse) {
            $this->renderAccessDenied();
            return;
        }

        if ($action === 'rn') {
            $this->handleRunNow();
            return;
        }

        if ($action === 'hist') {
            $this->handleHistory();
            return;
        }

        parent::execute();
    }

    /**
     * Add a "Run Now" button to the task detail/edit view.
     * Add it via the browser action column using toolbarExtra on the detail view.
     */
    public function onBrowse(): void
    {
        // No toolbar extra needed — run is per-row in the action column
    }

    /**
     * Show cron installation hint on first browse.
     */
    public function onEdit(int $id): void
    {
        // no-op
    }

    // =========================================================================
    // Private: custom actions
    // =========================================================================

    /**
     * Execute a task immediately, bypassing schedule check.
     */
    private function handleRunNow(): void
    {
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            echo 'CSRF validation failed.';
            exit;
        }

        $taskId = Input::getInt('id', 0);
        $mod    = Input::get('mod');

        if ($taskId <= 0) {
            $this->redirectWithFlash($mod, 'error', 'Invalid task ID.');
            return;
        }

        $runner = new CronRunner($this->db);
        $result = $runner->runTaskById($taskId);

        $type    = $result['status'] === 'completed' ? 'success' : 'error';
        $message = '[' . strtoupper($result['status']) . '] ' . $result['output'];

        $this->redirectWithFlash($mod, $type, $message);
    }

    /**
     * Render the run history for a specific task.
     */
    private function handleHistory(): void
    {
        $taskId = Input::getInt('id', 0);
        $mod    = Output::attr(Input::get('mod'));

        $task = null;
        $runs = [];

        if ($taskId > 0) {
            $task = $this->db->fetchOne(
                'SELECT * FROM ib_cron_tasks WHERE id = :id',
                ['id' => $taskId]
            );

            $runs = $this->db->fetchAll(
                'SELECT * FROM ib_cron_runs WHERE task_id = :tid ORDER BY started_at DESC LIMIT 50',
                ['tid' => $taskId]
            );
        }

        $session = $this->container->get(Session::class);
        $layout  = new \Intrabase\View\Layout($session, $this->csrf, $this->db);

        ob_start();
        $this->renderHistoryPage($task, $runs, $mod, $taskId);
        $content = (string) ob_get_clean();

        $layout->render($content, [
            'title'      => 'Run History',
            'breadcrumb' => [
                ['label' => 'Scheduled Tasks', 'url' => '?mod=' . $mod],
                ['label' => 'History'],
            ],
        ]);
    }

    /**
     * Render the run history HTML output.
     *
     * @param array<string,mixed>|null    $task
     * @param array<int,array<string,mixed>> $runs
     */
    private function renderHistoryPage(?array $task, array $runs, string $mod, int $taskId): void
    {
        $token = $this->csrf->token();

        echo '<div class="ib-browser">';
        echo '<div class="ib-browser__toolbar">';
        echo '<h2 class="ib-browser__title"><i class="fa-solid fa-history" aria-hidden="true"></i> Run History';
        if ($task !== null) {
            echo ': ' . Output::e($task['name']);
        }
        echo '</h2>';
        echo '<a href="?mod=' . $mod . '" class="ib-btn ib-btn--secondary">'
           . '<i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back</a>';

        if ($task !== null && $taskId > 0) {
            echo '<form method="post" action="?mod=' . $mod . '&_=rn&id=' . $taskId . '" style="display:inline">'
               . '<input type="hidden" name="csrf_token" value="' . Output::attr($token) . '">'
               . '<button type="submit" class="ib-btn ib-btn--success"'
               . ' onclick="return confirm(\'Run this task now?\')">'
               . '<i class="fa-solid fa-play" aria-hidden="true"></i> Run Now'
               . '</button>'
               . '</form>';
        }
        echo '</div>';

        if (empty($runs)) {
            echo '<div class="ib-alert ib-alert--info">No run history yet for this task.</div>';
        } else {
            echo '<div class="ib-browser__table-wrap"><table class="ib-table">';
            echo '<thead><tr>'
               . '<th>Started</th>'
               . '<th>Finished</th>'
               . '<th>Status</th>'
               . '<th>Output</th>'
               . '</tr></thead><tbody>';

            foreach ($runs as $run) {
                $statusClass = match ($run['status']) {
                    'completed' => 'ib-status--active',
                    'failed'    => 'ib-status--inactive',
                    default     => 'ib-status--pending',
                };
                echo '<tr>';
                echo '<td>' . Output::e($run['started_at'] ?? '') . '</td>';
                echo '<td>' . Output::e($run['finished_at'] ?? '—') . '</td>';
                echo '<td><span class="ib-status ' . $statusClass . '">' . Output::e($run['status']) . '</span></td>';
                echo '<td>' . Output::e(mb_substr((string) ($run['output'] ?? ''), 0, 200)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
        }

        echo '</div>';
    }

    /**
     * Override: add "Run Now" and "History" action buttons to browser rows.
     * We inject them via the toolbar for the detail view.
     */
    public function onView(int $id): void
    {
        // no-op
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function redirectWithFlash(string $mod, string $type, string $message): never
    {
        $session = $this->container->get(Session::class);
        $session->flash($type, $message);
        header('Location: ?mod=' . urlencode($mod), true, 302);
        exit;
    }

    private function renderAccessDenied(): void
    {
        $session = $this->container->get(Session::class);
        $layout  = new \Intrabase\View\Layout($session, $this->csrf, $this->db);
        $layout->render(
            '<div class="ib-alert ib-alert--danger"><strong>Access Denied</strong></div>',
            ['title' => 'Access Denied']
        );
    }
}
