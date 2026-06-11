<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * widget_sessions — una sessione conversazionale KITT per il widget.
 *
 * Port di `kitt_sessions` (gescat) adattato al modello cross-origin di
 * AskMyDocs. Ogni sessione è legata alla `widget_keys` che l'ha avviata
 * (quindi a tenant + project). `public_session_id` è l'identificatore
 * esposto al browser (mai l'auto-increment) → niente enumerazione.
 *
 * R31: tenant-aware. FK su widget_key_id con cascade: cancellando una key
 * spariscono le sue sessioni (e a cascata gli step).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_sessions', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('widget_key_id')->constrained('widget_keys')->cascadeOnDelete();
            $table->string('project_key', 120);

            // Id pubblico opaco usato da FE/Transport (UUID). Risolvibile
            // globalmente → unique globale.
            $table->uuid('public_session_id')->unique();

            // Stato della state machine (BotBridge): active | waiting_user |
            // waiting_tool | completed | blocked | aborted | error.
            $table->string('status', 20)->default('active')->index();

            $table->string('skill', 100)->nullable();
            $table->string('mission', 120)->nullable();
            $table->string('page_url', 1024)->nullable();
            $table->string('origin', 255)->nullable();

            $table->text('summary')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            // Lookup tipico: sessioni di una key per stato.
            $table->index(['widget_key_id', 'status'], 'idx_widget_sessions_key_status');
            // #28 — l'admin list filtra tenant_id e ordina per created_at:
            // senza questo indice il DB fa un filesort per pagina su volumi grandi.
            $table->index(['tenant_id', 'created_at'], 'idx_widget_sessions_tenant_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_sessions');
    }
};
