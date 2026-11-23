<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_keys', function (Blueprint $table): void {
            $table->boolean('data_agent_enabled')->default(true);
        });

        Schema::create('widget_project_setups', function (Blueprint $table): void {
            $table->foreignId('project_id')->primary()->constrained('projects')->cascadeOnDelete();
            $table->foreignId('widget_key_id')->nullable()->constrained('widget_keys')->nullOnDelete();
            $table->boolean('user_auth_enabled');
            $table->string('language', 80);
            $table->string('audience', 200);
            $table->text('topics');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_project_setups');

        Schema::table('widget_keys', function (Blueprint $table): void {
            $table->dropColumn('data_agent_enabled');
        });
    }
};
