<?php
/**
 * Models: list + filters, inline toggles, edit metadata, OpenRouter catalogue sync.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;

final class ModelsController
{
    /** GET /admin/models */
    public static function index(): void
    {
        \require_admin();
        $f = [
            'active' => (string) \input('active', ''),
            'judge' => (string) \input('judge', ''),
            'vendor' => (string) \input('vendor', ''),
            'q' => mb_substr((string) \input('q', ''), 0, 100),
        ];
        $where = [];
        $params = [];
        if ($f['active'] === '1' || $f['active'] === '0') {
            $where[] = 'm.is_active = ?';
            $params[] = (int) $f['active'];
        }
        if ($f['judge'] === '1' || $f['judge'] === '0') {
            $where[] = 'm.is_judge = ?';
            $params[] = (int) $f['judge'];
        }
        if ($f['vendor'] !== '') {
            $where[] = 'm.provider = ?';
            $params[] = $f['vendor'];
        }
        if ($f['q'] !== '') {
            $where[] = '(m.name LIKE ? OR m.or_id LIKE ? OR m.slug LIKE ?)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $sql = 'SELECT m.*, (SELECT COUNT(*) FROM runs r WHERE r.model_id = m.id) AS run_count FROM models m'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY m.is_judge DESC, m.judge_order, m.is_active DESC, m.provider, m.name';
        Admin::render('models', [
            'title' => \__('admin.nav.models'),
            'nav_active' => 'models',
            'models' => DB::all($sql, $params),
            'vendors' => DB::col('SELECT DISTINCT provider FROM models ORDER BY provider'),
            'filters' => $f,
        ]);
    }

    /** GET /admin/models/{id} or /admin/models/new */
    public static function edit(?int $id): void
    {
        \require_admin();
        $model = $id === null ? self::blank() : DB::one('SELECT * FROM models WHERE id = ?', [$id]);
        if (!$model) {
            \abort(404);
        }
        $old = $_SESSION['admin_model_old'] ?? null;
        $errors = $_SESSION['admin_model_errors'] ?? [];
        unset($_SESSION['admin_model_old'], $_SESSION['admin_model_errors']);
        if (is_array($old)) {
            $model = array_merge($model, $old);
        }
        Admin::render('model_edit', [
            'title' => $id === null ? \__('admin.models.new') : $model['name'],
            'nav_active' => 'models',
            'model' => $model,
            'is_new' => $id === null,
            'errors' => is_array($errors) ? $errors : [],
            'run_count' => $id === null ? 0 : (int) DB::val('SELECT COUNT(*) FROM runs WHERE model_id = ?', [$id]),
        ]);
    }

    private static function blank(): array
    {
        return [
            'id' => null, 'slug' => '', 'or_id' => '', 'name' => '', 'provider' => '', 'provider_name' => '', 'description' => '',
            'open_weights' => 0, 'params_b' => null, 'active_params_b' => null, 'n_layers' => null, 'kv_dim' => null,
            'context_length' => null, 'price_in' => null, 'price_out' => null, 'supports_vision' => 0, 'supports_tools' => 0,
            'release_date' => null, 'is_active' => 1, 'is_judge' => 0, 'judge_order' => 0, 'color' => '', 'gen_params' => '',
        ];
    }

    /** POST /admin/models/{id} or /admin/models/new */
    public static function save(?int $id): void
    {
        Admin::guardPost();
        if ($id !== null && !DB::val('SELECT id FROM models WHERE id = ?', [$id])) {
            \abort(404);
        }
        [$data, $errors] = self::validate($_POST, $id);
        $back = $id === null ? '/admin/models/new' : '/admin/models/' . $id;
        if ($errors) {
            $old = array_intersect_key($_POST, self::blank());
            foreach (['open_weights', 'supports_vision', 'supports_tools', 'is_active', 'is_judge'] as $cb) {
                $old[$cb] = !empty($_POST[$cb]) ? 1 : 0;
            }
            $_SESSION['admin_model_old'] = $old;
            $_SESSION['admin_model_errors'] = $errors;
            \flash('error', \__('admin.form_errors'));
            \redirect($back);
        }
        if ($id === null) {
            $id = DB::insert('models', $data);
            \flash('success', \__('admin.models.created'));
        } else {
            DB::update('models', $data, 'id = ?', [$id]);
            \flash('success', \__('admin.saved'));
        }
        \redirect('/admin/models/' . $id);
    }

    /** Validate the edit form. Returns [data, errors(field => message)]. */
    private static function validate(array $in, ?int $id): array
    {
        $e = [];
        $str = static fn(string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $data = [
            'name' => $str('name', 190),
            'slug' => strtolower($str('slug', 160)),
            'or_id' => $str('or_id', 190),
            'provider_name' => $str('provider_name', 120) ?: null,
            'description' => $str('description', 4000) ?: null,
            'open_weights' => !empty($in['open_weights']) ? 1 : 0,
            'supports_vision' => !empty($in['supports_vision']) ? 1 : 0,
            'supports_tools' => !empty($in['supports_tools']) ? 1 : 0,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
            'is_judge' => !empty($in['is_judge']) ? 1 : 0,
        ];
        if ($data['name'] === '') {
            $e['name'] = \__('admin.v.required');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{0,158}$/', $data['slug'])) {
            $e['slug'] = \__('admin.v.slug_format');
        } elseif (DB::val('SELECT id FROM models WHERE slug = ? AND id <> ?', [$data['slug'], (int) $id])) {
            $e['slug'] = \__('admin.v.duplicate', ['v' => $data['slug']]);
        }
        if (!Admin::validOrId($data['or_id'])) {
            $e['or_id'] = \__('admin.v.or_id');
        } elseif (DB::val('SELECT id FROM models WHERE or_id = ? AND id <> ?', [$data['or_id'], (int) $id])) {
            $e['or_id'] = \__('admin.v.duplicate', ['v' => $data['or_id']]);
        }
        $data['provider'] = Admin::validOrId($data['or_id']) ? explode('/', $data['or_id'], 2)[0] : '';
        $nums = [
            'params_b' => [0, 100000, false], 'active_params_b' => [0, 100000, false], 'n_layers' => [1, 1000, true],
            'kv_dim' => [1, 1000000, true], 'context_length' => [1, 100000000, true], 'price_in' => [0, 100000, false],
            'price_out' => [0, 100000, false], 'judge_order' => [-1000, 1000, true],
        ];
        foreach ($nums as $k => [$min, $max, $int]) {
            $raw = trim((string) ($in[$k] ?? ''));
            if ($raw === '') {
                $data[$k] = $k === 'judge_order' ? 0 : null;
                continue;
            }
            if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max || ($int && (int) $raw != $raw)) {
                $e[$k] = \__('admin.v.range', ['min' => $min, 'max' => $max]);
                continue;
            }
            $data[$k] = $int ? (int) $raw : (float) $raw;
        }
        $date = trim((string) ($in['release_date'] ?? ''));
        if ($date === '') {
            $data['release_date'] = null;
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $e['release_date'] = \__('admin.v.date_format');
        } else {
            $data['release_date'] = $date;
        }
        $color = trim((string) ($in['color'] ?? ''));
        if ($color === '') {
            $data['color'] = null;
        } elseif (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $e['color'] = \__('admin.v.color');
        } else {
            $data['color'] = strtoupper($color);
        }
        $gp = trim((string) ($in['gen_params'] ?? ''));
        if ($gp === '') {
            $data['gen_params'] = null;
        } else {
            $decoded = json_decode($gp, true);
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                $e['gen_params'] = \__('admin.v.json_object');
            } elseif (($bad = self::genParamProblem($decoded)) !== null) {
                $e['gen_params'] = $bad;
            } else {
                $data['gen_params'] = \to_json($decoded);
            }
        }
        return [$data, $e];
    }

    /** Reject keys that would override what the engine controls. */
    public static function genParamProblem(array $p): ?string
    {
        foreach (['model', 'messages', 'stream', 'tools', 'tool_choice', 'response_format'] as $k) {
            if (array_key_exists($k, $p)) {
                return \__('admin.v.gen_reserved', ['k' => $k]);
            }
        }
        return null;
    }

    /** POST /admin/models/{id}/delete — only models without runs. */
    public static function delete(int $id): void
    {
        Admin::guardPost();
        $runs = (int) DB::val('SELECT COUNT(*) FROM runs WHERE model_id = ?', [$id]);
        $judg = (int) DB::val('SELECT COUNT(*) FROM judgments WHERE judge_model_id = ?', [$id]);
        if ($runs > 0 || $judg > 0) {
            \flash('error', \__('admin.models.err_has_runs'));
            \redirect('/admin/models/' . $id);
        }
        DB::exec('DELETE FROM models WHERE id = ?', [$id]);
        \flash('success', \__('admin.deleted'));
        \redirect('/admin/models');
    }

    /** POST /admin/api/models/{id} JSON {field: is_active|is_judge|judge_order, value} */
    public static function apiUpdate(int $id): void
    {
        Admin::guardPost();
        $b = \json_body();
        $field = (string) ($b['field'] ?? '');
        if (!DB::val('SELECT id FROM models WHERE id = ?', [$id])) {
            \json_out(['ok' => false, 'error' => \__('admin.not_found')], 404);
        }
        if (in_array($field, ['is_active', 'is_judge'], true)) {
            $value = !empty($b['value']) ? 1 : 0;
        } elseif ($field === 'judge_order') {
            $value = (int) \clamp((float) ($b['value'] ?? 0), -1000, 1000);
        } else {
            \json_out(['ok' => false, 'error' => 'Invalid field'], 422);
        }
        DB::update('models', [$field => $value], 'id = ?', [$id]);
        \json_out(['ok' => true, 'field' => $field, 'value' => $value]);
    }

    /** GET /admin/models/sync */
    public static function sync(): void
    {
        \require_admin();
        $cat = ModelSync::catalog(\input('refresh') === '1');
        $existing = [];
        foreach (DB::all('SELECT id, or_id, price_in, price_out, is_active FROM models') as $r) {
            $existing[$r['or_id']] = $r;
        }
        Admin::render('model_sync', [
            'title' => \__('admin.sync.title'),
            'nav_active' => 'models',
            'catalog' => $cat,
            'existing' => $existing,
        ]);
    }

    /** POST /admin/models/sync — action=import (ids[]) | prices */
    public static function syncPost(): void
    {
        Admin::guardPost();
        $cat = ModelSync::catalog(false);
        if (!$cat['ok']) {
            \flash('error', (string) $cat['error']);
            \redirect('/admin/models/sync');
        }
        if (\input('action') === 'prices') {
            $r = ModelSync::refreshPrices($cat['models']);
            \flash('success', \__('admin.sync.prices_done', ['n' => $r['updated'], 'm' => $r['missing']]));
            \redirect('/admin/models/sync');
        }
        $ids = $_POST['ids'] ?? [];
        $ids = is_array($ids) ? array_values(array_filter(array_map('strval', $ids), [Admin::class, 'validOrId'])) : [];
        if (!$ids) {
            \flash('warn', \__('admin.sync.none_selected'));
            \redirect('/admin/models/sync');
        }
        $r = ModelSync::import(array_slice($ids, 0, 300), $cat['models']);
        \flash('success', \__('admin.sync.imported', ['a' => $r['added'], 'u' => $r['updated']]));
        \redirect('/admin/models');
    }
}
