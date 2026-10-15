<?php
/**
 * Runtime settings stored in the `settings` table (JSON-encoded values), with code defaults.
 *   setting('season')           -> value or default
 *   Settings::set('season', 'x')
 */
declare(strict_types=1);

defined('WB') || exit;

final class Settings
{
    private static ?array $cache = null;

    /** Defaults for every known setting. Modules may read unknown keys with their own default. */
    public static function defaults(): array
    {
        return [
            'site_name' => 'WOAIBench',
            'site_domain' => 'woaibench.si',
            'tagline_ar' => 'المؤشر العلمي لقياس نماذج الذكاء الاصطناعي في البرمجة',
            'tagline_en' => 'The scientific index of AI coding models',
            'default_lang' => 'ar',
            'season' => gmdate('Y') . '.' . gmdate('m'),
            'gen_temperature' => '',          // '' = provider default
            'gen_max_tokens' => 32000,
            'gen_timeout_s' => 240,
            'judge_temperature' => 0,
            'judge_max_tokens' => 4000,
            'judge_count' => 3,
            'judge_tiebreak_spread' => 25,    // add an extra judge when panel spread exceeds this
            'verify_mode' => 'required',      // required | optional | off
            'verify_wait_minutes' => 30,      // optional mode: wait this long for a browser verifier
            'render_fail_factor' => 0.5,      // multiplier when the output does not render at all
            'bootstrap_iters' => 1000,
            'min_coverage' => 0.8,
            'agent_constraint_gate' => 1,     // agent safety/efficiency goals only count once a real goal is achieved
            'agent_critical_cap' => 20,       // max agent score when a safety-critical goal is violated
            'runs_per_task' => 3,
            'arena_enabled' => 1,
            'arena_votes_per_hour' => 60,
            'api_public' => 1,
            'requests_enabled' => 1,
            'image_model' => 'openai/gpt-image-2.5-sunburst',
            'forge_model' => '',
            'brand_logo' => '',               // relative upload path of an active generated logo (optional)
            'analytics_snippet' => '',
            'worker_last_run' => '',
            'aggregate_dirty' => 0,
            'aggregate_last' => '',
            'contact_email' => '',
            'arena_last_recompute' => '',
            'arena_votes_at_recompute' => 0,
            'social_x' => '',
            'social_github' => '',
        ];
    }

    private static function loadAll(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            foreach (DB::all('SELECT k, v FROM settings') as $row) {
                $decoded = json_decode((string) $row['v'], true);
                self::$cache[$row['k']] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $row['v'];
            }
        } catch (Throwable $e) {
            log_error('Settings load failed: ' . $e->getMessage());
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::loadAll();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        $defaults = self::defaults();
        return array_key_exists($key, $defaults) ? $defaults[$key] : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        DB::upsert('settings', ['k' => $key, 'v' => to_json($value)], ['v']);
        self::loadAll();
        self::$cache[$key] = $value;
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::set((string) $k, $v);
        }
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}

function setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}
