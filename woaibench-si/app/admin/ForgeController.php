<?php
/**
 * Task Forge pages: generate drafts with an LLM, review/edit, save as inactive hold-out tasks.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;

final class ForgeController
{
    private const SESSION_KEY = 'admin_forge';

    /** GET /admin/forge */
    public static function index(): void
    {
        \require_admin();
        $state = $_SESSION[self::SESSION_KEY] ?? [];
        Admin::render('forge', [
            'title' => \__('admin.nav.forge'),
            'nav_active' => 'forge',
            'tracks' => DB::all('SELECT id, slug, name_en, name_ar, desc_en FROM tracks WHERE is_active = 1 ORDER BY sort, id'),
            'model' => Forge::model(),
            'drafts' => is_array($state['drafts'] ?? null) ? $state['drafts'] : [],
            'opts' => is_array($state['opts'] ?? null) ? $state['opts'] : [],
            'meta' => is_array($state['meta'] ?? null) ? $state['meta'] : [],
            'recent' => DB::all("SELECT id, slug, title_en, title_ar, is_active, created_at FROM tasks WHERE source = 'forge' ORDER BY id DESC LIMIT 8"),
        ]);
    }

    /** POST /admin/forge — generate drafts. */
    public static function generate(): void
    {
        Admin::guardPost();
        $track = DB::one('SELECT * FROM tracks WHERE slug = ?', [(string) \input('track', '')]);
        $type = (string) \input('output_type', 'html');
        $opts = [
            'track' => $track['slug'] ?? '',
            'difficulty' => \input_int('difficulty', 2, 1, 4),
            'output_type' => in_array($type, TaskValidator::OUTPUT_TYPES, true) ? $type : 'html',
            'count' => \input_int('count', 1, 1, 5),
            'theme' => mb_substr(trim((string) \input('theme', '')), 0, 300),
        ];
        if (!$track) {
            \flash('error', \__('admin.v.unknown_track', ['v' => (string) \input('track', '')]));
            \redirect('/admin/forge');
        }
        if (!\rate_limit('admin_forge:' . (\auth_user()['id'] ?? 0), 20, 3600)) {
            \flash('error', \__('admin.login.too_many'));
            \redirect('/admin/forge');
        }
        $res = Forge::generate($track, $opts['difficulty'], $opts['output_type'], $opts['count'], $opts['theme']);
        $_SESSION[self::SESSION_KEY] = [
            'opts' => $opts,
            'drafts' => $res['ok'] ? $res['drafts'] : ($_SESSION[self::SESSION_KEY]['drafts'] ?? []),
            'meta' => ['model' => $res['model'], 'cost' => $res['cost'], 'at' => \now()],
        ];
        if (!$res['ok']) {
            \flash('error', \__('admin.forge.failed', ['msg' => (string) $res['error']]));
        } else {
            \flash('success', \__('admin.forge.generated', ['n' => count($res['drafts'])]));
        }
        \redirect('/admin/forge');
    }

    /** POST /admin/forge/save — validate the (possibly edited) draft JSON and store it. */
    public static function save(): void
    {
        Admin::guardPost();
        $idx = \input_int('index', -1, -1, 10);
        $json = is_string($_POST['json'] ?? null) ? $_POST['json'] : '';
        $state = $_SESSION[self::SESSION_KEY] ?? ['drafts' => []];
        $data = json_decode($json, true);
        $slugs = array_keys(TaskStore::trackIds());
        if (!is_array($data) || array_is_list($data)) {
            self::updateDraft($state, $idx, ['json' => $json, 'errors' => [\__('admin.v.json_object')], 'warnings' => [], 'title' => (string) ($state['drafts'][$idx]['title'] ?? '?')]);
            \flash('error', \__('admin.forge.save_invalid'));
            \redirect('/admin/forge#draft-' . $idx);
        }
        $data['visibility'] = 'holdout';
        $v = TaskValidator::task($data, $slugs);
        if ($v['task']['slug'] !== '' && DB::val('SELECT id FROM tasks WHERE slug = ?', [$v['task']['slug']])) {
            $v['errors'][] = 'slug: ' . \__('admin.v.duplicate', ['v' => $v['task']['slug']]);
        }
        if ($v['errors']) {
            self::updateDraft($state, $idx, ['json' => $json, 'errors' => $v['errors'], 'warnings' => $v['warnings'], 'title' => (string) ($data['title_en'] ?? '?')]);
            \flash('error', \__('admin.forge.save_invalid'));
            \redirect('/admin/forge#draft-' . $idx);
        }
        $id = Forge::save($v['task']);
        if ($idx >= 0 && isset($state['drafts'][$idx])) {
            array_splice($state['drafts'], $idx, 1);
            $_SESSION[self::SESSION_KEY] = $state;
        }
        \flash('success', \__('admin.forge.saved'));
        \redirect('/admin/tasks/' . $id);
    }

    private static function updateDraft(array $state, int $idx, array $draft): void
    {
        if ($idx >= 0) {
            $state['drafts'][$idx] = $draft;
            $_SESSION[self::SESSION_KEY] = $state;
        }
    }

    /** POST /admin/forge/clear — discard drafts (one or all). */
    public static function clear(): void
    {
        Admin::guardPost();
        $idx = \input_int('index', -1, -1, 10);
        $state = $_SESSION[self::SESSION_KEY] ?? [];
        if ($idx >= 0 && isset($state['drafts'][$idx])) {
            array_splice($state['drafts'], $idx, 1);
        } else {
            $state['drafts'] = [];
        }
        $_SESSION[self::SESSION_KEY] = $state;
        \flash('info', \__('admin.forge.discarded'));
        \redirect('/admin/forge');
    }
}
