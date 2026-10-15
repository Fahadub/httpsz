<?php
/**
 * WOAIBench.si — application bootstrap.
 * Loaded by index.php (web), cron/*.php (CLI) and install/ (installer, with WB_INSTALLER defined).
 */
declare(strict_types=1);

if (!defined('WB')) {
    define('WB', true);
}
define('WB_VERSION', '1.0.0');
define('WB_ROOT', dirname(__DIR__));
define('WB_APP', WB_ROOT . '/app');
define('WB_STORAGE', WB_ROOT . '/storage');
define('WB_UPLOADS', WB_ROOT . '/uploads');
define('WB_CONFIG_FILE', WB_ROOT . '/config/config.local.php');
define('WB_CLI', PHP_SAPI === 'cli');

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('WOAIBench requires PHP 8.1 or newer. Current: ' . PHP_VERSION);
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

require WB_APP . '/core/helpers.php';
require WB_APP . '/core/Config.php';
require WB_APP . '/core/DB.php';
require WB_APP . '/core/Settings.php';
require WB_APP . '/core/I18n.php';
require WB_APP . '/core/Csrf.php';
require WB_APP . '/core/Auth.php';
require WB_APP . '/core/RateLimit.php';
require WB_APP . '/core/Router.php';
require WB_APP . '/core/View.php';
require WB_APP . '/core/Http.php';
require WB_APP . '/core/ui.php';

// Autoload app classes: WB\Eval\Worker -> app/eval/Worker.php (namespace segments lower-cased as dirs).
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'WB\\', 3) !== 0) {
        return;
    }
    $parts = explode('\\', substr($class, 3));
    $name = array_pop($parts);
    $dir = $parts ? strtolower(implode('/', $parts)) . '/' : 'core/';
    $file = WB_APP . '/' . $dir . $name . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Config::load();

$debug = (bool) Config::get('debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', WB_STORAGE . '/logs/php-error.log');

set_exception_handler(static function (\Throwable $e) use ($debug): void {
    log_error('Uncaught ' . get_class($e) . ': ' . $e->getMessage(), [
        'file' => $e->getFile() . ':' . $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    if (WB_CLI) {
        fwrite(STDERR, $e . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (wants_json()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => $debug ? $e->getMessage() : 'Server error']);
        return;
    }
    try {
        View::render('errors/500', ['message' => $debug ? (string) $e : null], 'layout');
    } catch (\Throwable $inner) {
        echo '<h1>500</h1><p>Server error.</p>';
        if ($debug) {
            echo '<pre>' . e((string) $e) . '</pre>';
        }
    }
});

if (!WB_CLI && !defined('WB_INSTALLER')) {
    Http::sendSecurityHeaders();
    if (!Config::installed()) {
        header('Location: ' . base_path() . '/install/');
        exit;
    }
    Auth::startSession();
    I18n::boot();
} elseif (WB_CLI) {
    I18n::boot('en');
}
