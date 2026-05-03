<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.0.1 patch — replace `embedding_cache.text_hash` UNIQUE with a
 * composite UNIQUE on `(text_hash, provider, model)`.
 *
 * Why
 * ---
 * The original v4.0 schema enforced UNIQUE on `text_hash` alone but
 * `EmbeddingCacheService` reads / inserts by the tuple
 * `(text_hash, provider, model)`. Two consequences fell out of that
 * mismatch:
 *
 *   1. The cache could only hold ONE active model per text. Switching
 *      provider or model forced operators to call
 *      `EmbeddingCacheService::flush($provider)` first, otherwise the
 *      first miss-and-insert on an already-cached `text_hash` raised a
 *      duplicate-key SQL error. The PR #99 audit recommended the
 *      schema fix; the v4.0.0 release notes parked it as a known
 *      v4.0.x follow-up.
 *
 *   2. Multi-model deployments (e.g. dev `text-embedding-3-small`
 *      sharing a database with prod `text-embedding-3-large`) couldn't
 *      coexist without bespoke schema-level shadowing.
 *
 * The composite UNIQUE makes the schema enforce exactly what the
 * service queries: identical (text, provider, model) is the dedupe
 * key. Multiple models can now coexist for the same text without
 * conflict; `flush($provider)` becomes optional cleanup rather than
 * a required pre-condition for model swaps.
 *
 * Migration safety
 * ----------------
 * - Existing rows are preserved verbatim. The composite UNIQUE is a
 *   relaxation of the prior single-column UNIQUE (every prior unique
 *   tuple stays unique under the new constraint), so the ALTER never
 *   needs to dedupe data.
 * - Provider + model already had standalone non-unique indexes —
 *   those remain in place; the new composite UNIQUE is additive.
 * - Down-migration restores the single-column UNIQUE; data that
 *   accumulated under the composite contract may violate the older
 *   constraint (multiple rows sharing `text_hash` under different
 *   models). Down on a populated database therefore requires manual
 *   dedupe via `EmbeddingCacheService::flush($provider)` for every
 *   non-active provider before running `migrate:rollback`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embedding_cache', function (Blueprint $table) {
            // Drop the single-column UNIQUE that the v4.0 baseline
            // declared via `->unique()` on the `text_hash` column —
            // Laravel's default index name follows the
            // `<table>_<column>_unique` convention.
            $table->dropUnique('embedding_cache_text_hash_unique');

            // Add the composite UNIQUE that matches the service's
            // actual lookup key.
            $table->unique(
                ['text_hash', 'provider', 'model'],
                'embedding_cache_text_hash_provider_model_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('embedding_cache', function (Blueprint $table) {
            $table->dropUnique('embedding_cache_text_hash_provider_model_unique');
            $table->unique('text_hash', 'embedding_cache_text_hash_unique');
        });
    }
};
