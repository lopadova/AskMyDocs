<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Eval\Support\EvalHarnessRunner;
use App\Eval\Support\NightlyDeltaCalculator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

/**
 * v4.3/W3 — Nightly RAG regression run + alerting.
 *
 * Drives `eval-harness:run` against the baseline factuality dataset
 * (rag.askmydocs.factuality.fy2026) using the `nightly` batch profile
 * and writes a dated JSON + Markdown report under
 * storage/app/eval-harness/nightly/<YYYY-MM-DD>.{json,md}.
 *
 * After the run, reads the prior nightly report (or falls back to the
 * most recent regular report under eval-harness/reports/) and uses
 * NightlyDeltaCalculator to compare macro_f1 + per-metric means. When
 * the macro_f1 delta is below -EVAL_NIGHTLY_REGRESSION_THRESHOLD the
 * command logs Log::alert() AND writes an alert sidecar JSON named
 * <YYYY-MM-DD>.alert.json next to the report.
 *
 * Cost guard (defense-in-depth):
 *   The scheduler in bootstrap/app.php only registers the command
 *   when EVAL_NIGHTLY_ENABLED=true. Even then, the live-AI override
 *   only activates when EVAL_NIGHTLY_LIVE=true AND a provider key is
 *   present; otherwise the command refuses with an alert log and
 *   exits SUCCESS so the scheduler heartbeat stays clean.
 *
 * Operator surface:
 *   --dry-run     prints the planned action and exits without running.
 *   --status      prints the latest nightly summary and exits.
 *   --prune-only  deletes nightly reports older than retention and exits.
 *
 * v4.4/W4 — Adversarial nightly opt-in (ADR 0007). When
 * `EVAL_NIGHTLY_ADVERSARIAL=true` AND the baseline run succeeds, the
 * command additionally runs each enabled adversarial dataset (or the
 * subset listed in `EVAL_NIGHTLY_ADVERSARIAL_DATASETS`) and writes a
 * per-slug `.adversarial.<slug>.summary.json` sidecar. Adversarial
 * regressions are advisory only — no `Log::alert()` fires for them.
 * Default OFF: when the knob is unset/false, the v4.3/W3 baseline-only
 * behaviour is preserved bit-identically.
 */
class EvalNightlyCommand extends Command
{
    protected $signature = 'eval:nightly
        {--dry-run : Print what would be done without invoking the eval run}
        {--status : Print the latest nightly summary (last run, macro_f1, alert state) and exit}
        {--prune-only : Delete nightly reports older than the retention window and exit}';

    protected $description = 'Run the eval-harness baseline nightly, persist a dated report, and alert on macro_f1 regression.';

    private const NIGHTLY_DIRECTORY = 'eval-harness/nightly';

    private const BASELINE_DATASET = 'rag.askmydocs.factuality.fy2026';

    private const REGISTRAR_FQCN = 'App\\Eval\\EvalRegistrar';

    public function handle(NightlyDeltaCalculator $calculator, EvalHarnessRunner $runner): int
    {
        if ($this->option('status')) {
            return $this->printStatus();
        }

        if ($this->option('prune-only')) {
            return $this->prune();
        }

        $live = $this->resolveLiveMode();
        $today = Carbon::now()->toDateString();
        $jsonRelative = self::NIGHTLY_DIRECTORY.'/'.$today.'.json';
        $markdownRelative = self::NIGHTLY_DIRECTORY.'/'.$today.'.md';

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                'eval:nightly dry-run — would write %s + %s (live=%s).',
                $jsonRelative,
                $markdownRelative,
                $live ? 'true' : 'false',
            ));

            return self::SUCCESS;
        }

        if (! $live && (bool) env('EVAL_NIGHTLY_LIVE', false)) {
            // EVAL_NIGHTLY_LIVE was requested but no provider key is
            // present. Loud refusal rather than silent fallback to
            // Http::fake() — the operator's intent (live run) didn't
            // match the host's capability. Exit 0 so the scheduler
            // doesn't flap; the alert log makes the situation visible.
            Log::alert('eval:nightly refused live run: EVAL_NIGHTLY_LIVE=true but no provider key configured (OPENAI_API_KEY / EVAL_HARNESS_JUDGE_API_KEY).', [
                'date' => $today,
            ]);
            $this->warn('Live mode requested but no provider key found. Skipping run.');

            return self::SUCCESS;
        }

        $disk = $this->reportsDisk();
        $disk->makeDirectory(self::NIGHTLY_DIRECTORY);

        $jsonAbsolute = $this->absolutePathFor($disk, $jsonRelative);
        $markdownAbsolute = $this->absolutePathFor($disk, $markdownRelative);

        $exitCode = $this->invokeEvalRun($runner, $live, $jsonAbsolute, $markdownAbsolute, $disk, $jsonRelative);
        if ($exitCode === self::FAILURE) {
            return self::FAILURE;
        }

        $current = $this->readJsonReport($disk, $jsonRelative);
        if ($current === null) {
            Log::alert('eval:nightly current report missing or unreadable after eval-harness:run.', [
                'date' => $today,
                'path' => $jsonRelative,
            ]);

            return self::FAILURE;
        }

        $prior = $this->loadPriorReport($disk, $today);
        $delta = $calculator->compute($prior, $current);

        $this->reportDelta($delta, $today, $disk);

        // v4.4/W4 — adversarial opt-in: only after the baseline pass
        // succeeded above (we reach this point only on baseline success;
        // the FAILURE branch returned early). Adversarial runs are
        // advisory: failures are logged but never propagate, and the
        // baseline alert pipeline above is unaffected by anything
        // happening below.
        $this->runAdversarialPass($runner, $live, $disk, $today);

        $this->prune();

        return self::SUCCESS;
    }

    /**
     * v4.4/W4 — opt-in adversarial nightly pass. No-op when
     * `eval-harness.adversarial_nightly.enabled` is false (the default),
     * preserving the v4.3/W3 baseline-only behaviour bit-identically.
     *
     * Each enabled slug runs `eval-harness:run` twice (JSON + Markdown)
     * with the `nightly` batch profile and writes a summary sidecar
     * under `<NIGHTLY_DIRECTORY>/<date>.adversarial.<slug>.summary.json`.
     * Adversarial regressions are advisory: the sidecar is the audit
     * trail; no `Log::alert()` fires (per ADR 0007 — adversarial
     * scoring is too noisy in live mode for hard alerting). Per-slug
     * runtime exceptions are logged with `Log::warning()` so a single
     * misbehaving lane never poisons the others or the scheduler
     * heartbeat.
     */
    private function runAdversarialPass(
        EvalHarnessRunner $runner,
        bool $live,
        Filesystem $disk,
        string $today,
    ): void {
        if (! (bool) Config::get('eval-harness.adversarial_nightly.enabled', false)) {
            return;
        }

        $slugs = $this->resolveAdversarialSlugs();
        if ($slugs === []) {
            return;
        }

        // Re-assert the live_ai fence per slug iteration just like the
        // baseline pass does. A misbehaving runner that mutates config
        // must not silently flip subsequent runs.
        foreach ($slugs as $slug) {
            Config::set('eval-harness.askmydocs.live_ai', $live);

            $jsonRelative = self::NIGHTLY_DIRECTORY.'/'.$today.'.adversarial.'.$slug.'.json';
            $markdownRelative = self::NIGHTLY_DIRECTORY.'/'.$today.'.adversarial.'.$slug.'.md';
            $summaryRelative = self::NIGHTLY_DIRECTORY.'/'.$today.'.adversarial.'.$slug.'.summary.json';

            $jsonAbsolute = $this->absolutePathFor($disk, $jsonRelative);
            $markdownAbsolute = $this->absolutePathFor($disk, $markdownRelative);
            $dataset = 'rag.askmydocs.adversarial.'.$slug;

            try {
                $jsonExit = $runner->run([
                    'dataset' => $dataset,
                    '--registrar' => self::REGISTRAR_FQCN,
                    '--batch-profile' => 'nightly',
                    '--json' => true,
                    '--out' => $jsonAbsolute,
                    '--raw-path' => true,
                ]);

                $markdownExit = $runner->run([
                    'dataset' => $dataset,
                    '--registrar' => self::REGISTRAR_FQCN,
                    '--batch-profile' => 'nightly',
                    '--out' => $markdownAbsolute,
                    '--raw-path' => true,
                ]);
            } catch (Throwable $e) {
                // R7/R14 — surface the failure loudly via Log::warning so
                // ops sees the missed lane, but DO NOT fire Log::alert
                // (advisory scope) and DO NOT abort the loop: the
                // remaining adversarial slugs still get their chance.
                Log::warning('eval:nightly adversarial slug failed: '.$slug, [
                    'date' => $today,
                    'slug' => $slug,
                    'dataset' => $dataset,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                $this->warn(sprintf(
                    'Adversarial slug "%s" threw %s: %s',
                    $slug,
                    $e::class,
                    $e->getMessage(),
                ));

                continue;
            }

            $exitCode = ($jsonExit !== 0 || $markdownExit !== 0) ? 1 : 0;
            $summary = [
                'schema_version' => 'eval-harness.adversarial-summary.v1',
                'dataset' => $dataset,
                'slug' => $slug,
                'profile' => 'nightly',
                'exit_code' => $exitCode,
                'ran_at' => Carbon::now()->toIso8601String(),
                'json_path' => $jsonRelative,
                'md_path' => $markdownRelative,
            ];

            try {
                $encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                Log::warning('eval:nightly failed to encode adversarial summary for '.$slug.': '.$e->getMessage(), $summary);

                continue;
            }

            if (! $disk->put($summaryRelative, $encoded)) {
                // R4 — surface the write failure rather than pretending
                // success. Still no Log::alert: this is the advisory lane.
                Log::warning('eval:nightly failed to persist adversarial summary at '.$summaryRelative, $summary);
                $this->warn('Failed to write adversarial summary at '.$summaryRelative);

                continue;
            }

            $this->info(sprintf(
                'eval:nightly adversarial slug "%s" complete (exit=%d).',
                $slug,
                $exitCode,
            ));
        }
    }

    /**
     * Resolve the configured adversarial dataset slugs filtered by the
     * operator allowlist. Unknown slugs are skipped with a Log::warning
     * — typos in the allowlist must surface, not silently no-op.
     *
     * @return list<string>
     */
    private function resolveAdversarialSlugs(): array
    {
        /** @var array<string, mixed> $configured */
        $configured = (array) Config::get('eval-harness.askmydocs.golden.adversarial', []);
        $configuredSlugs = array_keys($configured);

        $rawAllowlist = trim((string) Config::get('eval-harness.adversarial_nightly.datasets', ''));
        if ($rawAllowlist === '') {
            return $configuredSlugs;
        }

        $requested = array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            explode(',', $rawAllowlist),
        ), static fn (string $part): bool => $part !== ''));

        $resolved = [];
        foreach ($requested as $slug) {
            if (! in_array($slug, $configuredSlugs, true)) {
                Log::warning('eval:nightly adversarial slug not configured; skipping.', [
                    'slug' => $slug,
                    'configured' => $configuredSlugs,
                ]);

                continue;
            }
            if (in_array($slug, $resolved, true)) {
                continue;
            }
            $resolved[] = $slug;
        }

        return $resolved;
    }

    private function invokeEvalRun(
        EvalHarnessRunner $runner,
        bool $live,
        string $jsonAbsolute,
        string $markdownAbsolute,
        Filesystem $disk,
        string $jsonRelative,
    ): int {
        // Two-fence cost guard: ALWAYS write the live_ai value, never
        // assume the prior config matches our intent. A host with
        // EVAL_LIVE_AI=1 in env would otherwise leak into the nightly
        // run even when EVAL_NIGHTLY_LIVE=false, bypassing the second
        // fence and billing tokens unexpectedly.
        Config::set('eval-harness.askmydocs.live_ai', $live);

        // --raw-path: pass the absolute filesystem path so the
        // package writes EXACTLY where we tell it; without this knob
        // eval-harness:run resolves --out under
        // eval-harness.reports.path_prefix and our subsequent
        // disk lookups (which use the unprefixed relative path) miss.
        try {
            $jsonExit = $runner->run([
                'dataset' => self::BASELINE_DATASET,
                '--registrar' => self::REGISTRAR_FQCN,
                '--batch-profile' => 'nightly',
                '--json' => true,
                '--out' => $jsonAbsolute,
                '--raw-path' => true,
            ]);

            $markdownExit = $runner->run([
                'dataset' => self::BASELINE_DATASET,
                '--registrar' => self::REGISTRAR_FQCN,
                '--batch-profile' => 'nightly',
                '--out' => $markdownAbsolute,
                '--raw-path' => true,
            ]);
        } catch (Throwable $e) {
            Log::alert('eval:nightly invocation failed: '.$e->getMessage(), [
                'exception' => $e::class,
            ]);
            $this->error('eval-harness:run threw: '.$e->getMessage());

            return self::FAILURE;
        }

        // eval-harness:run exits non-zero on captured failures. We do
        // NOT propagate that as command failure — the report has been
        // written and the regression-detection logic below will alert.
        // Crashing the scheduler on every detected regression would
        // bury the artifact behind a noisy retry loop.
        if ($jsonExit !== 0 || $markdownExit !== 0) {
            $this->warn(sprintf(
                'eval-harness:run reported failures (json exit=%d, md exit=%d). Continuing to delta computation.',
                $jsonExit,
                $markdownExit,
            ));
        }

        if (! $disk->exists($jsonRelative)) {
            $this->error('Expected JSON report not found at '.$jsonRelative);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *     macro_f1_prior: float,
     *     macro_f1_current: float,
     *     macro_f1_delta: float,
     *     regressed_metrics: list<array{name: string, prior: float, current: float, delta: float}>,
     *     improved_metrics: list<array{name: string, prior: float, current: float, delta: float}>
     * }|null  $delta
     */
    private function reportDelta(?array $delta, string $today, Filesystem $disk): void
    {
        if ($delta === null) {
            $this->info('eval:nightly first run — no prior report to compare against.');

            return;
        }

        $threshold = $this->regressionThreshold();
        $macroDelta = $delta['macro_f1_delta'];

        $this->info(sprintf(
            'eval:nightly macro_f1: prior=%.4f current=%.4f delta=%+.4f (threshold=%.4f)',
            $delta['macro_f1_prior'],
            $delta['macro_f1_current'],
            $macroDelta,
            $threshold,
        ));

        if ($macroDelta >= -$threshold) {
            return;
        }

        $alertPayload = [
            'date' => $today,
            'threshold' => $threshold,
            'delta' => $delta,
        ];
        $alertRelative = self::NIGHTLY_DIRECTORY.'/'.$today.'.alert.json';

        try {
            $encoded = json_encode($alertPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::alert('eval:nightly failed to encode alert sidecar: '.$e->getMessage(), $alertPayload);
            $this->error('Alert detected but sidecar JSON encoding failed: '.$e->getMessage());

            return;
        }

        if (! $disk->put($alertRelative, $encoded)) {
            // R4: surface the failure rather than pretending success.
            Log::alert('eval:nightly failed to persist alert sidecar to '.$alertRelative, $alertPayload);
            $this->error('Failed to write alert sidecar to '.$alertRelative);

            return;
        }

        Log::alert('eval:nightly REGRESSION detected — macro_f1 dropped beyond threshold.', $alertPayload);
        $this->error(sprintf(
            'REGRESSION: macro_f1 delta %+.4f exceeds threshold %.4f. Alert sidecar at %s.',
            $macroDelta,
            $threshold,
            $alertRelative,
        ));
    }

    private function loadPriorReport(Filesystem $disk, string $today): ?array
    {
        $todayFile = self::NIGHTLY_DIRECTORY.'/'.$today.'.json';

        $candidates = [];
        foreach ($disk->files(self::NIGHTLY_DIRECTORY) as $path) {
            if ($path === $todayFile) {
                continue;
            }
            if (! str_ends_with($path, '.json')) {
                continue;
            }
            // Skip alert sidecars (regression payload, not a baseline
            // report) and v4.4/W4 adversarial sidecars / summary files
            // (advisory only; never compared as a baseline-prior).
            if (str_ends_with($path, '.alert.json')) {
                continue;
            }
            if (str_contains($path, '.adversarial.')) {
                continue;
            }
            $candidates[] = $path;
        }

        if ($candidates !== []) {
            sort($candidates);

            return $this->readJsonReport($disk, end($candidates));
        }

        $regularDir = $this->regularReportsDirectory();
        $regular = [];
        if ($regularDir !== '' && $disk->exists($regularDir)) {
            foreach ($disk->files($regularDir) as $path) {
                if (str_ends_with($path, '.json')) {
                    $regular[] = $path;
                }
            }
        }

        if ($regular === []) {
            return null;
        }

        usort(
            $regular,
            fn (string $a, string $b): int => $disk->lastModified($a) <=> $disk->lastModified($b),
        );

        return $this->readJsonReport($disk, end($regular));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonReport(Filesystem $disk, string $path): ?array
    {
        if (! $disk->exists($path)) {
            return null;
        }

        $raw = $disk->get($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::warning('eval:nightly could not decode report JSON at '.$path.': '.$e->getMessage());

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function printStatus(): int
    {
        $disk = $this->reportsDisk();
        if (! $disk->exists(self::NIGHTLY_DIRECTORY)) {
            $this->warn('No nightly reports directory yet.');

            return self::SUCCESS;
        }

        $reports = [];
        foreach ($disk->files(self::NIGHTLY_DIRECTORY) as $path) {
            if (! str_ends_with($path, '.json')) {
                continue;
            }
            if (str_ends_with($path, '.alert.json')) {
                continue;
            }
            // v4.4/W4 — adversarial sidecars are advisory and never
            // representative of the "latest nightly" status surface.
            if (str_contains($path, '.adversarial.')) {
                continue;
            }
            $reports[] = $path;
        }

        if ($reports === []) {
            $this->warn('No nightly reports written yet.');

            return self::SUCCESS;
        }

        sort($reports);
        $latest = end($reports);
        $payload = $this->readJsonReport($disk, $latest);
        $alertSidecar = preg_replace('/\.json$/', '.alert.json', $latest) ?? '';

        $this->info('Latest nightly report: '.$latest);
        $this->info(sprintf('  macro_f1:        %.4f', (float) ($payload['macro_f1'] ?? 0.0)));
        $this->info(sprintf('  total_samples:   %d', (int) ($payload['total_samples'] ?? 0)));
        $this->info(sprintf('  total_failures:  %d', (int) ($payload['total_failures'] ?? 0)));
        $this->info(sprintf('  alert state:     %s', $disk->exists($alertSidecar) ? 'ALERT' : 'OK'));

        return self::SUCCESS;
    }

    private function prune(): int
    {
        $disk = $this->reportsDisk();
        if (! $disk->exists(self::NIGHTLY_DIRECTORY)) {
            return self::SUCCESS;
        }

        $retentionDays = max(1, (int) env('EVAL_NIGHTLY_RETENTION_DAYS', 90));
        $cutoff = Carbon::now()->subDays($retentionDays)->timestamp;
        $removed = 0;

        foreach ($disk->files(self::NIGHTLY_DIRECTORY) as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                if ($disk->delete($path)) {
                    $removed++;

                    continue;
                }
                Log::warning('eval:nightly failed to delete old report at '.$path);
            }
        }

        if ($removed > 0) {
            $this->info(sprintf('Pruned %d nightly reports older than %d days.', $removed, $retentionDays));
        }

        return self::SUCCESS;
    }

    private function resolveLiveMode(): bool
    {
        if (! (bool) env('EVAL_NIGHTLY_LIVE', false)) {
            return false;
        }

        $candidateKeys = [
            (string) env('EVAL_HARNESS_JUDGE_API_KEY', ''),
            (string) env('EVAL_HARNESS_EMBEDDINGS_API_KEY', ''),
            (string) env('OPENAI_API_KEY', ''),
            (string) env('OPENROUTER_API_KEY', ''),
            (string) env('ANTHROPIC_API_KEY', ''),
            (string) env('REGOLO_API_KEY', ''),
        ];

        foreach ($candidateKeys as $key) {
            if (trim($key) !== '') {
                return true;
            }
        }

        return false;
    }

    private function regressionThreshold(): float
    {
        $raw = env('EVAL_NIGHTLY_REGRESSION_THRESHOLD', 0.05);
        if (! is_numeric($raw)) {
            return 0.05;
        }

        $value = (float) $raw;
        if ($value < 0.0 || $value > 1.0) {
            return 0.05;
        }

        return $value;
    }

    private function reportsDisk(): Filesystem
    {
        $diskName = (string) Config::get('eval-harness.reports.disk', 'local');

        return Storage::disk($diskName);
    }

    /**
     * Resolve the directory the package's `eval-harness:run` writes
     * its regular (non-nightly) reports into. Honors the operator's
     * `EVAL_HARNESS_REPORTS_PATH` override so the prior-baseline
     * fallback locates reports written under a custom prefix.
     */
    private function regularReportsDirectory(): string
    {
        $prefix = (string) Config::get('eval-harness.reports.path_prefix', '');

        return trim($prefix, '/');
    }

    /**
     * Resolve the absolute filesystem path for a relative entry on the
     * reports disk so we can pass it to `eval-harness:run --raw-path`.
     * Local disks expose `path()`; cloud-only disks do not — operators
     * pointing the reports disk at a remote driver should keep using
     * the relative path, but cloud disks aren't a documented use case
     * for the nightly cron and would need separate plumbing anyway.
     */
    private function absolutePathFor(Filesystem $disk, string $relative): string
    {
        if (method_exists($disk, 'path')) {
            return $disk->path($relative);
        }

        return $relative;
    }
}
