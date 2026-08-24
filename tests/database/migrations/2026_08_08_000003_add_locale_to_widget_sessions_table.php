<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('widget_sessions', 'locale')) {
            Schema::table('widget_sessions', function (Blueprint $table): void {
                $table->string('locale', 35)->nullable()->after('origin');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('widget_sessions', 'locale')) {
            Schema::table('widget_sessions', function (Blueprint $table): void {
                $table->dropColumn('locale');
            });
        }
    }
};
