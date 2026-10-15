<?php
/**
 * OpenRouter catalogue sync: fetch (cached), normalise, import selected models and refresh prices.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;
use Config;
use Throwable;

final class ModelSync
{
    private const CACHE_TTL = 1800;

    /** Cache file per API base, so switching endpoints never shows a stale catalogue. */
    private static function cacheFile(): string
    {
        $base = (string) Config::get('openrouter_base', 'https://openrouter.ai/api/v1');
        return WB_STORAGE . '/cache/admin-openrouter-models-' . substr(hash('sha256', $base), 0, 12) . '.json';
    }

    /**
     * Catalogue of normalised models.
     * Returns ['ok' => bool, 'models' => [...], 'error' => ?string, 'fetched_at' => ?int, 'cached' => bool].
     */
    public static function catalog(bool $refresh = false): array
    {
        if ((string) Config::get('openrouter_key', '') === '') {
            return ['ok' => false, 'models' => [], 'error' => \__('admin.sync.err_key'), 'fetched_at' => null, 'cached' => false];
        }
        $file = self::cacheFile();
        if (!$refresh && is_file($file) && filemtime($file) > time() - self::CACHE_TTL) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && isset($data['models'])) {
                return ['ok' => true, 'models' => $data['models'], 'error' => null, 'fetched_at' => (int) ($data['at'] ?? filemtime($file)), 'cached' => true];
            }
        }
        if (!Admin::engine('OpenRouter')) {
            return ['ok' => false, 'models' => [], 'error' => \__('admin.engine_missing', ['c' => 'WB\\Eval\\OpenRouter']), 'fetched_at' => null, 'cached' => false];
        }
        try {
            $raw = (new \WB\Eval\OpenRouter())->models();
        } catch (Throwable $e) {
            \log_error('OpenRouter models() failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'models' => [], 'error' => \__('admin.sync.err_unreachable') . ' (' . $e->getMessage() . ')', 'fetched_at' => null, 'cached' => false];
        }
        if (isset($raw['data']) && is_array($raw['data'])) {
            $raw = $raw['data'];
        }
        $models = [];
        foreach (is_array($raw) ? $raw : [] as $m) {
            if (is_array($m) && ($n = self::normalize($m)) !== null) {
                $models[] = $n;
            }
        }
        if (!$models) {
            return ['ok' => false, 'models' => [], 'error' => \__('admin.sync.err_unreachable'), 'fetched_at' => null, 'cached' => false];
        }
        usort($models, static fn($a, $b) => [$a['provider'], $a['name']] <=> [$b['provider'], $b['name']]);
        if (is_dir(dirname($file)) || @mkdir(dirname($file), 0775, true)) {
            @file_put_contents($file, json_encode(['at' => time(), 'models' => $models], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        return ['ok' => true, 'models' => $models, 'error' => null, 'fetched_at' => time(), 'cached' => false];
    }

    /** USD per token (string) -> USD per 1M tokens. Negative/variable prices -> null. */
    public static function perMillion(mixed $perToken): ?float
    {
        if ($perToken === null || $perToken === '' || !is_numeric($perToken)) {
            return null;
        }
        $v = (float) $perToken;
        return $v < 0 ? null : round($v * 1_000_000, 6);
    }

    /** One raw OpenRouter model -> our columns. */
    public static function normalize(array $m): ?array
    {
        $id = (string) ($m['id'] ?? '');
        if (!Admin::validOrId($id)) {
            return null;
        }
        [$vendor] = explode('/', $id, 2);
        $fullName = trim((string) ($m['name'] ?? $id));
        $providerName = null;
        $name = $fullName;
        if (str_contains($fullName, ': ')) {
            [$providerName, $name] = array_map('trim', explode(': ', $fullName, 2));
        }
        $inMods = $m['architecture']['input_modalities'] ?? [];
        $params = $m['supported_parameters'] ?? [];
        $hf = trim((string) ($m['hugging_face_id'] ?? ''));
        [$total, $active] = self::paramsFromId($id . ' ' . $fullName);
        $created = isset($m['created']) && is_numeric($m['created']) ? gmdate('Y-m-d', (int) $m['created']) : null;
        return [
            'or_id' => $id,
            'name' => mb_substr($name !== '' ? $name : $id, 0, 190),
            'provider' => mb_substr($vendor, 0, 80),
            'provider_name' => $providerName !== null ? mb_substr($providerName, 0, 120) : null,
            'description' => mb_substr(trim((string) ($m['description'] ?? '')), 0, 2000),
            'context_length' => isset($m['context_length']) && is_numeric($m['context_length']) ? (int) $m['context_length'] : null,
            'price_in' => self::perMillion($m['pricing']['prompt'] ?? null),
            'price_out' => self::perMillion($m['pricing']['completion'] ?? null),
            'supports_vision' => is_array($inMods) && in_array('image', $inMods, true) ? 1 : 0,
            'supports_tools' => is_array($params) && in_array('tools', $params, true) ? 1 : 0,
            'open_weights' => $hf !== '' ? 1 : 0,
            'hf_id' => $hf,
            'params_b' => $total,
            'active_params_b' => $active,
            'release_date' => $created,
        ];
    }

    /** Parameter count hints from names like "qwen3-235b-a22b" or "Llama 3.1 70B". */
    public static function paramsFromId(string $text): array
    {
        $total = null;
        $active = null;
        if (preg_match('/(?<![\d.])(\d{1,4}(?:\.\d+)?)b(?:[-_ ]a(\d{1,3}(?:\.\d+)?)b)?(?![a-z])/i', $text, $mm)) {
            $total = (float) $mm[1];
            $active = isset($mm[2]) && $mm[2] !== '' ? (float) $mm[2] : $total;
        }
        return [$total, $active];
    }

    /** Unique slug for a new model. */
    private static function uniqueSlug(string $base): string
    {
        $base = \slugify($base, 150);
        $slug = $base;
        $n = 2;
        while (DB::val('SELECT id FROM models WHERE slug = ?', [$slug])) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    /**
     * Import the selected OpenRouter ids (upsert by or_id). Existing rows get prices/capabilities refreshed
     * but keep admin-edited fields (name, slug, colour, judge flags, gen params, Fit Lab numbers).
     * Returns ['added' => n, 'updated' => n, 'missing' => [ids]].
     */
    public static function import(array $orIds, array $catalog): array
    {
        $byId = [];
        foreach ($catalog as $m) {
            $byId[$m['or_id']] = $m;
        }
        $added = 0;
        $updated = 0;
        $missing = [];
        foreach (array_unique($orIds) as $id) {
            $id = (string) $id;
            $m = $byId[$id] ?? null;
            if ($m === null) {
                $missing[] = $id;
                continue;
            }
            $existing = DB::one('SELECT id FROM models WHERE or_id = ?', [$id]);
            $common = [
                'provider' => $m['provider'], 'context_length' => $m['context_length'],
                'price_in' => $m['price_in'], 'price_out' => $m['price_out'],
                'supports_vision' => $m['supports_vision'], 'supports_tools' => $m['supports_tools'],
            ];
            if ($existing) {
                DB::update('models', $common, 'id = ?', [(int) $existing['id']]);
                $updated++;
                continue;
            }
            DB::insert('models', $common + [
                'name' => $m['name'],
                'slug' => self::uniqueSlug($m['name']),
                'or_id' => $id,
                'provider_name' => $m['provider_name'],
                'description' => $m['description'] !== '' ? $m['description'] : null,
                'open_weights' => $m['open_weights'],
                'params_b' => $m['params_b'],
                'active_params_b' => $m['active_params_b'],
                'release_date' => $m['release_date'],
                'is_active' => 1,
            ]);
            $added++;
        }
        return ['added' => $added, 'updated' => $updated, 'missing' => $missing];
    }

    /** Refresh prices/context/capabilities of every existing model found in the catalogue. */
    public static function refreshPrices(array $catalog): array
    {
        $byId = [];
        foreach ($catalog as $m) {
            $byId[$m['or_id']] = $m;
        }
        $updated = 0;
        $missing = 0;
        foreach (DB::all('SELECT id, or_id FROM models') as $row) {
            $m = $byId[$row['or_id']] ?? null;
            if ($m === null) {
                $missing++;
                continue;
            }
            DB::update('models', [
                'price_in' => $m['price_in'], 'price_out' => $m['price_out'], 'context_length' => $m['context_length'],
                'supports_vision' => $m['supports_vision'], 'supports_tools' => $m['supports_tools'],
            ], 'id = ?', [(int) $row['id']]);
            $updated++;
        }
        return ['updated' => $updated, 'missing' => $missing];
    }
}
