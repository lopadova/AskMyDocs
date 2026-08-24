<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_dataset_operation_nonces', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50);
            $table->string('token_hash', 64);
            $table->string('operation', 120);
            $table->string('actor', 120);
            $table->string('args_hash', 64);
            $table->string('dataset_version', 160);
            $table->string('manifest_checksum', 64);
            $table->json('selection_json');
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->unique(
                ['tenant_id', 'token_hash'],
                'email_dataset_nonce_tenant_token_uniq',
            );
            $table->index('expires_at', 'email_dataset_nonce_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_dataset_operation_nonces');
    }
};
