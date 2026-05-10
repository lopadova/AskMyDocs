<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Padosoft\PiiRedactor\Strategies\DropStrategy;
use Padosoft\PiiRedactor\Strategies\HashStrategy;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory;
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * v4.3/W1 sub-PR 4.5 — B4 — PII strategy admin endpoint.
 *
 * GET /api/admin/pii/strategy
 *
 * Returns the active redaction strategy + the catalogue of selectable
 * strategies (`mask`, `hash`, `tokenise`, `drop`) so the SPA can show
 * which one is currently in effect AND offer a future "switch strategy"
 * affordance. Pure config read; no DB and no LLM.
 *
 * The endpoint is mounted behind `auth:sanctum` + the
 * `can:viewPiiRedactorAdmin` middleware (Gate registered in
 * AppServiceProvider). The Gate admits `super-admin`, `dpo`, `admin`
 * — i.e. the three roles the broader pii-redactor-admin SPA also
 * trusts. The route is intentionally NOT mounted under the
 * `role:admin|super-admin` admin group, because that would 403 the
 * `dpo` role despite the Gate allowing it (mirrors the
 * laravel-pii-redactor-admin v1.0.2 mounting precedent).
 *
 * Strategies + token lengths + salt-presence are configuration
 * metadata that should not leak to standard viewers/editors.
 *
 * Response shape (200):
 *   {
 *     active: { name: string, class: string|null, requires_tokenise_store: bool },
 *     available: list<string>,
 *     config: {
 *       mask_token: string,
 *       hash_hex_length: int,
 *       token_hex_length: int,
 *       has_salt: bool,
 *     },
 *   }
 *
 * On factory error (e.g. missing salt for hash/tokenise) → 503 with the
 * exception message in the body. R14: don't pretend success when
 * misconfigured.
 */
final class PiiStrategyController extends Controller
{
    public function __construct(
        private readonly RedactionStrategyFactory $factory,
    ) {}

    public function show(): JsonResponse
    {
        try {
            $active = app(RedactionStrategy::class);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'PII redaction strategy is not configured.',
                'error' => $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $activeName = $this->resolveStrategyName($active);

        return response()->json([
            'active' => [
                'name' => $activeName,
                'class' => $active::class,
                'requires_tokenise_store' => $active instanceof TokeniseStrategy,
            ],
            'available' => $this->factory->names(),
            'config' => [
                'mask_token' => (string) config('pii-redactor.mask_token', '[REDACTED]'),
                'hash_hex_length' => (int) config('pii-redactor.hash_hex_length', 16),
                'token_hex_length' => (int) config('pii-redactor.token_hex_length', 16),
                'has_salt' => (string) config('pii-redactor.salt', '') !== '',
            ],
        ]);
    }

    private function resolveStrategyName(RedactionStrategy $strategy): string
    {
        return match (true) {
            $strategy instanceof MaskStrategy => 'mask',
            $strategy instanceof HashStrategy => 'hash',
            $strategy instanceof TokeniseStrategy => 'tokenise',
            $strategy instanceof DropStrategy => 'drop',
            default => 'unknown',
        };
    }
}
