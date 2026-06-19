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
 * v8.16/W4 — MCP read surface (R44) for AI FinOps spend.
 *
 * The third surface over the same capability: PHP (the `padosoft/laravel-ai-finops`
 * services + `ReportCommand`) and HTTP (the package admin routes under
 * `api/admin/ai-finops`) already exist; this tool exposes the same usage-ledger
 * data to an MCP client. It reads the `ai_finops_usage_ledger` table directly
 * (the package records via `DatabaseUsageRecorder`, no Eloquent model) and is
 * STRICTLY tenant-scoped (R30) — a tenant can only ever see its own spend.
 *
 * Degrades cleanly when finops is absent: if the ledger table doesn't exist the
 * tool returns an empty, well-formed summary rather than throwing (R43 OFF path).
 */
#[Description('Summarise this tenant\'s AI spend from the FinOps usage ledger over a rolling window: total cost + token counts in the base currency, and a per-(provider, model) breakdown of cost / tokens / call count, ordered by cost.')]
#[IsReadOnly]
#[IsIdempotent]
class FinOpsSpendSummaryTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()
                ->description('Rolling window in days (1–365) of ledger rows to aggregate.')
                ->default(30),
            'limit' => $schema->integer()
                ->description('Max (provider, model) rows in the breakdown, ordered by descending cost.')
                ->default(10),
        ];
    }

    public function handle(Request $request, TenantContext $tenants): Response
    {
        $days = max(1, min(365, (int) ($request->get('days') ?? 30)));
        $limit = max(1, min(100, (int) ($request->get('limit') ?? 10)));
        $tenantId = $tenants->current();

        // OFF path: finops not installed / table absent → empty, well-formed result.
        if (! $this->ledgerExists()) {
            return Response::json($this->emptyPayload($days, $tenantId));
        }

        $since = Carbon::now()->subDays($days);
        $base = (string) config('ai-finops.currency.base', 'USD');

        $breakdown = UsageRecord::query()
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
            ->map(static fn ($row): array => [
                'provider' => (string) $row->provider,
                'model' => (string) $row->model,
                'cost' => number_format((float) $row->cost, 8, '.', ''),
                'tokens_input' => (int) $row->tokens_input,
                'tokens_output' => (int) $row->tokens_output,
                'calls' => (int) $row->calls,
            ])
            ->all();

        $totals = UsageRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since)
            ->first([
                DB::raw('COALESCE(SUM(cost_total), 0) as cost'),
                DB::raw('COALESCE(SUM(tokens_input), 0) as tokens_input'),
                DB::raw('COALESCE(SUM(tokens_output), 0) as tokens_output'),
                DB::raw('COUNT(*) as calls'),
            ]);

        return Response::json([
            'tenant_id' => $tenantId,
            'window_days' => $days,
            'currency' => $base,
            'total_cost' => number_format((float) ($totals->cost ?? 0), 8, '.', ''),
            'total_tokens_input' => (int) ($totals->tokens_input ?? 0),
            'total_tokens_output' => (int) ($totals->tokens_output ?? 0),
            'total_calls' => (int) ($totals->calls ?? 0),
            'breakdown' => $breakdown,
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
    private function emptyPayload(int $days, string $tenantId): array
    {
        return [
            'tenant_id' => $tenantId,
            'window_days' => $days,
            'currency' => (string) config('ai-finops.currency.base', 'USD'),
            'total_cost' => '0.00000000',
            'total_tokens_input' => 0,
            'total_tokens_output' => 0,
            'total_calls' => 0,
            'breakdown' => [],
        ];
    }
}
