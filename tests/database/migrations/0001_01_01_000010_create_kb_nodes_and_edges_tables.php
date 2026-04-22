<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SQLite-compatible mirror of
// database/migrations/2026_04_22_000002_create_kb_nodes_and_edges_tables.php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kb_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('node_uid', 191)->unique();
            $table->string('node_type', 64)->index();
            $table->string('label', 255);
            $table->string('project_code', 120)->index();
            $table->string('source_doc_id', 128)->nullable()->index();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['node_type', 'project_code'], 'idx_kb_nodes_type_project');
            $table->index(['label'], 'idx_kb_nodes_label');
        });

        Schema::create('kb_edges', function (Blueprint $table) {
            $table->id();
            $table->string('edge_uid', 191)->unique();
            $table->string('from_node_uid', 191)->index();
            $table->string('to_node_uid', 191)->index();
            $table->string('edge_type', 64)->index();
            $table->string('project_code', 120)->index();
            $table->string('source_doc_id', 128)->nullable()->index();
            $table->decimal('weight', 8, 4)->default(1.0);
            $table->string('provenance', 64)->default('wikilink');
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->foreign('from_node_uid', 'fk_kb_edges_from_node')
                ->references('node_uid')->on('kb_nodes')
                ->onDelete('cascade');
            $table->foreign('to_node_uid', 'fk_kb_edges_to_node')
                ->references('node_uid')->on('kb_nodes')
                ->onDelete('cascade');

            $table->index(['project_code', 'edge_type'], 'idx_kb_edges_project_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_edges');
        Schema::dropIfExists('kb_nodes');
    }
};
