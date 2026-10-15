<?php
/**
 * Chat-completions transport used by the engine. OpenRouter implements it in production;
 * tests inject scripted fakes so the generator and the agent simulator run without a network.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

interface ChatClient
{
    /**
     * Send one chat-completions request and return the normalized response
     * (see OpenRouter::chat() for the exact shape: ok, status, content, reasoning, tool_calls,
     * message, finish_reason, usage, provider, id, ms, error, retryable, halt, retry_after).
     * halt = ['code', 'message'] for an account problem (401 / 402 / 403 key limit, see OpenRouter::haltOf);
     * retry_after = seconds from a 429 / 503 Retry-After header. Fakes may omit both.
     */
    public function chat(array $payload, int $timeout = 240): array;
}
