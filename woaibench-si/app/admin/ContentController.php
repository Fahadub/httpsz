<?php
/**
 * Model requests (triage) and the bilingual public changelog.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;

final class ContentController
{
    public const REQUEST_STATUSES = ['new', 'done', 'dismissed'];

    /** GET /admin/requests */
    public static function requests(): void
    {
        \require_admin();
        $status = Admin::str('status', 'new');
        $where = in_array($status, self::REQUEST_STATUSES, true) ? ' WHERE status = ?' : '';
        $params = $where ? [$status] : [];
        $total = (int) DB::val('SELECT COUNT(*) FROM model_requests' . $where, $params);
        [$page, $pages, $offset] = Admin::paginate($total, 50);
        $rows = DB::all('SELECT * FROM model_requests' . $where . ' ORDER BY id DESC LIMIT 50 OFFSET ' . $offset, $params);
        $known = [];
        $orIds = array_values(array_filter(array_column($rows, 'or_id')));
        if ($orIds) {
            foreach (DB::all('SELECT id, or_id FROM models WHERE or_id IN (' . DB::in($orIds) . ')', $orIds) as $m) {
                $known[$m['or_id']] = (int) $m['id'];
            }
        }
        $counts = [];
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM model_requests GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        Admin::render('requests', [
            'title' => \__('admin.nav.requests'),
            'nav_active' => 'requests',
            'requests' => $rows,
            'status' => $status,
            'counts' => $counts,
            'known' => $known,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /** POST /admin/requests/{id}  status=new|done|dismissed|delete */
    public static function requestStatus(int $id): void
    {
        Admin::guardPost();
        $status = Admin::str('status');
        if ($status === 'delete') {
            DB::exec('DELETE FROM model_requests WHERE id = ?', [$id]);
            \flash('success', \__('admin.deleted'));
        } elseif (in_array($status, self::REQUEST_STATUSES, true)) {
            DB::update('model_requests', ['status' => $status], 'id = ?', [$id]);
            \flash('success', \__('admin.saved'));
        } else {
            \abort(400, 'Unknown status');
        }
        \redirect_back('/admin/requests');
    }

    /** GET /admin/changelog */
    public static function changelog(): void
    {
        \require_admin();
        Admin::render('changelog', [
            'title' => \__('admin.nav.changelog'),
            'nav_active' => 'changelog',
            'entries' => DB::all('SELECT * FROM changelog ORDER BY published_at DESC, id DESC'),
        ]);
    }

    /** GET /admin/changelog/{id} | /admin/changelog/new */
    public static function changelogEdit(?int $id): void
    {
        \require_admin();
        $entry = $id === null
            ? ['id' => null, 'version' => (string) \setting('season') . '.' . ((int) DB::val('SELECT COUNT(*) FROM changelog') + 1), 'title_ar' => '', 'title_en' => '', 'body_ar' => '', 'body_en' => '', 'published_at' => \now()]
            : DB::one('SELECT * FROM changelog WHERE id = ?', [$id]);
        if (!$entry) {
            \abort(404);
        }
        $old = $_SESSION['admin_cl_old'] ?? null;
        unset($_SESSION['admin_cl_old']);
        if (is_array($old)) {
            $entry = array_merge($entry, $old);
        }
        Admin::render('changelog_edit', [
            'title' => $id === null ? \__('admin.changelog.new') : \__('admin.changelog.edit'),
            'nav_active' => 'changelog',
            'entry' => $entry,
        ]);
    }

    /** POST /admin/changelog/{id} | /admin/changelog/new */
    public static function changelogSave(?int $id): void
    {
        Admin::guardPost();
        if ($id !== null && !DB::val('SELECT id FROM changelog WHERE id = ?', [$id])) {
            \abort(404);
        }
        $s = static fn(string $k, int $max) => mb_substr(trim(Admin::str($k, '')), 0, $max);
        $data = [
            'version' => $s('version', 32), 'title_ar' => $s('title_ar', 200), 'title_en' => $s('title_en', 200),
            'body_ar' => $s('body_ar', 20000) ?: null, 'body_en' => $s('body_en', 20000) ?: null,
        ];
        $date = $s('published_at', 20);
        $ts = $date !== '' ? strtotime(str_replace('T', ' ', $date) . ' UTC') : time();
        $errors = [];
        if ($data['version'] === '' || $data['title_ar'] === '' || $data['title_en'] === '') {
            $errors[] = \__('admin.changelog.err_required');
        }
        if ($ts === false) {
            $errors[] = \__('admin.v.date_format');
        }
        if ($errors) {
            $_SESSION['admin_cl_old'] = $data + ['published_at' => $date];
            foreach ($errors as $err) {
                \flash('error', $err);
            }
            \redirect($id === null ? '/admin/changelog/new' : '/admin/changelog/' . $id);
        }
        $data['published_at'] = gmdate('Y-m-d H:i:s', (int) $ts);
        if ($id === null) {
            DB::insert('changelog', $data);
        } else {
            DB::update('changelog', $data, 'id = ?', [$id]);
        }
        \flash('success', \__('admin.saved'));
        \redirect('/admin/changelog');
    }

    /** POST /admin/changelog/{id}/delete */
    public static function changelogDelete(int $id): void
    {
        Admin::guardPost();
        DB::exec('DELETE FROM changelog WHERE id = ?', [$id]);
        \flash('success', \__('admin.deleted'));
        \redirect('/admin/changelog');
    }
}
