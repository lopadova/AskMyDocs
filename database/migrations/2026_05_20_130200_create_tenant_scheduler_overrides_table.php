<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_scheduler_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('slot_name', 64);
            $table->string('cron', 64);
            $table->boolean('enabled')->default(true);
            $table->string('timezone', 64)->default('UTC');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'slot_name'],
                'uq_tenant_scheduler_overrides_tenant_slot',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_scheduler_overrides');
    }
};

