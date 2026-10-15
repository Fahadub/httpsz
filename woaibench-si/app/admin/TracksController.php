<?php
/**
 * Tracks: bilingual names/descriptions, icon, colour, auto weight, order, active flag.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;

final class TracksController
{
    /** Icons offered in the picker (subset of the sprite that suits tracks). */
    public const ICONS = [
        'palette', 'gamepad', 'cube', 'sparkles', 'chart', 'code', 'bug', 'robot', 'brain', 'shield', 'lock', 'accessibility',
        'type', 'layers', 'globe', 'bolt', 'flask', 'terminal', 'database', 'server', 'target', 'wand', 'compass', 'book',
    ];

    /** GET /admin/tracks */
    public static function index(): void
    {
        \require_admin();
        $tracks = DB::all(
            'SELECT tr.*, COUNT(t.id) AS task_total, COALESCE(SUM(t.is_active = 1), 0) AS task_active
               FROM tracks tr LEFT JOIN tasks t ON t.track_id = tr.id GROUP BY tr.id ORDER BY tr.sort, tr.id'
        );
        $errors = $_SESSION['admin_track_errors'] ?? [];
        unset($_SESSION['admin_track_errors']);
        Admin::render('tracks', [
            'title' => \__('admin.nav.tracks'),
            'nav_active' => 'tracks',
            'tracks' => $tracks,
            'icons' => self::ICONS,
            'errors' => is_array($errors) ? $errors : [],
        ]);
    }

    /** POST /admin/tracks/{id} */
    public static function save(int $id): void
    {
        Admin::guardPost();
        if (!DB::val('SELECT id FROM tracks WHERE id = ?', [$id])) {
            \abort(404);
        }
        $s = static fn(string $k, int $max) => mb_substr(trim((string) \input($k, '')), 0, $max);
        $data = [
            'name_ar' => $s('name_ar', 120), 'name_en' => $s('name_en', 120),
            'desc_ar' => $s('desc_ar', 2000) ?: null, 'desc_en' => $s('desc_en', 2000) ?: null,
            'icon' => $s('icon', 40), 'color' => strtoupper($s('color', 9)),
            'sort' => \input_int('sort', 0, -1000, 1000),
            'is_active' => \input('is_active') ? 1 : 0,
        ];
        $errors = [];
        if ($data['name_ar'] === '' || $data['name_en'] === '') {
            $errors[] = \__('admin.tracks.err_names');
        }
        if (!preg_match('/^[a-z0-9\-]{1,40}$/', $data['icon'])) {
            $errors[] = \__('admin.tracks.err_icon');
        }
        if (!preg_match('/^#[0-9A-F]{6}$/', $data['color'])) {
            $errors[] = \__('admin.v.color');
        }
        $aw = trim((string) \input('auto_weight', ''));
        if (!is_numeric($aw) || (float) $aw < 0 || (float) $aw > 1) {
            $errors[] = \__('admin.tracks.err_weight');
        } else {
            $data['auto_weight'] = round((float) $aw, 3);
        }
        if ($errors) {
            $_SESSION['admin_track_errors'] = [$id => $errors];
            \flash('error', \__('admin.form_errors'));
            \redirect('/admin/tracks#track-' . $id);
        }
        DB::update('tracks', $data, 'id = ?', [$id]);
        \Settings::set('aggregate_dirty', 1);
        \flash('success', \__('admin.tracks.saved', ['name' => $data['name_' . \lang()]]));
        \redirect('/admin/tracks#track-' . $id);
    }
}
