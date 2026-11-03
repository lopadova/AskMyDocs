<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qa_inference_attempts', fn (Blueprint $table) => $table->json('identity_json')->nullable());
    }

    public function down(): void
    {
        Schema::table('qa_inference_attempts', fn (Blueprint $table) => $table->dropColumn('identity_json'));
    }
};
