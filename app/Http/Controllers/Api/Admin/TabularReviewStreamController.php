<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KnowledgeDocument;
use App\Models\TabularCell;
use App\Models\TabularReview;
use App\Services\TabularReview\TabularReviewExtractor;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
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
 * Wire format — one SSE message per event:
 *
 *     event: start
 *     data: {"review_id":42,"documents_total":7,"max_documents":200}
 *
 *     event: document
 *     data: {"document_id":101,"processed":1,"total":7}
 *
 *     event: cell
 *     data: {"document_id":101,"column_index":0,"summary":"...","flag":"green","status":"ok","reasoning":"..."}
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

        $response->headers->set('Content-Type', 'text/event-stream');
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
     */
    private function emit(string $event, array $payload): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
