<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Padosoft\LaravelAiFinOps\Models\Budget;

/**
 * v8.16/W4 — MCP read surface (R44) for this tenant's AI spend budgets.
 *
 * The third surface over the FinOps budgets capability already exposed via PHP
 * (the `Budget` model + `BudgetResolver`) and HTTP (the package `budgets`
 * routes). It delegates spend/limit/state computation to the package's own
 * `Budget::status()` core (NO reimplementation — R44 thin-layer rule) and is
 * STRICTLY tenant-scoped (R30): it only ever returns budgets whose scope is the
 * active tenant. Global / other-scope budgets aggregate spend ACROSS tenants,
 * so exposing their consumption to a single-tenant MCP client would leak
 * cross-tenant totals — they are deliberately excluded.
 *
 * Degrades cleanly when finops is absent (R43 OFF path): returns an empty,
 * well-formed list rather than throwing.
 */
#[Description('Report this tenant\'s AI spend budgets: for each enabled tenant-scoped budget, its limit, spend in the current period, remaining amount, percent consumed, and state (ok / warning / exceeded) in the budget\'s currency. Only budgets scoped to the active tenant are returned.')]
#[IsReadOnly]
#[IsIdempotent]
class FinOpsBudgetStatusTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request, TenantContext $tenants): Response
    {
        $tenantId = $tenants->current();

        if (! $this->budgetsExist()) {
            return Response::json($this->emptyPayload($tenantId));
        }

        // R30: only budgets explicitly scoped to THIS tenant. Global/provider/etc.
        // budgets aggregate spend across tenants and must not surface here.
        $budgets = Budget::query()
            ->where('enabled', true)
            ->where('scope_type', 'tenant')
            ->where('scope_id', $tenantId)
            ->orderBy('name')
            ->get();

        $rows = $budgets
            ->map(static fn (Budget $budget): array => $budget->status()->toArray(
                $budget->soft_limit_pct !== null ? (int) $budget->soft_limit_pct : null,
            ))
            ->all();

        return Response::json([
            'tenant_id' => $tenantId,
            'budgets' => $rows,
        ]);
    }

    private function budgetsExist(): bool
    {
        try {
            $model = new Budget();

            return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(string $tenantId): array
    {
        return [
            'tenant_id' => $tenantId,
            'budgets' => [],
        ];
    }
}
