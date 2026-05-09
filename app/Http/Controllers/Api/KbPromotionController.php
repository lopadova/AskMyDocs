<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Flow\Definitions\PromotionFlow;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Services\Kb\Canonical\PromotionSuggestService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;

/**
 * Promotion pipeline entry points — human-gated (see ADR 0003).
 *
 *   POST /api/kb/promotion/suggest    → LLM extracts candidates. Writes nothing.
 *   POST /api/kb/promotion/candidates → validate a draft against the schema.
 *                                       Writes nothing. Returns errors.
 *   POST /api/kb/promotion/promote    → kicks off the {@see PromotionFlow}
 *                                       saga. Returns HTTP 202 with a
 *                                       single-use approval token + URL the
 *                                       operator must POST to before the
 *                                       canonical bytes hit disk.
 *
 * v4.2/W2 PR #116: the legacy "validate + write + dispatch ingest" inline
 * controller logic has moved into the {@see PromotionFlow} saga. The
 * controller's job is now validation + dispatch + token shaping.
 *
 * Claude skills MUST stop at `candidates` (or `suggest`). Only operators
 * (human approval via `flow:approve` CLI or the future flow-admin SPA)
 * commit to canonical storage by approving the issued token.
 */
class KbPromotionController extends Controller
{
    public function suggest(Request $request, PromotionSuggestService $svc): JsonResponse
    {
        if (! (bool) config('kb.promotion.enabled', true)) {
            return response()->json(['error' => 'promotion_disabled'], 503);
        }

        $validated = $request->validate([
            'transcript' => ['required', 'string', 'max:50000'],
            'project_key' => ['nullable', 'string', 'max:120'],
            'existing_slugs' => ['nullable', 'array'],
            'existing_slugs.*' => ['string', 'max:120'],
        ]);

        $result = $svc->suggest(
            transcript: $validated['transcript'],
            projectKey: $validated['project_key'] ?? null,
            context: ['existing_slugs' => $validated['existing_slugs'] ?? []],
        );

        return response()->json($result);
    }

    public function candidates(Request $request, CanonicalParser $parser): JsonResponse
    {
        $validated = $request->validate([
            'markdown' => ['required', 'string', 'max:200000'],
        ]);

        $parsed = $parser->parse($validated['markdown']);
        if ($parsed === null) {
            return response()->json([
                'valid' => false,
                'errors' => ['frontmatter' => ['No YAML frontmatter block detected at the top of the document.']],
            ], 422);
        }

        $result = $parser->validate($parsed);
        if (! $result->valid) {
            return response()->json([
                'valid' => false,
                'errors' => $result->errors,
            ], 422);
        }

        return response()->json([
            'valid' => true,
            'parsed' => [
                'doc_id' => $parsed->docId,
                'slug' => $parsed->slug,
                'type' => $parsed->type?->value,
                'status' => $parsed->status?->value,
                'title_line' => $this->firstHeading($parsed->body),
                'related_slugs' => $parsed->relatedSlugs,
                'supersedes_slugs' => $parsed->supersedesSlugs,
                'tags' => $parsed->tags,
                'owners' => $parsed->owners,
            ],
        ]);
    }

    public function promote(
        Request $request,
        CanonicalParser $parser,
        ApprovalTokenManager $approvals,
        TenantContext $tenants,
    ): JsonResponse {
        if (! (bool) config('kb.promotion.enabled', true)) {
            return response()->json(['error' => 'promotion_disabled'], 503);
        }

        $validated = $request->validate([
            'project_key' => ['required', 'string', 'max:120'],
            'markdown' => ['required', 'string', 'max:200000'],
            'title' => ['nullable', 'string', 'max:500'],
        ]);

        // Pre-validate the markdown so we surface frontmatter errors as
        // HTTP 422 BEFORE issuing an approval token (the saga will run
        // its own validate-frontmatter step too — we mirror it here so
        // the controller never minted a token for a draft the engine
        // would immediately reject).
        $parsed = $parser->parse($validated['markdown']);
        if ($parsed === null) {
            return response()->json(['error' => 'no_frontmatter'], 422);
        }
        $validation = $parser->validate($parsed);
        if (! $validation->valid) {
            return response()->json([
                'error' => 'invalid_frontmatter',
                'errors' => $validation->errors,
            ], 422);
        }

        $tenantId = $tenants->current();
        $title = $validated['title'] ?? ($this->firstHeading($parsed->body) ?? ((string) $parsed->slug));

        try {
            $run = Flow::execute(
                PromotionFlow::NAME,
                [
                    // R30/R31 — tenant rides the input bag.
                    'tenant_id' => $tenantId,
                    'project_key' => $validated['project_key'],
                    'markdown' => $validated['markdown'],
                    'title' => $title,
                    'promotion_source' => 'api',
                ],
                FlowExecutionOptions::make(
                    correlationId: $tenantId,
                ),
            );
        } catch (\Throwable $e) {
            $correlationId = bin2hex(random_bytes(8));
            Log::error('KbPromotion: flow dispatch failed', [
                'correlation_id' => $correlationId,
                'project_key' => $validated['project_key'],
                'slug' => $parsed->slug,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'promotion_dispatch_failed',
                'message' => 'Failed to start the promotion flow.',
                'correlation_id' => $correlationId,
            ], 500);
        }

        // The saga should be paused at the approval-gate step waiting for
        // operator approval. If the validate-frontmatter step somehow
        // succeeded but the engine reports a non-paused outcome, surface
        // it transparently (the response shape distinguishes by `status`).
        if ($run->status !== FlowRun::STATUS_PAUSED) {
            return response()->json([
                'status' => $run->status,
                'flow_run_id' => $run->id,
                'doc_id' => $parsed->docId,
                'slug' => $parsed->slug,
            ], 202);
        }

        // R21 — issue (or re-issue) the single-use token tied to this
        // run + approval-gate step. The plain token is returned ONCE in
        // this response; only its SHA-256 hash is persisted.
        $issued = $approvals->reissuePendingForStep($run->id, PromotionFlow::APPROVAL_STEP);
        if ($issued === null) {
            // Defensive: the engine reports paused but the token row is
            // not pending. Surface enough context for operators to debug.
            return response()->json([
                'status' => 'paused',
                'flow_run_id' => $run->id,
                'doc_id' => $parsed->docId,
                'slug' => $parsed->slug,
                'approval' => null,
                'message' => 'Flow is paused but no pending approval token was found.',
            ], 202);
        }

        // Iter5 (PR #116) — surface both absolute URL and relative path so
        // clients that route through a reverse-proxy / different base
        // host don't have to strip the host themselves. The absolute
        // form is preserved for backwards compatibility with iteration-4
        // consumers; the *_path keys are the new, canonical, host-free
        // form recommended for new integrations.
        $approvePath = "/api/kb/promotion/{$issued->approvalId}/approve";
        $rejectPath = "/api/kb/promotion/{$issued->approvalId}/reject";

        return response()->json([
            'status' => 'paused',
            'flow_run_id' => $run->id,
            'doc_id' => $parsed->docId,
            'slug' => $parsed->slug,
            'approval' => [
                'approval_id' => $issued->approvalId,
                'token' => $issued->plainTextToken,
                'expires_at' => $issued->expiresAt->format(\DateTimeInterface::ATOM),
                'approve_url' => url($approvePath),
                'reject_url' => url($rejectPath),
                'approve_path' => $approvePath,
                'reject_path' => $rejectPath,
            ],
        ], 202);
    }

    /**
     * Approve a paused PromotionFlow run by consuming its single-use
     * token. Returns the resumed run's status + persisted relative
     * path on success.
     *
     * B.1 — `approvalId` (route path) must correspond to the supplied
     * `token` (request body). We hash the plain token via
     * {@see ApprovalTokenManager::hashToken()} and compare against the
     * stored {@see FlowApprovalRecord::$token_hash}. A mismatch — whether
     * the id is unknown OR the token doesn't match the id — returns a
     * uniform 403 with no internal detail (does not reveal which side
     * failed; mitigates token-fishing).
     *
     * B.2 — the Flow::resume call is wrapped in try/catch so the raw
     * exception message never leaks to the client; correlation_id is
     * logged + returned for operator-side debugging.
     *
     * R21 — token validation + decision are atomic inside
     * {@see ApprovalTokenManager::approve()} (transactional consume of
     * the pending row). Tokens are single-use; replay returns null.
     */
    public function approve(Request $request, string $approvalId): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'actor' => ['nullable', 'array'],
        ]);

        if (! $this->approvalIdMatchesToken($approvalId, $validated['token'])) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        try {
            $run = Flow::resume(
                $validated['token'],
                actor: array_merge(['source' => 'api'], (array) ($validated['actor'] ?? [])),
            );
        } catch (\Throwable $e) {
            $correlationId = bin2hex(random_bytes(8));
            Log::error('KbPromotion: approve failed', [
                'correlation_id' => $correlationId,
                'approval_id' => $approvalId,
                'error' => $e::class.': '.$e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'approve_failed',
                'message' => 'Failed to record approval decision.',
                'correlation_id' => $correlationId,
            ], 500);
        }

        if ($run->status !== FlowRun::STATUS_SUCCEEDED) {
            return response()->json([
                'status' => $run->status,
                'flow_run_id' => $run->id,
                'failed_step' => $run->failedStep,
            ], 202);
        }

        $writeOutput = $run->stepResults['write-markdown'] ?? null;
        $relativePath = $writeOutput?->output['relative_path'] ?? null;

        // Iteration 4 (PR #116) — R14: a 200 response with `path: null`
        // is the same defect class as a 200 response with an empty body.
        // The saga reports SUCCEEDED but the write-markdown step's
        // contract was not honoured — surface that loudly with a
        // correlation_id rather than handing the operator a useless
        // success envelope.
        if (! is_string($relativePath) || $relativePath === '') {
            $correlationId = bin2hex(random_bytes(8));
            Log::error('KbPromotion: approve completed but write-markdown output missing relative_path', [
                'correlation_id' => $correlationId,
                'approval_id' => $approvalId,
                'flow_run_id' => $run->id,
            ]);
            return response()->json([
                'error' => 'incomplete_promotion',
                'message' => 'Promotion completed but the canonical path could not be determined.',
                'correlation_id' => $correlationId,
            ], 500);
        }

        return response()->json([
            'status' => 'accepted',
            'flow_run_id' => $run->id,
            'approval_id' => $approvalId,
            'path' => $relativePath,
        ], 200);
    }

    /**
     * Reject a paused PromotionFlow run. The disk stays untouched (the
     * write-markdown step never runs) and a `rejected_promotion` audit
     * row is bridged into kb_canonical_audit by FlowServiceProvider.
     *
     * B.1 + B.3 — same validate-id-against-token guard and try/catch
     * correlation-id wrapping as {@see self::approve()}.
     */
    public function reject(Request $request, string $approvalId): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'actor' => ['nullable', 'array'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $this->approvalIdMatchesToken($approvalId, $validated['token'])) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        try {
            $run = Flow::reject(
                $validated['token'],
                payload: ['reason' => $validated['reason'] ?? null],
                actor: array_merge(['source' => 'api'], (array) ($validated['actor'] ?? [])),
            );
        } catch (\Throwable $e) {
            $correlationId = bin2hex(random_bytes(8));
            Log::error('KbPromotion: reject failed', [
                'correlation_id' => $correlationId,
                'approval_id' => $approvalId,
                'error' => $e::class.': '.$e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'reject_failed',
                'message' => 'Failed to record rejection decision.',
                'correlation_id' => $correlationId,
            ], 500);
        }

        return response()->json([
            'status' => $run->status,
            'flow_run_id' => $run->id,
            'approval_id' => $approvalId,
        ], 200);
    }

    /**
     * B.1 — return true only when the `approvalId` from the URL maps to
     * an approval row whose stored `token_hash` matches the SHA-256 of
     * the plain `token` from the body AND the row is still
     * actionable. Uniform false (caller maps to 403 with no internal
     * detail) for any of:
     *
     *   - id-not-found
     *   - token mismatch
     *   - status != 'pending' (already approved/rejected/expired)
     *   - consumed_at IS NOT NULL (token already consumed)
     *   - decided_at IS NOT NULL (decision already recorded)
     *   - expires_at IS NOT NULL AND expires_at <= now() (expired)
     *
     * {@see hash_equals} is constant-time so we don't leak the exact
     * mismatch surface via timing. The order of checks is deliberately
     * uniform — every path returns the same `false` so the caller
     * cannot infer which condition failed (mitigates token-fishing /
     * replay-attack reconnaissance).
     *
     * Iteration 3 (PR #116) — Copilot flagged that the docblock promised
     * uniform false for expired/already-consumed but the code only
     * verified the hash. A replayed token could pass this gate, then
     * Flow::resume() / Flow::reject() may either succeed (replay) or
     * fail with a revealing error message.
     *
     * Iteration 4 (PR #116) — R30 + step-name pinning. The lookup is now
     * scoped to the active tenant via `where('tenant_id', $current)` AND
     * to {@see PromotionFlow::APPROVAL_STEP}. Without these guards a
     * leaked approval id from another tenant or another flow's approval
     * step (e.g. a future promote-elsewhere flow) could be replayed
     * here. The vendor `flow_approvals` table carries `tenant_id` from
     * our supplementary migration `2026_05_09_146000_add_tenant_id_to_flow_tables`.
     *
     * Iteration 5 (PR #116) — defence-in-depth FLOW DEFINITION pinning.
     * Step name pinning above protects against unrelated flows that
     * happen to use a different step name. But every PromotionFlow-style
     * flow that uses the generic `approval-gate` step name would still
     * pass that gate. We now ALSO require the parent `flow_runs.definition_name`
     * to equal {@see PromotionFlow::NAME} so a token issued for some
     * future flow re-using the `approval-gate` step name CANNOT be
     * replayed against the kb.promote endpoints. The join is direct
     * SQL against the stable `flow_runs` table to avoid coupling to
     * the internal Eloquent model.
     */
    private function approvalIdMatchesToken(string $approvalId, string $plainToken): bool
    {
        $tenantId = app(TenantContext::class)->current();
        $row = FlowApprovalRecord::query()
            ->from('flow_approvals as fa')
            ->join('flow_runs as fr', 'fr.id', '=', 'fa.run_id')
            ->where('fa.tenant_id', $tenantId)
            ->where('fa.id', $approvalId)
            ->where('fa.step_name', PromotionFlow::APPROVAL_STEP)
            ->where('fr.definition_name', PromotionFlow::NAME)
            ->select('fa.*')
            ->first();
        if ($row === null) {
            return false;
        }
        if ((string) $row->status !== FlowApprovalRecord::STATUS_PENDING) {
            return false;
        }
        if ($row->consumed_at !== null) {
            return false;
        }
        if ($row->decided_at !== null) {
            return false;
        }
        if ($row->expires_at !== null && $row->expires_at->getTimestamp() <= time()) {
            return false;
        }
        $expectedHash = (string) $row->token_hash;
        $providedHash = ApprovalTokenManager::hashToken($plainToken);
        return hash_equals($expectedHash, $providedHash);
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
}
