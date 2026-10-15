<?php
/**
 * Brand Studio pages: presets + free prompt -> image generation, gallery, active asset per kind.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;

final class BrandController
{
    /** GET /admin/brand */
    public static function index(): void
    {
        \require_admin();
        $kind = Admin::str('kind', '');
        $where = in_array($kind, BrandStudio::KINDS, true) ? ' WHERE kind = ?' : '';
        Admin::render('brand', [
            'title' => \__('admin.nav.brand'),
            'nav_active' => 'brand',
            'presets' => BrandStudio::presets(),
            'kinds' => BrandStudio::KINDS,
            'sizes' => BrandStudio::SIZES,
            'assets' => DB::all('SELECT * FROM brand_assets' . $where . ' ORDER BY id DESC LIMIT 60', $where ? [$kind] : []),
            'filter' => $kind,
            'image_model' => (string) \setting('image_model'),
            'gd' => function_exists('imagecreatefromstring'),
            'writable' => is_dir(WB_UPLOADS . '/brand') ? is_writable(WB_UPLOADS . '/brand') : is_writable(WB_UPLOADS),
        ]);
    }

    /** POST /admin/brand/generate */
    public static function generate(): void
    {
        Admin::guardPost();
        $kind = Admin::str('kind', 'logo');
        $prompt = trim(Admin::str('prompt', ''));
        $model = trim(Admin::str('model', '')) ?: (string) \setting('image_model');
        $size = Admin::str('size', '1024x1024');
        if ($prompt === '' || mb_strlen($prompt) > 4000) {
            \flash('error', \__('admin.brand.err_prompt'));
            \redirect('/admin/brand');
        }
        if (!Admin::validOrId($model)) {
            \flash('error', \__('admin.v.or_id'));
            \redirect('/admin/brand');
        }
        if (!in_array($size, BrandStudio::SIZES, true)) {
            $size = '1024x1024';
        }
        if (!\rate_limit('admin_brand:' . (\auth_user()['id'] ?? 0), 30, 3600)) {
            \flash('error', \__('admin.login.too_many'));
            \redirect('/admin/brand');
        }
        @set_time_limit(300);
        $res = BrandStudio::generate($kind, $prompt, $model, $size);
        if ($res['ok']) {
            \flash('success', \__('admin.brand.generated'));
        } else {
            \flash('error', \__('admin.brand.failed', ['msg' => (string) $res['error']]));
        }
        \redirect('/admin/brand');
    }

    /** POST /admin/brand/{id}/action  action=activate|deactivate|delete */
    public static function action(int $id): void
    {
        Admin::guardPost();
        $action = Admin::str('action');
        $ok = match ($action) {
            'activate' => BrandStudio::activate($id),
            'deactivate' => BrandStudio::deactivate($id),
            'delete' => BrandStudio::delete($id),
            default => \abort(400, 'Unknown action'),
        };
        \flash($ok ? 'success' : 'error', $ok ? \__($action === 'delete' ? 'admin.deleted' : 'admin.saved') : \__('admin.not_found'));
        \redirect('/admin/brand');
    }
}
