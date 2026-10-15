<?php
/**
 * Shared helpers for the back-office: rendering, guards, navigation, campaign progress, spend.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;
use Config;
use Throwable;

final class Admin
{
    /** Render an admin page inside the admin layout (always noindex). */
    public static function render(string $view, array $vars = []): void
    {
        $vars['robots'] = 'noindex, nofollow';
        \view('admin/' . $view, $vars, 'admin');
    }

    /** Guard for state-changing admin requests: admin session first, then CSRF. */
    public static function guardPost(): array
    {
        $user = \require_admin();
        \csrf_check();
        return $user;
    }

    /** Sidebar navigation: group => [key => [path, lang key, icon]]. */
    public static function nav(): array
    {
        return [
            'admin.nav.group_overview' => [
                'dashboard' => ['/admin', 'admin.nav.dashboard', 'gauge'],
                'console' => ['/admin/console', 'admin.nav.console', 'terminal'],
                'verify' => ['/admin/verify', 'admin.nav.verify', 'shield'],
            ],
            'admin.nav.group_bench' => [
                'campaigns' => ['/admin/campaigns', 'admin.nav.campaigns', 'target'],
                'runs' => ['/admin/runs', 'admin.nav.runs', 'history'],
                'models' => ['/admin/models', 'admin.nav.models', 'robot'],
                'tasks' => ['/admin/tasks', 'admin.nav.tasks', 'list'],
                'tracks' => ['/admin/tracks', 'admin.nav.tracks', 'layers'],
                'forge' => ['/admin/forge', 'admin.nav.forge', 'wand'],
            ],
            'admin.nav.group_site' => [
                'settings' => ['/admin/settings', 'admin.nav.settings', 'settings'],
                'brand' => ['/admin/brand', 'admin.nav.brand', 'palette'],
                'requests' => ['/admin/requests', 'admin.nav.requests', 'mail'],
                'changelog' => ['/admin/changelog', 'admin.nav.changelog', 'file'],
                'tokens' => ['/admin/tokens', 'admin.nav.tokens', 'key'],
                'account' => ['/admin/account', 'admin.nav.account', 'users'],
            ],
        ];
    }

    /** Only allow post-login redirects to back-office paths on this site. */
    public static function safeNext(mixed $next): string
    {
        if (!is_string($next) || $next === '' || strlen($next) > 200) {
            return '/admin';
        }
        if (!preg_match('#^/admin(?:/[A-Za-z0-9_\-/]*)?$#', $next) || str_contains($next, '//')) {
            return '/admin';
        }
        if (preg_match('#^/admin/(login|logout)#', $next)) {
            return '/admin';
        }
        return $next;
    }

    /** Is an engine class (WB\Eval\<name>) available? */
    public static function engine(string $class): bool
    {
        return class_exists('WB\\Eval\\' . $class);
    }

    /** Page / offset computation for simple list pages. */
    public static function paginate(int $total, int $perPage): array
    {
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = \input_int('page', 1, 1, $pages);
        return [$page, $pages, ($page - 1) * $perPage];
    }

    /** Total spend (runs + judgments) in USD, optionally since a UTC datetime. */
    public static function spend(?string $since = null): float
    {
        $where = $since !== null ? ' WHERE updated_at >= ?' : '';
        $params = $since !== null ? [$since] : [];
        $runs = (float) DB::val('SELECT COALESCE(SUM(cost_usd), 0) FROM runs' . $where, $params);
        $judg = (float) DB::val('SELECT COALESCE(SUM(cost_usd), 0) FROM judgments' . $where, $params);
        return $runs + $judg;
    }

    /** Seconds since setting('worker_last_run') or null when never. */
    public static function heartbeatAge(): ?int
    {
        $v = \setting('worker_last_run', '');
        if ($v === '' || $v === null) {
            return null;
        }
        if (is_numeric($v)) {
            $ts = (int) $v;
        } else {
            $s = (string) $v;
            $ts = preg_match('/(Z|[+\-]\d\d:?\d\d)$/i', $s) ? strtotime($s) : strtotime($s . ' UTC');
        }
        return $ts ? max(0, time() - (int) $ts) : null;
    }

    /** Run counts by status for one campaign (or all when $campaignId is null). */
    public static function statusCounts(?int $campaignId = null): array
    {
        $rows = $campaignId === null
            ? DB::all('SELECT status, COUNT(*) AS n FROM runs GROUP BY status')
            : DB::all('SELECT status, COUNT(*) AS n FROM runs WHERE campaign_id = ? GROUP BY status', [$campaignId]);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Normalised campaign progress. Uses WB\Eval\Campaign::progress() when the engine is installed
     * and falls back to counting runs, so the UI keeps working either way.
     */
    public static function progress(array $campaign): array
    {
        $id = (int) $campaign['id'];
        $p = [];
        if (self::engine('Campaign')) {
            try {
                $p = \WB\Eval\Campaign::progress($id);
            } catch (Throwable $e) {
                \log_error('Campaign::progress failed', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }
        return self::normalizeProgress($p, $campaign);
    }

    /** Fill missing keys of a progress array: total, done, pct, counts, spent, name, status. */
    public static function normalizeProgress(array $p, array $campaign): array
    {
        $id = (int) ($campaign['id'] ?? $p['id'] ?? 0);
        $counts = isset($p['counts']) && is_array($p['counts']) ? array_map('intval', $p['counts']) : self::statusCounts($id);
        $total = isset($p['total']) ? (int) $p['total'] : array_sum($counts);
        $finished = ($counts['scored'] ?? 0) + ($counts['failed'] ?? 0) + ($counts['skipped'] ?? 0);
        $done = isset($p['done']) ? (int) $p['done'] : $finished;
        $spent = $p['spent_usd'] ?? $p['spent'] ?? null;
        if ($spent === null) {
            $spent = (float) DB::val('SELECT COALESCE(SUM(cost_usd),0) FROM runs WHERE campaign_id = ?', [$id])
                + (float) DB::val('SELECT COALESCE(SUM(j.cost_usd),0) FROM judgments j JOIN runs r ON r.id = j.run_id WHERE r.campaign_id = ?', [$id]);
        }
        return [
            'id' => $id,
            'name' => (string) ($campaign['name'] ?? $p['name'] ?? ('#' . $id)),
            'status' => (string) ($campaign['status'] ?? $p['status'] ?? ''),
            'total' => $total,
            'done' => min($done, max($total, $done)),
            'failed' => (int) ($counts['failed'] ?? 0),
            'pct' => $total > 0 ? round(min(100, $done / $total * 100), 1) : 0.0,
            'counts' => $counts,
            'spent' => (float) $spent,
            'est' => isset($campaign['est_cost']) && $campaign['est_cost'] !== null ? (float) $campaign['est_cost'] : null,
        ];
    }

    /** Wrap a value in Unicode bidi isolates so "$1.20" or ids render correctly inside Arabic sentences. */
    public static function iso(string $value): string
    {
        return "\u{2066}" . $value . "\u{2069}";
    }

    /** Mask a secret: "••••abcd". */
    public static function mask(?string $secret): string
    {
        $secret = (string) $secret;
        if ($secret === '') {
            return '';
        }
        return str_repeat('•', 8) . substr($secret, -4);
    }

    /** Pretty JSON for display (input may be JSON text or a PHP value). */
    public static function pretty(mixed $value, int $maxBytes = 200000): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return mb_strcut($value, 0, $maxBytes);
            }
            $value = $decoded;
        }
        $out = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
        return strlen($out) > $maxBytes ? mb_strcut($out, 0, $maxBytes) . "\n…" : $out;
    }

    /** Raw config file contents (without environment overrides) — the base for Config::write(). */
    public static function rawConfig(): array
    {
        if (is_file(WB_CONFIG_FILE) && is_readable(WB_CONFIG_FILE)) {
            $cfg = (static function (string $f) {
                return require $f;
            })(WB_CONFIG_FILE);
            if (is_array($cfg)) {
                return $cfg;
            }
        }
        return Config::all();
    }

    /** Persist config changes; returns false when the file is not writable. */
    public static function writeConfig(array $changes): bool
    {
        $file = WB_CONFIG_FILE;
        $dir = dirname($file);
        if ((is_file($file) && !is_writable($file)) || !is_writable($dir)) {
            return false;
        }
        $ok = Config::write(array_replace_recursive(self::rawConfig(), $changes));
        if ($ok) {
            // Re-apply runtime values (env overrides stay in effect for this request).
            foreach ($changes as $k => $v) {
                if (!is_array($v)) {
                    Config::set((string) $k, $v);
                }
            }
        }
        return $ok;
    }

    /** True when the config file can be (re)written by PHP. */
    public static function configWritable(): bool
    {
        $file = WB_CONFIG_FILE;
        return is_file($file) ? is_writable($file) && is_writable(dirname($file)) : is_writable(dirname($file));
    }

    /** OpenRouter model id shape (vendor/model[:variant]). */
    public static function validOrId(string $id): bool
    {
        return (bool) preg_match('#^[a-z0-9][a-z0-9._\-]*/[A-Za-z0-9][A-Za-z0-9._:\-]*$#', $id) && strlen($id) <= 190;
    }

    /** Count of new model requests (sidebar badge). */
    public static function newRequests(): int
    {
        try {
            return (int) DB::val("SELECT COUNT(*) FROM model_requests WHERE status = 'new'");
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Start of the current UTC month as DATETIME. */
    public static function monthStart(): string
    {
        return gmdate('Y-m-01 00:00:00');
    }
}
