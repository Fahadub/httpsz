<?php
/**
 * Brand Studio: image generation via OpenRouter, GD re-encoding into uploads/brand, active asset per kind.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;
use Config;
use Settings;
use Throwable;

final class BrandStudio
{
    public const KINDS = ['logo', 'wordmark', 'hero', 'og'];
    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];
    private const MAX_BYTES = 25 * 1024 * 1024;
    private const MAX_SIDE = 4096;

    /** Premium prompt presets (dark obsidian + champagne gold identity). */
    public static function presets(): array
    {
        $style = 'Luxury editorial art direction, deep obsidian black (#07080C) background, champagne-gold (#D9B26A) metallic accents with subtle ivory highlights, '
            . 'precise hairline geometry, soft studio lighting, high-end print quality, no text artifacts, no watermark, no third-party logos or brand marks.';
        return [
            'logo' => [
                'size' => '1024x1024',
                'prompt' => 'A minimalist monogram logo mark for a scientific AI benchmark called "WOAIBench". A single elegant symbol that fuses a precision measuring gauge with an abstract neural node, '
                    . 'built from fine champagne-gold lines on a flat obsidian square, perfectly centred with generous margins, symmetric, vector-like, readable at 32 pixels. ' . $style,
            ],
            'wordmark' => [
                'size' => '1536x1024',
                'prompt' => 'A refined horizontal wordmark reading exactly "WOAIBench.si" set in a high-contrast modern serif, "WOAI" in ivory, "Bench" in champagne gold, ".si" in a small muted gold, '
                    . 'perfect kerning, centred on a flat obsidian background, ample negative space. ' . $style,
            ],
            'hero' => [
                'size' => '1536x1024',
                'prompt' => 'An abstract cinematic hero background for an AI benchmark website: flowing champagne-gold data contours and faint measurement grids receding into darkness, '
                    . 'delicate particles like a star chart, strong negative space on the left third for headline text, subtle depth of field, ultra wide composition. ' . $style,
            ],
            'og' => [
                'size' => '1536x1024',
                'prompt' => 'A social share card background (will be cropped to 1200x630): a centered constellation of thin champagne-gold rings and scoring bars suggesting a leaderboard, '
                    . 'soft vignette, calm and authoritative, empty centre band for overlaid text, no letters. ' . $style,
            ],
        ];
    }

    /** Generate an image and store it. Returns ['ok' => bool, 'id' => ?int, 'error' => ?string]. */
    public static function generate(string $kind, string $prompt, string $model, string $size): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            return ['ok' => false, 'id' => null, 'error' => \__('admin.brand.err_kind')];
        }
        if ((string) Config::get('openrouter_key', '') === '') {
            return ['ok' => false, 'id' => null, 'error' => \__('admin.sync.err_key')];
        }
        if (!Admin::engine('OpenRouter')) {
            return ['ok' => false, 'id' => null, 'error' => \__('admin.engine_missing', ['c' => 'WB\\Eval\\OpenRouter'])];
        }
        try {
            $res = (new \WB\Eval\OpenRouter())->images($prompt, $model, $size);
        } catch (Throwable $e) {
            \log_error('Brand image generation failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'id' => null, 'error' => $e->getMessage()];
        }
        if (empty($res['ok']) || !is_string($res['bytes'] ?? null) || $res['bytes'] === '') {
            return ['ok' => false, 'id' => null, 'error' => (string) ($res['error'] ?? \__('admin.brand.err_empty'))];
        }
        $saved = self::store($res['bytes'], $kind);
        if (!$saved['ok']) {
            return ['ok' => false, 'id' => null, 'error' => $saved['error']];
        }
        $id = DB::insert('brand_assets', [
            'kind' => $kind, 'prompt' => mb_substr($prompt, 0, 4000), 'model' => mb_substr($model, 0, 190),
            'path' => $saved['path'], 'width' => $saved['w'], 'height' => $saved['h'], 'is_active' => 0,
        ]);
        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    /**
     * Validate + re-encode image bytes with GD (drops any embedded payloads), save as PNG.
     * OG cards are cover-cropped to 1200x630.
     */
    public static function store(string $bytes, string $kind): array
    {
        if (!function_exists('imagecreatefromstring')) {
            return ['ok' => false, 'error' => \__('admin.brand.err_gd')];
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => \__('admin.brand.err_large')];
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 16 || $info[1] < 16 || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            return ['ok' => false, 'error' => \__('admin.brand.err_invalid')];
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return ['ok' => false, 'error' => \__('admin.brand.err_invalid')];
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $out = $kind === 'og' ? self::cover($src, $w, $h, 1200, 630) : self::copy($src, $w, $h);
        imagedestroy($src);
        $dir = WB_UPLOADS . '/brand';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            imagedestroy($out);
            return ['ok' => false, 'error' => \__('admin.brand.err_write')];
        }
        $name = $kind . '-' . gmdate('Ymd-His') . '-' . substr(\random_token(3), 0, 6) . '.png';
        $ok = imagepng($out, $dir . '/' . $name, 6);
        $ow = imagesx($out);
        $oh = imagesy($out);
        imagedestroy($out);
        if (!$ok) {
            return ['ok' => false, 'error' => \__('admin.brand.err_write')];
        }
        return ['ok' => true, 'path' => 'brand/' . $name, 'w' => $ow, 'h' => $oh, 'error' => null];
    }

    private static function copy(\GdImage $src, int $w, int $h): \GdImage
    {
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        return $dst;
    }

    private static function cover(\GdImage $src, int $w, int $h, int $tw, int $th): \GdImage
    {
        $scale = max($tw / $w, $th / $h);
        $cw = (int) round($tw / $scale);
        $ch = (int) round($th / $scale);
        $sx = (int) max(0, ($w - $cw) / 2);
        $sy = (int) max(0, ($h - $ch) / 2);
        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $tw, $th, $cw, $ch);
        return $dst;
    }

    /** Make an asset the active one of its kind and publish it as setting brand_<kind>. */
    public static function activate(int $id): bool
    {
        $row = DB::one('SELECT id, kind, path FROM brand_assets WHERE id = ?', [$id]);
        if (!$row) {
            return false;
        }
        DB::tx(static function () use ($row): void {
            DB::exec('UPDATE brand_assets SET is_active = 0 WHERE kind = ?', [$row['kind']]);
            DB::exec('UPDATE brand_assets SET is_active = 1 WHERE id = ?', [(int) $row['id']]);
        });
        Settings::set('brand_' . $row['kind'], $row['path']);
        return true;
    }

    /** Remove the active flag of a kind (site falls back to the built-in SVG). */
    public static function deactivate(int $id): bool
    {
        $row = DB::one('SELECT id, kind FROM brand_assets WHERE id = ?', [$id]);
        if (!$row) {
            return false;
        }
        DB::exec('UPDATE brand_assets SET is_active = 0 WHERE id = ?', [$id]);
        Settings::set('brand_' . $row['kind'], '');
        return true;
    }

    /** Delete an asset and its file. */
    public static function delete(int $id): bool
    {
        $row = DB::one('SELECT * FROM brand_assets WHERE id = ?', [$id]);
        if (!$row) {
            return false;
        }
        $file = WB_UPLOADS . '/' . $row['path'];
        if (str_starts_with((string) $row['path'], 'brand/') && !str_contains((string) $row['path'], '..') && is_file($file)) {
            @unlink($file);
        }
        DB::exec('DELETE FROM brand_assets WHERE id = ?', [$id]);
        if ((int) $row['is_active'] === 1) {
            Settings::set('brand_' . $row['kind'], '');
        }
        return true;
    }
}
