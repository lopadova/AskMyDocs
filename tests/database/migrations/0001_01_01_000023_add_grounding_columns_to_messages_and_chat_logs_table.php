<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Testbench (SQLite) mirror of the T3.1 grounding-columns migration.
 *
 * SQLite doesn't natively understand `unsignedTinyInteger` the same way as
 * pgsql/mysql, but Laravel's grammar emits a TINYINT-affine column (which
 * SQLite stores as INTEGER) and integer affinity accepts the 0..100 range
 * we need. No CHECK constraint here — the production database can opt in
 * later via a follow-up pgsql-only migration without breaking the
 * testbench mirror.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedTinyInteger('confidence')->nullable()->after('rating');
            $table->string('refusal_reason', 64)->nullable()->after('confidence');
        });

        Schema::table('chat_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('confidence')->nullable()->after('latency_ms');
            $table->string('refusal_reason', 64)->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['confidence', 'refusal_reason']);
        });

        Schema::table('chat_logs', function (Blueprint $table) {
            $table->dropColumn(['confidence', 'refusal_reason']);
        });
    }
};
