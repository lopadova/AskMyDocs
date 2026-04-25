<?php

namespace App\Services\Admin;

use App\Models\ChatLog;
use App\Models\EmbeddingCache;
use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only admin metrics aggregation.
 *
 * Every method scopes by an optional `project_key` and a rolling window
 * in days. Queries are aggregated on the database side (COUNT, SUM, AVG,
 * GROUP BY) — never load-and-filter in PHP (R3). Soft-deleted documents
 * are automatically hidden by the SoftDeletes trait / AccessScopeScope
 * on the Eloquent reads; the raw table-count queries on `knowledge_*`
 * explicitly filter `deleted_at IS NULL` so R2 is honoured even for
 * non-Eloquent aggregates.
 */
class AdminMetricsService
{
    /**
     * Headline KPIs shown on the dashboard KPI strip.
     *
     * @return array{
     *   total_docs: int,
     *   total_chunks: int,
     *   total_chats: int,
     *   avg_latency_ms: int,
     *   failed_jobs: int,
     *   pending_jobs: int,
     *   cache_hit_rate: float,
     *   canonical_coverage_pct: float,
     *   storage_used_mb: float,
     * }
     */
    public function kpiOverview(?string $projectKey = null, int $days = 7): array
    {
        $since = Carbon::now()->subDays(max(1, $days));

        $docsQuery = DB::table('knowledge_documents')->whereNull('deleted_at');
        if ($projectKey !== null) {
            $docsQuery->where('project_key', $projectKey);
        }

        $totalDocs = (int) $docsQuery->count();
        $canonicalDocs = (int) (clone $docsQuery)->where('is_canonical', true)->count();

        // Copilot #4 fix: count chunks only for non-soft-deleted
        // documents (R2). `knowledge_chunks` doesn't have its own
        // SoftDeletes trait — chunks are only wiped on hard delete,
        // so a bare count inflated the KPI after every soft delete.
        $chunksQuery = DB::table('knowledge_chunks')
            ->join(
                'knowledge_documents',
                'knowledge_chunks.knowledge_document_id',
                '=',
                'knowledge_documents.id'
            )
            ->whereNull('knowledge_documents.deleted_at');
        if ($projectKey !== null) {
            $chunksQuery->where('knowledge_documents.project_key', $projectKey);
        }

        $totalChunks = (int) $chunksQuery->count();

        $chatQuery = DB::table('chat_logs')->where('created_at', '>=', $since);
        if ($projectKey !== null) {
            $chatQuery->where('project_key', $projectKey);
        }

        $totalChats = (int) (clone $chatQuery)->count();
        $avgLatency = (int) round((float) (clone $chatQuery)->avg('latency_ms'));

        $failedJobs = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->count()
            : 0;

        $pendingJobs = Schema::hasTable('jobs')
            ? (int) DB::table('jobs')->count()
            : 0;

        $cacheHitRate = $this->cacheHitRate($since);
        $coveragePct = $totalDocs > 0
            ? round(($canonicalDocs / $totalDocs) * 100, 1)
            : 0.0;

        $storageMb = $this->storageUsedMb($projectKey);

        return [
            'total_docs' => $totalDocs,
            'total_chunks' => $totalChunks,
            'total_chats' => $totalChats,
            'avg_latency_ms' => $avgLatency,
            'failed_jobs' => $failedJobs,
            'pending_jobs' => $pendingJobs,
            'cache_hit_rate' => $cacheHitRate,
            'canonical_coverage_pct' => $coveragePct,
            'storage_used_mb' => $storageMb,
        ];
    }

    /**
     * Chat counts grouped by day (YYYY-MM-DD) within the rolling window.
     *
     * @return array<int, array{date:string,count:int}>
     */
    public function chatVolume(?string $projectKey, int $days): array
    {
        $since = Carbon::now()->subDays(max(1, $days));

        $query = DB::table('chat_logs')
            ->selectRaw("strftime('%Y-%m-%d', created_at) as bucket, COUNT(*) as cnt")
            ->where('created_at', '>=', $since);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            // Postgres and MySQL understand DATE() — sqlite gets strftime.
            $query = DB::table('chat_logs')
                ->selectRaw('DATE(created_at) as bucket, COUNT(*) as cnt')
                ->where('created_at', '>=', $since);
        }

        if ($projectKey !== null) {
            $query->where('project_key', $projectKey);
        }

        $rows = $query->groupBy('bucket')->orderBy('bucket')->get();

        return $rows->map(fn ($row) => [
            'date' => (string) $row->bucket,
            'count' => (int) $row->cnt,
        ])->all();
    }

    /**
     * Prompt + completion token sums grouped by provider for the window.
     *
     * @return array<int, array{provider:string,prompt_tokens:int,completion_tokens:int,total_tokens:int}>
     */
    public function tokenBurn(?string $projectKey, int $days): array
    {
        $since = Carbon::now()->subDays(max(1, $days));

        $query = DB::table('chat_logs')
            ->selectRaw('ai_provider as provider,
                COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->where('created_at', '>=', $since);

        if ($projectKey !== null) {
            $query->where('project_key', $projectKey);
        }

        $rows = $query->groupBy('ai_provider')->orderBy('ai_provider')->get();

        return $rows->map(fn ($row) => [
            'provider' => (string) $row->provider,
            'prompt_tokens' => (int) $row->prompt_tokens,
            'completion_tokens' => (int) $row->completion_tokens,
            'total_tokens' => (int) $row->total_tokens,
        ])->all();
    }

    /**
     * Distribution of message ratings (positive/negative/none) across the window.
     *
     * @return array{positive:int,negative:int,unrated:int,total:int}
     */
    public function ratingDistribution(?string $projectKey, int $days): array
    {
        $since = Carbon::now()->subDays(max(1, $days));

        $base = DB::table('messages')
            ->where('role', 'assistant')
            ->where('created_at', '>=', $since);

        if ($projectKey !== null) {
            // messages table does not carry project_key directly; scope via
            // the owning conversation so project filters are honoured.
            $base->whereIn('conversation_id', function ($sub) use ($projectKey) {
                $sub->select('id')
                    ->from('conversations')
                    ->where('project_key', $projectKey);
            });
        }

        $positive = (int) (clone $base)->where('rating', 'positive')->count();
        $negative = (int) (clone $base)->where('rating', 'negative')->count();
        $total = (int) (clone $base)->count();
        $unrated = max(0, $total - $positive - $negative);

        return [
            'positive' => $positive,
            'negative' => $negative,
            'unrated' => $unrated,
            'total' => $total,
        ];
    }

    /**
     * Top N projects by chat volume within the rolling window.
     *
     * Copilot #1 fix: accept `$projectKey` + `$days` so this metric
     * stays consistent with the `(project, days)` cache key used by
     * DashboardMetricsController::series(). Previously hard-coded
     * to 7 days and no project filter, which meant two different
     * cache entries could return the same top_projects payload.
     *
     * @return array<int, array{project_key:string,count:int}>
     */
    public function topProjects(int $limit = 10, ?string $projectKey = null, int $days = 7): array
    {
        $since = Carbon::now()->subDays(max(1, $days));

        $query = DB::table('chat_logs')
            ->selectRaw('project_key, COUNT(*) as cnt')
            ->where('created_at', '>=', $since)
            ->whereNotNull('project_key');

        if ($projectKey !== null) {
            $query->where('project_key', $projectKey);
        }

        $rows = $query
            ->groupBy('project_key')
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'project_key' => (string) $row->project_key,
            'count' => (int) $row->cnt,
        ])->all();
    }

    /**
     * Mixed activity feed: last N chat logs + canonical audit rows ordered by time.
     *
     * @return array<int, array{
     *   source:string,
     *   id:int,
     *   actor:string,
     *   action:string,
     *   target:string,
     *   project:string,
     *   created_at:string
     * }>
     */
    public function activityFeed(int $limit = 20, ?string $projectKey = null): array
    {
        // Copilot #1 fix: honour the `$projectKey` filter so the feed
        // stays consistent with the `(project, days)` cache key in
        // DashboardMetricsController::series(). Without this a user
        // scoped to `hr-portal` would see audit rows and chats from
        // every other tenant mixed in.
        $half = max(1, (int) ceil($limit / 2));

        $chatsQuery = DB::table('chat_logs as cl')
            ->leftJoin('users as u', 'u.id', '=', 'cl.user_id')
            ->select([
                'cl.id',
                'cl.project_key',
                'cl.ai_provider',
                'cl.ai_model',
                'cl.created_at',
                'u.name as user_name',
                'u.email as user_email',
            ]);

        if ($projectKey !== null) {
            $chatsQuery->where('cl.project_key', $projectKey);
        }

        $chats = $chatsQuery
            ->orderByDesc('cl.created_at')
            ->limit($half)
            ->get()
            ->map(fn ($row) => [
                'source' => 'chat',
                'id' => (int) $row->id,
                'actor' => (string) ($row->user_name ?? $row->user_email ?? 'anonymous'),
                'action' => 'asked via',
                'target' => sprintf('%s/%s', $row->ai_provider, $row->ai_model),
                'project' => (string) ($row->project_key ?? ''),
                'created_at' => (string) $row->created_at,
            ])
            ->all();

        $audits = [];
        if (Schema::hasTable('kb_canonical_audit')) {
            $auditQuery = DB::table('kb_canonical_audit');
            if ($projectKey !== null) {
                $auditQuery->where('project_key', $projectKey);
            }
            $audits = $auditQuery
                ->orderByDesc('created_at')
                ->limit($half)
                ->get()
                ->map(fn ($row) => [
                    'source' => 'audit',
                    'id' => (int) $row->id,
                    'actor' => (string) $row->actor,
                    'action' => (string) $row->event_type,
                    'target' => (string) ($row->slug ?? $row->doc_id ?? 'n/a'),
                    'project' => (string) ($row->project_key ?? ''),
                    'created_at' => (string) $row->created_at,
                ])
                ->all();
        }

        $merged = array_merge($chats, $audits);
        usort($merged, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return array_slice($merged, 0, $limit);
    }

    private function cacheHitRate(Carbon $since): float
    {
        if (! Schema::hasTable('embedding_cache')) {
            return 0.0;
        }

        $total = (int) EmbeddingCache::query()->count();
        if ($total === 0) {
            return 0.0;
        }

        $recentlyUsed = (int) EmbeddingCache::query()
            ->where('last_used_at', '>=', $since)
            ->count();

        return round(($recentlyUsed / $total) * 100, 1);
    }

    private function storageUsedMb(?string $projectKey): float
    {
        // Copilot #5 fix: sum chunk text only for non-soft-deleted
        // documents (R2). Without the join, the KPI would drift
        // upward after soft deletes because chunks stay until the
        // document is hard-deleted.
        $query = DB::table('knowledge_chunks')
            ->join(
                'knowledge_documents',
                'knowledge_chunks.knowledge_document_id',
                '=',
                'knowledge_documents.id'
            )
            ->whereNull('knowledge_documents.deleted_at');

        if ($projectKey !== null) {
            $query->where('knowledge_documents.project_key', $projectKey);
        }

        // SQLite doesn't support LENGTH() on TEXT columns returning bytes the
        // same way Postgres does; both return character counts for non-binary
        // text which is a reasonable approximation at the MB scale.
        $bytes = (int) $query->sum(DB::raw('LENGTH(knowledge_chunks.chunk_text)'));

        return round($bytes / (1024 * 1024), 2);
    }
}
