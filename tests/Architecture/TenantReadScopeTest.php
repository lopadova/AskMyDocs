<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * R30 systemic guard — every controller / service file that issues a query
 * against a tenant-aware model MUST also scope it with forTenant().
 *
 * BelongsToTenant adds NO global read scope (write-side auto-fill only), so
 * a tenant-aware `Model::query()` / `::where()` / `::find()` without an
 * accompanying `forTenant(` is a cross-tenant leak waiting to happen — the
 * exact class of bug the 2026-05 deep review found across five controllers.
 *
 * This is a file-level heuristic (presence of forTenant somewhere in the
 * file), deliberately conservative to avoid flakiness. The ALLOWLIST holds
 * the handful of files that legitimately query a tenant-aware model without
 * forTenant — each with a documented reason. A NEW unscoped file fails the
 * build and must either add forTenant() or justify an allowlist entry.
 */
final class TenantReadScopeTest extends TestCase
{
    /**
     * EVERY model that uses the BelongsToTenant trait (i.e. exposes the
     * forTenant() scope). Keep this in lockstep with `grep -rl
     * "use BelongsToTenant" app/Models` — the completeness test below
     * asserts they match, so a new tenant-aware model can't silently escape
     * this guard (Copilot caught ChatLogProvenance + 16 others missing).
     */
    private const TENANT_AWARE_MODELS = [
        'AdminCommandAudit', 'AdminCommandNonce', 'AdminInsightsSnapshot',
        'ChatFilterPreset', 'ChatLog', 'ChatLogProvenance', 'ComplianceReport',
        'Conversation', 'HiddenWorkflow', 'KbAnalysisSetting', 'KbCanonicalAudit',
        'KbCanonicalHealthSnapshot', 'KbChunkFeedback', 'KbCollection',
        'KbCollectionMember', 'KbContributionEvent', 'KbDocAnalysis', 'KbDocAnalysisApplication', 'KbEdge', 'KbEngagementSnapshot', 'KbNode', 'KbSearchFailure', 'KbSynonym', 'KbTag', 'KbWikiIndex', 'KnowledgeChunk',
        'KnowledgeDocument', 'KnowledgeDocumentAcl', 'McpServer',
        'McpTenantToken', 'McpToolCallAudit', 'Message', 'NotificationDigest',
        'NotificationEvent', 'NotificationPreference', 'NotificationTenantDefault',
        'ProjectMembership', 'TabularCell', 'TabularReview',
        'TenantSchedulerOverride', 'WidgetKey', 'WidgetSession',
        'WidgetSessionStep', 'WidgetSessionToken', 'Workflow',
    ];

    /**
     * Files that query a tenant-aware model WITHOUT forTenant for a
     * documented, legitimate reason. Keyed by repo-relative path.
     *
     * @var array<string, string>
     */
    private const ALLOWLIST = [
        // Cross-tenant by design: the embedding cache + the deleter's
        // legacy unscoped sweep both log/justify the cross-tenant access
        // inline, and DocumentDeleter applies forTenant CONDITIONALLY
        // (it contains forTenant, so it would pass anyway — listed for clarity).
        //
        // Global retention/maintenance sweep — deletes rows older than a
        // retention window across ALL tenants by design (same posture as
        // kb:prune-deleted / chat-log:prune; the scheduler runs it
        // instance-wide, not per-tenant). NOT a user-facing cross-tenant read.
        'app/Console/Commands/PruneAdminCommandAuditCommand.php' => 'Global audit-retention prune; intentionally instance-wide.',
        'app/Console/Commands/PruneAdminCommandNoncesCommand.php' => 'Global expired-nonce retention prune; intentionally instance-wide.',

        // Maintenance commands that ENUMERATE tenants (the only tenant-aware
        // read here is the distinct-tenant_id discovery query) then set the
        // TenantContext per tenant and delegate the actual scoped work to a
        // Flow / DocumentDeleter that IS tenant-scoped. The enumeration must
        // be cross-tenant by definition.
        'app/Console/Commands/KbRebuildGraphCommand.php' => 'Tenant enumeration → per-tenant RebuildGraphFlow (scoped in the flow).',
        'app/Console/Commands/PruneChatLogsCommand.php' => 'Tenant enumeration → per-tenant prune flow (scoped downstream).',
        'app/Console/Commands/PruneDeletedDocumentsCommand.php' => 'Tenant enumeration → per-tenant DocumentDeleter (scoped downstream).',
        'app/Console/Commands/PruneOrphanFilesCommand.php' => 'Orphan-file maintenance sweep; reconciles disk vs DB by design.',

        // User→tenant DISCOVERY for DSAR/compliance: its whole job is to find
        // EVERY tenant a user touched (memberships, conversations, chat logs,
        // connectors). Inherently cross-tenant — the inverse of a leak.
        'app/Compliance/UserTenantResolver.php' => 'Resolves the full set of tenants a user belongs to (DSAR); cross-tenant by design.',

        // CLI command that resolves a WidgetKey by public_key (globally unique);
        // no HTTP request context → tenant is derived from the key itself, not
        // from TenantContext. This is the same posture as the HTTP ResolveWidgetKey
        // middleware which does WidgetKey::query()->where('public_key', …).
        'app/Console/Commands/WidgetEmitSecretCommand.php' => 'Looks up WidgetKey by globally-unique public_key; no tenant context in CLI.',
    ];

    public function test_tenant_aware_reads_are_scoped(): void
    {
        $roots = [
            dirname(__DIR__, 2).'/app/Http/Controllers',
            dirname(__DIR__, 2).'/app/Services',
            // Audit#3 — these were NOT scanned originally and harboured the
            // MCP-tool / insights-command / provenance cross-tenant leaks.
            dirname(__DIR__, 2).'/app/Mcp',
            dirname(__DIR__, 2).'/app/Console',
            dirname(__DIR__, 2).'/app/Compliance',
        ];

        $modelAlternation = implode('|', self::TENANT_AWARE_MODELS);
        // Match a static read entry point on a tenant-aware model, including
        // the soft-delete / global-scope-bypass entry points
        // (withTrashed/onlyTrashed/withoutGlobalScopes) that also START a
        // query — Copilot flagged these as previously-missed openings.
        // `\\?` tolerates a leading backslash on a fully-qualified
        // `\App\Models\X::query(` reference.
        // `with` is included because `Model::with(...)->findOrFail(...)` is a
        // read entry point too — its omission let KbReadDocumentTool /
        // KbReadChunkTool ship an unscoped cross-tenant read past this guard
        // (security review v8.8).
        $readPattern = '/(?:\\\\App\\\\Models\\\\)?\b('.$modelAlternation.')::(query|with|where|find|findOrFail|whereNotNull|distinct|pluck|withTrashed|onlyTrashed|withoutGlobalScopes)\s*\(/';

        $violations = [];

        foreach ($this->phpFiles($roots) as $file) {
            // Strip // # and /* */ comments BEFORE any detection (Copilot:
            // a `forTenant(` or `'tenant_id' =>` marker that lived only in a
            // comment used to falsely satisfy the scope check, and a
            // `Model::query(` in a commented-out line used to falsely trip
            // the read check). Scanning comment-free code makes both the
            // read pattern AND the scope markers reflect real executable
            // statements only. String literals are intentionally KEPT — the
            // `'tenant_id' =>` write-stamp marker and `where('tenant_id'`
            // read filter are themselves string literals.
            $code = $this->stripComments((string) file_get_contents($file));
            if (preg_match($readPattern, $code) !== 1) {
                continue;
            }
            // Accepted scope markers (R30 permits all three):
            //  - forTenant(...) scope;
            //  - an explicit where('tenant_id', ...) read filter;
            //  - a `'tenant_id' => ...` assignment — covers tenant-STAMPED
            //    writes (create/insert/updateOrCreate match keys), e.g. the
            //    MCP tool-call audit + token-level provenance inserts, where
            //    the matched `::query()->create/insert` is a write, not a
            //    read.
            $scoped = str_contains($code, 'forTenant(')
                || preg_match('/where\(\s*[\'"]tenant_id[\'"]/', $code) === 1
                || preg_match('/[\'"]tenant_id[\'"]\s*=>/', $code) === 1;
            if ($scoped) {
                continue;
            }
            $rel = $this->relative($file);
            if (array_key_exists($rel, self::ALLOWLIST)) {
                continue;
            }
            $violations[] = $rel;
        }

        $this->assertSame(
            [],
            $violations,
            "These files query a tenant-aware model without forTenant() (R30). "
            ."Add ->forTenant(\$ctx->current()) or justify an ALLOWLIST entry:\n  - "
            .implode("\n  - ", $violations),
        );
    }

    public function test_tenant_aware_models_list_is_complete(): void
    {
        // The guard above is only as good as TENANT_AWARE_MODELS. Assert it
        // matches EVERY model using BelongsToTenant, so a new tenant-aware
        // model can't silently escape the scope check (Copilot: the list was
        // missing ChatLogProvenance + 16 others).
        $modelsDir = dirname(__DIR__, 2).'/app/Models';
        $actual = [];
        foreach (glob($modelsDir.'/*.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            if (preg_match('/^\s*use [\w\\\\]*BelongsToTenant;/m', $code) === 1) {
                $actual[] = basename($file, '.php');
            }
        }
        sort($actual);

        $declared = self::TENANT_AWARE_MODELS;
        sort($declared);

        $this->assertSame(
            $actual,
            $declared,
            'TENANT_AWARE_MODELS is out of sync with the BelongsToTenant models in app/Models. '
            ."Missing from the list: ".implode(', ', array_diff($actual, $declared)).'. '
            .'Extra in the list: '.implode(', ', array_diff($declared, $actual)).'.',
        );
    }

    /**
     * Return the source with all comments (// # /* *​/ and doc-blocks)
     * removed, so the read + scope-marker detection only sees executable
     * code. String literals are preserved on purpose.
     */
    private function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Keep a newline so line-based structure (and the `m`
                    // regex anchors elsewhere) is not disturbed.
                    $out .= "\n";

                    continue;
                }
                $out .= $token[1];

                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * @param  list<string>  $roots
     * @return iterable<string>
     */
    private function phpFiles(array $roots): iterable
    {
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    yield $entry->getPathname();
                }
            }
        }
    }

    private function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', dirname(__DIR__, 2)).'/';

        return str_replace($base, '', $path);
    }
}
