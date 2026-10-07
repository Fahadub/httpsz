<?php

declare(strict_types=1);

namespace Udeyou\Services;

use Udeyou\Repositories\ApiKeyRepository;

/**
 * Key format: udy_live_<40 base62 chars> / udy_test_<40 base62 chars>.
 * Only sha256(key) is stored; the plain key is shown to the client exactly once.
 */
final class ApiKeyService
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const RANDOM_LENGTH = 40; // ~238 bits of entropy

    public function __construct(private readonly ApiKeyRepository $keys = new ApiKeyRepository())
    {
    }

    /** @return string the plain key (display once, never stored) */
    public function generate(int $clientId, string $name, string $mode): string
    {
        $mode = $mode === 'test' ? 'test' : 'live';
        $plain = "udy_{$mode}_" . self::randomString(self::RANDOM_LENGTH);
        $prefix = substr($plain, 0, strlen("udy_{$mode}_") + 6);

        $this->keys->create($clientId, $name, $mode, $prefix, self::hash($plain));
        return $plain;
    }

    /** @throws ApiException */
    public function authenticate(?string $plainKey): array
    {
        if ($plainKey === null || !preg_match('/^udy_(live|test)_[0-9A-Za-z]{' . self::RANDOM_LENGTH . '}$/', $plainKey)) {
            throw new ApiException('invalid_api_key', 'Missing or malformed API key. Send it as "Authorization: Bearer udy_live_..."', 401);
        }

        $key = $this->keys->findActiveByHash(self::hash($plainKey));
        if ($key === null) {
            throw new ApiException('invalid_api_key', 'API key is invalid or has been revoked', 401);
        }
        if ($key['status'] !== 'active') {
            throw new ApiException('account_suspended', 'This account is suspended. Contact support.', 403);
        }

        $this->keys->touch((int) $key['key_id']);
        return $key;
    }

    public static function hash(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    private static function randomString(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }
}
