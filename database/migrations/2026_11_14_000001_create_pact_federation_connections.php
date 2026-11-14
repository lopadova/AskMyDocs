<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pact_federation_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('grant_id')->unique();
            $table->string('federation_id');
            $table->string('member_id');
            $table->text('coordinator_url');
            $table->text('mcp_endpoint');
            $table->text('credential_encrypted');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('pact_federation_connections'); }
};
