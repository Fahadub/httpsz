<?php

declare(strict_types=1);

namespace Udeyou\Core;

final class View
{
    public static function render(string $template, array $data = []): void
    {
        $viewsDir = dirname(__DIR__, 2) . '/views';
        extract($data, EXTR_SKIP);
        ob_start();
        require $viewsDir . '/' . $template . '.php';
        $content = ob_get_clean();
        require $viewsDir . '/layout.php';
    }
}

