<?php

namespace Tests\Unit\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CanonicalLayerMigrationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------
    // knowledge_documents canonical columns
    // -------------------------------------------------------------

    public function test_canonical_columns_exist_on_knowledge_documents(): void
    {
        $expected = [
            'doc_id', 'slug', 'canonical_type', 'canonical_status',
            'is_canonical', 'retrieval_priority', 'source_of_truth', 'frontmatter_json',
        ];
        foreach ($expected as $col) {
            $this->assertTrue(
                Schema::hasColumn('knowledge_documents', $col),
                "Expected column knowledge_documents.$col is missing"
            );
        }
    }

    public function test_non_canonical_row_inserts_without_canonical_values(): void
    {
        DB::table('knowledge_documents')->insert([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'Plain doc',
            'source_path' => 'docs/plain.md',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('knowledge_documents')->where('source_path', 'docs/plain.md')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->doc_id);
        $this->assertNull($row->slug);
        $this->assertNull($row->canonical_type);
        $this->assertNull($row->canonical_status);
        $this->assertSame(0, (int) $row->is_canonical);
        $this->assertSame(50, (int) $row->retrieval_priority);
        $this->assertSame(1, (int) $row->source_of_truth);
        $this->assertNull($row->frontmatter_json);
    }

    public function test_canonical_row_persists_all_canonical_fields(): void
    {
        DB::table('knowledge_documents')->insert([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'Cache invalidation v2',
            'source_path' => 'decisions/dec-cache-v2.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('b', 64),
            'version_hash' => str_repeat('b', 64),
            'doc_id' => 'DEC-2026-0001',
            'slug' => 'dec-cache-v2',
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
            'retrieval_priority' => 90,
            'source_of_truth' => true,
            'frontmatter_json' => json_encode(['owners' => ['platform-team']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('knowledge_documents')->where('doc_id', 'DEC-2026-0001')->first();
        $this->assertNotNull($row);
        $this->assertSame('dec-cache-v2', $row->slug);
        $this->assertSame('decision', $row->canonical_type);
        $this->assertSame('accepted', $row->canonical_status);
        $this->assertSame(1, (int) $row->is_canonical);
        $this->assertSame(90, (int) $row->retrieval_priority);
    }

    public function test_doc_id_uniqueness_is_scoped_per_project(): void
    {
        // Same doc_id in two different projects must coexist.
        $base = [
            'source_type' => 'markdown',
            'title' => 't',
            'source_path' => 'p.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('c', 64),
            'version_hash' => str_repeat('c', 64),
            'doc_id' => 'DEC-2026-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('knowledge_documents')->insert($base + ['project_key' => 'acme', 'source_path' => 'a.md', 'version_hash' => str_repeat('c', 64)]);
        DB::table('knowledge_documents')->insert($base + ['project_key' => 'beta', 'source_path' => 'b.md', 'version_hash' => str_repeat('d', 64)]);

        $this->assertSame(2, DB::table('knowledge_documents')->where('doc_id', 'DEC-2026-0001')->count());
    }

    public function test_slug_uniqueness_is_scoped_per_project(): void
    {
        $base = [
            'source_type' => 'markdown',
            'title' => 't',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('e', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('knowledge_documents')->insert($base + ['project_key' => 'acme', 'source_path' => 'a.md', 'version_hash' => str_repeat('e', 64), 'slug' => 'dec-x']);
        DB::table('knowledge_documents')->insert($base + ['project_key' => 'beta', 'source_path' => 'b.md', 'version_hash' => str_repeat('f', 64), 'slug' => 'dec-x']);

        $this->assertSame(2, DB::table('knowledge_documents')->where('slug', 'dec-x')->count());
    }

    // -------------------------------------------------------------
    // kb_nodes / kb_edges
    // -------------------------------------------------------------

    public function test_kb_nodes_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('kb_nodes'));
        foreach (['node_uid', 'node_type', 'label', 'project_code', 'source_doc_id', 'payload_json', 'created_at', 'updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('kb_nodes', $col), "kb_nodes.$col missing");
        }
    }

    public function test_kb_edges_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('kb_edges'));
        foreach (['edge_uid', 'from_node_uid', 'to_node_uid', 'edge_type', 'project_code', 'source_doc_id', 'weight', 'provenance', 'payload_json', 'created_at', 'updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('kb_edges', $col), "kb_edges.$col missing");
        }
    }

    public function test_can_insert_nodes_and_edges_across_project_scope(): void
    {
        DB::table('kb_nodes')->insert([
            ['node_uid' => 'dec-cache-v2', 'node_type' => 'decision', 'label' => 'Cache v2', 'project_code' => 'acme', 'created_at' => now(), 'updated_at' => now()],
            ['node_uid' => 'module-cache', 'node_type' => 'module', 'label' => 'Cache module', 'project_code' => 'acme', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('kb_edges')->insert([
            'edge_uid' => 'dec-cache-v2->module-cache',
            'from_node_uid' => 'dec-cache-v2',
            'to_node_uid' => 'module-cache',
            'edge_type' => 'decision_for',
            'project_code' => 'acme',
            'weight' => 1.0,
            'provenance' => 'wikilink',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('kb_nodes')->count());
        $this->assertSame(1, DB::table('kb_edges')->count());
    }

    public function test_cascade_delete_from_node_removes_its_edges(): void
    {
        DB::table('kb_nodes')->insert([
            ['node_uid' => 'a', 'node_type' => 'decision', 'label' => 'A', 'project_code' => 'acme', 'created_at' => now(), 'updated_at' => now()],
            ['node_uid' => 'b', 'node_type' => 'module', 'label' => 'B', 'project_code' => 'acme', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('kb_edges')->insert([
            'edge_uid' => 'a->b',
            'from_node_uid' => 'a',
            'to_node_uid' => 'b',
            'edge_type' => 'decision_for',
            'project_code' => 'acme',
            'weight' => 1.0,
            'provenance' => 'wikilink',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('kb_nodes')->where('node_uid', 'a')->delete();

        $this->assertSame(0, DB::table('kb_edges')->count(), 'Edge should cascade-delete with its from_node');
    }

    // -------------------------------------------------------------
    // kb_canonical_audit
    // -------------------------------------------------------------

    public function test_kb_canonical_audit_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('kb_canonical_audit'));
        foreach (['project_key', 'doc_id', 'slug', 'event_type', 'actor', 'before_json', 'after_json', 'metadata_json', 'created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('kb_canonical_audit', $col), "kb_canonical_audit.$col missing");
        }
    }

    public function test_audit_row_inserts_with_minimal_columns(): void
    {
        DB::table('kb_canonical_audit')->insert([
            'project_key' => 'acme',
            'event_type' => 'promoted',
            'actor' => 'test-user',
            'created_at' => now(),
        ]);
        $this->assertSame(1, DB::table('kb_canonical_audit')->count());
    }
}
