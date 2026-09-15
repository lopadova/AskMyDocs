<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 2026_10_02_000009_add_version_provenance_columns_to_knowledge_documents was
 * renamed from `…000008…`, and a database that ran the old filename keeps a
 * `migrations` row naming a file that no longer exists. `migrate` ignores it;
 * `migrate:rollback` resolves every name in the batch and would fail on the
 * missing one — or, if the file were ever restored, drop these columns twice.
 *
 * The schema half of this migration is asserted by the test-schema mirror.
 * The history half is not, which is why it is asserted here — against the
 * REAL migration file, and against the mirror too, since `TestCase` loads the
 * mirror as the test schema and a mirror that drifts from production leaves
 * exactly this behaviour untested (which is how the block went missing from
 * it in the first place).
 */
final class VersionProvenanceMigrationReconcilesRenamedRowTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY = '2026_10_02_000008_add_version_provenance_columns_to_knowledge_documents';

    public static function migrationPaths(): array
    {
        return [
            'production' => ['database/migrations/2026_10_02_000009_add_version_provenance_columns_to_knowledge_documents.php'],
            'test mirror' => ['tests/database/migrations/2026_10_02_000009_add_version_provenance_columns_to_knowledge_documents.php'],
        ];
    }

    #[DataProvider('migrationPaths')]
    public function test_the_stale_row_of_the_old_filename_is_removed(string $relativePath): void
    {
        DB::table('migrations')->insert(['migration' => self::LEGACY, 'batch' => 1]);
        // A neighbour that must survive: the cleanup is targeted, not a sweep.
        DB::table('migrations')->insert(['migration' => 'some_other_migration', 'batch' => 1]);

        $this->runMigration($relativePath);

        $this->assertSame(0, DB::table('migrations')->where('migration', self::LEGACY)->count());
        $this->assertSame(1, DB::table('migrations')->where('migration', 'some_other_migration')->count());
    }

    #[DataProvider('migrationPaths')]
    public function test_a_database_that_never_ran_the_old_filename_is_untouched(string $relativePath): void
    {
        $before = DB::table('migrations')->count();

        $this->runMigration($relativePath);

        $this->assertSame($before, DB::table('migrations')->count());
    }

    private function runMigration(string $relativePath): void
    {
        // Not database_path(): under Testbench that resolves into the
        // skeleton app, not this repository.
        $migration = require dirname(__DIR__, 3).'/'.$relativePath;

        $migration->up();
    }
}
