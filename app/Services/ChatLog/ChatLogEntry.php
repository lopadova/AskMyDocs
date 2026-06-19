<?php

namespace App\Services\ChatLog;

final readonly class ChatLogEntry
{
    /**
     * @param  list<string>  $sources   Source file paths that contributed context.
     * @param  array<string, mixed>  $extra  Arbitrary metadata for debugging / analytics.
     */
    public function __construct(
        public string $sessionId,
        public ?int $userId,
        public string $question,
        public string $answer,
        public ?string $projectKey,
        public string $aiProvider,
        public string $aiModel,
        public int $chunksCount,
        public array $sources,
        public ?int $promptTokens,
        public ?int $completionTokens,
        public ?int $totalTokens,
        public int $latencyMs,
        public ?string $clientIp,
        public ?string $userAgent,
        public array $extra = [],
        // v8.8.3 — an anonymous turn is logged minimally (or not at all) per
        // `chat-log.anonymous_level`: the driver strips question / answer /
        // sources / user_id / client_ip / user_agent and keeps only the
        // by-norm operational fields. Default false = full logging as before.
        public bool $anonymous = false,
        // v8.16/W3 — the request-scoped finops trace id (set by the controller
        // via TraceContext) so this chat-log row joins its usage-ledger row(s).
        // Null when finops trace context is not active.
        public ?string $traceId = null,
    ) {}
}
