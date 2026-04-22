<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Jobs\IngestDocumentJob;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Services\Kb\Canonical\CanonicalWriter;
use App\Services\Kb\Canonical\PromotionSuggestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Promotion pipeline entry points — human-gated (see ADR 0003).
 *
 *   POST /api/kb/promotion/suggest    → LLM extracts candidates. Writes nothing.
 *   POST /api/kb/promotion/candidates → validate a draft against the schema.
 *                                       Writes nothing. Returns errors.
 *   POST /api/kb/promotion/promote    → writes canonical markdown to the KB
 *                                       disk, dispatches ingest. HTTP 202.
 *
 * Claude skills MUST stop at `candidates` (or `suggest`). Only operators
 * and the explicit `promote` call commit to canonical storage.
 */
class KbPromotionController extends Controller
{
    public function suggest(Request $request, PromotionSuggestService $svc): JsonResponse
    {
        if (! (bool) config('kb.promotion.enabled', true)) {
            return response()->json(['error' => 'promotion_disabled'], 503);
        }

        $validated = $request->validate([
            'transcript' => ['required', 'string', 'max:50000'],
            'project_key' => ['nullable', 'string', 'max:120'],
            'existing_slugs' => ['nullable', 'array'],
            'existing_slugs.*' => ['string', 'max:120'],
        ]);

        $result = $svc->suggest(
            transcript: $validated['transcript'],
            projectKey: $validated['project_key'] ?? null,
            context: ['existing_slugs' => $validated['existing_slugs'] ?? []],
        );

        return response()->json($result);
    }

    public function candidates(Request $request, CanonicalParser $parser): JsonResponse
    {
        $validated = $request->validate([
            'markdown' => ['required', 'string', 'max:200000'],
        ]);

        $parsed = $parser->parse($validated['markdown']);
        if ($parsed === null) {
            return response()->json([
                'valid' => false,
                'errors' => ['frontmatter' => ['No YAML frontmatter block detected at the top of the document.']],
            ], 422);
        }

        $result = $parser->validate($parsed);
        if (! $result->valid) {
            return response()->json([
                'valid' => false,
                'errors' => $result->errors,
            ], 422);
        }

        return response()->json([
            'valid' => true,
            'parsed' => [
                'doc_id' => $parsed->docId,
                'slug' => $parsed->slug,
                'type' => $parsed->type?->value,
                'status' => $parsed->status?->value,
                'title_line' => $this->firstHeading($parsed->body),
                'related_slugs' => $parsed->relatedSlugs,
                'supersedes_slugs' => $parsed->supersedesSlugs,
                'tags' => $parsed->tags,
                'owners' => $parsed->owners,
            ],
        ]);
    }

    public function promote(
        Request $request,
        CanonicalParser $parser,
        CanonicalWriter $writer,
    ): JsonResponse {
        if (! (bool) config('kb.promotion.enabled', true)) {
            return response()->json(['error' => 'promotion_disabled'], 503);
        }

        $validated = $request->validate([
            'project_key' => ['required', 'string', 'max:120'],
            'markdown' => ['required', 'string', 'max:200000'],
            'title' => ['nullable', 'string', 'max:500'],
        ]);

        $parsed = $parser->parse($validated['markdown']);
        if ($parsed === null) {
            return response()->json(['error' => 'no_frontmatter'], 422);
        }

        $validation = $parser->validate($parsed);
        if (! $validation->valid) {
            return response()->json([
                'error' => 'invalid_frontmatter',
                'errors' => $validation->errors,
            ], 422);
        }

        try {
            $relativePath = $writer->write($parsed, $validated['markdown']);
        } catch (\RuntimeException $e) {
            // Never leak the disk name / full storage path to API clients —
            // that would expose internal layout. Log the full exception
            // server-side and surface a correlation id the client can
            // report to support without revealing infrastructure details.
            $correlationId = bin2hex(random_bytes(8));
            Log::error('KbPromotion: write failed', [
                'correlation_id' => $correlationId,
                'project_key' => $validated['project_key'],
                'slug' => $parsed->slug,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'write_failed',
                'message' => 'Failed to write promoted document.',
                'correlation_id' => $correlationId,
            ], 500);
        }

        $title = $validated['title'] ?? ($this->firstHeading($parsed->body) ?? ((string) $parsed->slug));
        IngestDocumentJob::dispatch(
            projectKey: $validated['project_key'],
            relativePath: $relativePath,
            disk: (string) config('kb.sources.disk', 'kb'),
            title: $title,
            metadata: [
                'disk' => (string) config('kb.sources.disk', 'kb'),
                'prefix' => (string) config('kb.sources.path_prefix', ''),
                'promotion_source' => 'api',
            ],
        );

        return response()->json([
            'status' => 'accepted',
            'path' => $relativePath,
            'doc_id' => $parsed->docId,
            'slug' => $parsed->slug,
        ], 202);
    }

    private function firstHeading(string $body): ?string
    {
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            if (preg_match('/^\s*#\s+(.+?)\s*$/', $line, $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }
}
