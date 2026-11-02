<?php

declare(strict_types=1);

namespace Tests\Feature\FinOps;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Tests\TestCase;

final class AiUsageActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware(['api', \App\Http\Middleware\ResolveTenant::class])->prefix('api')
            ->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('usage-alpha');
    }

    private function actor(string $role = 'admin'): User
    {
        $user = User::create(['name' => 'Usage administrator', 'email' => uniqid('usage-').'@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('fixture-password')]);
        $user->assignRole($role);
        ProjectMembership::create(['tenant_id' => 'usage-alpha', 'user_id' => $user->id, 'project_key' => 'usage-project', 'role' => 'admin']);
        return $user;
    }

    private function entry(array $overrides = []): void
    {
        UsageRecord::create([...['tenant_id' => 'usage-alpha', 'trace_id' => 'one-trace', 'provider' => 'example', 'model' => 'model-one',
            'status' => 'recorded', 'currency' => 'USD', 'modality' => 'text', 'tokens_input' => 100, 'tokens_output' => 50,
            'tokens_cached' => 30, 'tokens_reasoning' => 20, 'cost_total' => .15, 'cost_method' => 'computed',
            'purpose_tag' => 'chat', 'latency_ms' => 100, 'metadata' => ['price_source' => 'fixture', 'private' => 'NEVER-EXPOSE'],
            'created_at' => '2026-11-01 12:00:00'], ...$overrides]);
    }

    private function url(string $extra = ''): string
    {
        return '/api/admin/ai-usage?from=2026-11-01&to=2026-11-03&timezone=Europe%2FRome'.$extra;
    }

    public function test_tenant_currency_timezones_and_subsets_reconcile_with_all_chart_series(): void
    {
        for ($i = 1; $i <= 7; $i++) $this->entry(['model' => 'model-'.$i, 'cost_total' => $i / 10]);
        $this->entry(['created_at' => '2026-10-31 23:30:00', 'cost_total' => .2]); // Local Nov 1.
        $this->entry(['created_at' => '2026-11-01 23:30:00', 'cost_total' => .3]); // Local Nov 2.
        $this->entry(['created_at' => '2026-11-03 23:00:00', 'cost_total' => 999]); // Exclusive boundary.
        $this->entry(['tenant_id' => 'usage-beta', 'cost_total' => 10000]);
        $this->entry(['currency' => 'EUR', 'cost_total' => 200]);
        $this->entry(['status' => 'estimated', 'cost_total' => 100]);
        $this->entry(['status' => 'blocked', 'cost_total' => 100]);
        $r = $this->actingAs($this->actor())->getJson($this->url().'&tenant_id=usage-beta')->assertOk()
            ->assertJsonPath('status', 'available')->assertJsonPath('currency', 'USD')
            ->assertJsonPath('summary.entries', 9)->assertJsonPath('summary.tokens', 1350)
            ->assertJsonPath('summary.tokens_cached', 270)->assertJsonPath('summary.tokens_reasoning', 180)
            ->assertJsonPath('buckets.2.entries', 0);
        $data = $r->json(); $this->assertCount(3, $data['buckets']); $this->assertCount(6, $data['series']);
        $this->assertEqualsWithDelta(3.3, $data['summary']['cost'], .0000001);
        $this->assertEqualsWithDelta($data['summary']['cost'], array_sum(array_column($data['buckets'], 'cost')), .0000001);
        $this->assertEqualsWithDelta(3.3, array_sum(array_map(fn ($series) => array_sum(array_column($series['values'], 'cost')), $data['series'])), .0000001);
        $this->assertNotContains('other', array_column($data['top_models'], 'id'));
        $this->assertStringNotContainsString('NEVER-EXPOSE', $r->getContent());
        $this->assertStringNotContainsString('trace_id', $r->getContent());
        $this->assertSame(['EUR', 'USD'], $data['options']['currencies']);
        $this->assertSame(1, $data['buckets'][1]['entries']);
        $this->getJson('/api/admin/ai-usage?from=2026-11-01&to=2026-11-01&timezone=Europe%2FRome')
            ->assertOk()->assertJsonCount(1, 'buckets')->assertJsonPath('summary.entries', 8);
    }

    public function test_filters_match_summary_rankings_models_and_series_and_other_currencies_stay_separate(): void
    {
        $this->entry(); $this->entry(['provider' => 'other']); $this->entry(['purpose_tag' => 'embedding']);
        $this->entry(['status' => 'failed']); $this->entry(['currency' => 'EUR', 'cost_total' => 9]);
        $this->actingAs($this->actor())->getJson($this->url('&provider=example&purpose=chat&status=recorded&currency=USD'))
            ->assertOk()->assertJsonPath('summary.entries', 1)->assertJsonPath('top_providers.0.provider', 'example')
            ->assertJsonCount(1, 'models')->assertJsonPath('series.0.values.0.entries', 1);
        $this->getJson($this->url('&currency=EUR'))->assertOk()->assertJsonPath('summary.cost', 9)->assertJsonPath('summary.entries', 1);
    }

    public function test_cost_per_million_excludes_non_token_spend_and_preserves_negative_adjustments(): void
    {
        $this->entry(['cost_total' => .1]);
        $this->entry(['cost_total' => -.02]);
        $this->entry(['modality' => 'image', 'tokens_input' => 0, 'tokens_output' => 0, 'cost_total' => 5]);
        $r = $this->actingAs($this->actor())->getJson($this->url())->assertOk()->json();
        $this->assertEqualsWithDelta(5.08, $r['summary']['cost'], .0000001);
        $this->assertEqualsWithDelta(.08 / 300 * 1_000_000, $r['summary']['cost_per_million'], .0000001);
    }

    public function test_zero_without_price_is_unknown_but_subscription_coverage_is_not(): void
    {
        $this->entry(['cost_total' => 0, 'metadata' => null]);
        $this->entry(['cost_total' => 0, 'cost_method' => 'covered', 'status' => 'covered', 'metadata' => null]);
        $this->actingAs($this->actor())->getJson($this->url())->assertOk()
            ->assertJsonPath('summary.unpriced_entries', 1)->assertJsonPath('summary.cost_per_million', null);
    }

    public function test_entirely_unpriced_usage_ranks_real_token_volume_instead_of_free_spend(): void
    {
        $this->entry(['model' => 'small', 'cost_total' => 0, 'metadata' => null]);
        $this->entry(['model' => 'large', 'tokens_input' => 1000, 'cost_total' => 0, 'metadata' => null]);
        $this->actingAs($this->actor())->getJson($this->url())->assertOk()
            ->assertJsonPath('ranking_metric', 'tokens')->assertJsonPath('top_models.0.model', 'large')
            ->assertJsonPath('series.0.values.0.tokens', 1050)->assertJsonPath('summary.cost_per_million', null);
    }

    public function test_empty_period_and_unavailable_source_are_distinct_and_no_missing_durations_are_invented(): void
    {
        $this->actingAs($this->actor())->getJson($this->url())->assertOk()->assertJsonPath('status', 'available')
            ->assertJsonPath('summary.entries', 0)->assertJsonPath('summary.average_latency_ms', null);
        Schema::drop((new UsageRecord)->getTable());
        $this->getJson($this->url())->assertOk()->assertJsonPath('status', 'unavailable')->assertJsonPath('summary', null);
    }

    public function test_long_interval_keeps_last_partial_bucket_and_dst_boundaries(): void
    {
        $this->entry(['created_at' => '2026-10-24 22:30:00']); // Oct 25 before DST end.
        $this->entry(['created_at' => '2026-10-25 23:30:00']); // Oct 26 after DST end.
        $this->actingAs($this->actor());
        $this->getJson('/api/admin/ai-usage?from=2026-10-25&to=2026-10-26&timezone=Europe%2FRome')
            ->assertOk()->assertJsonPath('buckets.0.entries', 1)->assertJsonPath('buckets.1.entries', 1);
        $r = $this->getJson('/api/admin/ai-usage?from=2026-01-01&to=2026-11-03&timezone=Europe%2FRome')->assertOk()->json();
        $this->assertLessThanOrEqual(60, count($r['buckets']));
        $this->assertSame('2026-11-03', end($r['buckets'])['to']);
        $this->assertSame(2, $r['summary']['entries']);
    }

    public function test_roles_and_foreign_tenant_cannot_read_activity(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        foreach (['viewer', 'editor', 'dpo', 'system-admin'] as $role) {
            $this->actingAs($this->actor($role))->getJson($this->url())->assertForbidden();
        }
        $this->actingAs($this->actor())->withHeader('X-Tenant-Id', 'usage-beta')->getJson($this->url())->assertForbidden();
    }

    public function test_interval_and_timezone_validation_do_not_turn_into_unbounded_queries(): void
    {
        $this->actingAs($this->actor());
        $this->getJson('/api/admin/ai-usage?from=2020-01-01&to=2026-11-03')->assertUnprocessable();
        $this->getJson('/api/admin/ai-usage?timezone=not-a-timezone')->assertUnprocessable();
        $this->getJson('/api/admin/ai-usage?from=2026-11-04&to=2026-11-03')->assertUnprocessable();
    }
}
