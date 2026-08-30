<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\KnowledgeDocument;
use App\Models\UnmappedSourcePrincipal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * v8.33 / ADR 0028 phase 2 — seed one source-restricted document and the
 * principals its source named that this application cannot place, so the
 * "Source Permissions" Playwright happy path exercises the real queue and the
 * real dismiss endpoint against REAL data (R13).
 *
 * Deliberately not driven through a connector sync: no connector is
 * configured in E2E, and the point of the scenario is the triage surface, not
 * the ingestion path (which has its own PHPUnit coverage).
 *
 * Idempotent per (tenant, document, principal), so re-seeding between
 * scenarios does not accumulate duplicates.
 */
final class SourceAclTriageSeeder extends Seeder
{
    public function run(): void
    {
        $document = KnowledgeDocument::withoutGlobalScopes()
            ->where('tenant_id', DemoSeeder::PRIMARY_TENANT)
            ->orderBy('id')
            ->first();

        if ($document === null) {
            // DemoSeeder has not run. Nothing to attach a permission list to,
            // and inventing a document here would diverge from what the rest
            // of the suite sees.
            return;
        }

        // The document is governed by its source: this is what makes project
        // membership insufficient on its own for it.
        DB::table('knowledge_documents')
            ->where('id', $document->getKey())
            ->update(['source_acl_enforced_at' => now()]);

        $principals = [
            ['user', 'contractor@agency.example'],
            ['group', 'board-members'],
            ['domain', 'partner.example'],
        ];

        foreach ($principals as [$type, $externalId]) {
            UnmappedSourcePrincipal::updateOrCreate(
                [
                    'tenant_id' => DemoSeeder::PRIMARY_TENANT,
                    'knowledge_document_id' => $document->getKey(),
                    'principal_type' => $type,
                    'principal_external_id' => $externalId,
                ],
                [
                    'project_key' => $document->project_key,
                    'effect' => 'allow',
                    'status' => UnmappedSourcePrincipal::STATUS_PENDING,
                    'first_seen_at' => now()->subDays(6),
                    'last_seen_at' => now(),
                ],
            );
        }
    }
}
