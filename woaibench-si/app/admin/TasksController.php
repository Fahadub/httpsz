<?php
/**
 * Tasks: filtered list, create/edit with JSON validation, duplicate, (de)activate, import/export.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;

final class TasksController
{
    private const PER_PAGE = 50;
    private const IMPORT_MAX_BYTES = 8 * 1024 * 1024;

    /** Filters shared by list and export. Returns [where SQL, params, filters]. */
    private static function filters(): array
    {
        $f = [
            'track' => (string) \input('track', ''),
            'visibility' => (string) \input('visibility', ''),
            'active' => (string) \input('active', ''),
            'type' => (string) \input('type', ''),
            'source' => (string) \input('source', ''),
            'q' => mb_substr((string) \input('q', ''), 0, 100),
        ];
        $w = [];
        $p = [];
        if ($f['track'] !== '') {
            $w[] = 'tr.slug = ?';
            $p[] = $f['track'];
        }
        if (in_array($f['visibility'], TaskValidator::VISIBILITY, true)) {
            $w[] = 't.visibility = ?';
            $p[] = $f['visibility'];
        }
        if ($f['active'] === '1' || $f['active'] === '0') {
            $w[] = 't.is_active = ?';
            $p[] = (int) $f['active'];
        }
        if (in_array($f['type'], TaskValidator::OUTPUT_TYPES, true)) {
            $w[] = 't.output_type = ?';
            $p[] = $f['type'];
        }
        if (in_array($f['source'], TaskStore::SOURCES, true)) {
            $w[] = 't.source = ?';
            $p[] = $f['source'];
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $w[] = '(t.slug LIKE ? OR t.title_en LIKE ? OR t.title_ar LIKE ?)';
            array_push($p, $like, $like, $like);
        }
        return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p, $f];
    }

    /** GET /admin/tasks */
    public static function index(): void
    {
        \require_admin();
        [$where, $params, $f] = self::filters();
        $total = (int) DB::val('SELECT COUNT(*) FROM tasks t JOIN tracks tr ON tr.id = t.track_id' . $where, $params);
        [$page, $pages, $offset] = Admin::paginate($total, self::PER_PAGE);
        $rows = DB::all(
            'SELECT t.id, t.slug, t.title_en, t.title_ar, t.output_type, t.difficulty, t.visibility, t.is_active, t.version, t.source,
                    t.updated_at, tr.slug AS track_slug, tr.name_en, tr.name_ar, tr.icon, tr.color,
                    (SELECT COUNT(*) FROM runs r WHERE r.task_id = t.id) AS run_count
               FROM tasks t JOIN tracks tr ON tr.id = t.track_id' . $where
            . ' ORDER BY tr.sort, t.is_active DESC, t.id LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset,
            $params
        );
        Admin::render('tasks', [
            'title' => \__('admin.nav.tasks'),
            'nav_active' => 'tasks',
            'tasks' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => $f,
            'tracks' => DB::all('SELECT slug, name_en, name_ar FROM tracks ORDER BY sort, id'),
        ]);
    }

    /** GET /admin/tasks/{id} | /admin/tasks/new */
    public static function edit(?int $id): void
    {
        \require_admin();
        $tracks = DB::all('SELECT id, slug, name_en, name_ar, auto_weight FROM tracks ORDER BY sort, id');
        $task = $id === null ? null : DB::one('SELECT t.*, tr.slug AS track FROM tasks t JOIN tracks tr ON tr.id = t.track_id WHERE t.id = ?', [$id]);
        if ($id !== null && !$task) {
            \abort(404);
        }
        $form = $task ? [
            'slug' => $task['slug'], 'track' => $task['track'], 'title_en' => $task['title_en'], 'title_ar' => $task['title_ar'],
            'summary_en' => (string) $task['summary_en'], 'summary_ar' => (string) $task['summary_ar'], 'prompt' => $task['prompt'],
            'output_type' => $task['output_type'], 'difficulty' => (int) $task['difficulty'],
            'rubric_json' => Admin::pretty($task['rubric_json'] ?: '[]'), 'checks_json' => Admin::pretty($task['checks_json'] ?: '{}'),
            'auto_weight' => $task['auto_weight'] !== null ? (string) (float) $task['auto_weight'] : '',
            'visibility' => $task['visibility'], 'is_active' => (int) $task['is_active'],
        ] : [
            'slug' => '', 'track' => $tracks[0]['slug'] ?? '', 'title_en' => '', 'title_ar' => '', 'summary_en' => '', 'summary_ar' => '',
            'prompt' => '', 'output_type' => 'html', 'difficulty' => 2, 'rubric_json' => self::rubricTemplate(), 'checks_json' => self::checksTemplate(),
            'auto_weight' => '', 'visibility' => 'public', 'is_active' => 0,
        ];
        $old = $_SESSION['admin_task_old'] ?? null;
        $result = $_SESSION['admin_task_result'] ?? null;
        unset($_SESSION['admin_task_old'], $_SESSION['admin_task_result']);
        if (is_array($old)) {
            $form = array_merge($form, $old);
        }
        Admin::render('task_edit', [
            'title' => $task ? $task['title_' . \lang()] : \__('admin.tasks.new'),
            'nav_active' => 'tasks',
            'task' => $task,
            'form' => $form,
            'tracks' => $tracks,
            'result' => is_array($result) ? $result : null,
            'spec' => TaskValidator::spec(),
            'run_count' => $task ? (int) DB::val('SELECT COUNT(*) FROM runs WHERE task_id = ?', [$id]) : 0,
        ]);
    }

    private static function rubricTemplate(): string
    {
        return Admin::pretty([
            ['key' => 'correctness', 'label_en' => 'Correctness', 'label_ar' => 'صحة التنفيذ', 'weight' => 3, 'guide' => 'Every stated requirement works as specified.'],
            ['key' => 'visual', 'label_en' => 'Visual polish', 'label_ar' => 'الإتقان البصري', 'weight' => 2, 'guide' => 'Cohesive palette, typography and spacing.'],
            ['key' => 'code', 'label_en' => 'Code quality', 'label_ar' => 'جودة الشيفرة', 'weight' => 1, 'guide' => 'Readable, structured, no dead code.'],
        ]);
    }

    private static function checksTemplate(): string
    {
        return Admin::pretty([
            'static' => [['id' => 'viewport', 'type' => 'viewport', 'weight' => 1, 'label_en' => 'Has viewport meta', 'label_ar' => 'يحتوي وسم viewport']],
            'browser' => [
                ['id' => 'loads', 'type' => 'loads', 'weight' => 2, 'label_en' => 'Page loads', 'label_ar' => 'الصفحة تُحمَّل'],
                ['id' => 'no_errors', 'type' => 'no_errors', 'weight' => 2, 'label_en' => 'No JS errors', 'label_ar' => 'لا أخطاء JavaScript'],
            ],
        ]);
    }

    /** Collect the edit form into import format. */
    private static function formInput(): array
    {
        $s = static fn(string $k) => is_string($_POST[$k] ?? null) ? (string) $_POST[$k] : '';
        return [
            'slug' => strtolower(trim($s('slug'))), 'track' => trim($s('track')), 'title_en' => $s('title_en'), 'title_ar' => $s('title_ar'),
            'summary_en' => $s('summary_en'), 'summary_ar' => $s('summary_ar'), 'prompt' => str_replace("\r\n", "\n", $s('prompt')),
            'output_type' => $s('output_type'), 'difficulty' => $s('difficulty'), 'rubric' => $s('rubric_json'), 'checks' => $s('checks_json'),
            'auto_weight' => trim($s('auto_weight')), 'visibility' => $s('visibility'),
        ];
    }

    /** POST /admin/tasks/{id} | /admin/tasks/new */
    public static function save(?int $id): void
    {
        Admin::guardPost();
        if ($id !== null && !DB::val('SELECT id FROM tasks WHERE id = ?', [$id])) {
            \abort(404);
        }
        $in = self::formInput();
        $trackIds = TaskStore::trackIds();
        $v = TaskValidator::task($in, array_keys($trackIds));
        $slugOwner = $v['task']['slug'] !== '' ? DB::val('SELECT id FROM tasks WHERE slug = ?', [$v['task']['slug']]) : null;
        if ($slugOwner && (int) $slugOwner !== (int) $id) {
            $v['errors'][] = 'slug: ' . \__('admin.v.duplicate', ['v' => $v['task']['slug']]);
        }
        $back = $id === null ? '/admin/tasks/new' : '/admin/tasks/' . $id;
        if ($v['errors']) {
            $_SESSION['admin_task_old'] = [
                'slug' => $in['slug'], 'track' => $in['track'], 'title_en' => $in['title_en'], 'title_ar' => $in['title_ar'],
                'summary_en' => $in['summary_en'], 'summary_ar' => $in['summary_ar'], 'prompt' => $in['prompt'], 'output_type' => $in['output_type'],
                'difficulty' => $in['difficulty'], 'rubric_json' => $in['rubric'], 'checks_json' => $in['checks'], 'auto_weight' => $in['auto_weight'],
                'visibility' => $in['visibility'], 'is_active' => \input('is_active') ? 1 : 0,
            ];
            $_SESSION['admin_task_result'] = ['errors' => $v['errors'], 'warnings' => $v['warnings']];
            \flash('error', \__('admin.tasks.invalid', ['n' => count($v['errors'])]));
            \redirect($back);
        }
        $active = \input('is_active') ? 1 : 0;
        if ($id === null) {
            $id = TaskStore::insert($v['task'], $trackIds, 'manual', (bool) $active);
            \flash('success', \__('admin.tasks.created'));
        } else {
            $version = TaskStore::update($id, $v['task'], $trackIds);
            DB::update('tasks', ['is_active' => $active], 'id = ?', [$id]);
            \flash('success', \__('admin.tasks.saved_v', ['v' => $version]));
        }
        if ($v['warnings']) {
            $_SESSION['admin_task_result'] = ['errors' => [], 'warnings' => $v['warnings']];
        }
        \Settings::set('aggregate_dirty', 1);
        \redirect('/admin/tasks/' . $id);
    }

    /** POST /admin/tasks/{id}/action  action=duplicate|activate|deactivate|delete */
    public static function action(int $id): void
    {
        Admin::guardPost();
        $task = DB::one('SELECT id, slug, title_en FROM tasks WHERE id = ?', [$id]);
        if (!$task) {
            \abort(404);
        }
        switch ((string) \input('action')) {
            case 'duplicate':
                $new = TaskStore::duplicate($id);
                \flash('success', \__('admin.tasks.duplicated'));
                \redirect('/admin/tasks/' . $new);
            case 'activate':
            case 'deactivate':
                $on = \input('action') === 'activate';
                DB::update('tasks', ['is_active' => $on ? 1 : 0], 'id = ?', [$id]);
                \Settings::set('aggregate_dirty', 1);
                \flash('success', \__($on ? 'admin.tasks.activated' : 'admin.tasks.deactivated'));
                \redirect_back('/admin/tasks');
            case 'delete':
                if ((int) DB::val('SELECT COUNT(*) FROM runs WHERE task_id = ?', [$id]) > 0) {
                    \flash('error', \__('admin.tasks.err_has_runs'));
                    \redirect('/admin/tasks/' . $id);
                }
                DB::exec('DELETE FROM tasks WHERE id = ?', [$id]);
                \flash('success', \__('admin.deleted'));
                \redirect('/admin/tasks');
            default:
                \abort(400, 'Unknown action');
        }
    }

    /** POST /admin/api/validate-task — server-side validation for the live editor. */
    public static function apiValidate(): void
    {
        Admin::guardPost();
        $b = \json_body();
        $task = [
            'slug' => (string) ($b['slug'] ?? ''), 'track' => (string) ($b['track'] ?? ''), 'title_en' => (string) ($b['title_en'] ?? ''),
            'title_ar' => (string) ($b['title_ar'] ?? ''), 'summary_en' => (string) ($b['summary_en'] ?? ''), 'summary_ar' => (string) ($b['summary_ar'] ?? ''),
            'prompt' => (string) ($b['prompt'] ?? ''), 'output_type' => (string) ($b['output_type'] ?? ''), 'difficulty' => $b['difficulty'] ?? 2,
            'rubric' => (string) ($b['rubric_json'] ?? ''), 'checks' => (string) ($b['checks_json'] ?? ''),
            'auto_weight' => $b['auto_weight'] ?? null, 'visibility' => (string) ($b['visibility'] ?? 'public'),
        ];
        $v = TaskValidator::task($task, array_keys(TaskStore::trackIds()));
        $id = (int) ($b['id'] ?? 0);
        $owner = $v['task']['slug'] !== '' ? DB::val('SELECT id FROM tasks WHERE slug = ?', [$v['task']['slug']]) : null;
        if ($owner && (int) $owner !== $id) {
            $v['errors'][] = 'slug: ' . \__('admin.v.duplicate', ['v' => $v['task']['slug']]);
        }
        \json_out(['ok' => !$v['errors'], 'errors' => $v['errors'], 'warnings' => $v['warnings']]);
    }

    /** GET /admin/tasks/import */
    public static function importForm(): void
    {
        \require_admin();
        $result = $_SESSION['admin_import_result'] ?? null;
        $old = $_SESSION['admin_import_json'] ?? '';
        unset($_SESSION['admin_import_result'], $_SESSION['admin_import_json']);
        Admin::render('task_import', [
            'title' => \__('admin.tasks.import'),
            'nav_active' => 'tasks',
            'result' => is_array($result) ? $result : null,
            'json' => is_string($old) ? $old : '',
            'tracks' => DB::col('SELECT slug FROM tracks ORDER BY sort, id'),
        ]);
    }

    /** POST /admin/tasks/import — file upload or pasted JSON array. */
    public static function import(): void
    {
        Admin::guardPost();
        $json = '';
        $file = $_FILES['file'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] > self::IMPORT_MAX_BYTES) {
                \flash('error', \__('admin.tasks.import_upload_err'));
                \redirect('/admin/tasks/import');
            }
            $json = (string) file_get_contents($file['tmp_name']);
        } else {
            $json = is_string($_POST['json'] ?? null) ? $_POST['json'] : '';
        }
        if (strlen($json) > self::IMPORT_MAX_BYTES) {
            \flash('error', \__('admin.tasks.import_upload_err'));
            \redirect('/admin/tasks/import');
        }
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
        try {
            $data = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::importFail($json, [-1 => ['JSON: ' . $e->getMessage()]]);
        }
        if (is_array($data) && isset($data['tasks']) && is_array($data['tasks'])) {
            $data = $data['tasks'];
        }
        if (!is_array($data) || !array_is_list($data) || $data === []) {
            self::importFail($json, [-1 => [\__('admin.tasks.import_not_list')]]);
        }
        if (count($data) > 1000) {
            self::importFail($json, [-1 => [\__('admin.tasks.import_too_many')]]);
        }
        $mode = \input('mode') === 'update' ? 'update' : 'skip';
        $r = TaskStore::import($data, $mode, (bool) \input('activate'));
        if ($r['errors']) {
            $labels = [];
            foreach ($r['errors'] as $i => $msgs) {
                $labels[$i] = ['slug' => is_array($data[$i] ?? null) ? (string) ($data[$i]['slug'] ?? '') : '', 'messages' => $msgs];
            }
            $_SESSION['admin_import_result'] = ['ok' => false, 'items' => $labels, 'count' => count($data)];
            $_SESSION['admin_import_json'] = strlen($json) < 400000 ? $json : '';
            \flash('error', \__('admin.tasks.import_failed', ['n' => count($r['errors'])]));
            \redirect('/admin/tasks/import');
        }
        \Settings::set('aggregate_dirty', 1);
        \flash('success', \__('admin.tasks.import_done', ['c' => $r['created'], 'u' => $r['updated'], 's' => $r['skipped']]));
        \redirect('/admin/tasks');
    }

    private static function importFail(string $json, array $errors): never
    {
        $items = [];
        foreach ($errors as $i => $msgs) {
            $items[$i] = ['slug' => '', 'messages' => $msgs];
        }
        $_SESSION['admin_import_result'] = ['ok' => false, 'items' => $items, 'count' => 0];
        $_SESSION['admin_import_json'] = strlen($json) < 400000 ? $json : '';
        \flash('error', \__('admin.tasks.import_failed', ['n' => count($errors)]));
        \redirect('/admin/tasks/import');
    }

    /** GET /admin/tasks/export — JSON download of the filtered tasks in import format. */
    public static function export(): void
    {
        \require_admin();
        [$where, $params] = self::filters();
        $rows = DB::all('SELECT t.* FROM tasks t JOIN tracks tr ON tr.id = t.track_id' . $where . ' ORDER BY tr.sort, t.id', $params);
        $slugs = [];
        foreach (DB::all('SELECT id, slug FROM tracks') as $tr) {
            $slugs[(int) $tr['id']] = $tr['slug'];
        }
        $out = array_map(static fn($r) => TaskStore::export($r, $slugs), $rows);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="woaibench-tasks-' . gmdate('Ymd-His') . '.json"');
        header('Cache-Control: no-store');
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
