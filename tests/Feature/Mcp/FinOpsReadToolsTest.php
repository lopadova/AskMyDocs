<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\FinOpsBudgetStatusTool;
use App\Mcp\Tools\FinOpsSpendSummaryTool;
use App\Mcp\Tools\FinOpsTopModelsTool;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Tests\TestCase;

/**
 * v8.16/W4 — proves the three AI FinOps MCP read surfaces (R44 third surface):
 *   - {@see FinOpsSpendSummaryTool} — tenant-scoped spend + per-model breakdown;
 *   - {@see FinOpsTopModelsTool}    — costliest models with cost-share;
 *   - {@see FinOpsBudgetStatusTool} — per tenant-scoped budget limit/spend/state.
 *
 * Invariants covered across all three: strict tenant scoping (R30) — tenant-a
 * never sees tenant-b's spend/budgets; aggregation + ordering; and both R43 OFF
 * paths (empty in-window result + finops tables absent) — never throws.
 *
 * The seed/drop helpers resolve the table + connection through the package's own
 * {@see UsageRecord} / {@see Budget} models so the test exercises exactly the
 * storage the tools read (prefix/connection-safe).
 */
final class FinOpsReadToolsTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenants = $this->app->make(TenantContext::class);
    }

    protected function tearDown(): void
    {
        $this->tenants->reset();
        parent::tearDown();
    }

    public function test_summary_is_scoped_to_the_active_tenant(): void
    {
        $this->seedRow('tenant-a', 'openai', 'gpt-5', costTotal: 1.25, tokensIn: 100, tokensOut: 40);
        $this->seedRow('tenant-a', 'anthropic', 'claude-opus-4-8', costTotal: 0.50, tokensIn: 30, tokensOut: 10);
        // Cross-tenant rows must be invisible to tenant-a.
        $this->seedRow('tenant-b', 'openai', 'gpt-5', costTotal: 99.00, tokensIn: 9000, tokensOut: 9000);

        $this->tenants->set('tenant-a');

        $payload = $this->invoke(['days' => 30, 'limit' => 10]);

        $this->assertSame('tenant-a', $payload['tenant_id']);
        $this->assertSame('1.75000000', $payload['total_cost']);
        $this->assertSame(130, $payload['total_tokens_input']);
        $this->assertSame(50, $payload['total_tokens_output']);
        $this->assertSame(2, $payload['total_calls']);

        // Breakdown ordered by descending cost: openai (1.25) before anthropic (0.50).
        $this->assertCount(2, $payload['breakdown']);
        $this->assertSame('openai', $payload['breakdown'][0]['provider']);
        $this->assertSame('1.25000000', $payload['breakdown'][0]['cost']);
        $this->assertSame('anthropic', $payload['breakdown'][1]['provider']);
    }

    public function test_rows_outside_the_window_are_excluded(): void
    {
        $this->seedRow('tenant-a', 'openai', 'gpt-5', costTotal: 2.00, createdAt: Carbon::now()->subDays(2));
        $this->seedRow('tenant-a', 'openai', 'gpt-5', costTotal: 5.00, createdAt: Carbon::now()->subDays(40));

        $this->tenants->set('tenant-a');

        $payload = $this->invoke(['days' => 30]);

        $this->assertSame('2.00000000', $payload['total_cost']);
        $this->assertSame(1, $payload['total_calls']);
    }

    public function test_empty_window_returns_a_well_formed_zero_payload(): void
    {
        $this->seedRow('tenant-b', 'openai', 'gpt-5', costTotal: 10.00);

        $this->tenants->set('tenant-a');

        $payload = $this->invoke(['days' => 30]);

        $this->assertSame('tenant-a', $payload['tenant_id']);
        $this->assertSame('0.00000000', $payload['total_cost']);
        $this->assertSame(0, $payload['total_calls']);
        $this->assertSame([], $payload['breakdown']);
    }

    public function test_limit_caps_the_breakdown_rows(): void
    {
        $this->seedRow('tenant-a', 'openai', 'gpt-5', costTotal: 3.00);
        $this->seedRow('tenant-a', 'anthropic', 'claude-opus-4-8', costTotal: 2.00);
        $this->seedRow('tenant-a', 'gemini', 'gemini-2.5-pro', costTotal: 1.00);

        $this->tenants->set('tenant-a');

        $payload = $this->invoke(['days' => 30, 'limit' => 1]);

        // Only the costliest (provider, model) row survives the limit; totals still aggregate all.
        $this->assertCount(1, $payload['breakdown']);
        $this->assertSame('openai', $payload['breakdown'][0]['provider']);
        $this->assertSame('6.00000000', $payload['total_cost']);
        $this->assertSame(3, $payload['total_calls']);
    }

    public function test_top_models_ranks_by_cost_with_share_and_is_tenant_scoped(): void
    {
        $this->seedRow('tenant-a', 'openai', 'gpt-5', costTotal: 3.00);
        $this->seedRow('tenant-a', 'anthropic', 'claude-opus-4-8', costTotal: 1.00);
        $this->seedRow('tenant-b', 'openai', 'gpt-5', costTotal: 50.00);

        $this->tenants->set('tenant-a');

        $payload = $this->invokeTopModels(['days' => 30, 'limit' => 5]);

        $this->assertSame('tenant-a', $payload['tenant_id']);
        $this->assertSame('4.00000000', $payload['total_cost']);
        $this->assertCount(2, $payload['models']);
        $this->assertSame('openai', $payload['models'][0]['provider']);
        // JSON decodes whole floats back as ints; compare loosely on numeric value.
        $this->assertEquals(75.0, $payload['models'][0]['cost_share_pct']);
        $this->assertEquals(25.0, $payload['models'][1]['cost_share_pct']);
    }

    public function test_top_models_empty_window_is_well_formed(): void
    {
        $this->seedRow('tenant-b', 'openai', 'gpt-5', costTotal: 9.00);

        $this->tenants->set('tenant-a');

        $payload = $this->invokeTopModels(['days' => 30]);

        $this->assertSame('0.00000000', $payload['total_cost']);
        $this->assertSame([], $payload['models']);
    }

    public function test_budget_status_returns_only_active_tenant_budgets(): void
    {
        // tenant-a budget: $10 monthly, $4 spent → 40%, ok.
        $this->seedBudget('tenant-a', 'Monthly cap', limit: 10.0, softLimitPct: 80);
        $this->seedRow('tenant-a', 'openai', 'gpt-5', costTotal: 4.00);
        // A budget scoped to tenant-b, plus a global budget, must NOT surface for tenant-a.
        $this->seedBudget('tenant-b', 'Other tenant cap', limit: 5.0);
        $this->seedBudget(null, 'Global cap', limit: 100.0, scopeType: 'global');

        $this->tenants->set('tenant-a');

        $payload = $this->invokeBudgetStatus();

        $this->assertSame('tenant-a', $payload['tenant_id']);
        $this->assertCount(1, $payload['budgets']);
        $this->assertSame('Monthly cap', $payload['budgets'][0]['name']);
        // JSON decodes whole floats back as ints; compare loosely on numeric value.
        $this->assertEquals(10.0, $payload['budgets'][0]['limit']);
        $this->assertEquals(4.0, $payload['budgets'][0]['spent']);
        $this->assertEquals(40.0, $payload['budgets'][0]['percent']);
        $this->assertSame('ok', $payload['budgets'][0]['state']);
    }

    public function test_budget_status_flags_exceeded(): void
    {
        $this->seedBudget('tenant-a', 'Tight cap', limit: 2.0, softLimitPct: 50);
        $this->seedRow('tenant-a', 'openai', 'gpt-5', costTotal: 3.00);

        $this->tenants->set('tenant-a');

        $payload = $this->invokeBudgetStatus();

        $this->assertSame('exceeded', $payload['budgets'][0]['state']);
    }

    public function test_budget_status_empty_when_no_tenant_budgets(): void
    {
        $this->seedBudget('tenant-b', 'Other cap', limit: 5.0);

        $this->tenants->set('tenant-a');

        $payload = $this->invokeBudgetStatus();

        $this->assertSame('tenant-a', $payload['tenant_id']);
        $this->assertSame([], $payload['budgets']);
    }

    public function test_tools_degrade_cleanly_when_finops_tables_are_absent(): void
    {
        // R43 OFF path: finops not installed → ledger/budgets tables gone. The
        // tools must return well-formed empty payloads, never throw. Drop the
        // tables the package models actually resolve (prefix/connection-safe).
        $ledger = new UsageRecord();
        $ledger->getConnection()->getSchemaBuilder()->dropIfExists($ledger->getTable());
        $budget = new Budget();
        $budget->getConnection()->getSchemaBuilder()->dropIfExists($budget->getTable());

        $this->tenants->set('tenant-a');

        $spend = $this->invoke(['days' => 30]);
        $this->assertSame('0.00000000', $spend['total_cost']);
        $this->assertSame([], $spend['breakdown']);

        $top = $this->invokeTopModels(['days' => 30]);
        $this->assertSame('0.00000000', $top['total_cost']);
        $this->assertSame([], $top['models']);

        $budgets = $this->invokeBudgetStatus();
        $this->assertSame([], $budgets['budgets']);
    }

    public function test_tools_honour_the_finops_master_switch_off(): void
    {
        // R43: even with the tables present and rows seeded, AI_FINOPS_ENABLED=false
        // must make the read surface return empty — a disabled deployment can't read
        // historical spend/budgets over MCP (mirrors FinOpsDisabledTest's contract).
        config()->set('ai-finops.enabled', false);

        $this->seedRow('tenant-a', 'openai', 'gpt-5', costTotal: 5.00);
        $this->seedBudget('tenant-a', 'Monthly cap', limit: 10.0);

        $this->tenants->set('tenant-a');

        $this->assertSame([], $this->invoke(['days' => 30])['breakdown']);
        $this->assertSame('0.00000000', $this->invoke(['days' => 30])['total_cost']);
        $this->assertSame([], $this->invokeTopModels(['days' => 30])['models']);
        $this->assertSame([], $this->invokeBudgetStatus()['budgets']);
    }

    /**
     * @param  array<string, int>  $args
     * @return array<string, mixed>
     */
    private function invoke(array $args): array
    {
        $response = (new FinOpsSpendSummaryTool())->handle(new Request($args), $this->tenants);

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, int>  $args
     * @return array<string, mixed>
     */
    private function invokeTopModels(array $args): array
    {
        $response = (new FinOpsTopModelsTool())->handle(new Request($args), $this->tenants);

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeBudgetStatus(): array
    {
        $response = (new FinOpsBudgetStatusTool())->handle(new Request([]), $this->tenants);

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function seedBudget(
        ?string $tenantId,
        string $name,
        float $limit,
        ?int $softLimitPct = null,
        string $scopeType = 'tenant',
    ): void {
        // Insert via the package Budget model so the connection + resolved table
        // name match exactly what FinOpsBudgetStatusTool queries.
        Budget::query()->insert([
            'name' => $name,
            'scope_type' => $scopeType,
            'scope_id' => $tenantId,
            'limit_amount' => $limit,
            'currency' => 'USD',
            'period' => 'monthly',
            'rolling_days' => 30,
            'soft_limit_pct' => $softLimitPct,
            'hard' => true,
            'enabled' => true,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function seedRow(
        string $tenantId,
        string $provider,
        string $model,
        float $costTotal = 0.0,
        int $tokensIn = 0,
        int $tokensOut = 0,
        ?Carbon $createdAt = null,
    ): void {
        // Insert via the package UsageRecord model (resolves connection + table);
        // ->insert() bypasses Eloquent timestamps so the explicit created_at the
        // windowing tests rely on is preserved verbatim.
        UsageRecord::query()->insert([
            'trace_id' => 'trace-'.bin2hex(random_bytes(6)),
            'provider' => $provider,
            'model' => $model,
            'modality' => 'text',
            'status' => 'recorded',
            'tenant_id' => $tenantId,
            'tokens_input' => $tokensIn,
            'tokens_output' => $tokensOut,
            'cost_total' => $costTotal,
            'currency' => 'USD',
            'created_at' => ($createdAt ?? Carbon::now())->toDateTimeString(),
        ]);
    }
}
