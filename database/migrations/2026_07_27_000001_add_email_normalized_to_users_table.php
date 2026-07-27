<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Case-insensitive account identity.
 *
 * `users.email` is globally unique (including soft-deleted accounts), but
 * PostgreSQL treats differently-cased values as distinct. The normalized
 * companion column gives every creation path the same database-enforced
 * identity and closes the race between a preflight check and INSERT.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seen = [];
        foreach (DB::table('users')->select(['id', 'email'])->orderBy('id')->cursor() as $row) {
            $normalized = mb_strtolower(trim((string) $row->email));
            if (isset($seen[$normalized])) {
                throw new RuntimeException(
                    "Cannot add case-insensitive email uniqueness: users {$seen[$normalized]} and {$row->id} normalize to {$normalized}."
                );
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
