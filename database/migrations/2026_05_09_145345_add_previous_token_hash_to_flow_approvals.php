<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_approvals') || Schema::hasColumn('flow_approvals', 'previous_token_hash')) {
            return;
        }

        Schema::table('flow_approvals', function (Blueprint $table): void {
            $table->string('previous_token_hash', 64)->nullable()->unique()->after('token_hash');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_approvals') || ! Schema::hasColumn('flow_approvals', 'previous_token_hash')) {
            return;
        }

        Schema::table('flow_approvals', function (Blueprint $table): void {
            $table->dropUnique(['previous_token_hash']);
            $table->dropColumn('previous_token_hash');
        });
    }
};
