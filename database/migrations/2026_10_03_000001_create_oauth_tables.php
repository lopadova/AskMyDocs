<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->json('redirect_uris');
            $table->json('scopes');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('oauth_authorization_codes', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->foreignUuid('client_id')->constrained('oauth_clients')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_id', 64);
            $table->text('redirect_uri');
            $table->json('scopes');
            $table->char('code_challenge', 43);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_access_token_id')->unique()
                ->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('oauth_clients')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_id', 64);
            $table->json('scopes');
            $table->timestamps();
            $table->index(['user_id', 'client_id']);
        });
    }

    public function down(): void
    {
        // A code rollback must not leave live OAuth PATs behind after the
        // provider enforcing their route/tenant restrictions has been removed.
        DB::table('personal_access_tokens')->whereIn('id',
            DB::table('oauth_access_tokens')->select('personal_access_token_id'))->delete();
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_authorization_codes');
        Schema::dropIfExists('oauth_clients');
    }
};
