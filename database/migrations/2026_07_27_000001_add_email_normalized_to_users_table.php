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
        // Additive and restartable: if collision diagnostics stop the
        // migration, operators can resolve the conflicting rows and rerun it
        // without a duplicate-column failure.
        if (! Schema::hasColumn('users', 'email_normalized')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('email_normalized')->nullable()->after('email');
            });
        }

        // Bounded memory regardless of account count. Populate only the
        // companion identity key; preserving the display email avoids a
        // transient collision on the legacy case-sensitive email index.
        DB::table('users')
            ->select(['id', 'email'])
            ->whereNull('email_normalized')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('users')->where('id', $row->id)->update([
                        'email_normalized' => mb_strtolower(trim((string) $row->email)),
                    ]);
                }
            }, 'id');

        $collisions = DB::table('users')
            ->select('email_normalized')
            ->selectRaw('COUNT(*) AS aggregate')
            ->whereNotNull('email_normalized')
            ->groupBy('email_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('email_normalized')
            ->limit(10)
            ->get();

        if ($collisions->isNotEmpty()) {
            $diagnostics = $collisions->map(function ($collision): string {
                $ids = DB::table('users')
                    ->where('email_normalized', $collision->email_normalized)
                    ->orderBy('id')
                    ->limit(3)
                    ->pluck('id')
                    ->implode(',');

                return sprintf(
                    'ids=[%s], identity_sha256=%s',
                    $ids,
                    substr(hash('sha256', (string) $collision->email_normalized), 0, 16),
                );
            })->implode('; ');

            throw new RuntimeException(
                'Cannot add case-insensitive email uniqueness; resolve normalized collisions: '.$diagnostics
            );
        }

        if (! Schema::hasIndex('users', 'users_email_normalized_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('email_normalized', 'users_email_normalized_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'email_normalized')) {
            return;
        }

        if (Schema::hasIndex('users', 'users_email_normalized_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique('users_email_normalized_unique');
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('email_normalized');
        });
    }
};
