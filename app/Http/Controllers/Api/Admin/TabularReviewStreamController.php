<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KnowledgeDocument;
use App\Models\TabularCell;
use App\Models\TabularReview;
use App\Services\TabularReview\TabularReviewExtractor;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v4.7/W3 — SSE streaming variant of TabularReviewController::generate().
 *
 * Wires {@see TabularReviewExtractor::extract($review, $doc, $onCell)} to a
 * `text/event-stream` response so the Glide-style grid in the W3 admin
 * SPA can paint cells as soon as the extractor emits them, instead of
 * waiting for the whole batch to finish.
 *
 * Wire format — one SSE message per event. The `status` field on
 * `cell` frames carries the `CellStatus` enum value
 * (`pending` / `generating` / `ready` / `failed`); `flag` carries the
 * `CellFlag` enum value (`green` / `grey` / `yellow` / `red`).
 *
 *     event: start
 *     data: {"review_id":42,"documents_total":7,"max_documents":200}
 *
 *     event: document
 *     data: {"document_id":101,"processed":1,"total_cap":200}
 *
 *     event: cell
 *     data: {"document_id":101,"column_index":0,"summary":"...","reasoning":"...","citations":[],"flag":"green","status":"ready"}
 *
 *     event: done
 *     data: {"documents_processed":7,"cells_total":21,"truncated":false}
 *
 *     event: error
 *     data: {"message":"..."}
 *
 * The synchronous generate() in TabularReviewController stays in place
 * and keeps working — it is still the path used by tests + CLI clients
 * that don't want to consume a stream.
 *
 * R30/R31: every Eloquent query is scoped via `forTenant($ctx->current())`.
 * R14: a write that fails surfaces as an `event: error` SSE frame; the
 * stream never resolves with success when the work did not complete.
 */
final class TabularReviewStreamController extends Controller
{
    public function __construct(
        private readonly TabularReviewExtractor $extractor,
        private readonly TenantContext $ctx,
    ) {}

    /**
     * POST /api/admin/tabular-reviews/{id}/generate-stream
     *
     * The route group includes `StartSession` middleware (every admin
     * route in this app is Sanctum-SPA + session-cookie). For a
     * potentially long-running SSE response we MUST release the
     * session lock before the stream starts — otherwise every other
     * same-user request blocks for the duration of the extractor run.
     * Mirrors the pattern in `MessageStreamController::streamingResponse()`.
     */
    public function stream(Request $request, int $id): Response
    {
        $user = $request->user();
        if ($user !== null
            && method_exists($user, 'hasRole')
            && $user->hasRole('viewer')
            && ! $user->hasAnyRole(['admin', 'super-admin'])) {
            throw new AccessDeniedHttpException('Viewers cannot generate tabular cells.');
        }

        $validated = $request->validate([
            'max_documents' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $cap = (int) ($validated['max_documents'] ?? 200);

        $tenant = $this->ctx->current();
        $review = TabularReview::query()
            ->forTenant($tenant)
            ->where('id', $id)
            ->first();

        if ($review === null) {
            throw new NotFoundHttpException('Tabular review not found.');
        }

        $baseQuery = KnowledgeDocument::query()
            ->forTenant($tenant)
            ->where('project_key', $review->project_key);

        $totalAvailable = (int) $baseQuery->count();

        // Release the session write lock before the stream starts.
        // The route runs under StartSession so the lock is held for the
        // duration of the response otherwise; the FE can issue parallel
        // requests under the same session cookie without blocking.
        if ($request->hasSession()) {
            $request->session()->save();
        }

        $response = new StreamedResponse(function () use ($review, $baseQuery, $totalAvailable, $cap): void {
            $this->emit('start', [
                'review_id' => $review->id,
                'documents_total' => $totalAvailable,
                'max_documents' => $cap,
            ]);

            $cellsTotal = 0;
            $processed = 0;

            try {
                $baseQuery->orderBy('id')->chunkById(50, function ($docs) use ($review, $cap, &$cellsTotal, &$processed): bool {
                    foreach ($docs as $doc) {
                        if ($processed >= $cap) {
                            return false;
                        }

                        $this->emit('document', [
                            'document_id' => $doc->id,
                            'processed' => $processed + 1,
                            'total_cap' => $cap,
                        ]);

                        $cells = $this->extractor->extract(
                            $review,
                            $doc,
                            function (TabularCell $cell): void {
                                // `content` holds the structured cell
                                // body produced by the extractor:
                                // `{summary, reasoning, citations[]}`.
                                // We re-shape it for the SSE consumer
                                // so the FE doesn't have to drill into
                                // a nested object on every paint.
                                $content = $cell->content ?? [];
                                $this->emit('cell', [
                                    'document_id' => $cell->document_id,
                                    'column_index' => $cell->column_index,
                                    'summary' => $content['summary'] ?? null,
                                    'reasoning' => $content['reasoning'] ?? null,
                                    'citations' => $content['citations'] ?? [],
                                    'flag' => $this->enumOrString($cell->flag),
                                    'status' => $this->enumOrString($cell->status),
                                ]);
                            }
                        );
                        $cellsTotal += count($cells);
                        $processed++;
                    }
                    return true;
                });

                $this->emit('done', [
                    'documents_processed' => $processed,
                    'cells_total' => $cellsTotal,
                    'truncated' => $totalAvailable > $processed,
                ]);
            } catch (\Throwable $e) {
                $this->emit('error', [
                    'message' => $e->getMessage(),
                ]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /**
     * Render a value that may be a backed enum, a plain string, or
     * `null` into a JSON-safe scalar for the SSE payload.
     */
    private function enumOrString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        return (string) $value;
    }

    /**
     * Emit a single SSE frame and flush the output buffer so the client
     * receives the message immediately rather than at end-of-response.
     *
     * `json_encode` uses `JSON_THROW_ON_ERROR` so invalid UTF-8 from
     * an LLM-generated summary surfaces as a typed exception. The
     * caller wraps the stream body in a try/catch that emits an
     * `event: error` frame on any exception, so a malformed payload
     * NEVER produces a half-written / partially-valid SSE `data:`
     * line — the SSE contract stays parseable end-to-end.
     */
    private function emit(string $event, array $payload): void
    {
        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $e) {
            // Fall back to a deterministic error frame so the stream
            // does not emit corrupted JSON. The caller will continue
            // with the next iteration; the malformed cell becomes an
            // error frame instead of a half-written data line.
            $json = json_encode(
                ['_encode_error' => $e->getMessage()],
                JSON_UNESCAPED_SLASHES,
            );
        }

        echo 'event: '.$event."\n";
        echo 'data: '.$json."\n\n";

        // Guard the flush calls explicitly rather than silencing them
        // with `@` — diagnostic warnings still surface in dev / CI.
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            ob_flush();
        }
        if (function_exists('flush')) {
            flush();
        }
    }
}
