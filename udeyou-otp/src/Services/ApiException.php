<?php

declare(strict_types=1);

namespace Udeyou\Services;

/** A business error that maps 1:1 to an API JSON error response. */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
