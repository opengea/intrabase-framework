<?php

/**
 * Intrabase installer: creates the kernel schema in an EMPTY database and the
 * first administrator.
 *
 *   php bin/install.php --admin-email=you@example.org [--admin-name="Your Name"]
 *                       [--organization="My organization"] [--locale=en]
 *
 * The password is asked interactively (or read from the IB_ADMIN_PASSWORD
 * environment variable for unattended installs). Database credentials come
 * from config/app.php (copy config/app.php.example first).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

define('BASE_PATH', dirname(__DIR__));

$fail = static function (string $msg): never {
    fwrite(STDERR, "[ERROR] {$msg}\n");
    exit(1);
};

if (!is_file(BASE_PATH . '/vendor/autoload.php')) {
    $fail('vendor/autoload.php not found. Run: composer install --no-dev');
}
require BASE_PATH . '/vendor/autoload.php';

$configPath = BASE_PATH . '/config/app.php';
if (!is_file($configPath)) {
    $fail('config/app.php not found. Copy config/app.php.example and fill in the database settings.');
}
$config = require $configPath;

if (strlen((string) ($config['app_secret'] ?? '')) < 32) {
    $fail("config('app_secret') is missing or too short. Put this generated value in config/app.php and run again:\n"
        . "    'app_secret' => '" . base64_encode(random_bytes(32)) . "',");
}

$opts = getopt('', ['admin-email:', 'admin-name::', 'organization::', 'locale::']);
$email = strtolower(trim((string) ($opts['admin-email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fail('--admin-email is required and must be a valid address.');
}
$name   = trim((string) ($opts['admin-name'] ?? 'Administrator')) ?: 'Administrator';
$org    = trim((string) ($opts['organization'] ?? 'My organization')) ?: 'My organization';
$locale = (string) ($opts['locale'] ?? 'en');
if (!in_array($locale, ['ca', 'en', 'es', 'eu'], true)) {
    $fail('--locale must be one of: ca, en, es, eu.');
}

$password = (string) (getenv('IB_ADMIN_PASSWORD') ?: '');
if ($password === '') {
    if (!stream_isatty(STDIN)) {
        $fail('Set IB_ADMIN_PASSWORD or run the installer from a terminal.');
    }
    echo 'Administrator password (min. 12 characters): ';
    system('stty -echo');
    $password = trim((string) fgets(STDIN));
    system('stty echo');
    echo "\n";
}
if (mb_strlen($password) < 12) {
    $fail('The administrator password must have at least 12 characters.');
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db_host'], (int) ($config['db_port'] ?? 3306), $config['db_name']),
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]
    );
} catch (PDOException $e) {
    $fail('Cannot connect to the database: ' . $e->getMessage());
}

if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ib_users'")->fetchColumn() > 0) {
    $fail('The database already has an Intrabase schema (ib_users exists). The installer only runs on an empty database.');
}

// 1. Schema
echo "Creating tables...\n";
$schema = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
foreach (preg_split('/;\s*\n/', $schema) as $stmt) {
    $sql = trim(preg_replace('/^--.*$/m', '', $stmt));
    if ($sql !== '') {
        $pdo->exec($sql);
    }
}

$pdo->beginTransaction();
try {
    // 2. Groups, administrator, organization
    $groups = [];
    foreach (['admin' => 'Administrators', 'staff' => 'Staff', 'readonly' => 'Read only'] as $key => $label) {
        $pdo->prepare('INSERT INTO ib_groups (name, description) VALUES (?, ?)')->execute([$key, $label]);
        $groups[$key] = (int) $pdo->lastInsertId();
    }

    $pdo->prepare(
        'INSERT INTO ib_users (username, email, password_hash, name, locale, active) VALUES (?, ?, ?, ?, ?, 1)'
    )->execute([strstr($email, '@', true), $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $name, $locale]);
    $adminId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO ib_user_groups (user_id, group_id) VALUES (?, ?)')->execute([$adminId, $groups['admin']]);

    $pdo->prepare("INSERT INTO ib_obj_entities (name, type, status, created_by) VALUES (?, 'company', 'active', ?)")
        ->execute([$org, $adminId]);
    $entityId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO ib_user_entities (user_id, entity_id, role, is_default) VALUES (?, ?, 'owner', 1)")
        ->execute([$adminId, $entityId]);

    // 3. The System app: the kernel's administration modules
    $mod = static fn(string $key, string $label, string $icon): array => ['key' => $key, 'name' => $label, 'icon' => $icon];
    $systemModules = [
        ['group' => 'Users & Access', 'icon' => 'fa-solid fa-user-shield', 'modules' => [
            $mod('sys_users', 'Users', 'fa-solid fa-users'),
            $mod('sys_groups', 'Roles & Groups', 'fa-solid fa-users-gear'),
            $mod('sys_permissions', 'Permissions', 'fa-solid fa-key'),
            $mod('sys_permissions_matrix', 'Permissions Matrix', 'fa-solid fa-table-cells'),
            $mod('sys_field_access_rules', 'Field Access Rules', 'fa-solid fa-eye-slash'),
            $mod('sys_two_factor_auth', 'Two-factor Authentication', 'fa-solid fa-shield-halved'),
            $mod('sys_trusted_devices', 'Trusted Devices', 'fa-solid fa-laptop'),
            $mod('sys_sessions', 'Sessions', 'fa-solid fa-desktop'),
            $mod('sys_login_log', 'Login Log', 'fa-solid fa-right-to-bracket'),
            $mod('sys_audit', 'Audit Log', 'fa-solid fa-clipboard-list'),
        ]],
        ['group' => 'Apps & Modules', 'icon' => 'fa-solid fa-cubes', 'modules' => [
            $mod('sys_apps', 'Apps', 'fa-solid fa-table-cells-large'),
            $mod('sys_module_manager', 'Module Manager', 'fa-solid fa-cubes'),
            $mod('sys_mod', 'Module Builder', 'fa-solid fa-wand-magic-sparkles'),
            $mod('sys_mod_fam', 'Module Families', 'fa-solid fa-layer-group'),
            $mod('sys_mod_attributes', 'Module Fields', 'fa-solid fa-list'),
            $mod('sys_mod_actions', 'Module Actions', 'fa-solid fa-bolt'),
            $mod('sys_views', 'Views', 'fa-solid fa-filter'),
            $mod('sys_hook_rules', 'Hook Rules', 'fa-solid fa-code-branch'),
        ]],
        ['group' => 'Notifications', 'icon' => 'fa-solid fa-bell', 'modules' => [
            $mod('sys_notifications', 'Notifications', 'fa-solid fa-bell'),
            $mod('sys_notification_rules', 'Notification Rules', 'fa-solid fa-sliders'),
            $mod('sys_notification_templates', 'Notification Templates', 'fa-solid fa-file-lines'),
            $mod('sys_notification_channels', 'Notification Channels', 'fa-solid fa-tower-broadcast'),
            $mod('sys_smtp', 'SMTP Mail', 'fa-solid fa-envelope-circle-check'),
        ]],
        ['group' => 'Settings', 'icon' => 'fa-solid fa-gear', 'modules' => [
            $mod('sys_app_settings', 'App Settings', 'fa-solid fa-sliders'),
            $mod('sys_config', 'Parameters', 'fa-solid fa-gear'),
            $mod('sys_lang', 'Translations', 'fa-solid fa-language'),
            $mod('sys_languages', 'Languages', 'fa-solid fa-globe'),
            $mod('sys_api_tokens', 'API Tokens', 'fa-solid fa-key'),
            $mod('sys_cron', 'Cron Jobs', 'fa-solid fa-clock'),
            $mod('sys_tenants', 'Tenants', 'fa-solid fa-building'),
        ]],
    ];
    $pdo->prepare(
        "INSERT INTO ib_apps (keyname, catalog_slug, name, icon, modules, default_module, sort_order, is_active, group_access, display_location)
         VALUES ('system', 'system', 'System', 'fa-solid fa-gear', ?, 'sys_users', 100, 1, 'admin', 'appbar')"
    )->execute([json_encode($systemModules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

    // 4. Kernel cron tasks
    $tasks = [
        ['Clean up idle sessions', 'cleanup_sessions', '15 * * * *'],
        ['Prune the audit log', 'cleanup_audit', '30 3 * * *'],
        ['Prune the cron run history', 'cleanup_cron_runs', '45 3 * * *'],
    ];
    $st = $pdo->prepare('INSERT INTO ib_cron_tasks (name, description, schedule, task_type, enabled) VALUES (?, ?, ?, ?, 1)');
    foreach ($tasks as [$label, $type, $schedule]) {
        $st->execute([$label, '', $schedule, $type]);
    }

    // 5. Translations: lang/*.php is the source, ib_lang the editable copy
    $rows = [];
    foreach (['ca', 'en', 'es', 'eu'] as $lc) {
        foreach (['', 'js_'] as $prefix) {
            $file = BASE_PATH . "/lang/{$prefix}{$lc}.php";
            if (!is_file($file)) {
                continue;
            }
            foreach ((array) require $file as $key => $text) {
                $rows[$key][$lc] = (string) $text;
            }
        }
    }
    $st = $pdo->prepare('INSERT INTO ib_lang (constant, ca, en, es, eu) VALUES (?, ?, ?, ?, ?)');
    foreach ($rows as $key => $t) {
        $st->execute([$key, $t['ca'] ?? null, $t['en'] ?? null, $t['es'] ?? null, $t['eu'] ?? null]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $fail('Seeding failed: ' . $e->getMessage());
}

printf("Done: %d translations, administrator %s (id %d).\n", count($rows), $email, $adminId);
echo "Next: point your web server's document root to public/ and open the site to log in.\n";
