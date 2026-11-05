<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\KbWikiImportRateLimitedException;
use App\Models\KbWikiImportCandidate;
use App\Models\User;
use App\Services\Kb\Import\KbWikiImportService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * v8.38/W4c (ADR 0032 §10/§11) — propose-only MCP surface of the import
 * round-trip. Single-DOCUMENT, same shape as
 * `POST /api/admin/kb/imports`: an MCP tool call has no server-side
 * filesystem folder to walk (that capability exists only on
 * `kb:import-wiki`, the CLI surface) — the caller submits one page's
 * markdown, unchanged from a `wiki/*.md` file, and this tool diffs it
 * against the server and proposes a promotion candidate exactly like
 * {@see \App\Services\Kb\Import\KbWikiImportService::importDocument()}
 * does for the CLI's per-file loop.
 *
 * A write, so NOT annotated `#[IsReadOnly]` — carries the SAME
 * mutating-tool control set as `KbProposeTextCorrectionTool` (ADR 0032
 * §11): authorization before validation (the principal-binding fix this
 * cycle also closed — `EnforceMcpScope` resolves the token's `created_by`
 * user before any tool runs), an idempotency key
 * ({@see \App\Models\KbWikiImportCandidate::idempotencyKeyFor()}), an
 * audit row (the SAME blanket `EnforceMcpScope::auditInvocation()` gate
 * every MCP tool call already writes to `kb_canonical_audit`), and a
 * per-actor rate cap (`kb.wiki_export.import_candidates_per_hour`, enforced
 * inside the shared service core, identical for CLI/HTTP/MCP).
 *
 * Requires the write scope (`mcp:tools:write`), same normalization gap the
 * middleware's own docblock warns about — `kbimportwikitool` is explicitly
 * listed in `EnforceMcpScope::PROPOSE_TOOL_NAMES` so a call needs only
 * `mcp:tools:propose`, not the stronger `mcp:tools:write`: this tool never
 * writes the corpus, only proposes, the same distinction that list already
 * draws for `KbProposeTextCorrectionTool`.
 */
#[Description('Propose a knowledge-base page (Markdown with slug/type/status frontmatter, as exported under wiki/*.md by kb:export-wiki) as a promotion candidate. Writes NOTHING to the corpus directly — starts a paused promotion flow run awaiting human approval, exactly like POST /api/kb/promotion/promote. Diffs against the server\'s current version first: unchanged content is reported as such rather than proposed again. Rate-limited per actor; idempotent (a repeated identical proposal resolves to the SAME flow run rather than starting a second one — the replay response carries no new approval token, since the original is single-use and available only once). Requires mcp:tools:propose scope. Answers {status: "disabled"} when Wiki Export is off (KB_WIKI_EXPORT_ENABLED — the same flag gates both export and import).')]
#[IsIdempotent]
class KbImportWikiTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('The project_key this page belongs to.')
                ->required(),
            'markdown' => $schema->string()
                ->description('The full Markdown content, including its YAML frontmatter block (slug/type/status required).')
                ->required(),
        ];
    }

    public function handle(Request $request, KbWikiImportService $importer, TenantContext $tenants): Response
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return Response::error('No MCP principal bound to this request.');
        }

        $projectKey = trim((string) $request->get('project_key', ''));
        if ($projectKey === '') {
            return Response::error('project_key is required.');
        }

        $markdown = (string) $request->get('markdown', '');
        if (trim($markdown) === '') {
            return Response::error('markdown must not be empty.');
        }

        try {
            $result = $importer->importDocument($tenants->current(), $projectKey, $markdown, $user, KbWikiImportCandidate::SOURCE_MCP);
        } catch (KbWikiImportRateLimitedException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json($result);
    }
}
