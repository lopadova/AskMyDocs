<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class EmailNormalizedMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_caches_the_normalized_email_column_capability(): void
    {
        Schema::partialMock()
            ->shouldReceive('hasColumn')
            ->once()
            ->with('users', 'email_normalized')
            ->andReturnTrue();

        $this->assertTrue(User::hasNormalizedEmailColumn());
        $this->assertTrue(User::hasNormalizedEmailColumn());
    }

    public function test_backfill_is_complete_for_a_multi_thousand_user_dataset(): void
    {
        $migration = $this->migration();
        $migration->down();

        $now = now();
        foreach (array_chunk(range(1, 2105), 100) as $chunk) {
            DB::table('users')->insert(array_map(static fn (int $id): array => [
                'name' => "Legacy {$id}",
                'email' => "Legacy-{$id}@Example.test",
                'password' => 'not-used',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        $migration->up();

        $this->assertSame(2105, DB::table('users')->whereNotNull('email_normalized')->count());
        $this->assertSame(
            'legacy-2105@example.test',
            DB::table('users')->where('email', 'Legacy-2105@Example.test')->value('email_normalized'),
        );
        $this->assertTrue(Schema::hasIndex('users', 'users_email_normalized_unique'));
    }

    public function test_collision_failure_is_bounded_restartable_and_does_not_create_unique_index(): void
    {
        $migration = $this->migration();
        $migration->down();
        $now = now();

        DB::table('users')->insert([
            [
                'name' => 'First',
                'email' => 'Case@Example.test',
                'password' => 'not-used',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Second',
                'email' => 'case@example.test',
                'password' => 'not-used',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        try {
            $migration->up();
            $this->fail('Expected a normalized-email collision failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('identity_sha256=', $e->getMessage());
            $this->assertStringNotContainsString('case@example.test', strtolower($e->getMessage()));
        }

        $this->assertTrue(Schema::hasColumn('users', 'email_normalized'));
        $this->assertFalse(Schema::hasIndex('users', 'users_email_normalized_unique'));
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 3).'/database/migrations/2026_07_27_000001_add_email_normalized_to_users_table.php';
    }
}
