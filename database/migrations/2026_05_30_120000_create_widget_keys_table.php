<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * widget_keys — credenziali pubblicabili per il widget KITT embeddabile.
 *
 * Una riga per (tenant, project) abilita la chat RAG + agentica su siti
 * terzi. Due modalità d'accesso (D1 = A + B):
 *   - A (browser): `public_key` (pk_…) inviata via header X-Widget-Key,
 *     gating per Origin allowlist + rate-limit. Visibile nel sorgente HTML
 *     del sito ospite ma a basso rischio (vincolata a domini + limiti).
 *   - B (proxy server-to-server): `secret_hash` (hash di sk_…) via
 *     Authorization: Bearer; nessun controllo Origin (alta fiducia).
 *
 * Tenant/project sono SEMPRE risolti DALLA KEY lato server (R30): il client
 * non può mai indicare un tenant/progetto diverso. La key è il confine.
 *
 * R31: tabella tenant-aware → `tenant_id` string(50) default 'default' index.
 * `public_key` è UNIQUE GLOBALE (non per-tenant) perché è proprio il valore
 * che risolve il tenant: deve essere risolvibile senza contesto. Lo stesso
 * vale per `secret_hash`. (R28 "per-project unique" vale per le tassonomie,
 * non per una credenziale risolvibile.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_keys', function (Blueprint $table) {
            $table->id();

            // R31 — tenant-aware.
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120);

            // Credenziali. public_key risolvibile globalmente (browser);
            // secret_hash solo per la modalità proxy (B), hashato.
            $table->string('public_key', 64)->unique();
            $table->string('secret_hash', 255)->nullable();

            // Allowlist domini per la modalità A (browser). Es.
            // ["https://example.com","https://app.example.com"].
            $table->json('allowed_origins')->nullable();

            // Guardia costo/abuso: richieste/minuto per (key + IP).
            $table->unsignedInteger('rate_limit')->default(60);

            // Skill di default applicata alle sessioni avviate con questa key.
            $table->string('skill', 100)->default('askmydocs-assistant@1');

            $table->boolean('is_active')->default(true);
            $table->string('label', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            // Una key "viva" per (tenant, project, label) — evita duplicati
            // accidentali dallo stesso scopo. R31: unique composito che parte
            // da tenant_id.
            $table->unique(['tenant_id', 'project_key', 'label'], 'uq_widget_keys_tenant_project_label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_keys');
    }
};
