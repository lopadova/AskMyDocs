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

        return (string) preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)]*)\)/',
            static function (array $m) use ($keep): string {
                if (isset($keep[trim($m[2])])) {
                    return $m[0];
                }
                $alt = trim($m[1]);
                if ($alt === '' || strcasecmp($alt, 'figure') === 0) {
                    return '*[Figure]*';
                }

                return str_starts_with(strtolower($alt), 'figure') ? "*[{$alt}]*" : "*[Figure: {$alt}]*";
            },
            $markdown,
        );
    }
}
