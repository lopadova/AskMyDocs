<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Routines: cosa gira, quando, per conto di chi — e cosa è successo ogni volta.
 *
 * Due tabelle e non una: la routine è la CONFIGURAZIONE (dura anni, la si modifica), il run è un
 * FATTO (immutabile, è evidenza). Tenerli insieme significherebbe che modificare uno schedule
 * riscrive la storia di ciò che è già successo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routines', function (Blueprint $t): void {
            $t->ulid('id')->primary();

            // Proprietario in forma canonica `type:id`. Àncora di responsabilità: se viene
            // disabilitato la routine si sospende, perché una routine senza un umano dietro è
            // esattamente ciò che non deve girare da sola.
            $t->string('owner', 128)->index();
            $t->string('organization_id', 64)->nullable()->index();

            $t->string('name');
            $t->text('description')->nullable();

            // Bersaglio polimorfico: il core non sa cosa lancia.
            $t->string('target_type', 64)->index();
            $t->json('target_payload');

            // Innesco. `cron` e `once_at` sono gli unici che producono un next_run_at.
            $t->string('trigger_kind', 16)->default('cron');
            $t->string('cron')->nullable();
            $t->timestamp('once_at')->nullable();
            $t->string('event_name')->nullable()->index();

            // Il segreto con cui si firma il webhook, cifrato a riposo. Non e' un hash: per
            // verificare un HMAC serve il segreto in chiaro al momento del confronto, quindi il
            // massimo ottenibile e' che un dump del database non lo consegni.
            $t->text('webhook_secret')->nullable();

            // Il fuso del PROPRIETARIO, non UTC: "ogni giorno alle 6" sono le sue 6.
            $t->string('timezone', 64)->default('UTC');

            $t->string('status', 16)->default('active')->index();
            $t->string('ended_reason', 64)->nullable();
            $t->string('suspension_reason')->nullable();

            $t->string('overlap_policy', 16)->default('skip');
            $t->string('missed_run_policy', 16)->default('catch_up');

            // Schedulazione: si interroga `next_run_at <= now`, non "il cron corrisponde a questo
            // minuto". È ciò che rende il sistema immune a un tick saltato.
            $t->timestamp('next_run_at')->nullable()->index();

            // L'occorrenza LOCALE già eseguita (Y-m-d H:i). Con l'ora legale un orario locale può
            // presentarsi due volte in due istanti UTC distinti: senza questo, il fire avverrebbe
            // due volte. Vedi RoutineScheduler.
            $t->string('last_local_occurrence', 20)->nullable();

            // Lock con TTL. Il TTL non è un dettaglio: senza, un worker morto blocca la routine
            // per sempre e nessuno se ne accorge finché qualcuno non la guarda.
            $t->string('lock_token', 64)->nullable();
            $t->timestamp('locked_until')->nullable();

            // Identità delegata (fase 2). Nullable: una routine può girare come l'applicazione.
            $t->string('delegation_grant_id', 64)->nullable()->index();
            $t->string('mandate_digest', 64)->nullable();
            $t->json('mandate')->nullable();

            // L'evidenza del consenso, non la sua decorazione: quale conferma step-up, a che
            // livello di garanzia, quando. Senza queste tre cose "l'utente aveva acconsentito"
            // e' un'affermazione, non un fatto verificabile.
            $t->timestamp('mandate_granted_at')->nullable();
            $t->string('consent_confirmation_id', 64)->nullable();
            $t->string('consent_aal', 8)->nullable();

            // Tetti.
            $t->decimal('budget_per_run', 12, 4)->nullable();
            $t->decimal('budget_per_period', 12, 4)->nullable();
            $t->string('budget_period', 16)->nullable();      // day | week | month
            $t->string('currency', 3)->default('EUR');
            $t->unsignedInteger('timeout_seconds')->nullable();
            $t->unsignedTinyInteger('max_attempts')->default(3);

            // Provenienza dell'intento: "l'ha chiesto un umano" e "se l'è creata l'assistente"
            // sono la stessa riga con due significati diversi, e un revisore deve distinguerli.
            $t->string('initiation', 32)->default('human_request');
            $t->string('created_by', 128)->nullable();

            $t->timestamp('last_fired_at')->nullable();
            $t->timestamps();

            // La query calda del tick.
            $t->index(['status', 'next_run_at']);
        });

        Schema::create('routine_runs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('routine_id')->constrained('routines')->cascadeOnDelete();

            $t->string('reason', 16);                        // FireReason
            $t->string('outcome', 16)->nullable();           // null finché è in corso
            $t->unsignedTinyInteger('attempt')->default(1);

            // L'occorrenza a cui questo run corrisponde: distingue un catch-up delle 6:00 eseguito
            // alle 9:00 da un fire manuale delle 9:00.
            $t->timestamp('scheduled_for')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();

            // Generata al fire e STABILE attraverso i retry: è ciò che impedisce alla seconda
            // email di partire dopo un timeout.
            $t->string('idempotency_key', 64)->index();

            $t->text('message')->nullable();
            $t->json('metadata')->nullable();
            $t->string('external_ref', 128)->nullable();
            $t->decimal('cost', 12, 4)->nullable();

            // Pausa in attesa di un umano.
            $t->string('pending_approval_id', 64)->nullable()->index();
            $t->text('resume_token')->nullable();

            // La risposta: chi, quando, e con che parole. Il motivo di un rifiuto e' obbligatorio
            // perche' qualcuno lo leggera' - tipicamente chi si chiede perche' quella cosa non
            // e' stata fatta.
            $t->string('action_class', 64)->nullable();
            $t->text('question')->nullable();
            $t->string('resolved_by', 128)->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->text('resolution_note')->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->string('escalation_error')->nullable();

            // Quando ritentare. Un backoff ha bisogno di un ISTANTE, non di un contatore: il tick
            // seguente raccoglie i falliti la cui ora e' arrivata, e il ritardo sopravvive a un
            // restart come qualsiasi altra schedulazione.
            $t->timestamp('retry_at')->nullable();

            $t->string('correlation_id', 64)->nullable()->index();
            $t->timestamps();

            $t->index(['routine_id', 'created_at']);
            $t->index(['routine_id', 'outcome']);
            $t->index(['outcome', 'retry_at']);

            // Un'occorrenza schedulata produce UN run per tentativo: la garanzia at-most-once
            // contro due tick concorrenti, a livello di database e non di codice.
            $t->unique(['routine_id', 'idempotency_key', 'attempt'], 'routine_runs_idem_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_runs');
        Schema::dropIfExists('routines');
    }
};
