<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Invite\MetricsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP read surface (R44) over MetricsService — exposes the invite funnel +
 * virality metrics (K-factor, acceptance, conversion, TTR) for the
 * MCP-resolved tenant.
 */
#[Description('Read invite-system funnel + virality metrics (k_factor, acceptance_rate, conversion_rate, TTR percentiles) for the current tenant, optionally scoped to a campaign or a recent window.')]
#[IsReadOnly]
#[IsIdempotent]
class InviteMetricsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()->description('Optional campaign id to scope the metrics to.'),
            'since_days' => $schema->integer()->description('Optional lookback window in days.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $campaignId = $request->get('campaign_id');
        $sinceDays = $request->get('since_days');

        return Response::json(app(MetricsService::class)->summary(
            $campaignId !== null ? (int) $campaignId : null,
            $sinceDays !== null ? (int) $sinceDays : null,
        ));
    }
}
