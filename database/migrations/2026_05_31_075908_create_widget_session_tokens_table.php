<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * widget_session_tokens — token di sessione opzionali origin-bound per la
 * modalità browser (A). Elimina la necessità di passare pk a ogni richiesta.
 *
 * Flusso:
 *   1. POST /api/widget/session → conia un token HMAC a breve scadenza
 *   2. Il FE usa il token invece di X-Widget-Key nelle richieste successive
 *   3. Il consumo è ATOMICO (R21): lockForUpdate + write nella stessa transazione
 *
 * R31: tenant-aware.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_session_tokens', function (Blueprint $table) {
            $table->id();

            // R31
            $table->string('tenant_id', 50)->default('default')->index();

            // Il token stesso (HMAC-derived, opaco)
            $table->string('token', 128)->unique();

            // La key e la sessione associate
            $table->foreignId('widget_key_id')->constrained('widget_keys')->cascadeOnDelete();
            $table->foreignId('widget_session_id')->nullable()->constrained('widget_sessions')->cascadeOnDelete();

            // Origin-bound: il token è valido solo da questo Origin
            $table->string('origin', 255)->nullable();

            // Scadenza breve (tipicamente 30 min)
            $table->timestamp('expires_at');

            // Consumo atomico (R21): NULL = non consumato, timestamp = consumato
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            // Lookup: token non scaduto per key
            $table->index(['widget_key_id', 'expires_at'], 'idx_wst_key_expires');
            // #29 — Postgres non indicizza automaticamente le FK: senza questo
            // indice il cascade delete da una sessione scansiona l'intera tabella.
            $table->index('widget_session_id', 'idx_wst_session');
            // #29 — il prune dei token scaduti filtra su expires_at.
            $table->index('expires_at', 'idx_wst_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_session_tokens');
    }
};