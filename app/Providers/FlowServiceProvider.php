<?php

declare(strict_types=1);

namespace App\Providers;

use App\Flow\Definitions\CanonicalIndexFlow;
use App\Flow\Definitions\DeleteDocumentFlow;
use App\Flow\Definitions\IngestDocumentFlow;
use App\Flow\Definitions\PromotionFlow;
use App\Models\KbCanonicalAudit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Models\FlowStepRecord;

/**
 * Registers every AskMyDocs FlowDefinition with the FlowEngine and wires
 * the tenant_id stamping hook on the package's Eloquent records.
 *
 * Why an in-app provider:
 *
 * 1. The vendor `padosoft/laravel-flow` is tenant-agnostic by design
 *    (vendor CLAUDE.md: "Companion dashboard is a separate repo;
 *    package stays headless"). AskMyDocs is multi-tenant per R30/R31 —
 *    every persisted Flow row carries `tenant_id`. The
 *    {@see FlowRunRecord::creating} / {@see FlowStepRecord::creating} /
 *    {@see FlowAuditRecord::creating} hooks below stamp the active
 *    tenant from {@see TenantContext} when the engine inserts a row.
 *
 * 2. Definitions register themselves once per boot. Synchronous,
 *    idempotent (FlowEngine::registerDefinition uses `$name` as key —
 *    re-registering simply overwrites with the same instance).
 */
final class FlowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->stampTenantIdOnFlowRecords();
        $this->bridgeFlowAuditToCanonicalAudit();
        $this->registerDefinitions();
    }

    /**
     * Bridge selected `flow_audit` events into the
     * `kb_canonical_audit` editorial trail.
     *
     * Mapping (per FEATURE-CATALOG-laravel-flow.md "audit hooks"):
     *
     *   - `kb.promote` write-markdown FlowStepCompleted   → 'promoted'
     *   - `kb.promote` approval-gate FlowStepFailed       → 'rejected_promotion'
     *   - `kb.delete` hard-delete-rows FlowStepCompleted  → 'deprecated'
     *   - `kb.canonical-index` populate-edges Completed   → 'graph_rebuild'
     *
     * The mapping fires on FlowAuditRecord::created (post-insert) so we
     * never block the engine's transaction; if the bridge throws the
     * surrounding flow run is unaffected. Steps already write their
     * primary audit rows directly (so the trail remains correct even
     * with this listener disabled) — this hook contributes additional
     * cross-flow coverage (e.g. the rejected_promotion event has no
     * dedicated step writer, since rejection skips the write step).
     *
     * R30 — `flow_audit.tenant_id` was already stamped by the prior
     * `creating` hook; we propagate it onto the kb_canonical_audit
     * row via BelongsToTenant's auto-fill. Project key + slug come from
     * the audit payload's business_impact / step output snapshot when
     * available, or are left null when the run did not surface them.
     */
    private function bridgeFlowAuditToCanonicalAudit(): void
    {
        FlowAuditRecord::created(function (FlowAuditRecord $audit): void {
            try {
                $this->emitCanonicalAuditFromFlowAudit($audit);
            } catch (\Throwable $e) {
                // Bridge failures must NEVER break the engine's flow.
                // Log and move on; the primary step-level audit rows
                // remain authoritative.
                \Illuminate\Support\Facades\Log::warning(
                    'FlowServiceProvider: kb_canonical_audit bridge failed',
                    [
                        'flow_audit_id' => $audit->id,
                        'event' => $audit->event,
                        'error' => $e->getMessage(),
                    ],
                );
            }
        });
    }

    private function emitCanonicalAuditFromFlowAudit(FlowAuditRecord $audit): void
    {
        $payload = is_array($audit->payload) ? $audit->payload : [];
        $definitionName = (string) ($payload['definition_name'] ?? '');
        $stepName = (string) ($audit->step_name ?? '');
        $event = (string) $audit->event;

        $canonicalEvent = $this->mapToCanonicalEvent($definitionName, $stepName, $event);
        if ($canonicalEvent === null) {
            return;
        }

        $impact = is_array($audit->business_impact) ? $audit->business_impact : [];

        $projectKey = $this->stringFromPayload($impact, 'project_key')
            ?? $this->stringFromPayload($payload, 'project_key')
            ?? $this->stringFromPayload($payload['output'] ?? [], 'project_key');
        $slug = $this->stringFromPayload($impact, 'slug')
            ?? $this->stringFromPayload($payload, 'slug')
            ?? $this->stringFromPayload($payload['output'] ?? [], 'slug');
        $docId = $this->stringFromPayload($impact, 'doc_id')
            ?? $this->stringFromPayload($payload, 'doc_id')
            ?? $this->stringFromPayload($payload['output'] ?? [], 'doc_id');

        // Fall back to the run record's `input` bag — relevant for the
        // PromotionFlow rejection path where the approval-gate FlowStepFailed
        // audit row carries no project_key in payload/business_impact.
        if ($projectKey === null) {
            $runInput = $this->loadRunInput((string) $audit->run_id);
            if ($runInput !== null) {
                $projectKey = $this->stringFromPayload($runInput, 'project_key');
            }
        }

        if ($projectKey === null) {
            // Without a project_key the row is meaningless — KbCanonicalAudit
            // expects it. Skip rather than insert NULL.
            return;
        }

        KbCanonicalAudit::create([
            'project_key' => $projectKey,
            'doc_id' => $docId,
            'slug' => $slug,
            'event_type' => $canonicalEvent,
            'actor' => "flow:{$definitionName}:{$stepName}",
            'before_json' => null,
            'after_json' => null,
            'metadata_json' => [
                'flow_run_id' => (string) $audit->run_id,
                'flow_event' => $event,
                'bridged_from' => 'flow_audit',
            ],
        ]);
    }

    private function mapToCanonicalEvent(string $definitionName, string $stepName, string $event): ?string
    {
        // Bridge events that have NO authoritative step-level audit writer:
        //   - kb.promote.write-markdown — the step ALREADY writes its own
        //     `promoted` row directly; skip here to avoid duplicates.
        //   - kb.canonical-index.populate-edges — the step ALREADY writes
        //     its own `graph_rebuild` row directly; skip here too.
        //   - kb.delete.hard-delete-rows — DocumentDeleter::deleteRowsOnly()
        //     already writes the `deprecated` row inside the same
        //     transaction; skip here as well.
        // The bridge's REAL value is for events with no in-step writer:
        //   - kb.promote approval-gate FlowStepFailed → `rejected_promotion`
        //     (rejection halts the flow before write-markdown ever runs,
        //     so the step's own promote/reject audit code path never
        //     executes — only the bridge can record this event).
        if ($definitionName === PromotionFlow::NAME && $stepName === PromotionFlow::APPROVAL_STEP && $event === 'FlowStepFailed') {
            return 'rejected_promotion';
        }
        return null;
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function stringFromPayload(array $bag, string $key): ?string
    {
        $value = $bag[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRunInput(string $runId): ?array
    {
        $row = DB::table('flow_runs')->where('id', $runId)->first(['input']);
        if ($row === null) {
            return null;
        }
        $raw = $row->input ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        return null;
    }

    /**
     * Hook the package's Eloquent records' `creating` event to stamp
     * `tenant_id` from the active TenantContext singleton.
     *
     * The package models are `final` and `$guarded = []`, so we cannot
     * extend them — but the Eloquent `creating` hook fires before the
     * INSERT, which is the right place to backfill the column. If the
     * row already carries a `tenant_id` (e.g. a future caller passes
     * one explicitly) we leave it alone — explicit > implicit.
     */
    private function stampTenantIdOnFlowRecords(): void
    {
        $stamp = function ($model): void {
            // Skip if the package has been upgraded to a tenant-aware
            // schema and the engine starts setting it itself, OR if a
            // caller passed it explicitly.
            if (! empty($model->tenant_id)) {
                return;
            }
            /** @var TenantContext $ctx */
            $ctx = app(TenantContext::class);
            $model->tenant_id = $ctx->current();
        };

        FlowRunRecord::creating($stamp);
        FlowStepRecord::creating($stamp);
        FlowAuditRecord::creating($stamp);
    }

    private function registerDefinitions(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        IngestDocumentFlow::register($engine);
        CanonicalIndexFlow::register($engine);
        PromotionFlow::register($engine);
        DeleteDocumentFlow::register($engine);
    }
}
