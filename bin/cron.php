<?php

/**
 * Intrabase cron runner.
 *
 * Call it once per minute from the system crontab, as the web server user:
 *   * * * * * php /path/to/intrabase/bin/cron.php >> /var/log/intrabase/cron.log 2>&1
 *
 * Runs the due tasks of ib_cron_tasks in config('db_name'), or in every
 * database listed in config('cron_databases') when set (multi-tenant hosts).
 *
 * Options:
 *   --db=NAME   run just this database (must be one of the list above)
 *   --list      print the databases and exit
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

$configPath = BASE_PATH . '/config/app.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "[ERROR] Config file not found: {$configPath}\n");
    exit(1);
}
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'UTC');

$databases = array_values(array_filter(
    (array) ($config['cron_databases'] ?? [$config['db_name'] ?? '']),
    static fn($n): bool => is_string($n) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $n) === 1
));

$opts = getopt('', ['db:', 'list']);
if (isset($opts['list'])) {
    echo implode("\n", $databases), "\n";
    exit(0);
}
if (isset($opts['db'])) {
    if (!in_array($opts['db'], $databases, true)) {
        fwrite(STDERR, "[ERROR] Unknown database: {$opts['db']}\n");
        exit(1);
    }
    $databases = [$opts['db']];
}

// Plugins register their own task types (CronRunner::registerTask) when created.
foreach ((array) ($config['plugins'] ?? []) as $class) {
    if (is_string($class) && class_exists($class) && method_exists($class, 'registerCron')) {
        $class::registerCron();
    }
}

$exit = 0;
foreach ($databases as $name) {
    try {
        $db = new \Intrabase\Database\Connection(
            host:    $config['db_host'],
            name:    $name,
            user:    $config['db_user'],
            pass:    $config['db_pass'],
            charset: 'utf8mb4',
            port:    (int) ($config['db_port'] ?? 3306)
        );
        $ran = (new \Intrabase\Support\CronRunner($db, $name))->runDueTasks();
        if ($ran > 0) {
            echo '[' . date('Y-m-d H:i:s') . "] [{$name}] {$ran} task(s) run\n";
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] [{$name}] ERROR: " . $e->getMessage() . "\n");
        $exit = 1;
    }
}
exit($exit);
