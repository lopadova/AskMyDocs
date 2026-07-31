<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `kb_ingest_batches.status` so every declared lifecycle value fits.
 *
 * The original varchar(20) rejected `completed_with_errors` (21 characters)
 * on PostgreSQL while SQLite tests silently accepted it because SQLite does
 * not enforce varchar lengths.
 */
return new class extends Migration
{
    private const OLD_LENGTH = 20;

    private const NEW_LENGTH = 32;

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(sprintf(
                'ALTER TABLE kb_ingest_batches ALTER COLUMN status TYPE varchar(%d)',
                self::NEW_LENGTH,
            ));

            return;
        }

        Schema::table('kb_ingest_batches', function (Blueprint $table): void {
            $table->string('status', self::NEW_LENGTH)->default('staged')->change();
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        DB::table('kb_ingest_batches')
            ->whereRaw('CHAR_LENGTH(status) > ?', [self::OLD_LENGTH])
            ->update(['status' => 'completed']);

        if ($driver === 'pgsql') {
            DB::statement(sprintf(
                'ALTER TABLE kb_ingest_batches ALTER COLUMN status TYPE varchar(%d)',
                self::OLD_LENGTH,
            ));

            return;
        }

        Schema::table('kb_ingest_batches', function (Blueprint $table): void {
            $table->string('status', self::OLD_LENGTH)->default('staged')->change();
        });
    }
};
