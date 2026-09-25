<?php

declare(strict_types=1);

namespace App\Services\Kb\Import;

use App\Exceptions\KbWikiImportRateLimitedException;
use App\Flow\Definitions\PromotionFlow;
use App\Models\KbWikiImportCandidate;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Services\Kb\Canonical\CanonicalParsedDocument;
use App\Services\Kb\Versioning\DocumentVersionService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\IssuedApprovalToken;

/**
 * v8.38/W4c (ADR 0032 §10/§11) — the shared core (R44) behind
 * `kb:import-wiki`, `POST /api/admin/kb/imports`, and `KbImportWikiTool`.
 *
 * `importDocument()` is the tri-surface primitive: it never writes to
 * `knowledge_documents`. It runs the EXACT SAME
 * {@see \Padosoft\LaravelFlow\Facades\Flow::execute()} saga dispatch
 * {@see \App\Http\Controllers\Api\KbPromotionController::promote()} already
 * uses — a page becomes a promotion CANDIDATE (ADR 0003), paused at an
 * approval gate, never a direct write. `importFolder()` is CLI-ONLY: it is
 * the one surface with local filesystem access to a folder a person brought
 * back from an export, and it calls `importDocument()` once per changed
 * page after diffing against the server.
 *
 * **Diff mechanism, and why it compares BODY plus three specific
 * frontmatter fields, not the whole file.** A byte-for-byte comparison of
 * the incoming file against the server's regenerated export representation
 * would false-positive on every export cycle whose frontmatter
 * serialization order or governance-field values (`tier`, `extraction`, …)
 * legitimately shift without any editorial change — exactly the "false
 * CHANGED" noise this method exists to avoid. Comparing only the Markdown
 * BODY against {@see DocumentVersionService::contentFor()}'s own content —
 * the same source {@see \App\Services\Kb\Export\KbWikiExportService}
 * renders into `wiki/*.md` — targets what a human editor most often
 * touches, and needs no fragile YAML-round-trip guarantee to stay correct
 * either direction. It is NOT the whole story, though: {@see
 * frontmatterDiffers()} additionally checks `type`/`status`/
 * `retrieval_priority` — the three CanonicalParser-validated fields a person
 * CAN meaningfully edit without touching a single character of the body
 * (e.g. demoting `accepted` to `deprecated`). An independent review of this
 * PR caught the gap before it shipped: without that check, such an edit
 * produced a byte-identical body and was reported `unchanged`, silently
 * dropping it — exactly the R14 failure mode this class already warned
 * against in its own earlier draft ("a false 'unchanged' would SILENTLY
 * DROP a real edit, which is worse than a false 'changed' proposing a
 * no-op").
 *
 * **Idempotency, and its two honestly-documented limits.** A replayed call
 * with the identical (tenant, project, slug, content, actor) tuple resolves
 * the SAME paused {@see FlowRun} rather than starting a second one —
 * {@see KbWikiImportCandidate::idempotencyKeyFor()}. It does NOT hand back
 * a fresh usable approval token on replay:
 * {@see \Padosoft\LaravelFlow\ApprovalTokenManager::reissuePendingForStep()}
 * is a ONE-SHOT operation on the underlying approval record (its own
 * lookup requires `previous_token_hash IS NULL`, which the FIRST call
 * already clears) — a replay reports the run's id with `approval: null`
 * rather than pretending a second plain-text token exists. The dedup
 * lookup and the `Flow::execute()` dispatch both run inside a per-actor
 * `Cache::lock()` (mirrors
 * {@see \App\Services\Kb\Review\KbReviewService::proposeCorrection()}'s own
 * construction), which serializes the realistic case this exists for — the
 * SAME caller retrying an MCP/HTTP call. It does NOT serialize two
 * DIFFERENT actors proposing byte-identical content concurrently: Flow
 * itself exposes no "check-then-dispatch" primitive to build a fully
 * race-free guard on top of, so that narrow case can start two runs. This
 * is still strictly better than `KbPromotionController::promote()`'s own
 * unconditional dispatch, which has no idempotency layer at all.
 */
final class KbWikiImportService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DocumentVersionService $versions,
        private readonly CanonicalParser $parser,
        private readonly ApprovalTokenManager $approvals,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     slug: string|null,
     *     doc_id?: string|null,
     *     document_id?: int,
     *     flow_run_id?: string,
     *     errors?: array<string, list<string>>,
     *     approval?: array{approval_id: string, token: string, expires_at: string, approve_path: string, reject_path: string}|null,
     * }
     */
    public function importDocument(
        string $tenantId,
        string $projectKey,
        string $markdown,
        User $asUser,
        string $source,
    ): array {
        if (! $this->enabled()) {
            return ['status' => 'disabled', 'slug' => null];
        }

        $parsed = $this->parser->parse($markdown);
        if ($parsed === null) {
            return [
                'status' => 'invalid',
                'slug' => null,
                'errors' => ['frontmatter' => ['No YAML frontmatter block detected at the top of the document.']],
            ];
        }

        $validation = $this->parser->validate($parsed);
        if (! $validation->valid) {
            return ['status' => 'invalid', 'slug' => $parsed->slug, 'errors' => $validation->errors];
        }

        $slug = (string) $parsed->slug;
        $previousTenant = $this->tenant->current();
        $previousUser = Auth::user();

        try {
            // Same discipline as KbWikiExportService::export() (ADR 0032
            // §5) — the diff lookup below runs through AccessScopeScope, so
            // it must never see MORE than $asUser can retrieve either: an
            // importer with no visibility into a slug's project should not
            // learn whether that slug already exists on the server via this
            // method's "unchanged"/"new" signal.
            $this->tenant->set($tenantId);
            Auth::forgetGuards();
            Auth::setUser($asUser);

            $current = KnowledgeDocument::query()
                ->forTenant($tenantId)
                ->where('project_key', $projectKey)
                ->where('slug', $slug)
                ->where('status', 'active')
                ->first();

            if ($current instanceof KnowledgeDocument && ! $this->frontmatterDiffers($current, $parsed)) {
                $liveBody = trim((string) ($this->versions->contentFor($current)['content'] ?? ''));
                if ($liveBody === trim($parsed->body)) {
                    return ['status' => 'unchanged', 'slug' => $slug, 'document_id' => (int) $current->id];
                }
            }

            $contentHash = hash('sha256', $markdown);
            $actorIdentity = 'user:'.$asUser->id;
            $key = KbWikiImportCandidate::idempotencyKeyFor($tenantId, $projectKey, $slug, $contentHash, $actorIdentity);

            return Cache::lock("kb-wiki-import-actor:{$tenantId}:{$actorIdentity}", 10)->block(5, function () use (
                $tenantId, $projectKey, $slug, $markdown, $parsed, $asUser, $actorIdentity, $contentHash, $key, $source,
            ): array {
                // NOTE: reissuePendingForStep() is a ONE-SHOT operation on
                // the underlying FlowApprovalRecord (it flags
                // `previous_token_hash`, and its own lookup query requires
                // that column to be NULL) — calling it a second time for
                // the SAME approval record always returns null, whether the
                // run is still genuinely pending or has since been decided.
                // A replay therefore reports the SAME flow_run_id WITHOUT
                // attempting to mint a second token: the plain-text token is
                // available only once, at first issuance, and there is no
                // package-level operation that hands out a second one for
                // an approval record this method has already reissued
                // against. This is honest about the real constraint rather
                // than silently starting a SECOND promotion run for
                // identical content.
                $existing = KbWikiImportCandidate::query()->forTenant($tenantId)->where('idempotency_key', $key)->first();
                if ($existing instanceof KbWikiImportCandidate) {
                    return [
                        'status' => 'replayed',
                        'slug' => $slug,
                        'doc_id' => $parsed->docId,
                        'flow_run_id' => $existing->flow_run_id,
                        'approval' => null,
                    ];
                }

                $limit = max(0, (int) config('kb.wiki_export.import_candidates_per_hour', 30));
                $limiterKey = "kb-wiki-import:{$tenantId}:{$actorIdentity}";
                if ($limit > 0 && RateLimiter::tooManyAttempts($limiterKey, $limit)) {
                    throw new KbWikiImportRateLimitedException($actorIdentity, $limit);
                }
                if ($limit > 0) {
                    RateLimiter::hit($limiterKey, 3600);
                }

                $title = $this->firstHeading($parsed->body) ?? $slug;

                try {
                    $run = Flow::execute(
                        PromotionFlow::NAME,
                        [
                            'tenant_id' => $tenantId,
                            'project_key' => $projectKey,
                            'markdown' => $markdown,
                            'title' => $title,
                            'promotion_source' => 'wiki-import',
                        ],
                        FlowExecutionOptions::make(correlationId: $tenantId),
                    );
                } catch (\Throwable $e) {
                    $correlationId = bin2hex(random_bytes(8));
                    Log::error('kb_wiki_import.flow_dispatch_failed', [
                        'correlation_id' => $correlationId,
                        'tenant_id' => $tenantId,
                        'project_key' => $projectKey,
                        'slug' => $slug,
                        'exception' => $e::class,
                    ]);

                    throw new \RuntimeException("Failed to start the promotion flow for wiki import (correlation_id: {$correlationId}).", 0, $e);
                }

                if ($run->status !== FlowRun::STATUS_PAUSED) {
                    return ['status' => $run->status, 'slug' => $slug, 'doc_id' => $parsed->docId, 'flow_run_id' => $run->id];
                }

                $issued = $this->approvals->reissuePendingForStep($run->id, PromotionFlow::APPROVAL_STEP);
                if (! ($issued instanceof IssuedApprovalToken)) {
                    return ['status' => 'paused', 'slug' => $slug, 'doc_id' => $parsed->docId, 'flow_run_id' => $run->id, 'approval' => null];
                }

                // R21-adjacent, not fully race-free — see the class
                // docblock's "one honestly-documented gap": the expensive,
                // side-effecting Flow::execute() call above already ran by
                // the time this insert can fail on the unique constraint, so
                // a concurrent DIFFERENT actor racing the same content is
                // not prevented from also starting a run. The per-actor
                // Cache::lock() above DOES prevent it for the realistic
                // case (the SAME actor retrying).
                try {
                    KbWikiImportCandidate::create([
                        'tenant_id' => $tenantId,
                        'project_key' => $projectKey,
                        'slug' => $slug,
                        'requested_by' => $asUser->id,
                        'idempotency_key' => $key,
                        'content_hash' => $contentHash,
                        'flow_run_id' => $run->id,
                        'source' => $source,
                    ]);
                } catch (QueryException $e) {
                    Log::warning('kb_wiki_import.idempotency_race', [
                        'tenant_id' => $tenantId,
                        'slug' => $slug,
                        'flow_run_id' => $run->id,
                    ]);
                }

                return $this->present('created', $run->id, $slug, $parsed->docId, $issued);
            });
        } finally {
            Auth::forgetGuards();
            if ($previousUser !== null) {
                Auth::setUser($previousUser);
            }
            $this->tenant->set($previousTenant);
        }
    }

    /**
     * CLI-only (ADR 0032 §10): the one surface with local filesystem access
     * to the folder a person brought back. `{folder}` is resolved with
     * `realpath()` and every candidate file is re-checked to stay under
     * that resolved root — a DIFFERENT, deliberately separate check from
     * `KbPath::normalize()` (R1), which governs the KB-DISK destination of
     * an ingest, not a local read root (ADR 0032 §10 draws this distinction
     * explicitly: neither check substitutes for the other).
     *
     * Only `wiki/*.md` is walked — `index.md`/`log.md` are regenerated hub
     * pages, not editorial content, and `raw/` is the stored conversion
     * artifact (ADR 0030), never a promotion source.
     *
     * @return list<array{path: string, status: string, slug: string|null, document_id?: int, flow_run_id?: string, errors?: array<string, list<string>>}>
     */
    public function importFolder(string $tenantId, string $folderPath, User $asUser): array
    {
        if (! $this->enabled()) {
            return [['path' => $folderPath, 'status' => 'disabled', 'slug' => null]];
        }

        $root = realpath($folderPath);
        if ($root === false) {
            throw new \InvalidArgumentException("Import folder does not exist or is not readable: {$folderPath}");
        }

        $wikiDir = realpath($root.'/wiki');
        // Copilot/independent-review finding (PR #509) — a bare
        // str_starts_with($wikiDir, $root) is a classic prefix-without-
        // separator bug: a symlinked wiki/ resolving to a SIBLING of $root
        // whose name merely starts with $root's own name (e.g. "$root-evil")
        // would satisfy the check while resolving outside the intended
        // root. Requiring the trailing separator closes that gap.
        if ($wikiDir === false || ! str_starts_with($wikiDir, $root.DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException("No wiki/ folder found under: {$root}");
        }

        $manifestPath = $root.'/MANIFEST.json';
        $projectKey = $this->projectKeyFromManifest($manifestPath, $tenantId);

        $results = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wikiDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() || $item->getExtension() !== 'md') {
                continue;
            }
            if (in_array($item->getFilename(), ['index.md', 'log.md'], true)) {
                continue;
            }

            $realFile = realpath((string) $item->getPathname());
            // Same prefix-without-separator fix as the $wikiDir check above
            // — a bare str_starts_with($realFile, $wikiDir) would accept a
            // symlinked page resolving into "$wikiDir-evil/...".
            if ($realFile === false || ! str_starts_with($realFile, $wikiDir.DIRECTORY_SEPARATOR)) {
                // Symlink escape or a file that vanished between the scan
                // and this check — refuse rather than read outside the
                // resolved root (ADR 0032 §10).
                $results[] = ['path' => (string) $item->getPathname(), 'status' => 'refused_unsafe_path', 'slug' => null];

                continue;
            }

            $markdown = file_get_contents($realFile);
            if ($markdown === false) {
                $results[] = ['path' => $realFile, 'status' => 'unreadable', 'slug' => null];

                continue;
            }

            // Independent-review nit (PR #509) — importDocument() can throw
            // KbWikiImportRateLimitedException, which previously propagated
            // straight out of this loop and lost every result already
            // collected for files processed before the rate limit was hit.
            // Once the actor's budget is spent, every remaining file in
            // this SAME call would fail identically, so there is no value
            // in continuing to try them — record the one refusal and stop,
            // returning everything gathered so far.
            try {
                $result = $this->importDocument($tenantId, $projectKey, $markdown, $asUser, KbWikiImportCandidate::SOURCE_CLI);
            } catch (KbWikiImportRateLimitedException $e) {
                $results[] = ['path' => $realFile, 'status' => 'rate_limited', 'slug' => null, 'message' => $e->getMessage()];

                return $results;
            }
            $results[] = array_merge(['path' => $realFile], $result);
        }

        return $results;
    }

    private function projectKeyFromManifest(string $manifestPath, string $tenantId): string
    {
        $raw = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
        if ($raw === false) {
            throw new \InvalidArgumentException("Import folder has no MANIFEST.json — cannot determine project_key: {$manifestPath}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['project_key']) || ! is_string($decoded['project_key']) || $decoded['project_key'] === '') {
            throw new \InvalidArgumentException("MANIFEST.json is missing a valid project_key: {$manifestPath}");
        }

        $manifestTenant = is_string($decoded['tenant_id'] ?? null) ? $decoded['tenant_id'] : null;
        if ($manifestTenant !== null && $manifestTenant !== $tenantId) {
            throw new \InvalidArgumentException(
                "MANIFEST.json was exported for tenant '{$manifestTenant}', but --tenant='{$tenantId}' was given. Refusing to import a folder into a different tenant than it was exported from."
            );
        }

        return $decoded['project_key'];
    }

    /**
     * @return array{status: string, slug: string, doc_id: string|null, flow_run_id: string, approval: array{approval_id: string, token: string, expires_at: string, approve_path: string, reject_path: string}}
     */
    private function present(string $status, string $flowRunId, string $slug, ?string $docId, IssuedApprovalToken $issued): array
    {
        $approvePath = "/api/kb/promotion/{$issued->approvalId}/approve";
        $rejectPath = "/api/kb/promotion/{$issued->approvalId}/reject";

        return [
            'status' => $status,
            'slug' => $slug,
            'doc_id' => $docId,
            'flow_run_id' => $flowRunId,
            'approval' => [
                'approval_id' => $issued->approvalId,
                'token' => $issued->plainTextToken,
                'expires_at' => $issued->expiresAt->format(\DateTimeInterface::ATOM),
                'approve_path' => $approvePath,
                'reject_path' => $rejectPath,
            ],
        ];
    }

    /**
     * Independent-review finding (PR #509) — the body-only diff described
     * in the class docblock left a real gap: an edit that changes ONLY
     * `type`/`status`/`retrieval_priority` (e.g. demoting a page from
     * `accepted` to `deprecated`) produced a byte-identical body and was
     * reported `unchanged`, silently dropping an editorial change the
     * class's own docblock explicitly warns against ("a false 'unchanged'
     * would SILENTLY DROP a real edit"). This governs ONLY whether the
     * body-comparison branch runs at all — it does not replace it: a
     * frontmatter change alone is enough to skip straight to proposing,
     * without needing the (deliberately noisy-tolerant) body comparison to
     * agree.
     */
    private function frontmatterDiffers(KnowledgeDocument $current, CanonicalParsedDocument $parsed): bool
    {
        if ($current->canonical_type !== $parsed->type?->value) {
            return true;
        }
        if ($current->canonical_status !== $parsed->status?->value) {
            return true;
        }

        return (int) $current->retrieval_priority !== $parsed->retrievalPriority;
    }

    private function firstHeading(string $body): ?string
    {
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            if (preg_match('/^\s*#\s+(.+?)\s*$/', $line, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function enabled(): bool
    {
        return (bool) config('kb.wiki_export.enabled', false);
    }
}
