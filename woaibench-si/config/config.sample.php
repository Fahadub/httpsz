<?php
// Copy to config.local.php (the web installer at /install/ writes it for you).
// NEVER commit config.local.php — it holds your database password and API key.
defined('WB') || exit;

return [
    'db' => [
        'host' => 'localhost',              // on Hostinger always 'localhost' (srvXXXX.hstgr.io is only for remote clients and needs Remote MySQL)
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
    // Forwarded client IPs are believed only from these proxies: false (direct traffic, the default),
    // 'cloudflare' (Cloudflare's published edge ranges; uses CF-Connecting-IP), a list of proxy IPs/CIDRs
    // such as ['203.0.113.10', '10.0.0.0/8'], or true (legacy: private-network proxies only).
    'trust_proxy' => false,
    'cron_key' => '',                       // optional: enables https://site/worker/tick?key=... for external web-cron services
    'csp_extra_script' => '',               // e.g. 'https://www.googletagmanager.com' if you add analytics
];
