<?php

declare(strict_types=1);

namespace App\Services\Kb\Converters;

use App\Services\Kb\Code\CodeLanguageMap;
use App\Services\Kb\Code\SecretRedactor;
use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;
use RuntimeException;

/**
 * Source code → a single fenced markdown block (v8.45 / W5, ADR 0035 D7).
 *
 * The output is exactly one code fence tagged with the language, so the stored
 * conversion artifact renders as code everywhere and the source LINE NUMBERS
 * are the artifact's line numbers minus one (the opening fence) — the
 * {@see \App\Services\Kb\Chunkers\CodeAstChunker} and the line-range reader
 * both rely on that. The fence is longer than any backtick run in the source,
 * so code that itself contains ``` cannot close it early.
 *
 * Secrets are redacted HERE, before the text exists anywhere else: the
 * artifact, the chunks, the embeddings and the document row therefore never
 * hold a credential. Only a count is recorded (`secrets_redacted`).
 *
 * This converter claims its `text/x-<language>` MIMEs unconditionally. Whether
 * code is ACCEPTED is decided at the entry points ({@see \App\Services\Kb\Code\CodeIngestionPolicy}),
 * exactly like images (ADR 0029 §2) — so turning the flag off never strands a
 * queued job or a re-embed of an existing code document.
 */
final class CodePassthroughConverter implements ConverterInterface
{
    public function __construct(private readonly SecretRedactor $secrets) {}

    public function name(): string
    {
        return 'code-passthrough';
    }

    public function supports(string $mimeType): bool
    {
        return CodeLanguageMap::languageForMime($mimeType) !== null;
    }

    public function convert(SourceDocument $doc): ConvertedDocument
    {
        $language = CodeLanguageMap::languageForMime($doc->mimeType);
        if ($language === null) {
            throw new RuntimeException(sprintf('CodePassthroughConverter: "%s" is not a code MIME.', $doc->mimeType));
        }

        $source = $this->normalise($doc->bytes);
        $redaction = $this->secrets->redact($source);
        $code = $redaction['text'];

        $filename = basename($doc->sourcePath);
        $markdown = trim($code) === '' ? '' : $this->fence($language, $code);

        return new ConvertedDocument(
            markdown: $markdown,
            mediaItems: [],
            extractionMeta: [
                'converter' => $this->name(),
                'duration_ms' => 0,
                'source_path' => $doc->sourcePath,
                'filename' => $filename,
                'language' => $language,
                'secrets_redacted' => $redaction['count'],
            ],
            sourceMimeType: $doc->mimeType,
        );
    }

    /** UTF-8 BOM off, CRLF/CR → LF: stable hashes and line numbers. */
    private function normalise(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        return str_replace(["\r\n", "\r"], "\n", $bytes);
    }

    private function fence(string $language, string $code): string
    {
        $longest = 0;
        if (preg_match_all('/`+/', $code, $runs) > 0) {
            foreach ($runs[0] as $run) {
                $longest = max($longest, strlen($run));
            }
        }
        $fence = str_repeat('`', max(3, $longest + 1));

        return $fence.$language."\n".rtrim($code, "\n")."\n".$fence."\n";
    }
}
