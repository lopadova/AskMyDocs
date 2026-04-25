<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\AdminInsightsSnapshot;
use App\Models\KnowledgeDocument;
use App\Services\Admin\AiInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase I — insights:compute command feature tests.
 *
 * Covers:
 *   - Happy path writes one snapshot row for today.
 *   - Partial-failure: one insight throws → column null, row still written.
 *   - --force replaces an existing row for the target date.
 *   - No --force on existing row → no-op.
 */
class InsightsComputeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_happy_path_writes_single_snapshot(): void
    {
        Http::fake();

        $this->artisan('insights:compute')
            ->assertSuccessful();

        $this->assertSame(1, AdminInsightsSnapshot::count());
        $row = AdminInsightsSnapshot::first();
        $this->assertSame(Carbon::today()->toDateString(), $row->snapshot_date->toDateString());
        $this->assertNotNull($row->computed_at);
        $this->assertGreaterThanOrEqual(0, $row->computed_duration_ms);
    }

    public function test_partial_failure_writes_null_for_failing_column(): void
    {
        // Stub AiInsightsService to make coverageGaps() throw while
        // other functions return legal shapes.
        $stub = Mockery::mock(AiInsightsService::class);
        $stub->shouldReceive('suggestPromotions')->andReturn([]);
        $stub->shouldReceive('detectOrphans')->andReturn([]);
        $stub->shouldReceive('suggestTagsBatch')->andReturn([]);
        $stub->shouldReceive('coverageGaps')
            ->andThrow(new RuntimeException('simulated LLM timeout'));
        $stub->shouldReceive('detectStaleDocs')->andReturn([]);
        $stub->shouldReceive('qualityReport')->andReturn([
            'chunk_length_distribution' => [
                'under_100' => 0,
                'h100_500' => 0,
                'h500_1000' => 0,
                'h1000_2000' => 0,
                'over_2000' => 0,
            ],
            'outlier_short' => 0,
            'outlier_long' => 0,
            'missing_frontmatter' => 0,
            'total_docs' => 0,
            'total_chunks' => 0,
        ]);
        $this->app->instance(AiInsightsService::class, $stub);

        $this->artisan('insights:compute')
            ->assertSuccessful();

        $row = AdminInsightsSnapshot::first();
        $this->assertNotNull($row);
        // coverage_gaps is the only column that was null'd.
        $this->assertNull($row->coverage_gaps);
        // The others are present (empty arrays are not null).
        $this->assertNotNull($row->quality_report);
        $this->assertNotNull($row->suggest_promotions);
        $this->assertNotNull($row->orphan_docs);
        $this->assertNotNull($row->suggested_tags);
        $this->assertNotNull($row->stale_docs);
    }

    public function test_force_replaces_existing_row(): void
    {
        Http::fake();

        AdminInsightsSnapshot::create([
            'snapshot_date' => Carbon::today()->toDateString(),
            'suggest_promotions' => [['stale' => true]],
            'computed_at' => Carbon::now()->subHours(6),
            'computed_duration_ms' => 999,
        ]);

        $this->artisan('insights:compute', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, AdminInsightsSnapshot::count());
        $row = AdminInsightsSnapshot::first();
        // The stale promotions payload should have been replaced by
        // the recomputed value (most-likely [] against empty corpus).
        $this->assertNotSame([['stale' => true]], $row->suggest_promotions);
    }

    public function test_without_force_is_noop_when_row_exists(): void
    {
        Http::fake();

        AdminInsightsSnapshot::create([
            'snapshot_date' => Carbon::today()->toDateString(),
            'suggest_promotions' => [['marker' => 'should-survive']],
            'computed_at' => Carbon::now()->subHours(6),
            'computed_duration_ms' => 999,
        ]);

        $this->artisan('insights:compute')
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        $row = AdminInsightsSnapshot::first();
        // Unchanged.
        $this->assertSame([['marker' => 'should-survive']], $row->suggest_promotions);
    }

    public function test_invalid_date_option_returns_failure(): void
    {
        $this->artisan('insights:compute', ['--date' => 'not-a-date'])
            ->assertFailed();

        $this->assertSame(0, AdminInsightsSnapshot::count());
    }

    public function test_explicit_date_writes_row_for_that_date(): void
    {
        Http::fake();
        $target = Carbon::today()->subDays(2)->toDateString();

        $this->artisan('insights:compute', ['--date' => $target])
            ->assertSuccessful();

        $row = AdminInsightsSnapshot::first();
        $this->assertSame($target, $row->snapshot_date->toDateString());
    }
}
