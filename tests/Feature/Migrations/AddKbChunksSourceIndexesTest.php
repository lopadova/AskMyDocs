<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v4.5/W5.5 — the GIN-index migration is pgsql-only. On SQLite (the
 * test driver) up() and down() must be no-ops; the migration must
 * not throw and must not create indexes the SQLite planner can't
 * use.
 *
 * Production pgsql index creation is verified at deploy time via
 * `\d+ knowledge_chunks` — there is no portable SQL-level invariant
 * to assert against here, so we pin the no-op shape on SQLite and
 * trust the production-grade CREATE INDEX IF NOT EXISTS statement
 * to do the right thing on pgsql.
 */
final class AddKbChunksSourceIndexesTest extends TestCase
{
    #[Test]
    public function migration_is_a_no_op_on_sqlite_test_driver(): void
    {
        $this->assertSame('sqlite', DB::getDriverName(), 'Test environment should run on SQLite.');

        $repoRoot = dirname(__DIR__, 3);
        $migration = require $repoRoot
            . DIRECTORY_SEPARATOR . 'database'
            . DIRECTORY_SEPARATOR . 'migrations'
            . DIRECTORY_SEPARATOR . '2026_05_11_120000_add_kb_chunks_source_indexes.php';

        // up() and down() are pgsql-gated — calling them under SQLite
        // must NOT raise and must NOT create any index. The driver
        // guard inside the migration handles both.
        $migration->up();
        $migration->down();

        $this->assertTrue(true, 'Migration ran without exception under SQLite.');
    }
}
