<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['oauth_authorization_codes', 'oauth_access_tokens'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('pact_project_key', 120)->nullable();
                $table->char('pact_membership_hash', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Revoke only the new grants; preserve all legacy REST/MCP tokens.
        DB::table('personal_access_tokens')->whereIn('id', DB::table('oauth_access_tokens')
            ->whereNotNull('pact_project_key')->select('personal_access_token_id'))->delete();
        DB::table('oauth_authorization_codes')->whereNotNull('pact_project_key')->delete();
        foreach (['oauth_access_tokens', 'oauth_authorization_codes'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['pact_project_key', 'pact_membership_hash']));
        }
    }
};
