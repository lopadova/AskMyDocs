<?php

declare(strict_types=1);

namespace App\Services\Kb\Embedding;

use App\Services\Kb\Pii\ChunkRedactor;
use App\Services\Kb\Pipeline\ChunkDraft;

/**
 * v8.42/W2 (ADR 0035 §D2) — the ONE place that decides what text a chunk is
 * embedded as, and how that decision is fingerprinted.
 *
 * Both ingestion entry points (the direct `DocumentIngestor` path and the Flow
 * saga's `EmbedChunksStep`) compose their embedding inputs here, so a test can
 * assert they never diverge.
 *
 * OFF (`kb.embedding_input.contextual = false`, the default): the input is the
 * bare chunk text — byte-identical to every release before v8.42.
 *
 * ON: a fixed, versioned template that carries the document and section into
 * the vector:
 *
 *   prose   `title: {title} | section: {heading|none} | text: {chunk}`
 *           canonical docs insert `| type: {canonical_type}` before `text:`
 *   code    `file: {path} | symbol: {symbol path|none} | lang: {language} | code: {chunk}`
 *
 * The persisted `chunk_text` is never touched — the template shapes the
 * vector only.
 *
 * PII: the composed header (title, headings) can hold personal data the chunk
 * body does not, so every header fragment goes through
 * {@see ChunkRedactor::redactTexts()} — the same seam, gates and strategy as
 * the chunk body — before it reaches the provider.
 */
final class EmbeddingInputTemplate
{
    private const MAX_TITLE_CHARS = 200;
    private const MAX_SECTION_CHARS = 300;
    private const MAX_PATH_CHARS = 300;

    public function __construct(private readonly ChunkRedactor $redactor) {}

    public function contextual(): bool
    {
        return (bool) config('kb.embedding_input.contextual', false);
    }

    /** `raw` when OFF, `ctx-v{N}` when ON. Part of the fingerprint. */
    public function templateId(): string
    {
        return $this->contextual()
            ? 'ctx-v'.max(1, (int) config('kb.embedding_input.template_version', 1))
            : 'raw';
    }

    /**
     * @param  list<ChunkDraft>  $drafts  already PII-redacted chunk drafts
     * @return list<string>  one embedding input per draft, same order
     */
    public function inputs(
        string $projectKey,
        string $title,
        string $sourcePath,
        ?string $canonicalType,
        array $drafts,
        bool $previewSafe = false,
    ): array {
        $contextual = $this->contextual();
        // v8.45 / ADR 0035 D7 — code chunks ALWAYS embed with the code header
        // (`file | symbol | lang | code`), even while the prose template is
        // off: a bare slice of a function carries no file or symbol for the
        // bi-encoder to match a "where is X defined" query against. Prose
        // drafts keep the flag's behaviour exactly.
        $hasCode = false;
        foreach ($drafts as $d) {
            if (is_string($d->metadata['language'] ?? null) && $d->metadata['language'] !== '') {
                $hasCode = true;

                break;
            }
        }
        if (! $contextual && ! $hasCode) {
            return array_map(static fn (ChunkDraft $d): string => $d->text, $drafts);
        }
        if ($drafts === []) {
            return [];
        }

        // Redact the header fragments ONCE per document: the title, the path
        // (a file name can carry a person's name) and each distinct heading.
        $headings = [];
        foreach ($drafts as $d) {
            $headings[$d->headingPath] = true;
        }
        $headingKeys = array_keys($headings);

        $fragments = $this->redactor->redactTexts(
            $projectKey,
            array_merge(
                [$this->clean($title, self::MAX_TITLE_CHARS), $this->clean($sourcePath, self::MAX_PATH_CHARS)],
                array_map(fn (string $h): string => $this->clean($h, self::MAX_SECTION_CHARS), $headingKeys),
            ),
            $previewSafe,
        );

        $safeTitle = $fragments[0];
        $safePath = $fragments[1];
        $safeHeading = [];
        foreach ($headingKeys as $i => $original) {
            $safeHeading[$original] = $fragments[$i + 2];
        }
        $type = $canonicalType !== null && $canonicalType !== '' ? $this->clean($canonicalType, 40) : null;

        return array_map(function (ChunkDraft $d) use ($safeTitle, $safePath, $safeHeading, $type, $contextual): string {
            $heading = $safeHeading[$d->headingPath];
            $language = $d->metadata['language'] ?? null;
            if (! $contextual && ! (is_string($language) && $language !== '')) {
                return $d->text;
            }

            if (is_string($language) && $language !== '') {
                return sprintf(
                    'file: %s | symbol: %s | lang: %s | code: %s',
                    $safePath !== '' ? $safePath : 'none',
                    $heading !== '' ? $heading : 'none',
                    $this->clean($language, 30),
                    $d->text,
                );
            }

            return sprintf(
                'title: %s | section: %s%s | text: %s',
                $safeTitle !== '' ? $safeTitle : 'none',
                $heading !== '' ? $heading : 'none',
                $type !== null ? ' | type: '.$type : '',
                $d->text,
            );
        }, $drafts);
    }

    /**
     * Identifies HOW a vector was produced. Two vectors are comparable (with
     * each other and with a freshly embedded query) only when this matches.
     *
     * Dimensions are deliberately NOT part of it: provider + model already
     * determine them, and a dimension mismatch is a hard pgvector error long
     * before it could be a silent drift. Keeping them out also lets the active
     * fingerprint be computed WITHOUT a provider call.
     */
    public function fingerprint(string $provider, string $model): string
    {
        $significant = implode('|', [
            'provider:'.$provider,
            'model:'.$model,
            'template:'.$this->templateId(),
            'target:'.(int) config('kb.chunking.target_tokens', 512),
            'overlap:'.(int) config('kb.chunking.overlap_tokens', 64),
            'cap:'.(int) config('kb.chunking.hard_cap_tokens', 1024),
        ]);

        return substr(hash('sha256', $significant), 0, 16);
    }

    /** Single line, trimmed, bounded — a header fragment must not carry layout. */
    private function clean(string $value, int $max): string
    {
        $one = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strlen($one) > $max ? mb_substr($one, 0, $max) : $one;
    }
}
