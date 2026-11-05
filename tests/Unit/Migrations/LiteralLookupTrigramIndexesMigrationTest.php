<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LiteralLookupTrigramIndexesMigrationTest extends TestCase
{
    private const MIGRATION_PATH = __DIR__
        .'/../../../database/migrations/2026_10_22_000002_add_literal_lookup_trigram_indexes.php';

    public function test_concurrent_index_builds_run_outside_a_transaction(): void
    {
        $migration = require self::MIGRATION_PATH;

        $this->assertFalse($migration->withinTransaction);
    }

    public function test_up_and_down_do_not_execute_postgresql_sql_on_sqlite(): void
    {
        $database = DB::getFacadeRoot();
        try {
            DB::shouldReceive('getDriverName')->twice()->andReturn('sqlite');
            DB::shouldReceive('statement')->never();
            DB::shouldReceive('selectOne')->never();

            $migration = require self::MIGRATION_PATH;
            $migration->up();
            $migration->down();
        } finally {
            DB::swap($database);
        }
    }

    public function test_up_creates_trigram_indexes_for_both_literal_search_expressions(): void
    {
        $database = DB::getFacadeRoot();
        try {
            DB::shouldReceive('getDriverName')->once()->andReturn('pgsql');
            DB::shouldReceive('statement')->once()->ordered()
                ->with('CREATE EXTENSION IF NOT EXISTS pg_trgm')->andReturnTrue();
            DB::shouldReceive('selectOne')->once()->ordered()
                ->with('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', ['idx_kb_chunks_literal_trgm'])->andReturnNull();
            DB::shouldReceive('statement')->once()->ordered()
                ->with('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_kb_chunks_literal_trgm '.
                    'ON knowledge_chunks USING GIN (LOWER(chunk_text) gin_trgm_ops)')->andReturnTrue();
            DB::shouldReceive('selectOne')->once()->ordered()
                ->with('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', ['idx_kb_documents_title_trgm'])->andReturnNull();
            DB::shouldReceive('statement')->once()->ordered()
                ->with('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_kb_documents_title_trgm '.
                    'ON knowledge_documents USING GIN (LOWER(title) gin_trgm_ops)')->andReturnTrue();

            $migration = require self::MIGRATION_PATH;
            $migration->up();
        } finally {
            DB::swap($database);
        }
    }

    public function test_up_recovers_invalid_named_indexes_before_retrying_each_concurrent_build(): void
    {
        $database = DB::getFacadeRoot();
        try {
            DB::shouldReceive('getDriverName')->once()->andReturn('pgsql');
            DB::shouldReceive('statement')->once()->ordered()
                ->with('CREATE EXTENSION IF NOT EXISTS pg_trgm')->andReturnTrue();
            foreach ([
                'idx_kb_chunks_literal_trgm' => 'ON knowledge_chunks USING GIN (LOWER(chunk_text) gin_trgm_ops)',
                'idx_kb_documents_title_trgm' => 'ON knowledge_documents USING GIN (LOWER(title) gin_trgm_ops)',
            ] as $name => $definition) {
                DB::shouldReceive('selectOne')->once()->ordered()
                    ->with('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [$name])
                    ->andReturn((object) ['invalid' => 1]);
                DB::shouldReceive('statement')->once()->ordered()
                    ->with('DROP INDEX CONCURRENTLY IF EXISTS '.$name)->andReturnTrue();
                DB::shouldReceive('statement')->once()->ordered()
                    ->with('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' '.$definition)->andReturnTrue();
            }

            $migration = require self::MIGRATION_PATH;
            $migration->up();
        } finally {
            DB::swap($database);
        }
    }

    public function test_down_removes_only_its_indexes_and_preserves_the_shared_extension(): void
    {
        $database = DB::getFacadeRoot();
        try {
            DB::shouldReceive('getDriverName')->once()->andReturn('pgsql');
            DB::shouldReceive('statement')->once()->ordered()
                ->with('DROP INDEX CONCURRENTLY IF EXISTS idx_kb_documents_title_trgm')->andReturnTrue();
            DB::shouldReceive('statement')->once()->ordered()
                ->with('DROP INDEX CONCURRENTLY IF EXISTS idx_kb_chunks_literal_trgm')->andReturnTrue();

            $migration = require self::MIGRATION_PATH;
            $migration->down();
        } finally {
            DB::swap($database);
        }
    }
}
