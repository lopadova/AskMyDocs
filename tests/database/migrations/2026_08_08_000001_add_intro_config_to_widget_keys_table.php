<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->json('intro_config')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->dropColumn('intro_config');
        });
    }
};
