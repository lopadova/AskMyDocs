<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Separate statement so the down() can drop it before dropping the
        // column on SQLite (which doesn't allow dropping an indexed column).
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->index('deleted_at', 'knowledge_documents_deleted_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropIndex('knowledge_documents_deleted_at_index');
        });

        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
