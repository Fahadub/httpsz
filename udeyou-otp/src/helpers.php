<?php

declare(strict_types=1);

/** HTML-escape helper used by the view templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
