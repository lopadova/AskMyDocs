<?php

declare(strict_types=1);

namespace App\Services\Guardrails;

use DateTimeImmutable;
use DateTimeZone;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;
use Padosoft\AiGuardrails\Facades\AiGuardrails;
use Padosoft\AiGuardrails\Output\OutputStatKind;
use Padosoft\AiGuardrails\Support\ControlMode;
use Throwable;

/**
 * v8.19/W2 — host adapter that runs the laravel-ai-guardrails Control B (input
 * screening) and Control C (output sanitization) on the HTTP chat path.
 *
 * WHY a host adapter instead of the package middlewares: the package ships its
 * controls as `laravel/ai` AGENT middlewares (GuardrailInputMiddleware /
 * GuardrailOutputMiddleware) that operate inside the SDK agent pipeline on
 * AgentPrompt/AgentResponse objects. AskMyDocs's chat path calls
 * `AiManager::chat()` directly (it does not run the laravel/ai agent loop), so
 * those middlewares cannot be mounted here. This adapter reproduces their
 * essential behaviour — mode resolution + the append-only injection audit +
 * the output-stat counter — the same way the package's own non-agent entry
 * points do (the `ai-guardrails:screen` command screens then appends an
 * InjectionAttempt). The audit/stat writes are what feed the guardrails admin
 * panel (W3); without them the chat — the primary attack surface — would be
 * invisible to the console.
 *
 * Mode-aware (enforce | monitor | off): `enforce` blocks/rewrites + records;
 * `monitor` records (observed) but passes through (shadow rollout); `off` is a
 * pure no-op. The caller (KbChatController) additionally gates on the master +
 * per-control `enabled` flags so a disabled deployment never reaches this
 * adapter at all (R43 OFF-state).
 */
final class ChatGuardrails
{
    public function __construct(
        private readonly InjectionAuditStore $audit,
        private readonly OutputStatStore $outputStats,
    ) {}

    /**
     * Control B — screen a chat prompt and append the attempt to the append-only
     * audit. Returns true when the prompt must be REFUSED (a pattern matched AND
     * the mode enforces). In monitor mode the attempt is audited with
     * `blocked=false` (observed) and false is returned; in off mode nothing runs.
     */
    public function screenInput(string $prompt, ?string $principal): bool
    {
        $mode = ControlMode::resolve(
            config('ai-guardrails.modes.input_screen'),
            (bool) config('ai-guardrails.input_screen.enabled', true),
        );

        if (! $mode->isActive()) {
            return false;
        }

        $verdict = AiGuardrails::screen($prompt);
        $willBlock = $verdict->blocked && $mode->enforces();

        $this->audit->append(new InjectionAttempt(
            $prompt,
            $willBlock,
            $verdict->ruleId,
            $principal,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $verdict->rulesetVersion,
            $verdict->erroredRuleIds,
            $verdict->matchedSpan,
        ));

        return $willBlock;
    }

    /**
     * Control C — sanitize a chat answer (markdown-exfil neutralization; HTML
     * escaping + PII redaction are intentionally disabled in the host config —
     * see config/ai-guardrails.php). In enforce mode the cleaned text is
     * returned; in monitor mode the would-sanitize stat is still recorded but the
     * original text is returned; in off mode it is a no-op. The stat write is
     * fire-and-forget — a store failure must never break the answer.
     */
    public function sanitizeOutput(string $text): string
    {
        $mode = ControlMode::resolve(
            config('ai-guardrails.modes.output_handler'),
            (bool) config('ai-guardrails.output_handler.enabled', true),
        );

        if (! $mode->isActive()) {
            return $text;
        }

        $clean = AiGuardrails::sanitize($text);

        if ($clean !== $text) {
            try {
                $this->outputStats->record(OutputStatKind::MarkdownSanitized);
            } catch (Throwable) {
                // Fire-and-forget: a stats-store failure must never abort the chat answer.
            }
        }

        return $mode->enforces() ? $clean : $text;
    }
}
