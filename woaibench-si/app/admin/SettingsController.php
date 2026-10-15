<?php
/**
 * Settings: runtime settings (DB), secrets (config file), key test, score recompute.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use Config;
use Settings;
use Http;
use Throwable;

final class SettingsController
{
    /**
     * Field schema: key => [type, opts]. Types: str(max), text(max), email, url, enum(list), int(min,max),
     * float(min,max), bool, season, model, model_opt, temp_opt.
     */
    public static function schema(): array
    {
        return [
            'site' => [
                'site_name' => ['str', 80], 'site_domain' => ['domain'], 'tagline_ar' => ['str', 200], 'tagline_en' => ['str', 200],
                'default_lang' => ['enum', ['ar', 'en']], 'contact_email' => ['email'], 'social_x' => ['social'], 'social_github' => ['social'],
                'analytics_snippet' => ['text', 5000],
            ],
            'season' => ['season' => ['season']],
            'judging' => [
                'judge_count' => ['int', 1, 7], 'judge_temperature' => ['float', 0, 2], 'judge_max_tokens' => ['int', 256, 64000],
                'judge_tiebreak_spread' => ['float', 0, 100],
            ],
            'generation' => [
                'gen_temperature' => ['temp_opt'], 'gen_max_tokens' => ['int', 256, 200000], 'gen_timeout_s' => ['int', 10, 900],
                'runs_per_task' => ['int', 1, 10],
            ],
            'verification' => [
                'verify_mode' => ['enum', ['required', 'optional', 'off']], 'verify_wait_minutes' => ['int', 1, 10080],
                'render_fail_factor' => ['float', 0, 1],
            ],
            'statistics' => ['bootstrap_iters' => ['int', 100, 20000], 'min_coverage' => ['float', 0, 1]],
            'agents' => ['agent_constraint_gate' => ['bool'], 'agent_critical_cap' => ['int', 0, 100]],
            'features' => [
                'arena_enabled' => ['bool'], 'arena_votes_per_hour' => ['int', 1, 10000], 'api_public' => ['bool'], 'requests_enabled' => ['bool'],
            ],
            'models' => ['image_model' => ['model'], 'forge_model' => ['model_opt']],
        ];
    }

    /** GET /admin/settings */
    public static function index(): void
    {
        \require_admin();
        $values = [];
        foreach (self::schema() as $fields) {
            foreach ($fields as $k => $_) {
                $values[$k] = \setting($k);
            }
        }
        $errors = $_SESSION['admin_settings_errors'] ?? [];
        $old = $_SESSION['admin_settings_old'] ?? [];
        unset($_SESSION['admin_settings_errors'], $_SESSION['admin_settings_old']);
        Admin::render('settings', [
            'title' => \__('admin.nav.settings'),
            'nav_active' => 'settings',
            'schema' => self::schema(),
            'values' => array_merge($values, is_array($old) ? $old : []),
            'errors' => is_array($errors) ? $errors : [],
            'key_mask' => Admin::mask((string) Config::get('openrouter_key', '')),
            'key_set' => (string) Config::get('openrouter_key', '') !== '',
            'cron_key' => (string) Config::get('cron_key', ''),
            'csp_extra' => (string) Config::get('csp_extra_script', ''),
            'writable' => Admin::configWritable(),
            'base' => (string) Config::get('openrouter_base', 'https://openrouter.ai/api/v1'),
            'aggregate_last' => (string) \setting('aggregate_last', ''),
            'engine_aggregator' => Admin::engine('Aggregator'),
            'judges' => \DB::all('SELECT name, or_id, provider FROM models WHERE is_judge = 1 ORDER BY judge_order, id'),
            'setup_tools' => class_exists('WB\\Setup\\Migrator') && class_exists('WB\\Setup\\Seeder'),
        ]);
    }

    /** Validate one value. Returns [ok, value]. */
    private static function clean(array $rule, mixed $raw): array
    {
        $raw = is_string($raw) ? trim($raw) : $raw;
        switch ($rule[0]) {
            case 'str':
                return [is_string($raw) && $raw !== '' && mb_strlen($raw) <= $rule[1], (string) $raw];
            case 'text':
                return [is_string($raw) && strlen($raw) <= $rule[1], (string) $raw];
            case 'domain':
                return [is_string($raw) && (bool) preg_match('/^(?=.{3,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/i', $raw), strtolower((string) $raw)];
            case 'email':
                return [$raw === '' || (is_string($raw) && filter_var($raw, FILTER_VALIDATE_EMAIL) && strlen($raw) <= 190), (string) $raw];
            case 'social':
                $ok = $raw === '' || (is_string($raw) && strlen($raw) <= 190
                    && (preg_match('#^https://[^\s"<>]+$#', $raw) || preg_match('/^@?[A-Za-z0-9_.\-]{1,60}$/', $raw)));
                return [(bool) $ok, (string) $raw];
            case 'enum':
                return [in_array($raw, $rule[1], true), (string) $raw];
            case 'int':
                $ok = is_numeric($raw) && (int) $raw == $raw && (int) $raw >= $rule[1] && (int) $raw <= $rule[2];
                return [$ok, $ok ? (int) $raw : $raw];
            case 'float':
                $ok = is_numeric($raw) && (float) $raw >= $rule[1] && (float) $raw <= $rule[2];
                return [$ok, $ok ? (float) $raw : $raw];
            case 'temp_opt':
                if ($raw === '' || $raw === null) {
                    return [true, ''];
                }
                $ok = is_numeric($raw) && (float) $raw >= 0 && (float) $raw <= 2;
                return [$ok, $ok ? (float) $raw : $raw];
            case 'bool':
                return [true, !empty($raw) ? 1 : 0];
            case 'season':
                return [is_string($raw) && (bool) preg_match('/^[A-Za-z0-9._\-]{1,16}$/', $raw), (string) $raw];
            case 'model':
                return [is_string($raw) && Admin::validOrId($raw), (string) $raw];
            case 'model_opt':
                return [$raw === '' || (is_string($raw) && Admin::validOrId($raw)), (string) $raw];
        }
        return [false, $raw];
    }

    /** POST /admin/settings  (section=<group>) */
    public static function save(): void
    {
        Admin::guardPost();
        $section = Admin::str('section', '');
        $schema = self::schema();
        if (!isset($schema[$section])) {
            \abort(400, 'Unknown section');
        }
        $values = [];
        $errors = [];
        foreach ($schema[$section] as $key => $rule) {
            $raw = $rule[0] === 'text' ? ($_POST[$key] ?? '') : \input($key, $rule[0] === 'bool' ? 0 : '');
            [$ok, $val] = self::clean($rule, $raw);
            if (!$ok) {
                $errors[$key] = self::ruleHint($rule);
            }
            $values[$key] = $val;
        }
        if ($errors) {
            $_SESSION['admin_settings_errors'] = $errors;
            $_SESSION['admin_settings_old'] = $values;
            \flash('error', \__('admin.form_errors'));
            \redirect('/admin/settings#s-' . $section);
        }
        $prevSeason = (string) \setting('season');
        Admin::saveSettings($values);
        if ($section === 'season' && $values['season'] !== $prevSeason) {
            Settings::set('aggregate_dirty', 1);
            \flash('warn', \__('admin.settings.season_changed', ['s' => $values['season']]));
        }
        if (in_array($section, ['statistics', 'verification'], true)) {
            Settings::set('aggregate_dirty', 1);
        }
        \flash('success', \__('admin.saved'));
        \redirect('/admin/settings#s-' . $section);
    }

    private static function ruleHint(array $rule): string
    {
        return match ($rule[0]) {
            'int', 'float' => \__('admin.v.range', ['min' => $rule[1], 'max' => $rule[2]]),
            'temp_opt' => \__('admin.v.range', ['min' => 0, 'max' => 2]),
            'email' => \__('admin.account.err_email'),
            'enum' => \__('admin.v.one_of', ['list' => implode(', ', $rule[1])]),
            'model', 'model_opt' => \__('admin.v.or_id'),
            'season' => \__('admin.settings.err_season'),
            'domain' => \__('admin.settings.err_domain'),
            'social' => \__('admin.settings.err_social'),
            'str' => \__('admin.v.too_long', ['n' => $rule[1]]),
            default => \__('admin.v.invalid'),
        };
    }

    /** POST /admin/settings/secrets  action=key|cron|csp|clear_key */
    public static function saveSecrets(): void
    {
        Admin::guardPost();
        if (!Admin::configWritable()) {
            \flash('error', \__('admin.settings.err_writable', ['f' => 'config/config.local.php']));
            \redirect('/admin/settings#s-secrets');
        }
        $action = Admin::str('action');
        $changes = [];
        switch ($action) {
            case 'key':
                $key = trim((string) ($_POST['openrouter_key'] ?? ''));
                if ($key === '' || strlen($key) > 300 || !preg_match('/^[\x21-\x7E]+$/', $key)) {
                    \flash('error', \__('admin.settings.err_key'));
                    \redirect('/admin/settings#s-secrets');
                }
                $changes['openrouter_key'] = $key;
                break;
            case 'clear_key':
                $changes['openrouter_key'] = '';
                break;
            case 'cron':
                $changes['cron_key'] = \random_token(24);
                break;
            case 'clear_cron':
                $changes['cron_key'] = '';
                break;
            case 'csp':
                $csp = trim(preg_replace('/\s+/', ' ', Admin::str('csp_extra_script', '')) ?? '');
                foreach ($csp === '' ? [] : explode(' ', $csp) as $origin) {
                    if (!preg_match('#^https://[a-z0-9.\-]+(:\d+)?$#i', $origin)) {
                        \flash('error', \__('admin.settings.err_csp'));
                        \redirect('/admin/settings#s-secrets');
                    }
                }
                $changes['csp_extra_script'] = $csp;
                break;
            default:
                \abort(400, 'Unknown action');
        }
        if (!Admin::writeConfig($changes)) {
            \flash('error', \__('admin.settings.err_writable', ['f' => 'config/config.local.php']));
        } else {
            \flash('success', \__('admin.settings.secret_saved'));
        }
        \redirect('/admin/settings#s-secrets');
    }

    /** POST /admin/api/test-key — checks the stored key against OpenRouter. */
    public static function apiTestKey(): void
    {
        Admin::guardPost();
        $key = (string) Config::get('openrouter_key', '');
        if ($key === '') {
            \json_out(['ok' => false, 'error' => \__('admin.sync.err_key')]);
        }
        if (!\rate_limit('admin_testkey:' . \ip_hash(), 20, 600)) {
            \json_out(['ok' => false, 'error' => \__('admin.login.too_many')], 429);
        }
        $base = rtrim((string) Config::get('openrouter_base', 'https://openrouter.ai/api/v1'), '/');
        $headers = ['Authorization' => 'Bearer ' . $key, 'HTTP-Referer' => \site_origin(), 'X-Title' => 'WOAIBench.si'];
        $r = Http::request('GET', $base . '/key', null, $headers, 20);
        if ($r['status'] === 200 && is_array($r['json']['data'] ?? null)) {
            $d = $r['json']['data'];
            \json_out(['ok' => true, 'message' => \__('admin.settings.key_ok_info', [
                'label' => (string) ($d['label'] ?? '—'),
                'usage' => Admin::iso(\fmt_money($d['usage'] ?? 0)),
                'limit' => isset($d['limit']) && $d['limit'] !== null ? Admin::iso(\fmt_money($d['limit'])) : '∞',
            ]), 'ms' => $r['ms']]);
        }
        if (in_array($r['status'], [401, 403], true)) {
            \json_out(['ok' => false, 'error' => \__('admin.settings.key_invalid')]);
        }
        // Fallback: GET /models (also confirms reachability).
        $m = Http::request('GET', $base . '/models', null, $headers, 30);
        if ($m['status'] === 200 && is_array($m['json']['data'] ?? null)) {
            \json_out(['ok' => true, 'message' => \__('admin.settings.key_ok_models', ['n' => count($m['json']['data'])]), 'ms' => $m['ms']]);
        }
        if (in_array($m['status'], [401, 403], true)) {
            \json_out(['ok' => false, 'error' => \__('admin.settings.key_invalid')]);
        }
        \json_out(['ok' => false, 'error' => \__('admin.sync.err_unreachable') . ($m['error'] ? ' (' . $m['error'] . ')' : ' (HTTP ' . $m['status'] . ')')]);
    }

    /** POST /admin/settings/recompute — run the aggregator now. */
    public static function recompute(): void
    {
        Admin::guardPost();
        if (!Admin::engine('Aggregator')) {
            \flash('error', \__('admin.engine_missing', ['c' => 'WB\\Eval\\Aggregator']));
            \redirect_back('/admin');
        }
        \wb_time_limit(280);
        $t0 = microtime(true);
        try {
            \WB\Eval\Aggregator::recompute();
            Settings::flush();
            \flash('success', \__('admin.settings.recomputed', ['s' => number_format(microtime(true) - $t0, 1)]));
        } catch (Throwable $e) {
            \log_error('Aggregator::recompute failed', ['error' => $e->getMessage()]);
            \flash('error', \__('admin.settings.recompute_failed', ['msg' => $e->getMessage()]));
        }
        \redirect_back('/admin');
    }
}
