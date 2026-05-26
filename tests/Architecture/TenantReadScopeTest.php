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
    /** Basenames of models that expose the forTenant() scope (BelongsToTenant). */
    private const TENANT_AWARE_MODELS = [
        'ChatLog', 'KbCanonicalAudit', 'KbTag', 'AdminCommandAudit',
        'ComplianceReport', 'KnowledgeDocument', 'KnowledgeChunk', 'KbNode',
        'KbEdge', 'ProjectMembership', 'Conversation', 'Message',
        'AdminInsightsSnapshot', 'ChatFilterPreset', 'KbCollection',
        'KbCollectionMember',
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
        $readPattern = '/(?:\\\\App\\\\Models\\\\)?\b('.$modelAlternation.')::(query|where|find|findOrFail|whereNotNull|distinct|pluck|withTrashed|onlyTrashed|withoutGlobalScopes)\s*\(/';

        $violations = [];

        foreach ($this->phpFiles($roots) as $file) {
            $code = (string) file_get_contents($file);
            if (preg_match($readPattern, $code) !== 1) {
                continue;
            }
            // Accepted scope markers: the forTenant() scope OR an explicit
            // `where('tenant_id', ...)` filter (R30 permits both). Require the
            // marker in a real WHERE/scope form — a bare `'tenant_id'`
            // substring (e.g. in a comment or array key) is NOT enough
            // (Copilot: avoid false-negatives).
            $scoped = str_contains($code, 'forTenant(')
                || preg_match('/where\(\s*[\'"]tenant_id[\'"]/', $code) === 1;
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
