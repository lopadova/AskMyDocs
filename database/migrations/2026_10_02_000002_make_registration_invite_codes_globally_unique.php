<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invite_codes')) {
            return;
        }

        $duplicates = DB::table('invite_codes')
            ->select('code')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'Cannot make registration invite codes globally addressable: duplicate normalized codes exist.'
            );
        }

        Schema::table('invite_codes', function (Blueprint $table): void {
            $table->unique('code', 'uq_invite_codes_code_global');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invite_codes')) {
            return;
        }

        Schema::table('invite_codes', function (Blueprint $table): void {
            $table->dropUnique('uq_invite_codes_code_global');
        });
    }
};
