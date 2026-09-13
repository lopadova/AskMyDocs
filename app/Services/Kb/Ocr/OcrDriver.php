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
     * Why `isAvailable()` is false, in the words an operator needs to act
     * (`null` when the driver can run here). The preflight (estimate,
     * re-run, status) and the conversion guard surface THIS reason, so a
     * missing key, a binary off PATH or a non-allow-listed endpoint is
     * named before any work is queued — never a generic "not available"
     * that has to be diagnosed in the worker (R14).
     *
     * `$forPdf` names the input the caller will hand over: a driver that
     * rasterises PDFs itself (tesseract, vision-llm) needs Poppler's
     * `pdftoppm` / `pdfinfo` for a PDF but not for a raster image, so a
     * PDF preflight must report those binaries too, while an image-only
     * batch is not refused for a dependency it never uses. The default is
     * the conservative answer (a PDF may come).
     */
    public function unavailableReason(bool $forPdf = true): ?string;

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
     * Worst-case wall-clock seconds one `recognise()` of `$pages` pages can
     * take under the driver's own timeouts (process / HTTP / per-page). The
     * service sizes the run-directory reservation from it, so the lease is
     * provably longer than the work it protects (ADR 0029 §5): a driver
     * whose timeouts are per page must multiply, one whose timeout is per
     * document returns it as is.
     */
    public function maxDurationSeconds(int $pages): int;

    /**
     * Whether the driver bounds its own work when the page count could NOT
     * be verified before the run (a PDF the parser cannot read, ADR 0029 §4).
     * True for drivers that rasterise page by page under `KB_OCR_MAX_PAGES`
     * and a per-page timeout (the work is capped by construction, whatever
     * the file claims); false for drivers that hand the whole file to an
     * engine. A remote driver is refused such a document regardless.
     */
    public function boundsWorkWithoutPageCount(): bool;

    /**
     * Whether the driver OCRs EVERY frame of a multi-frame image (a
     * multi-page TIFF). The page cap counts every IFD as a page (ADR 0029
     * §4), so a driver that hands a raster to its engine as ONE image would
     * be estimated and metered for N pages and transcribe only the first:
     * such a driver answers false and the service refuses the file before
     * any work (`multi_frame_image`) — split it into one image per page.
     */
    public function acceptsMultiFrameImages(): bool;

    /**
     * @throws OcrDriverUnavailableException when the engine cannot run here
     * @throws \RuntimeException when recognition fails irrecoverably
     */
    public function recognise(OcrRequest $request): OcrResult;
}
