<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imap_backfill_windows', function (Blueprint $table): void {
            if (! Schema::hasColumn('imap_backfill_windows', 'snapshot_uid_validity')) {
                $table->unsignedBigInteger('snapshot_uid_validity')->default(0)->after('status');
            }
            if (! Schema::hasColumn('imap_backfill_windows', 'snapshot_max_uid')) {
                $table->unsignedBigInteger('snapshot_max_uid')->default(0)->after('snapshot_uid_validity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('imap_backfill_windows', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['snapshot_uid_validity', 'snapshot_max_uid'],
                static fn (string $column): bool => Schema::hasColumn('imap_backfill_windows', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
