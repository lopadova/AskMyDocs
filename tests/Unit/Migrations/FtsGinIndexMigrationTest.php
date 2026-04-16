<?php

namespace Tests\Unit\Migrations;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FtsGinIndexMigrationTest extends TestCase
{
    private string $migrationPath = __DIR__
        .'/../../../database/migrations/2026_01_01_000008_add_fts_gin_index_to_knowledge_chunks.php';

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists($this->migrationPath);
    }

    public function test_noop_on_non_pgsql_connections(): void
    {
        $this->assertNotSame('pgsql', DB::getDriverName());

        $migration = require $this->migrationPath;

        // Should not throw any exception on sqlite
        $migration->up();
        $migration->down();

        $this->assertTrue(true);
    }

    public function test_whitelists_language_to_prevent_injection(): void
    {
        config()->set('kb.hybrid_search.fts_language', "italian'; DROP TABLE x; --");

        $migration = require $this->migrationPath;

        $src = file_get_contents($this->migrationPath);
        // Whitelist constant includes 'simple' fallback and 'italian'
        $this->assertStringContainsString("'simple'", $src);
        $this->assertStringContainsString("'italian'", $src);
        // There's no raw placeholder-style interpolation of the user-supplied lang
        $this->assertStringContainsString('in_array($lang, $allowed, true)', $src);

        // up() is safe on sqlite (short-circuits) even with a hostile config value
        $migration->up();
        $this->assertTrue(true);
    }
}
