<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

/**
 * v8.36 / ADR 0029 — one contract for every OCR engine.
 *
 * A driver turns the bytes of a scanned PDF or an image into per-page
 * Markdown (`OcrResult`), reporting a confidence per page when the engine
 * can, and figures it extracted. Drivers are stateless; the registry
 * (`OcrDriverRegistry`) validates the FQCN at boot (R23) and resolves the
 * single configured driver (`kb.ocr.driver`).
 *
 * `isAvailable()` answers "can this driver run HERE" (binary on PATH, API
 * key present, SDK provider configured). An unavailable driver fails loudly
 * at conversion time (`OcrDriverUnavailableException`) — never an empty
 * document (R14).
 */
interface OcrDriver
{
    /** Stable lower-kebab-case identifier; equals the `kb.ocr.drivers` key. */
    public function name(): string;

    public function isAvailable(): bool;

    /**
     * Whether recognition sends the document bytes OUTSIDE the tenant's
     * infrastructure (an API call). ChunkRedactor protects the index, not
     * bytes that leave to be recognised, so remote drivers are a
     * final-egress policy of their own: the registry refuses them unless
     * `kb.ocr.allow_remote` is true (fail closed — ADR 0029 §7).
     */
    public function isRemote(): bool;

    /**
     * The engine variant that shapes the output — model, language, DPI,
     * binary — so the recorded-run key distinguishes "same bytes, other
     * engine" (ADR 0029 §6). Cheap, deterministic, no I/O.
     */
    public function fingerprint(): string;

    /**
     * How the driver's spend reaches the FinOps ledger: `per_page` drivers are
     * metered by {@see \App\FinOps\OcrCallMeter} at the configured rate;
     * `sdk` drivers are metered by the laravel/ai lifecycle hook per token
     * and must NOT be double-counted.
     */
    public function meteringMode(): OcrMeteringMode;

    /**
     * @throws OcrDriverUnavailableException when the engine cannot run here
     * @throws \RuntimeException when recognition fails irrecoverably
     */
    /**
     * Worst-case wall-clock seconds one `recognise()` of `$pages` pages can
     * take under the driver's own timeouts (process / HTTP / per-page). The
     * service sizes the run-directory reservation from it, so the lease is
     * provably longer than the work it protects (ADR 0029 §5): a driver
     * whose timeouts are per page must multiply, one whose timeout is per
     * document returns it as is.
     */
    public function maxDurationSeconds(int $pages): int;

    public function recognise(OcrRequest $request): OcrResult;
}
