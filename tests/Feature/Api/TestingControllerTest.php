<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\TestingController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Subclass the real controller so tests can observe the resolved
 * artisan intents without actually invoking `migrate:fresh` /
 * `db:seed` — both would fight RefreshDatabase's transaction on
 * SQLite :memory:.
 */
class TestingControllerSpy extends TestingController
{
    public static array $calls = [];

    protected function runMigrateFresh(): void
    {
        self::$calls[] = ['migrate:fresh'];
    }

    protected function runDbSeed(string $seederClass): void
    {
        self::$calls[] = ['db:seed', $seederClass];
    }
}

class TestingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TestingControllerSpy::$calls = [];

        // Register routes manually since they are conditional on APP_ENV
        // in the real routes/web.php. Tests go through the spy subclass
        // so the assertions observe the artisan intent without running
        // migrate:fresh inside a transaction.
        Route::post('/testing/reset', [TestingControllerSpy::class, 'reset'])->name('testing.reset');
        Route::post('/testing/seed', [TestingControllerSpy::class, 'seed'])->name('testing.seed');
    }

    public function test_reset_returns_ok_when_env_is_testing(): void
    {
        $this->postJson('/testing/reset')
            ->assertOk()
            ->assertJson(['reset' => true]);

        $this->assertSame([['migrate:fresh']], TestingControllerSpy::$calls);
    }

    public function test_reset_aborts_403_outside_testing_env(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->postJson('/testing/reset')
            ->assertStatus(403);
    }

    public function test_seed_runs_allowlisted_alias(): void
    {
        $this->postJson('/testing/seed', ['seeder' => 'DemoSeeder'])
            ->assertOk()
            ->assertJson(['seeded' => 'DemoSeeder']);

        $this->assertSame(
            [['db:seed', \Database\Seeders\DemoSeeder::class]],
            TestingControllerSpy::$calls,
        );
    }

    public function test_seed_rejects_unknown_seeder_with_422(): void
    {
        $this->postJson('/testing/seed', ['seeder' => 'ArbitrarySeeder'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unknown seeder.');
    }

    public function test_seed_requires_seeder_parameter(): void
    {
        $this->postJson('/testing/seed', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['seeder']);
    }

    public function test_seed_aborts_403_outside_testing_env(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->postJson('/testing/seed', ['seeder' => 'DemoSeeder'])
            ->assertStatus(403);
    }
}
