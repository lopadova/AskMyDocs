<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds tenant_id to pii_redactor_admin_audit_events.
 *
 * The padosoft/laravel-pii-redactor-admin v1.0.2 package is single-tenant
 * by design — its audit table records every detokenise event without any
 * tenant scoping (the package "asks" Gates and lets the host enforce
 * authorization). AskMyDocs is multi-tenant per R30/R31: every audit row
 * MUST carry tenant_id so:
 *
 *   - cross-tenant audit reads cannot leak the existence of a sister
 *     tenant's detokenise events,
 *   - per-tenant retention policies can be applied independently,
 *   - the future admin-audit dashboard can scope by `forTenant($id)`.
 *
 * The package controllers don't auto-populate tenant_id — we backfill it
 * via a saved/saving Eloquent observer wired in AppServiceProvider::boot().
 * (The published audit model is in `Padosoft\PiiRedactorAdmin\Models\
 * AuditEvent`; we attach an observer rather than fork the package model.)
 *
 * Default 'default' preserves v3.x backward compatibility where tenant_id
 * was implicit.
 */
return new class extends Migration
{
    private const TABLE = 'pii_redactor_admin_audit_events';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasColumn(self::TABLE, 'tenant_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 50)->default('default')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'tenant_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
