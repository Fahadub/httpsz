<?php

declare(strict_types=1);

use Udeyou\Controllers\Api\OtpController;
use Udeyou\Controllers\Web\AdminController;
use Udeyou\Controllers\Web\AuthController;
use Udeyou\Controllers\Web\DashboardController;
use Udeyou\Core\Request;
use Udeyou\Core\Response;
use Udeyou\Core\Router;
use Udeyou\Core\Session;
use Udeyou\Services\ApiException;

// Local dev server (php -S): let it serve real static files directly.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/bootstrap.php';

$request = Request::fromGlobals();
$router = new Router();

// ---------------- API (JSON, authenticated by API key) ----------------
$api = new OtpController();
$router->post('/api/v1/otp/send', [$api, 'send']);
$router->post('/api/v1/otp/verify', [$api, 'verify']);
$router->get('/api/v1/balance', [$api, 'balance']);
$router->get('/api/v1/health', fn () => Response::json(['success' => true, 'status' => 'ok']));

// ---------------- Web dashboard (session + CSRF) ----------------
$auth = new AuthController();
$dash = new DashboardController();
$admin = new AdminController();
$router->get('/', [$dash, 'home']);
$router->get('/docs', [$dash, 'docs']);
$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->get('/register', [$auth, 'showRegister']);
$router->post('/register', [$auth, 'register']);
$router->post('/logout', [$auth, 'logout']);
$router->get('/dashboard', [$dash, 'index']);
$router->post('/keys', [$dash, 'createKey']);
$router->post('/keys/{id}/revoke', [$dash, 'revokeKey']);
$router->get('/admin', [$admin, 'index']);
$router->post('/admin/clients/{id}/credits', [$admin, 'addCredits']);
$router->post('/admin/clients/{id}/toggle', [$admin, 'toggleStatus']);

$isApi = str_starts_with($request->path, '/api/');
if (!$isApi) {
    Session::start();
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

try {
    $router->dispatch($request);
} catch (ApiException $e) {
    Response::apiError($e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
} catch (\Throwable $e) {
    error_log('[udeyou] ' . $e);
    if ($isApi) {
        Response::apiError('server_error', 'Internal server error', 500);
    }
    http_response_code(500);
    echo 'حدث خطأ في الخادم';
}
