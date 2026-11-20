#!/usr/bin/env php8
<?php
/**
 * Generate lang/*.php files from ib_lang database table.
 * This is the single source of truth for all UI translations.
 *
 * Usage: php8 build-lang.php [database_name]
 *   database_name defaults to 'intrabase'
 */
declare(strict_types=1);

// Credentials come from config/app.php so the very same script runs on every
// host — never hardcode a host's DB user here.
$config = require __DIR__ . '/../config/app.php';
$dbHost = $config['db_host'] ?? '127.0.0.1';
$dbName = $argv[1] ?? ($config['db_name'] ?? 'intrabase');
$dbUser = $config['db_user'];
$dbPass = $config['db_pass'];
$langDir = '/usr/local/intrabase/lang';
$locales = ['ca', 'es', 'en', 'eu'];

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "[lang] DB connection failed: {$e->getMessage()}\n");
    exit(1);
}

// Fetch all translations
$cols = implode(', ', array_map(fn($l) => "`{$l}`", $locales));
$rows = $pdo->query("SELECT constant, {$cols} FROM ib_lang ORDER BY constant")->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    fwrite(STDERR, "[lang] No translations found in ib_lang table.\n");
    exit(1);
}

$localeNames = [
    'ca' => 'Catalan (Català)',
    'es' => 'Spanish (Español)',
    'en' => 'English',
    'eu' => 'Basque (Euskara)',
];

$generated = 0;

foreach ($locales as $locale) {
    $file = "{$langDir}/{$locale}.php";
    $name = $localeNames[$locale] ?? $locale;

    $lines = [];
    $lines[] = "<?php";
    $lines[] = "/**";
    $lines[] = " * {$name} UI translations for Intrabase";
    $lines[] = " * Auto-generated from ib_lang database — DO NOT EDIT MANUALLY";
    // No generation timestamp on purpose: these files are tracked in git and
    // rebuilt on several hosts — output must be deterministic or the installs
    // ping-pong the same file at each other on every auto-sync.
    $lines[] = " */";
    $lines[] = "return [";

    foreach ($rows as $row) {
        $key = $row['constant'];
        $value = $row[$locale] ?? '';

        // Fall back to English if empty, then to constant key
        if ($value === '' || $value === null) {
            $value = $row['en'] ?? '';
        }
        if ($value === '' || $value === null) {
            $value = $key;
        }

        // Escape single quotes for PHP string
        $escaped = str_replace("'", "\\'", $value);
        $escapedKey = str_replace("'", "\\'", $key);

        $lines[] = "    '{$escapedKey}' => '{$escaped}',";
    }

    $lines[] = "];";
    $lines[] = "";

    $content = implode("\n", $lines);
    file_put_contents($file, $content);
    $generated++;

    echo "[lang] Generated {$locale}.php (" . count($rows) . " keys)\n";
}

echo "[lang] Done: {$generated} locale files generated from " . count($rows) . " translations.\n";

// Generate js_*.php files (JS-specific keys used by frontend t() function)
// ticket_fb_ui_/ticket_fb_cfg_: textos de la valoració que pinta mail-tickets.js (badge, bloc del
// detall, panell de config). Sense, window.t() retornava la clau crua (13/10/2026).
// contact_language / language_name_: camp Idioma de la fitxa (contacts.js); no casen amb
// 'contacts_' i sortien en cru a /contacts (25/10/2026).
$jsPrefixes = ['mail_', 'calendar_', 'contacts_', 'drive_', 'docsed_', 'planner_', 'tickets_', 'chat_', 'meet_', 'day_', 'month_',
               'ticket_fb_ui_', 'ticket_fb_cfg_', 'access_', 'contact_language', 'language_name_'];
$jsGenericKeys = ['tasks', 'folder', 'billing', 'color', 'none', 'no_results', 'concept',
                  'remove', 'previous', 'next', 'close', 'save', 'delete', 'edit', 'cancel',
                  'data', 'other', 'all', 'yes', 'no', 'loading', 'error', 'success',
                  'keyboard_shortcuts', 'client', 'contact', 'supplier', 'project',
                  'duplicate', 'show', 'hide', 'options'];
$jsRows = array_filter($rows, function($row) use ($jsPrefixes, $jsGenericKeys) {
    foreach ($jsPrefixes as $prefix) {
        if (str_starts_with($row['constant'], $prefix)) return true;
    }
    return in_array($row['constant'], $jsGenericKeys, true);
});

$jsPrefixNames = [
    'mail_'      => 'MAIL',
    'calendar_'  => 'CALENDAR',
    'contacts_'  => 'CONTACTS',
    'drive_'     => 'DRIVE',
    'docsed_'    => 'DOCS EDITOR',
    'planner_'   => 'PLANNER',
    'tickets_'   => 'TICKETS',
    'chat_'      => 'CHAT',
    'ticket_fb_' => 'TICKET FEEDBACK',
    'access_'    => 'ACCESS',
];

foreach ($locales as $locale) {
    $file = "{$langDir}/js_{$locale}.php";
    $name = $localeNames[$locale] ?? $locale;

    $lines = [];
    $lines[] = "<?php";
    $lines[] = "/**";
    $lines[] = " * {$name} JS translations for Intrabase frontend (mail, drive, planner, contacts, etc.)";
    $lines[] = " * Auto-generated from ib_lang database — DO NOT EDIT MANUALLY";
    // No generation timestamp on purpose: these files are tracked in git and
    // rebuilt on several hosts — output must be deterministic or the installs
    // ping-pong the same file at each other on every auto-sync.
    $lines[] = " */";
    $lines[] = "return [";

    $currentSection = '';
    foreach ($jsRows as $row) {
        $key = $row['constant'];
        // Determine section
        foreach ($jsPrefixNames as $prefix => $sectionName) {
            if (str_starts_with($key, $prefix)) {
                if ($currentSection !== $sectionName) {
                    if ($currentSection !== '') $lines[] = "";
                    $lines[] = "    // === {$sectionName} ===";
                    $currentSection = $sectionName;
                }
                break;
            }
        }

        $value = $row[$locale] ?? '';
        if ($value === '' || $value === null) {
            $value = $row['en'] ?? '';
        }
        if ($value === '' || $value === null) {
            $value = $key;
        }

        $escaped = str_replace("'", "\\'", $value);
        $escapedKey = str_replace("'", "\\'", $key);

        $lines[] = "    '{$escapedKey}' => '{$escaped}',";
    }

    $lines[] = "];";
    $lines[] = "";

    file_put_contents($file, implode("\n", $lines));
    echo "[lang] Generated js_{$locale}.php (" . count($jsRows) . " JS keys)\n";
}

// Sync ib_lang to all tenant databases
$tenantDbs = [];
try {
    $tenantRows = $pdo->query("SELECT db_name FROM ib_tenants WHERE db_name != '{$dbName}' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    $tenantDbs = $tenantRows ?: [];
} catch (PDOException $e) {
    // ib_tenants may not exist — skip sync silently
}

foreach ($tenantDbs as $tenant) {
    try {
        $tPdo = new PDO(
            "mysql:host={$dbHost};dbname={$tenant};charset=utf8mb4",
            $dbUser, $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $colsList = implode(', ', array_map(fn($l) => "`{$l}`", $locales));
        $placeholders = implode(', ', array_fill(0, count($locales), '?'));
        $updateCols = implode(', ', array_map(fn($l) => "`{$l}` = VALUES(`{$l}`)", $locales));
        $stmtSync = $tPdo->prepare(
            "INSERT INTO ib_lang (constant, {$colsList}) VALUES (?, {$placeholders})
             ON DUPLICATE KEY UPDATE {$updateCols}"
        );
        foreach ($rows as $row) {
            $params = [$row['constant']];
            foreach ($locales as $l) {
                $params[] = $row[$l] ?? '';
            }
            $stmtSync->execute($params);
        }
        echo "[lang] Synced " . count($rows) . " translations to tenant: {$tenant}\n";
    } catch (PDOException $e) {
        fwrite(STDERR, "[lang] Warning: could not sync to {$tenant}: {$e->getMessage()}\n");
    }
}
