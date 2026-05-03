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

        // Accept either form: explicit-key `config('kb.pii_redactor.enabled')`
        // OR whole-block `config('kb.pii_redactor')` followed by array access.
        // Same lenience as the middleware-test: the runtime contract is
        // identical, only the call shape differs.
        $masterSwitchPresent = str_contains($body, "config('kb.pii_redactor.enabled'")
            || str_contains($body, "config('kb.pii_redactor')");
        $this->assertTrue(
            $masterSwitchPresent,
            'EmbeddingCacheService MUST read the master switch before pre-redact.',
        );

        $embeddingsKnobPresent = str_contains($body, "config('kb.pii_redactor.redact_before_embeddings'")
            || str_contains($body, "'redact_before_embeddings'");
        $this->assertTrue(
            $embeddingsKnobPresent,
            'EmbeddingCacheService MUST consume the redact_before_embeddings knob.',
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

        $masterSwitchPresent = str_contains($body, "config('kb.pii_redactor.enabled'")
            || str_contains($body, "config('kb.pii_redactor')");
        $this->assertTrue(
            $masterSwitchPresent,
            'AiInsightsService MUST read the master switch.',
        );

        $insightsKnobPresent = str_contains($body, "config('kb.pii_redactor.redact_insights_snippets'")
            || str_contains($body, "'redact_insights_snippets'");
        $this->assertTrue(
            $insightsKnobPresent,
            'AiInsightsService MUST consume the redact_insights_snippets knob.',
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
        // call sites MUST scope explicitly via `forTenant(...)`. To
        // catch a regression that drops the scope from THESE specific
        // methods (while leaving an unrelated `forTenant()` call
        // somewhere else in the file), we extract each method's body
        // and assert on the slice — not the whole file.
        $insights = $this->fileAt(self::AI_INSIGHTS_SERVICE);
        $coverageGapsBody = $this->methodBody($insights, 'coverageGaps');
        $this->assertStringContainsString(
            'forTenant(',
            $coverageGapsBody,
            'AiInsightsService::coverageGaps() MUST scope its ChatLog read to '
            .'the active tenant — R30. The `forTenant(` call must live INSIDE '
            .'this method body, not anywhere else in the file.',
        );

        $logViewer = $this->fileAt(self::LOG_VIEWER_CONTROLLER);
        $detokenizeBody = $this->methodBody($logViewer, 'chatDetokenize');
        $this->assertStringContainsString(
            'forTenant(',
            $detokenizeBody,
            'LogViewerController::chatDetokenize() MUST scope its ChatLog '
            .'lookup to the active tenant — R30. The `forTenant(` call must '
            .'live INSIDE this method body, not anywhere else in the file.',
        );
    }

    public function test_detokenize_endpoint_is_audited_via_admin_command_audit(): void
    {
        $body = $this->fileAt(self::LOG_VIEWER_CONTROLLER);
        $detokenizeBody = $this->methodBody($body, 'chatDetokenize');

        // Pin the actual create() invocation, not just the import.
        // A regression that removes the `AdminCommandAudit::query()
        // ->create([...])` lines but leaves the `use` statement +
        // the `'pii.detokenize'` config fallback in place would NOT
        // change the import surface — but it would silently disable
        // the forensic trail, which is the load-bearing invariant.
        $this->assertMatchesRegularExpression(
            '/AdminCommandAudit::query\(\)\s*->create\(/',
            $detokenizeBody,
            'chatDetokenize() MUST call AdminCommandAudit::query()->create([...]) '
            .'so unmask attempts (200 + 403) are forensically traceable. '
            .'A bare `use` statement is not enough — the literal create() '
            .'call must live inside the method body.',
        );

        // Double-check: the `command` tag stamped on the audit row
        // must be exactly `pii.detokenize` so dashboards filtering
        // on that string keep working.
        $this->assertMatchesRegularExpression(
            "/'command'\s*=>\s*'pii\.detokenize'/",
            $detokenizeBody,
            'chatDetokenize() MUST stamp the audit row with '
            ."`'command' => 'pii.detokenize'` so admin dashboards filtering "
            .'by command name keep matching.',
        );
    }

    /**
     * Extract the body of a named method from a PHP source file. Used
     * to scope architecture-test assertions to a specific method
     * rather than the whole file — so a regression that drops a
     * `forTenant()` from `coverageGaps()` (but leaves one elsewhere
     * in the file) still fails the test.
     *
     * The slice runs from the `function <name>(` line through to the
     * matching closing brace, found by depth counting. Returns an
     * empty string if the method isn't present (the caller's
     * `assertStringContainsString` then surfaces a clean failure).
     */
    private function methodBody(string $source, string $methodName): string
    {
        // Match `function <name>(` then capture until the closing `}`
        // that pairs with the method's opening `{`.
        $pattern = '/function\s+'.preg_quote($methodName, '/').'\s*\(/';
        if (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $offset = (int) $m[0][1];
        $openBrace = strpos($source, '{', $offset);
        if ($openBrace === false) {
            return '';
        }

        $depth = 1;
        $i = $openBrace + 1;
        $len = strlen($source);
        while ($i < $len && $depth > 0) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
            }
            $i++;
        }

        return substr($source, $offset, $i - $offset);
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
