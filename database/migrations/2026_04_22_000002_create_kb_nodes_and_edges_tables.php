<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lightweight knowledge graph (see ADR 0002).
 *
 *   kb_nodes — one row per canonical concept. `node_uid` is the slug
 *              (unique *per project*, NOT globally). `project_key`
 *              scopes every row — same as the knowledge_documents /
 *              knowledge_chunks convention (R9).
 *
 *   kb_edges — typed relation between two nodes. Both endpoints are
 *              composite-FK'd to kb_nodes on (project_key, node_uid)
 *              with ON DELETE CASCADE, so cross-tenant edges are
 *              structurally impossible. `edge_uid` is also unique per
 *              project. `provenance` records how the edge was created
 *              (wikilink | frontmatter_related | frontmatter_supersedes | inferred).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kb_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('node_uid', 191);
            $table->string('node_type', 64)->index();
            $table->string('label', 255);
            $table->string('project_key', 120);
            $table->string('source_doc_id', 128)->nullable()->index();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            // project-scoped uniqueness — two projects may share the same slug
            $table->unique(['project_key', 'node_uid'], 'uq_kb_nodes_project_uid');
            $table->index(['node_type', 'project_key'], 'idx_kb_nodes_type_project');
            $table->index(['label'], 'idx_kb_nodes_label');
        });

        Schema::create('kb_edges', function (Blueprint $table) {
            $table->id();
            $table->string('edge_uid', 191);
            $table->string('from_node_uid', 191);
            $table->string('to_node_uid', 191);
            $table->string('edge_type', 64)->index();
            $table->string('project_key', 120);
            $table->string('source_doc_id', 128)->nullable()->index();
            $table->decimal('weight', 8, 4)->default(1.0);
            $table->string('provenance', 64)->default('wikilink');
            $table->json('payload_json')->nullable();
            $table->timestamps();

            // project-scoped uniqueness
            $table->unique(['project_key', 'edge_uid'], 'uq_kb_edges_project_uid');
            // FK coverage + common query indexes (leading column = project_key)
            $table->index(['project_key', 'from_node_uid'], 'idx_kb_edges_project_from');
            $table->index(['project_key', 'to_node_uid'], 'idx_kb_edges_project_to');
            $table->index(['project_key', 'edge_type'], 'idx_kb_edges_project_type');

            // composite FKs — enforce tenant-scoped referential integrity.
            // An edge row in project X CANNOT point at a node in project Y,
            // because (project_key, from_node_uid) must resolve to
            // (project_key, node_uid) in kb_nodes for the SAME project.
            $table->foreign(['project_key', 'from_node_uid'], 'fk_kb_edges_from_node')
                ->references(['project_key', 'node_uid'])->on('kb_nodes')
                ->onDelete('cascade');
            $table->foreign(['project_key', 'to_node_uid'], 'fk_kb_edges_to_node')
                ->references(['project_key', 'node_uid'])->on('kb_nodes')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_edges');
        Schema::dropIfExists('kb_nodes');
    }
};
