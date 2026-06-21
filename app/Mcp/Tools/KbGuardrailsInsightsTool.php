<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.19/W2 — MCP read surface (R44, the third surface) for the AI Guardrails
 * posture: the four offline-first controls (input screening, output handler,
 * tool firewall, HITL) with their enabled flag + enforcement mode, plus an
 * aggregate of the injection-audit window (screened / blocked / observed counts).
 *
 * Deliberately exposes ONLY aggregate posture — NEVER raw prompts or per-row
 * audit content (that lives behind the admin RBAC API). The guardrails tables
 * are GLOBAL security infrastructure (not tenant-scoped, like embedding_cache),
 * so this returns an app-wide posture; it is the MCP twin of the admin
 * `GET /overview` endpoint, for an operator/agent asking "are the guardrails on
 * and are they catching anything?".
 *
 * OFF-safe (R43): when the package master switch is off, returns
 * `available:false` with a null posture rather than throwing; when the audit
 * store is not a database (no rows to count) the counts are null, not 0.
 */
#[Description('Return the AI Guardrails posture for this AskMyDocs instance: the four controls (input_screen, output_handler, tool_firewall, hitl) with their enabled flag and enforcement mode (enforce|monitor|off), plus aggregate injection-audit counts over the last N hours (screened, blocked, observed). Aggregate only — never raw prompts. Returns available:false when guardrails are disabled.')]
#[IsReadOnly]
#[IsIdempotent]
class KbGuardrailsInsightsTool extends Tool
{
    /** Controls reported, in display order. */
    private const CONTROLS = ['input_screen', 'output_handler', 'tool_firewall', 'hitl'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'hours' => $schema->integer()
                ->description('Look-back window in hours for the injection-audit aggregate (1–168). Defaults to 24.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $enabled = (bool) config('ai-guardrails.enabled', false);

        if (! $enabled) {
            return Response::json([
                'available' => false,
                'enabled' => false,
                'controls' => null,
                'injection_audit' => null,
            ]);
        }

        $hours = (int) ($request->get('hours') ?? 24);
        $hours = max(1, min(168, $hours));

        return Response::json([
            'available' => true,
            'enabled' => true,
            'controls' => $this->controls(),
            'injection_audit' => $this->injectionAudit($hours),
        ]);
    }

    /**
     * Per-control posture from config. `mode` (enforce|monitor|off) overrides the
     * boolean `enabled` when set — we report both so the reader sees the effective
     * stance.
     *
     * @return list<array{key: string, enabled: bool, mode: string}>
     */
    private function controls(): array
    {
        return array_map(static fn (string $key): array => [
            'key' => $key,
            'enabled' => (bool) config("ai-guardrails.{$key}.enabled", false),
            'mode' => (string) config("ai-guardrails.modes.{$key}", 'enforce'),
        ], self::CONTROLS);
    }

    /**
     * Aggregate the injection-audit window without touching prompt content.
     * `observed` = monitor-mode matches (a rule fired but the prompt was NOT
     * blocked). Null when the audit store is not a database / the table is absent
     * (R43 OFF-path safe — no fabricated zeros).
     *
     * @return array{window_hours: int, screened: int, blocked: int, observed: int}|null
     */
    private function injectionAudit(int $hours): ?array
    {
        if (config('ai-guardrails.audit.store') !== 'database') {
            return null;
        }

        $table = (string) config('ai-guardrails.audit.table', 'ai_guardrails_injection_audit');

        if (! Schema::hasTable($table)) {
            return null;
        }

        $since = now()->subHours($hours);
        $base = DB::table($table)->where('occurred_at', '>=', $since);

        $screened = (clone $base)->count();
        $blocked = (clone $base)->where('blocked', true)->count();
        $observed = (clone $base)->where('blocked', false)->whereNotNull('rule_id')->count();

        return [
            'window_hours' => $hours,
            'screened' => $screened,
            'blocked' => $blocked,
            'observed' => $observed,
        ];
    }
}
