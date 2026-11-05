<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_triage_evaluations', function (Blueprint $table) {
            $table->unsignedInteger('index_generation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('email_triage_evaluations', function (Blueprint $table) {
            $table->dropColumn('index_generation');
        });
    }
};
