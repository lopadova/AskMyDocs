<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'registration_completed_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('registration_completed_at')
                    ->nullable()
                    ->after('email_verified_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'registration_completed_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('registration_completed_at');
            });
        }
    }
};
