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
     * message, finish_reason, usage, provider, id, ms, error, retryable).
     */
    public function chat(array $payload, int $timeout = 240): array;
}
