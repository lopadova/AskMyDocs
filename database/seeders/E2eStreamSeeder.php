<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * v8.5 — seeds ONE vector-searchable hr-portal doc for the browser
 * streaming E2E (`frontend/e2e/chat-stream-browser.spec.ts`).
 *
 * Unlike DemoSeeder (which inserts chunks with a NULL embedding, so they
 * are NOT retrievable — every existing chat spec therefore stubs the AI
 * boundary), this seeder runs the REAL `DocumentIngestor::ingest()` path
 * inline. The Playwright webServer runs with AI_PROVIDER=fake /
 * AI_EMBEDDINGS_PROVIDER=fake, so the chunk is embedded with the
 * FakeProvider's constant vector. At chat time the same fake provider
 * embeds the query to the same vector → cosine 1.0 → the chunk is always
 * retrieved → MessageStreamController always emits a real `source-url`
 * citation frame + a streamed answer for the browser/SDK to validate.
 *
 * It runs via `Artisan::call('db:seed', ['--class' => …])` from
 * TestingController::seed (in-process — shares the fake-provider app), so
 * the ingest is synchronous and does NOT depend on a queue worker (CI runs
 * QUEUE_CONNECTION=database with no worker).
 */
final class E2eStreamSeeder extends Seeder
{
    public function run(): void
    {
        // Match the tenant the DemoSeeder users belong to so the chat
        // turn (authenticated as admin@demo.local) retrieves this doc.
        app(TenantContext::class)->set('default');

        app(DocumentIngestor::class)->ingest(
            'hr-portal',
            new SourceDocument(
                sourcePath: 'policies/e2e-remote-work.md',
                mimeType: 'text/markdown',
                bytes: "# Remote Work Policy\n\nEmployees may work remotely up to 3 days per week with manager approval.",
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: [],
            ),
            'e2e-remote-work',
        );
    }
}
