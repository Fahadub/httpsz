<?php
// Copy to config.local.php (the web installer at /install/ writes it for you).
// NEVER commit config.local.php — it holds your database password and API key.
defined('WB') || exit;

return [
    'db' => [
        'host' => 'srv1513.hstgr.io',       // on Hostinger itself you can usually use 'localhost'
        'port' => 3306,
        'name' => 'u000000000_dbname',
        'user' => 'u000000000_dbuser',
        'pass' => 'change-me',
    ],
    'openrouter_key' => 'sk-or-v1-...',     // https://openrouter.ai/keys
    'openrouter_base' => 'https://openrouter.ai/api/v1',
    'app_secret' => 'run: php -r "echo bin2hex(random_bytes(32));"',
    'site_url' => 'https://woaibench.si',
    'base_path' => '',                      // '' when installed at the domain root
    'debug' => false,
    'trust_proxy' => false,                 // true only behind Cloudflare / a trusted reverse proxy
    'cron_key' => '',                       // optional: enables https://site/worker/tick?key=... for external web-cron services
    'csp_extra_script' => '',               // e.g. 'https://www.googletagmanager.com' if you add analytics
];
