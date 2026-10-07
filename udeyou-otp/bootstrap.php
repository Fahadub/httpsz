<?php

declare(strict_types=1);

use Udeyou\Core\Config;

require __DIR__ . '/vendor/autoload.php';

date_default_timezone_set('UTC');
Config::load(__DIR__ . '/.env');

$debug = Config::get('APP_ENV', 'production') !== 'production';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/storage/logs/php-error.log');
error_reporting(E_ALL);
