<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ui4_workbench_sandbox_objects', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ui4_workbench_sandbox_objects', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
