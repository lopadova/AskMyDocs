<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminInsightsSnapshot;
use App\Services\Admin\AiInsightsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase I — compute the daily AI insights snapshot.
 *
 * Executes the six AiInsightsService functions, times each, and writes
 * ONE row keyed on calendar day. Partial-failure strategy: any single
 * function that throws is logged + its column is null'd; the other
 * columns are still populated. The snapshot is NEVER partially
 * persisted — it's upserted once at the end.
 *
 * `--force` replaces an existing row for `snapshot_date`. Without
 * `--force` the command no-ops when a row already exists (idempotent
 * scheduler reruns).
 */
class InsightsComputeCommand extends Command
{
    protected $signature = 'insights:compute
        {--date=today : Target snapshot_date (YYYY-MM-DD or "today")}
        {--force : Replace an existing row for the target date}';

    protected $description = 'Compute the daily AI insights snapshot (promotions / orphans / tags / gaps / stale / quality).';

    public function handle(AiInsightsService $insights): int
    {
        $targetDate = $this->resolveDate();
        if ($targetDate === null) {
            $this->error('Invalid --date value. Use YYYY-MM-DD or "today".');

            return self::FAILURE;
        }

        $existing = AdminInsightsSnapshot::query()
            ->whereDate('snapshot_date', $targetDate->toDateString())
            ->first();
        if ($existing !== null && ! $this->option('force')) {
            $this->warn("Snapshot for {$targetDate->toDateString()} already exists. Use --force to replace.");

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $payloads = [
            'suggest_promotions' => $this->runInsight('suggest_promotions', fn () => $insights->suggestPromotions()),
            'orphan_docs' => $this->runInsight('orphan_docs', fn () => $insights->detectOrphans()),
            'suggested_tags' => $this->runInsight('suggested_tags', fn () => $insights->suggestTagsBatch()),
            'coverage_gaps' => $this->runInsight('coverage_gaps', fn () => $insights->coverageGaps()),
            'stale_docs' => $this->runInsight('stale_docs', fn () => $insights->detectStaleDocs()),
            'quality_report' => $this->runInsight('quality_report', fn () => $insights->qualityReport()),
        ];
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $attributes = array_merge($payloads, [
            'computed_at' => Carbon::now(),
            'computed_duration_ms' => $durationMs,
        ]);

        // Upsert keyed on `snapshot_date`. Use `whereDate()` on the
        // lookup side because the `date` Eloquent cast round-trips
        // the column through Carbon — on SQLite the stored TEXT value
        // preserves the Y-m-d shape, but mixing Carbon and string
        // comparisons in a plain `where()` can miss the match. Branch
        // explicitly on existence so we never fall back to INSERT
        // against a unique-index collision.
        $dateString = $targetDate->toDateString();
        $row = AdminInsightsSnapshot::query()
            ->whereDate('snapshot_date', $dateString)
            ->first();
        if ($row !== null) {
            $row->update($attributes);
        } else {
            AdminInsightsSnapshot::create(array_merge(
                ['snapshot_date' => $dateString],
                $attributes,
            ));
        }

        $this->info("Insights snapshot for {$targetDate->toDateString()} written in {$durationMs} ms.");

        return self::SUCCESS;
    }

    /**
     * Invoke one insight function, catch anything it throws (LLM
     * timeouts, network failures, provider quota), and return null for
     * that column. Returning null is the contract with the migration:
     * every payload column is independently nullable so a single
     * failure does not sink the snapshot.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T|null
     */
    private function runInsight(string $name, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            // WARNING-level: the snapshot still completes, but operators
            // need to know which column dropped so they can investigate.
            Log::warning("InsightsComputeCommand: {$name} failed, column will be null.", [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->warn("{$name}: failed — column left null ({$e->getMessage()})");

            return null;
        }
    }

    private function resolveDate(): ?Carbon
    {
        $raw = (string) $this->option('date');
        $raw = trim($raw);
        if ($raw === '' || $raw === 'today') {
            return Carbon::today();
        }
        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
