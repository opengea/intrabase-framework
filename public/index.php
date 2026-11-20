<?php

declare(strict_types=1);

/**
 * Intrabase — Public Entry Point
 *
 * This is the ONLY file exposed to the web.
 * All other files are above the document root.
 *
 * Apache/Nginx should rewrite all requests to this file.
 */

// ---- Minimum PHP version check ----
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Intrabase requires PHP 8.1 or later. Current: ' . PHP_VERSION);
}

// ---- PHP built-in server (php -S): let it serve existing static files ----
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file)) {
        return false;
    }
}

// ---- Security headers ----
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 0'); // Deprecated; rely on CSP instead
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=UTF-8');

// ---- Autoloader ----
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    exit('Composer autoloader not found. Run: composer install');
}
require_once $autoload;

// ---- Configuration ----
$configFile = dirname(__DIR__) . '/config/app.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('Configuration file not found. Copy config/app.php.example to config/app.php');
}

// ---- Bootstrap & Run ----
use Intrabase\Core\Application;

$app = Application::getInstance();
$app->bootstrap($configFile);
$app->handle();
