<?php

// Deletes OTP records older than N days (default 30). Run daily from hPanel > Advanced > Cron Jobs:
//   php /home/USER/domains/udeyou.com/public_html/bin/cleanup.php 30

declare(strict_types=1);

use Udeyou\Core\Clock;
use Udeyou\Repositories\OtpRepository;

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$days = max(1, (int) ($argv[1] ?? 30));
$deleted = (new OtpRepository())->deleteOlderThan(Clock::minusSeconds($days * 86400));
echo "Deleted {$deleted} OTP records older than {$days} days.\n";
