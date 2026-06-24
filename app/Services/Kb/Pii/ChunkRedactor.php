<?php

declare(strict_types=1);

namespace App\Services\Kb\Pii;

use App\Services\Kb\Pipeline\ChunkDraft;
use App\Support\TenantContext;
use Padosoft\PiiRedactor\RedactorEngine;

/**
 * v8.23 (Ciclo 4) — the ONE place that redacts chunk text at ingestion time.
 *
 * Shared by BOTH inline ingestion paths so the GDPR-critical "never raw PII in
 * the vector store" contract holds regardless of which path runs:
 *   - the Flow saga (the real HTTP/CLI path): {@see \App\Flow\Steps\ChunkDocumentStep}
 *     redacts the chunk drafts so the downstream `embed-chunks` + `persist-chunks`
 *     steps (which both read `chunk_drafts`) only ever see surrogates;
 *   - the direct path: {@see \App\Services\Kb\DocumentIngestor::persistFromDrafts()}.
 *
 * Each chunk's text is rewritten through the resolved strategy (mask one-way /
 * tokenise reversible per-tenant vault) when the master engine flags are on AND
 * the per-(tenant, project) policy enables it ({@see KbPiiPolicyResolver}). A
 * no-op (drafts returned unchanged) unless redaction is genuinely active.
 *
 * Tokens are deterministic (per-tenant salt + hash), so re-redacting identical
 * content yields identical surrogates → stable chunk_hash → idempotent re-ingest.
 */
final class ChunkRedactor
{
    public function __construct(
        private readonly KbPiiPolicyResolver $policy,
        private readonly IngestStrategyResolver $strategies,
    ) {}

    /**
     * @param  list<ChunkDraft>  $drafts
     * @param  bool  $previewSafe  When true (a dry-run preview), NEVER write to
     *        the token vault — force the one-way `mask` strategy so a preview /
     *        flow-audit row shows no raw PII without minting reversible tokens.
     * @return list<ChunkDraft>
     */
    public function redact(string $projectKey, array $drafts, bool $previewSafe = false): array
    {
        if ($drafts === []) {
            return $drafts;
        }
        // The package engine no-ops while its own flag is off, so skip strategy
        // resolution entirely in that case — otherwise a typo'd config strategy
        // would throw even though no redaction would run.
        if (! (bool) config('pii-redactor.enabled', false)) {
            return $drafts;
        }
        if (! (bool) config('kb.pii_redactor.enabled', false)) {
            return $drafts;
        }

        $tenantId = app(TenantContext::class)->current();
        $policy = $this->policy->resolve($tenantId, $projectKey);
        if (! $policy['redact_enabled']) {
            return $drafts;
        }

        // A dry-run preview must not mint vault tokens — fall back to the
        // side-effect-free mask so flow_steps/audit never store raw PII.
        $strategyName = $previewSafe ? 'mask' : $policy['strategy'];
        $strategy = $this->strategies->forName($strategyName);
        $engine = app(RedactorEngine::class);

        return array_map(
            static fn (ChunkDraft $draft): ChunkDraft => new ChunkDraft(
                text: $engine->redact($draft->text, $strategy),
                order: $draft->order,
                headingPath: $draft->headingPath,
                metadata: $draft->metadata,
            ),
            $drafts,
        );
    }
}
