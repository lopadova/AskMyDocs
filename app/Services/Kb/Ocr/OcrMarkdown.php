<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

/**
 * v8.36 / ADR 0029 — the ONE rule for image links in OCR output, shared by
 * every driver: the persisted Markdown may reference only the figures the
 * driver actually extracted and the service stores (`images/fig-{page}-{n}.{ext}`).
 * Any other image link — a placeholder the engine invented, a provider- or
 * model-chosen URL, a path that does not exist — becomes an italic text
 * description, so a document never loads an arbitrary external URL through
 * the shared Markdown renderer and never cites a file that is not there
 * (SEC-LLM-001 gate 6 / SEC-EXTRESP-001: remote output is data, never a
 * reference).
 */
final class OcrMarkdown
{
    /**
     * @param  list<string>  $keepFileNames  the generated figure file names
     *                                       (`OcrFigure::fileName()`) whose
     *                                       `images/{name}` links stay
     */
    public static function stripForeignImageLinks(string $markdown, array $keepFileNames = []): string
    {
        $keep = [];
        foreach ($keepFileNames as $name) {
            $keep['images/'.$name] = true;
        }

        // Inline images: `![alt](target)` — kept only for a stored figure.
        $markdown = (string) preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)]*)\)/',
            static fn (array $m): string => isset($keep[trim($m[2])]) ? $m[0] : self::placeholder($m[1]),
            $markdown,
        );
        // Reference-style images — `![alt][id]`, `![alt][]`, `![alt]` — resolve
        // through a `[id]: url` definition the renderer honours just as it
        // honours an inline target; the store never writes a figure that way,
        // so every one of them becomes text (the definition line itself stays:
        // without an image reference it is at most a plain link).
        $markdown = (string) preg_replace_callback(
            '/!\[([^\]]*)\](?:\[[^\]]*\])?(?!\()/',
            static fn (array $m): string => self::placeholder($m[1]),
            $markdown,
        );

        // Raw `<img>` tags: the SPA renderer does not render raw HTML today,
        // but the persisted Markdown must not depend on that (ADR 0030 exports
        // it, other renderers may read it): a tag becomes the same text.
        return (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function (array $m): string {
                $alt = preg_match('/\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $m[0], $a) === 1 ? ($a[1] !== '' ? $a[1] : ($a[2] ?? '')) : '';

                return self::placeholder($alt);
            },
            $markdown,
        );
    }

    private static function placeholder(string $alt): string
    {
        $alt = trim($alt);
        if ($alt === '' || strcasecmp($alt, 'figure') === 0) {
            return '*[Figure]*';
        }

        return str_starts_with(strtolower($alt), 'figure') ? "*[{$alt}]*" : "*[Figure: {$alt}]*";
    }
}
