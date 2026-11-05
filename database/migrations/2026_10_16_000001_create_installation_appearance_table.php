<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_appearance', function (Blueprint $table): void {
            // The application writes only id=1; no tenant/project overrides exist.
            $table->unsignedTinyInteger('id')->primary();
            $table->string('theme', 40);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_appearance');
    }
};
