<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Test-schema mirror of the production normalized-email migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seen = [];
        foreach (DB::table('users')->select(['id', 'email'])->orderBy('id')->cursor() as $row) {
            $normalized = mb_strtolower(trim((string) $row->email));
            if (isset($seen[$normalized])) {
                throw new RuntimeException('Duplicate normalized email in test fixture: '.$normalized);
            }
            $seen[$normalized] = (int) $row->id;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email_normalized')->nullable()->after('email');
        });

        foreach ($seen as $normalized => $id) {
            DB::table('users')->where('id', $id)->update([
                'email' => $normalized,
                'email_normalized' => $normalized,
            ]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('email_normalized', 'users_email_normalized_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_email_normalized_unique');
            $table->dropColumn('email_normalized');
        });
    }
};
