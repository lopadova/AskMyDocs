<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v8.11 Auto-Wiki — extend knowledge_documents with two columns:
 *
 *  - `generation_source` ('human' | 'auto') — the auto-tier discriminator.
 *    A document compiled by the AutoWikiCompiler (or whose frontmatter was
 *    auto-enriched) is marked 'auto'. The reranker firewall ranks
 *    human-'accepted' above 'auto' above raw, and an admin can promote
 *    auto -> human. Default 'human' so every existing row keeps today's
 *    behaviour (back-compat / R43 OFF path).
 *  - `markdown_path` — when the source-retention policy keeps the converted
 *    markdown as a first-class artifact (full_copy / markdown_only), this is
 *    its path on the KB disk. NULL means "no stored markdown artifact"
 *    (today's behaviour: re-derive lossily from chunks via
 *    DocumentVersionService::reconstructContent()).
 *
 * Both nullable / safe-default — back-compat with every existing row.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->string('generation_source', 16)->default('human')->after('is_canonical')->index();
            $table->string('markdown_path', 1024)->nullable()->after('source_path');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropIndex('knowledge_documents_generation_source_index');
            $table->dropColumn(['generation_source', 'markdown_path']);
        });
    }
};
