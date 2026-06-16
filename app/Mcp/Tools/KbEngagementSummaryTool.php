<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KbEngagementSnapshot;
use App\Services\Engagement\EngagementMetricsService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.15/W1 — MCP read surface (R44) for KB engagement metrics: the daily
 * snapshot (contributors / activity / coverage / health) plus the contributor
 * leaderboard. Tenant-scoped via EnforceMcpScope (R30).
 */
#[Description('Summarise knowledge-base engagement: contributors, new/modified/promoted docs, answer rate, canonical coverage, average decision-debt score (higher = staler), and the contributor leaderboard.')]
#[IsReadOnly]
#[IsIdempotent]
class KbEngagementSummaryTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()
                ->description('Rolling activity window in days (1–90). Drives the live leaderboard and, when no daily snapshot exists yet, the live metrics fallback.')
                ->default(7),
            'leaderboard_limit' => $schema->integer()
                ->description('Max contributors to return in the leaderboard.')
                ->default(10),
        ];
    }

    public function handle(Request $request, EngagementMetricsService $metrics, TenantContext $tenants): Response
    {
        $days = max(1, min(90, (int) ($request->get('days') ?? 7)));
        $limit = max(1, min(50, (int) ($request->get('leaderboard_limit') ?? 10)));

        $snapshot = KbEngagementSnapshot::query()
            ->forTenant($tenants->current())
            ->latestSnapshot()
            ->first();

        // Prefer the daily snapshot; fall back to a live compute when none
        // exists yet (fresh install) OR when the snapshot's metrics column is
        // null (partial-compute failure). `?->` keeps this null-safe.
        $snapshotMetrics = $snapshot?->metrics;
        $usingSnapshot = $snapshotMetrics !== null && $snapshotMetrics !== [];

        return Response::json([
            'source' => $usingSnapshot ? 'snapshot' : 'live',
            'snapshot_date' => $snapshot?->snapshot_date?->toDateString(),
            'metrics' => $usingSnapshot ? $snapshotMetrics : $metrics->snapshotMetrics($days),
            'leaderboard' => $metrics->leaderboard($days, $limit),
        ]);
    }
}
