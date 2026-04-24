<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;

/**
 * Support endpoints for Playwright E2E.
 *
 * WARNING: every action is gated by `APP_ENV === 'testing'` and the
 * routes themselves are only registered from routes/web.php when the
 * environment matches. Triple-locked against accidental production
 * exposure.
 *
 *  - POST /testing/reset        — migrate:fresh (+ clear cached config)
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
    ];

    public function reset(Request $request): JsonResponse
    {
        $this->guardEnvironment();

        $this->runMigrateFresh();

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

        $this->runDbSeed(self::SEEDER_ALIASES[$seeder]);

        return response()->json(['seeded' => $seeder]);
    }

    /**
     * Extracted so tests can swap the real artisan side-effect with a
     * no-op. The Artisan facade can't be mocked under Testbench (final
     * Kernel), so these thin methods act as the seam.
     */
    protected function runMigrateFresh(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    /**
     * @param  class-string  $seederClass
     */
    protected function runDbSeed(string $seederClass): void
    {
        Artisan::call('db:seed', [
            '--class' => $seederClass,
            '--force' => true,
        ]);
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
