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
        // Match `Model::query(` / `Model::where(` / `Model::find(` /
        // `Model::findOrFail(` for a tenant-aware model.
        $readPattern = '/\b('.$modelAlternation.')::(query|where|find|findOrFail|whereNotNull|distinct|pluck)\s*\(/';

        $violations = [];

        foreach ($this->phpFiles($roots) as $file) {
            $code = (string) file_get_contents($file);
            if (preg_match($readPattern, $code) !== 1) {
                continue;
            }
            // Accepted scope markers: the forTenant() scope OR an explicit
            // `where('tenant_id', ...)` filter (R30 permits both). The
            // latter covers services like ComplianceReportGenerator that
            // scope by a passed tenant id under withoutGlobalScopes().
            if (str_contains($code, 'forTenant(') || str_contains($code, "'tenant_id'")) {
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
