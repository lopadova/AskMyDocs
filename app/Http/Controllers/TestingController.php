<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Support endpoints for Playwright E2E.
 *
 * WARNING: every action is gated by `APP_ENV === 'testing'` and the
 * routes themselves are only registered from routes/web.php when the
 * environment matches. Triple-locked against accidental production
 * exposure.
 *
 *  - POST /testing/reset        — migrate:fresh (drops every table,
 *                                  re-runs every migration). Does NOT
 *                                  flush config / route / view caches —
 *                                  CI uses `CACHE_STORE=array` so there
 *                                  is no persistent cache to clear, and
 *                                  Playwright spawns `php artisan serve`
 *                                  fresh per run, so config is never
 *                                  cached. (Copilot PR #33 docblock
 *                                  drift fix.)
 *  - POST /testing/seed         — runs the requested seeder class
 */
class TestingController extends Controller
{
    /**
     * A short allowlist of seeders Playwright scenarios are allowed to run.
     * Keyed by short alias. Anything else is rejected with 422.
     *
     * @var array<string, class-string>
     */
    private const SEEDER_ALIASES = [
        'DemoSeeder' => \Database\Seeders\DemoSeeder::class,
        'RbacSeeder' => \Database\Seeders\RbacSeeder::class,
        'EmptyAdminSeeder' => \Database\Seeders\EmptyAdminSeeder::class,
        'AdminDegradedSeeder' => \Database\Seeders\AdminDegradedSeeder::class,
        // PR14 / Phase I — one deterministic snapshot row for the
        // /app/admin/insights happy-path E2E.
        'AdminInsightsSeeder' => \Database\Seeders\AdminInsightsSeeder::class,
    ];

    public function reset(Request $request): JsonResponse
    {
        $this->guardEnvironment();

        try {
            $this->runMigrateFresh();
        } catch (Throwable $e) {
            // Surface the exception in the JSON response so Playwright's
            // setup error message contains the actual root cause instead
            // of an opaque 500 HTML page. Testing-env only — no
            // information leak risk in production.
            Log::error('TestingController::reset failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'reset' => false,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'trace_head' => $this->traceHead($e),
            ], 500);
        }

        return response()->json(['reset' => true]);
    }

    public function seed(Request $request): JsonResponse
    {
        $this->guardEnvironment();

        $validated = $request->validate([
            'seeder' => ['required', 'string'],
        ]);

        $seeder = $validated['seeder'];
        if (! isset(self::SEEDER_ALIASES[$seeder])) {
            return response()->json([
                'message' => 'Unknown seeder.',
                'allowed' => array_keys(self::SEEDER_ALIASES),
            ], 422);
        }

        try {
            $this->runDbSeed(self::SEEDER_ALIASES[$seeder]);
        } catch (Throwable $e) {
            Log::error('TestingController::seed failed', [
                'seeder' => $seeder,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'seeded' => false,
                'seeder' => $seeder,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'trace_head' => $this->traceHead($e),
            ], 500);
        }

        return response()->json(['seeded' => $seeder]);
    }

    /**
     * Top 5 stack frames as plain strings — enough context to pin the
     * call site without flooding the JSON payload. Testing-env only.
     *
     * @return array<int, string>
     */
    private function traceHead(Throwable $e): array
    {
        $head = [];
        foreach (array_slice($e->getTrace(), 0, 5) as $frame) {
            $file = $frame['file'] ?? '?';
            $line = $frame['line'] ?? '?';
            $function = $frame['function'] ?? '?';
            $head[] = "{$file}:{$line} {$function}";
        }

        return $head;
    }

    /**
     * Extracted so tests can swap the real artisan side-effect with a
     * no-op. The Artisan facade can't be mocked under Testbench (final
     * Kernel), so these thin methods act as the seam.
     *
     * Throws RuntimeException on non-zero exit so the controller's
     * try/catch surfaces the failure as a 500 JSON instead of a
     * silent partial success.
     */
    protected function runMigrateFresh(): void
    {
        $exit = Artisan::call('migrate:fresh', ['--force' => true]);
        if ($exit !== 0) {
            throw new \RuntimeException(
                'migrate:fresh exited with code '.$exit.': '.Artisan::output(),
            );
        }
    }

    /**
     * @param  class-string  $seederClass
     */
    protected function runDbSeed(string $seederClass): void
    {
        $exit = Artisan::call('db:seed', [
            '--class' => $seederClass,
            '--force' => true,
        ]);
        if ($exit !== 0) {
            throw new \RuntimeException(
                'db:seed exited with code '.$exit.': '.Artisan::output(),
            );
        }
    }

    /**
     * Abort 403 the moment we're outside `testing`. The routes-level guard
     * is a defense-in-depth backup; this is the primary check.
     */
    private function guardEnvironment(): void
    {
        abort_unless(app()->environment('testing'), 403, 'Testing endpoints disabled.');
    }
}
