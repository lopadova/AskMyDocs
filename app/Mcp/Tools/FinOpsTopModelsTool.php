<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

/**
 * v8.16/W4 — MCP read surface (R44) ranking this tenant's costliest AI models.
 *
 * The third surface over the FinOps "footprint" capability already exposed via
 * PHP (`padosoft/laravel-ai-finops` services + `ReportCommand`) and HTTP (the
 * package `footprint/summary` + `footprint/trend` routes). It reads the
 * `ai_finops_usage_ledger` directly, STRICTLY tenant-scoped (R30), and returns
 * the top (provider, model) pairs by cost with each pair's share of total spend.
 *
 * Degrades cleanly when finops is absent (R43 OFF path): returns an empty,
 * well-formed ranking rather than throwing.
 */
#[Description('Rank this tenant\'s costliest AI models over a rolling window: the top (provider, model) pairs by total cost, each with its token counts, call count, and percentage share of the window\'s total spend, in the base currency.')]
#[IsReadOnly]
#[IsIdempotent]
class FinOpsTopModelsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()
                ->description('Rolling window in days (1–365) of ledger rows to rank.')
                ->default(30),
            'limit' => $schema->integer()
                ->description('Max (provider, model) pairs to return, ordered by descending cost.')
                ->default(5),
        ];
    }

    public function handle(Request $request, TenantContext $tenants): Response
    {
        $days = max(1, min(365, (int) ($request->get('days') ?? 30)));
        $limit = max(1, min(100, (int) ($request->get('limit') ?? 5)));
        $tenantId = $tenants->current();
        $base = (string) config('ai-finops.currency.base', 'USD');

        if (! $this->ledgerExists()) {
            return Response::json($this->emptyPayload($days, $tenantId, $base));
        }

        $since = Carbon::now()->subDays($days);

        $totalCost = (float) UsageRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since)
            ->sum('cost_total');

        $models = UsageRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since)
            ->groupBy('provider', 'model')
            ->orderByDesc('cost')
            ->limit($limit)
            ->get([
                'provider',
                'model',
                DB::raw('SUM(cost_total) as cost'),
                DB::raw('SUM(tokens_input) as tokens_input'),
                DB::raw('SUM(tokens_output) as tokens_output'),
                DB::raw('COUNT(*) as calls'),
            ])
            ->map(static function ($row) use ($totalCost): array {
                $cost = (float) $row->cost;

                return [
                    'provider' => (string) $row->provider,
                    'model' => (string) $row->model,
                    'cost' => number_format($cost, 8, '.', ''),
                    'tokens_input' => (int) $row->tokens_input,
                    'tokens_output' => (int) $row->tokens_output,
                    'calls' => (int) $row->calls,
                    'cost_share_pct' => $totalCost > 0 ? round(($cost / $totalCost) * 100, 4) : 0.0,
                ];
            })
            ->all();

        return Response::json([
            'tenant_id' => $tenantId,
            'window_days' => $days,
            'currency' => $base,
            'total_cost' => number_format($totalCost, 8, '.', ''),
            'models' => $models,
        ]);
    }

    private function ledgerExists(): bool
    {
        try {
            $model = new UsageRecord();

            return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(int $days, string $tenantId, string $currency): array
    {
        return [
            'tenant_id' => $tenantId,
            'window_days' => $days,
            'currency' => $currency,
            'total_cost' => '0.00000000',
            'models' => [],
        ];
    }
}
