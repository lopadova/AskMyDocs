<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.11 Auto-Wiki — extend the per-(tenant, project) settings table with the
 * auto-wiki override knobs (read by {@see \App\Services\Kb\AutoWiki\AutoWikiGate}).
 *
 * Reuses the existing kb_analysis_settings table so an operator manages the
 * deep-analysis AND the auto-wiki posture from one place. Every new column is
 * NULLABLE: a null inherits the next level up (project → tenant '*' →
 * config('kb.autowiki.*')), so an override can flip a single knob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kb_analysis_settings', function (Blueprint $table): void {
            $table->boolean('autowiki_enabled')->nullable()->after('delete_enabled');
            $table->boolean('autowiki_canonical')->nullable()->after('autowiki_enabled');
            $table->boolean('autowiki_non_canonical')->nullable()->after('autowiki_canonical');
        });
    }

    public function down(): void
    {
        Schema::table('kb_analysis_settings', function (Blueprint $table): void {
            $table->dropColumn(['autowiki_enabled', 'autowiki_canonical', 'autowiki_non_canonical']);
        });
    }
};
