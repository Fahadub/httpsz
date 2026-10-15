<?php
/**
 * Task persistence for the back-office: create/update from the import format, export, duplicate.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;

final class TaskStore
{
    public const SOURCES = ['seed', 'manual', 'import', 'forge'];

    /** Track slug => id. */
    public static function trackIds(): array
    {
        $out = [];
        foreach (DB::all('SELECT id, slug FROM tracks ORDER BY sort, id') as $r) {
            $out[(string) $r['slug']] = (int) $r['id'];
        }
        return $out;
    }

    /** Normalised import-format task -> DB columns. */
    public static function columns(array $t, array $trackIds): array
    {
        return [
            'slug' => $t['slug'],
            'track_id' => $trackIds[$t['track']],
            'title_en' => $t['title_en'],
            'title_ar' => $t['title_ar'],
            'summary_en' => $t['summary_en'] !== '' ? $t['summary_en'] : null,
            'summary_ar' => $t['summary_ar'] !== '' ? $t['summary_ar'] : null,
            'prompt' => $t['prompt'],
            'output_type' => $t['output_type'],
            'difficulty' => $t['difficulty'],
            'rubric_json' => $t['rubric'] ? \to_json($t['rubric']) : null,
            'checks_json' => $t['checks'] ? \to_json($t['checks']) : null,
            'auto_weight' => $t['auto_weight'],
            'visibility' => $t['visibility'],
        ];
    }

    /** Insert a validated task. */
    public static function insert(array $t, array $trackIds, string $source, bool $active): int
    {
        return DB::insert('tasks', self::columns($t, $trackIds) + [
            'is_active' => $active ? 1 : 0,
            'source' => in_array($source, self::SOURCES, true) ? $source : 'manual',
            'season' => (string) \setting('season'),
        ]);
    }

    /** Update a task; bumps version when the prompt or checks changed. Returns the new version. */
    public static function update(int $id, array $t, array $trackIds): int
    {
        $old = DB::one('SELECT prompt, checks_json, rubric_json, version FROM tasks WHERE id = ?', [$id]);
        $cols = self::columns($t, $trackIds);
        $version = (int) ($old['version'] ?? 1);
        $oldChecks = \json_arr($old['checks_json'] ?? null);
        if ($old && ($old['prompt'] !== $cols['prompt'] || $oldChecks != $t['checks'])) {
            $version++;
        }
        $cols['version'] = $version;
        DB::update('tasks', $cols, 'id = ?', [$id]);
        return $version;
    }

    /** DB row -> import/export format. */
    public static function export(array $row, array $trackSlugs): array
    {
        $out = [
            'slug' => $row['slug'],
            'track' => $trackSlugs[(int) $row['track_id']] ?? '',
            'title_en' => $row['title_en'],
            'title_ar' => $row['title_ar'],
            'summary_en' => (string) $row['summary_en'],
            'summary_ar' => (string) $row['summary_ar'],
            'prompt' => $row['prompt'],
            'output_type' => $row['output_type'],
            'difficulty' => (int) $row['difficulty'],
            'rubric' => \json_arr($row['rubric_json']),
            'checks' => (object) \json_arr($row['checks_json']),
            'visibility' => $row['visibility'],
        ];
        if ($row['auto_weight'] !== null) {
            $out['auto_weight'] = (float) $row['auto_weight'];
        }
        return $out;
    }

    /** Free slug derived from $base ("x-copy", "x-copy-2", …). */
    public static function freeSlug(string $base): string
    {
        $base = substr(preg_replace('/[^a-z0-9\-]+/', '-', strtolower($base)) ?? 'task', 0, 110);
        $base = trim($base, '-') ?: 'task';
        $slug = $base;
        $n = 2;
        while (DB::val('SELECT id FROM tasks WHERE slug = ?', [$slug])) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    /** Copy a task as an inactive draft. Returns the new id. */
    public static function duplicate(int $id): ?int
    {
        $row = DB::one('SELECT * FROM tasks WHERE id = ?', [$id]);
        if (!$row) {
            return null;
        }
        unset($row['id'], $row['created_at'], $row['updated_at']);
        $row['slug'] = self::freeSlug($row['slug'] . '-copy');
        $row['is_active'] = 0;
        $row['version'] = 1;
        $row['source'] = 'manual';
        $row['title_en'] = mb_substr($row['title_en'] . ' (copy)', 0, 200);
        $row['title_ar'] = mb_substr($row['title_ar'] . ' (نسخة)', 0, 200);
        return DB::insert('tasks', $row);
    }

    /**
     * Import a list of tasks (import format). Mode: 'skip' existing slugs or 'update' them.
     * Returns ['created' => n, 'updated' => n, 'skipped' => n, 'errors' => [index => [messages]]].
     * Nothing is written when any task has errors.
     */
    public static function import(array $list, string $mode, bool $activate): array
    {
        $trackIds = self::trackIds();
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'warnings' => 0];
        $valid = [];
        $seen = [];
        foreach ($list as $i => $raw) {
            if (!is_array($raw) || array_is_list($raw)) {
                $result['errors'][$i] = [\__('admin.v.must_be_object')];
                continue;
            }
            $v = TaskValidator::task($raw, array_keys($trackIds));
            $slug = $v['task']['slug'];
            if ($slug !== '' && isset($seen[$slug])) {
                $v['errors'][] = 'slug: ' . \__('admin.v.duplicate', ['v' => $slug]);
            }
            $seen[$slug] = true;
            if ($v['errors']) {
                $result['errors'][$i] = $v['errors'];
                continue;
            }
            $result['warnings'] += count($v['warnings']);
            $valid[] = $v['task'];
        }
        if ($result['errors']) {
            return $result;
        }
        DB::tx(static function () use ($valid, $mode, $activate, $trackIds, &$result): void {
            foreach ($valid as $t) {
                $id = DB::val('SELECT id FROM tasks WHERE slug = ?', [$t['slug']]);
                if ($id) {
                    if ($mode === 'update') {
                        self::update((int) $id, $t, $trackIds);
                        $result['updated']++;
                    } else {
                        $result['skipped']++;
                    }
                    continue;
                }
                self::insert($t, $trackIds, 'import', $activate);
                $result['created']++;
            }
        });
        return $result;
    }
}
