<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft')->index();
            $table->json('graph');
            $table->string('checksum', 64)->index();
            $table->string('signature', 128)->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['name', 'version']);
            $table->index(['name', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_definitions');
    }
};
