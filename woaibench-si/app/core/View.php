<?php
/**
 * PHP templates in app/views. Pages render inside a layout:
 *
 *   View::render('pages/leaderboard', ['rows' => $rows, 'title' => __('nav.leaderboard')]);
 *   View::render('admin/models', [...], 'admin');   // admin layout (app/views/admin/_layout.php)
 *   View::render('pages/embed', [...], null);        // no layout
 *
 * Layout variables (all optional, set them in $vars):
 *   title, description, canonical, og_image, body_class, nav_active,
 *   page_css (string[] relative to /assets), page_js (string[] relative to /assets),
 *   json_ld (array|array[]), robots ('noindex' etc.), hide_chrome (bool)
 */
declare(strict_types=1);

defined('WB') || exit;

final class View
{
    /** Shared variables available to every template. */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $vars = [], ?string $layout = 'layout'): void
    {
        $content = self::capture($template, $vars);
        if ($layout === null) {
            echo $content;
            return;
        }
        $layoutFile = $layout === 'admin' ? 'admin/_layout' : $layout;
        echo self::capture($layoutFile, array_merge($vars, ['content' => $content]));
    }

    /** Render a template to a string. */
    public static function capture(string $template, array $vars = []): string
    {
        $file = WB_APP . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $template);
        }
        $vars = array_merge(self::$shared, $vars);
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $vars);
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}

function view(string $template, array $vars = [], ?string $layout = 'layout'): void
{
    View::render($template, $vars, $layout);
}

/** Render a partial and return HTML: partial('partials/model-card', ['m' => $m]) */
function partial(string $template, array $vars = []): string
{
    return View::capture($template, $vars);
}
