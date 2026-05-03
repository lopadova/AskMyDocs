<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * v4.1/W4.1.E — closure architecture test pinning the four observable
 * touch-points of the W4.1 PII redactor integration.
 *
 * If any of the integration call-sites is removed, renamed, or
 * accidentally relocated to a forbidden surface, this test fails
 * BEFORE a regression PR can ship. Complementary to the binding-scope
 * test `PiiRedactionMiddlewareScopeTest` (which pins the routing
 * surface); this one pins the SERVICE / CONTROLLER call-site
 * surface plus the config-gate names + their documented defaults.
 *
 * The four touch-points enumerated:
 *
 *   1. `App\Http\Middleware\RedactChatPii` — gates
 *      `kb.pii_redactor.enabled` AND
 *      `kb.pii_redactor.persist_chat_redacted`.
 *   2. `App\Services\Kb\EmbeddingCacheService::generate()` — gates
 *      `kb.pii_redactor.enabled` AND
 *      `kb.pii_redactor.redact_before_embeddings`.
 *   3. `App\Services\Admin\AiInsightsService::coverageGaps()` — gates
 *      `kb.pii_redactor.enabled` AND
 *      `kb.pii_redactor.redact_insights_snippets`.
 *   4. `App\Http\Controllers\Api\Admin\LogViewerController::chatDetokenize()`
 *      — gates the Spatie permission named in
 *      `kb.pii_redactor.detokenize_permission` (default
 *      `pii.detokenize`).
 *
 * Every touch-point also enforces R30 cross-tenant isolation when it
 * reads from a tenant-aware table — checked here at the source-text
 * level via `forTenant(` references inside the `chat_logs` query
 * sites.
 */
final class PiiRedactorIntegrationScopeTest extends TestCase
{
    private const REDACT_CHAT_PII = 'app/Http/Middleware/RedactChatPii.php';

    private const EMBEDDING_CACHE_SERVICE = 'app/Services/Kb/EmbeddingCacheService.php';

    private const AI_INSIGHTS_SERVICE = 'app/Services/Admin/AiInsightsService.php';

    private const LOG_VIEWER_CONTROLLER = 'app/Http/Controllers/Api/Admin/LogViewerController.php';

    public function test_redact_chat_pii_middleware_reads_both_master_switch_and_persist_knob(): void
    {
        $body = $this->fileAt(self::REDACT_CHAT_PII);

        // The middleware loads the whole `kb.pii_redactor` block once
        // and then array-accesses the individual gates — which is fine
        // for performance (single config() lookup) but means the
        // architecture check has to match either shape.
        $masterSwitchPresent = str_contains($body, "config('kb.pii_redactor.enabled'")
            || str_contains($body, "config('kb.pii_redactor')");
        $this->assertTrue(
            $masterSwitchPresent,
            'RedactChatPii middleware MUST read the master switch '
            .'(either via `config(\'kb.pii_redactor.enabled\')` '
            .'or `config(\'kb.pii_redactor\')` array access).',
        );

        $this->assertStringContainsString(
            'persist_chat_redacted',
            $body,
            'RedactChatPii middleware MUST consume the '
            .'persist_chat_redacted knob.',
        );
    }

    public function test_embedding_cache_pre_redact_reads_both_master_switch_and_embeddings_knob(): void
    {
        $body = $this->fileAt(self::EMBEDDING_CACHE_SERVICE);

        $this->assertStringContainsString(
            "config('kb.pii_redactor.enabled'",
            $body,
            'EmbeddingCacheService MUST read the master switch before pre-redact.',
        );
        $this->assertStringContainsString(
            "config('kb.pii_redactor.redact_before_embeddings'",
            $body,
            'EmbeddingCacheService MUST read the redact_before_embeddings knob.',
        );
        $this->assertStringContainsString(
            'MaskStrategy',
            $body,
            'EmbeddingCacheService MUST use MaskStrategy (NOT Tokenise) — '
            .'embeddings are one-way and mask is stable for cache hit-rate.',
        );
    }

    public function test_insights_service_reads_both_master_switch_and_insights_knob(): void
    {
        $body = $this->fileAt(self::AI_INSIGHTS_SERVICE);

        $this->assertStringContainsString(
            "config('kb.pii_redactor.enabled'",
            $body,
            'AiInsightsService MUST read the master switch.',
        );
        $this->assertStringContainsString(
            "config('kb.pii_redactor.redact_insights_snippets'",
            $body,
            'AiInsightsService MUST read the redact_insights_snippets knob.',
        );
    }

    public function test_log_viewer_detokenize_reads_config_driven_permission_name(): void
    {
        $body = $this->fileAt(self::LOG_VIEWER_CONTROLLER);

        $this->assertStringContainsString(
            "config('kb.pii_redactor.detokenize_permission'",
            $body,
            'chatDetokenize() MUST resolve the Spatie permission name from config '
            .'(NOT a literal) so hosts can override it.',
        );
        $this->assertStringContainsString(
            "'pii.detokenize'",
            $body,
            'The default permission name `pii.detokenize` MUST appear as the '
            .'config() fallback so the integration works out of the box.',
        );
    }

    public function test_chat_log_reads_at_redactor_call_sites_are_tenant_scoped(): void
    {
        // R30 — chat_logs is tenant-aware (BelongsToTenant). Both
        // call sites that read from it inside the W4.1 surface MUST
        // scope explicitly via `forTenant(...)`. The string match is
        // deliberately textual: a regression that drops the call
        // would compile fine but leak across tenants — this test
        // is the architectural guard.
        $insights = $this->fileAt(self::AI_INSIGHTS_SERVICE);
        $this->assertStringContainsString(
            'forTenant(',
            $insights,
            'AiInsightsService::coverageGaps() MUST scope its ChatLog read to '
            .'the active tenant — R30.',
        );

        $logViewer = $this->fileAt(self::LOG_VIEWER_CONTROLLER);
        $this->assertStringContainsString(
            'forTenant(',
            $logViewer,
            'LogViewerController::chatDetokenize() MUST scope its ChatLog read '
            .'to the active tenant — R30.',
        );
    }

    public function test_detokenize_endpoint_is_audited_via_admin_command_audit(): void
    {
        $body = $this->fileAt(self::LOG_VIEWER_CONTROLLER);

        $this->assertStringContainsString(
            'AdminCommandAudit',
            $body,
            'chatDetokenize() MUST write to admin_command_audit so unmask '
            .'attempts are forensically traceable.',
        );
        $this->assertStringContainsString(
            "'pii.detokenize'",
            $body,
            'admin_command_audit row MUST tag `command = pii.detokenize` for '
            .'filtering / dashboards.',
        );
    }

    private function fileAt(string $relative): string
    {
        // `base_path()` resolves to Testbench's stub Laravel app
        // under `vendor/orchestra/testbench-core/laravel/`, not the
        // project root — so we navigate from `tests/Architecture/`
        // up two levels instead. Same pattern as
        // PiiRedactionMiddlewareScopeTest's `routes/web.php` lookup.
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$relative;
        $this->assertFileExists(
            $path,
            "Architecture invariant: `{$relative}` is required for the W4.1 "
            .'PII redactor integration. If you renamed or moved it, update this '
            .'architecture test in the same PR so the new location is pinned.',
        );

        $contents = file_get_contents($path);
        $this->assertNotFalse(
            $contents,
            "Could not read `{$relative}` — file system or permission issue.",
        );

        return (string) $contents;
    }
}
