<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->json('payload_json');
            $table->string('hash_sha256', 64);
            $table->string('hash_hmac', 64);
            $table->string('pdf_path')->nullable();
            $table->timestamp('generated_at');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'period_start', 'period_end'], 'compliance_reports_tenant_period_unique');
            $table->index(['tenant_id', 'generated_at'], 'compliance_reports_tenant_generated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_reports');
    }
};

