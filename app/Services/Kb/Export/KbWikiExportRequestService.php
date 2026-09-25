<?php

declare(strict_types=1);

namespace App\Services\Kb\Export;

use App\Jobs\ExecuteKbWikiExportJob;
use App\Models\KbWikiExportRequest;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;

/**
 * v8.38/W4b (ADR 0032 §5/§11) — orchestrates the ASYNC export request:
 * computes the idempotency key while the requesting user's own Sanctum
 * session is still the active guard (so `AccessScopeScope` scopes the
 * visibility query to exactly what they can see), creates-or-reuses the
 * {@see KbWikiExportRequest} row, and dispatches
 * {@see ExecuteKbWikiExportJob}. The job itself re-derives everything from
 * the row + a reloaded `User` — this service never runs inside the job.
 *
 * DELIBERATELY does not call {@see KbWikiExportService::export()} directly:
 * that stays the CLI's (and the job's) synchronous entry point. This class
 * is the async front door only.
 */
final class KbWikiExportRequestService
{
    /**
     * @param  array{format?: string, include_images?: bool}  $options
     *
     * @throws \InvalidArgumentException when $options names a format or
     *         `include_images: true` — {@see KbWikiExportService::export()}
     *         does not implement `--format` variants or image inclusion yet
     *         (ADR 0032 §2/§7 — W4c). Rejecting loudly here (R14) is the
     *         alternative to silently accepting and ignoring an option the
     *         underlying export cannot honour.
     */
    public function requestExport(string $tenantId, string $projectKey, User $requestingUser, array $options = []): KbWikiExportRequest
    {
        // Defensive: the idempotency computation below relies on
        // AccessScopeScope resolving through the CURRENT auth()->user(),
        // so this service must only ever be called with that user already
        // the active principal (the admin HTTP request's own Sanctum
        // guard) — never on behalf of a different or unauthenticated user.
        $current = Auth::user();
        if (! $current instanceof User || (int) $current->id !== (int) $requestingUser->id) {
            throw new \RuntimeException(
                'KbWikiExportRequestService::requestExport() must run with $requestingUser as the active guard user.'
            );
        }

        $normalizedOptions = $this->normalizeOptions($options);
        $visibleIds = $this->visibleDocumentIds($tenantId, $projectKey);
        $corpusSnapshot = $this->corpusSnapshotFor($tenantId, $projectKey);
        $authDigest = $this->authorizationDigest($tenantId, $requestingUser, $visibleIds);
        $key = $this->idempotencyKeyFor(
            $tenantId,
            (int) $requestingUser->id,
            $projectKey,
            $normalizedOptions,
            $corpusSnapshot,
            $authDigest,
        );

        $existing = KbWikiExportRequest::query()
            ->forTenant($tenantId)
            ->where('idempotency_key', $key)
            ->whereIn('status', [
                KbWikiExportRequest::STATUS_QUEUED,
                KbWikiExportRequest::STATUS_PROCESSING,
                KbWikiExportRequest::STATUS_COMPLETED,
            ])
            ->first();
        if ($existing instanceof KbWikiExportRequest) {
            return $existing;
        }

        try {
            $request = KbWikiExportRequest::create([
                'tenant_id' => $tenantId,
                'project_key' => $projectKey,
                'requested_by' => $requestingUser->id,
                'status' => KbWikiExportRequest::STATUS_QUEUED,
                'options_json' => $normalizedOptions,
                'idempotency_key' => $key,
            ]);
        } catch (QueryException $exception) {
            // Concurrent identical request raced the unique
            // (tenant_id, idempotency_key) constraint — the loser reads
            // the winner instead of erroring (same posture as
            // KbReviewService::proposeCorrection()'s idempotency race).
            $winner = KbWikiExportRequest::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $key)
                ->first();
            if ($winner instanceof KbWikiExportRequest) {
                return $winner;
            }

            throw $exception;
        }

        ExecuteKbWikiExportJob::dispatch($request->id, (int) $requestingUser->id, $tenantId)
            ->onQueue((string) config('kb.wiki_export.queue', 'default'));

        return $request;
    }

    /**
     * @return list<int>
     */
    private function visibleDocumentIds(string $tenantId, string $projectKey): array
    {
        return KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array{max_updated_at: string, count: int}
     */
    private function corpusSnapshotFor(string $tenantId, string $projectKey): array
    {
        $row = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->where('status', 'active')
            ->selectRaw('MAX(updated_at) as max_updated_at, COUNT(*) as row_count')
            ->first();

        return [
            'max_updated_at' => $row?->max_updated_at !== null ? (string) $row->max_updated_at : '',
            'count' => (int) ($row?->row_count ?? 0),
        ];
    }

    /**
     * @param  list<int>  $visibleIds
     */
    private function authorizationDigest(string $tenantId, User $user, array $visibleIds): string
    {
        $roles = $user->getRoleNames()->sort()->values()->all();
        $memberships = ProjectMembership::query()
            ->forTenant($tenantId)
            ->where('user_id', $user->id)
            ->orderBy('project_key')
            ->pluck('project_key')
            ->all();
        sort($visibleIds);

        return hash('sha256', json_encode([
            'visible_document_ids' => $visibleIds,
            'roles' => $roles,
            'project_memberships' => $memberships,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{format: string, include_images: bool}  $normalizedOptions
     * @param  array{max_updated_at: string, count: int}  $corpusSnapshot
     */
    private function idempotencyKeyFor(
        string $tenantId,
        int $userId,
        string $projectKey,
        array $normalizedOptions,
        array $corpusSnapshot,
        string $authDigest,
    ): string {
        return hash('sha256', json_encode([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_key' => $projectKey,
            'options' => $normalizedOptions,
            'corpus_snapshot' => $corpusSnapshot,
            'authorization_digest' => $authDigest,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{format?: string, include_images?: bool}  $options
     * @return array{format: string, include_images: bool}
     */
    private function normalizeOptions(array $options): array
    {
        $format = (string) ($options['format'] ?? 'llm-wiki');
        if ($format !== 'llm-wiki') {
            throw new \InvalidArgumentException(
                "Export format '{$format}' is not supported yet — only 'llm-wiki' ships in W4b (ADR 0032 §2 format variants land in W4c)."
            );
        }

        $includeImages = (bool) ($options['include_images'] ?? false);
        if ($includeImages) {
            throw new \InvalidArgumentException(
                'include_images is not supported yet — image inclusion lands in W4c (ADR 0032 §7).'
            );
        }

        return [
            'format' => $format,
            'include_images' => $includeImages,
        ];
    }
}
