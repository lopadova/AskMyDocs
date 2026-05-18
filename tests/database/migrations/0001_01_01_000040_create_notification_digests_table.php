<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_digests', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->date('week_start_date');
            $table->json('payload');
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'week_start_date'],
                'uq_notif_digests_tenant_week',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digests');
    }
};
