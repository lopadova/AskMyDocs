<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invite system — AbuseSignal (append-only risk observation).
 *
 * Canonical entity from docs/04-data-model.md / docs/10-anti-abuse.md. One row
 * per detected risk event. `subject_value` is PII when the subject is an
 * ip/email/fingerprint — stored hashed/truncated, short-retention, erasable.
 * `context` carries the decision detail but NEVER raw PII beyond
 * `subject_value`. Immutable: insert-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_abuse_signals', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();

            $table->string('subject_type', 16); // ip | email | account | fingerprint | code
            $table->string('subject_value', 191); // hashed when ip/email/fingerprint
            $table->string('signal_type', 32); // rate_limit | self_referral | disposable_email | velocity | blacklist | honeypot | fingerprint_collision
            $table->string('severity', 8)->default('info'); // info | warn | block
            $table->integer('score')->nullable();
            $table->string('action_taken', 10)->default('none'); // none | flag | throttle | block
            $table->json('context')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_value'], 'ix_invite_abuse_subject');
            $table->index(['signal_type', 'created_at'], 'ix_invite_abuse_signal_created');
            $table->index(['tenant_id', 'created_at'], 'ix_invite_abuse_tenant_created');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE invite_abuse_signals ADD CONSTRAINT chk_invite_abuse_subject_type "
                . "CHECK (subject_type IN ('ip','email','account','fingerprint','code'))"
            );
            DB::statement(
                "ALTER TABLE invite_abuse_signals ADD CONSTRAINT chk_invite_abuse_severity "
                . "CHECK (severity IN ('info','warn','block'))"
            );
            DB::statement(
                "ALTER TABLE invite_abuse_signals ADD CONSTRAINT chk_invite_abuse_action "
                . "CHECK (action_taken IN ('none','flag','throttle','block'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_abuse_signals');
    }
};
