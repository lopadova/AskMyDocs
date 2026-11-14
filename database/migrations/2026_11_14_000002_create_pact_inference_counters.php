<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::create('pact_inference_counters', function (Blueprint $table): void { $table->string('task_id')->primary(); $table->unsignedInteger('next_step')->default(0); }); }
    public function down(): void { Schema::dropIfExists('pact_inference_counters'); }
};
