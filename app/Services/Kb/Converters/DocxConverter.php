<?php

declare(strict_types=1);

namespace App\Services\Kb\Converters;

use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use Throwable;

/**
 * DOCX → markdown converter.
 *
 * Walks every section + element of a Word2007 .docx parsed via PhpWord and
 * emits markdown:
 *  - `Heading{N}` paragraph style → `{#×N} {text}`
 *  - `Title` element (PhpWord creates these from heading-styled paragraphs) → same
 *  - normal paragraphs → prose lines
 *  - tables → markdown pipe-tables (header row from the FIRST row of the table)
 *  - list items → `- {text}` (flat bullet list — nested levels collapsed)
 *
 * Output starts with `# {basename}` so the downstream MarkdownChunker can
 * `section_aware`-chunk the output (the document-level basename is the
 * outer H1, headings inside the doc become H2..H6 nested under it).
 *
 * Per LESSONS T1.3 rule: `extractionMeta['filename'] = basename($doc->sourcePath)`
 * so the chunker (and admin observability surfaces) attribute chunks back
 * to the source file.
 *
 * Limitations (intentional for v3.0):
 *  - Embedded images NOT extracted (planned for v3.1 with vision-LLM pipeline).
 *  - Footnotes / endnotes / comments NOT included.
 *  - Custom paragraph styles other than `HeadingN` are treated as body prose.
 */
final class DocxConverter implements ConverterInterface
{
    private const SUPPORTED_MIME_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function name(): string
    {
        return 'docx-converter';
    }

    public function supports(string $mimeType): bool
    {
        return $mimeType === self::SUPPORTED_MIME_TYPE;
    }

    public function convert(SourceDocument $doc): ConvertedDocument
    {
        $start = hrtime(true);
        $tmp = tempnam(sys_get_temp_dir(), 'kb_docx_');
        if ($tmp === false || file_put_contents($tmp, $doc->bytes) === false) {
            throw new \RuntimeException('Failed to write temporary DOCX file for parsing.');
        }

        try {
            try {
                $phpWord = IOFactory::load($tmp, 'Word2007');
            } catch (Throwable $e) {
                throw new \RuntimeException(sprintf(
                    'DocxConverter could not parse "%s": %s',
                    $doc->sourcePath,
                    $e->getMessage(),
                ), previous: $e);
            }

            $blocks = [];
            $sectionCount = 0;
            foreach ($phpWord->getSections() as $section) {
                $sectionCount++;
                foreach ($section->getElements() as $element) {
                    $rendered = $this->renderElement($element);
                    if ($rendered !== '') {
                        $blocks[] = $rendered;
                    }
                }
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }

        $filename = basename($doc->sourcePath);
        $markdown = $this->renderMarkdown($filename, $blocks);
        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        return new ConvertedDocument(
            markdown: $markdown,
            mediaItems: [],
            extractionMeta: [
                'converter' => $this->name(),
                'duration_ms' => $durationMs,
                'section_count' => $sectionCount,
                'block_count' => count($blocks),
                'source_path' => $doc->sourcePath,
                'filename' => $filename,
            ],
            sourceMimeType: $doc->mimeType,
        );
    }

    /**
     * Returns the markdown rendering of a single element, or '' when the
     * element produces nothing (empty paragraph, unsupported type).
     */
    private function renderElement(AbstractElement $element): string
    {
        if ($element instanceof Title) {
            $depth = (int) $element->getDepth();
            $level = max(2, min(6, $depth + 1)); // doc-level H1 reserved for basename
            $text = trim($this->extractText($element));
            return $text === '' ? '' : str_repeat('#', $level) . ' ' . $text;
        }

        if ($element instanceof Table) {
            return $this->renderTable($element);
        }

        if ($element instanceof ListItem) {
            $text = trim($element->getTextObject()->getText());
            return $text === '' ? '' : '- ' . $text;
        }

        if ($element instanceof TextBreak) {
            // Treat blank paragraph as an empty separator that the upstream
            // join('\n\n') handles naturally — return empty so we don't
            // accumulate stray whitespace blocks.
            return '';
        }

        if ($element instanceof Text || $element instanceof TextRun) {
            $headingLevel = $this->detectHeadingLevel($element);
            $text = trim($this->extractText($element));
            if ($text === '') {
                return '';
            }
            if ($headingLevel !== null) {
                $level = max(2, min(6, $headingLevel + 1)); // basename = doc H1
                return str_repeat('#', $level) . ' ' . $text;
            }
            return $text;
        }

        // Unknown / unsupported element: try to recover any nested text so
        // we never silently drop content.
        if ($element instanceof AbstractContainer) {
            $text = trim($this->extractText($element));
            return $text;
        }

        return '';
    }

    /**
     * Detects `HeadingN` paragraph styles. PhpWord exposes both the styleId
     * (e.g. `Heading1`) and a Style object — we read the styleId because
     * Word-generated docs ship the styleId form whereas style.getStyleName()
     * may return the localized "heading 1" form. Returns the heading level
     * as an int (1..6) or null when not a heading style.
     */
    private function detectHeadingLevel(AbstractElement $element): ?int
    {
        $paragraphStyle = method_exists($element, 'getParagraphStyle')
            ? $element->getParagraphStyle()
            : null;
        if ($paragraphStyle === null) {
            return null;
        }

        $styleName = is_object($paragraphStyle) && method_exists($paragraphStyle, 'getStyleName')
            ? (string) $paragraphStyle->getStyleName()
            : (string) $paragraphStyle;

        if ($styleName === '') {
            return null;
        }

        // Match `Heading1`..`Heading6` (Word-generated) AND `heading 1`
        // (PhpWord's localized name). Accept both forms.
        if (preg_match('/^heading\s*([1-6])$/i', $styleName, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Recursively concatenates all leaf text from a container element.
     */
    private function extractText(AbstractElement $element): string
    {
        if ($element instanceof Text) {
            return (string) $element->getText();
        }
        // Title exposes its text directly via getText() — it does NOT extend
        // AbstractContainer, so it has no getElements() iteration. PhpWord
        // builds Title from heading-styled paragraphs and packs the run's
        // text into a single string at parse time.
        if ($element instanceof Title) {
            $text = $element->getText();
            // getText() can return string OR a TextRun on rich-formatted titles.
            if (is_string($text)) {
                return $text;
            }
            if ($text instanceof AbstractElement) {
                return $this->extractText($text);
            }
            return '';
        }
        if ($element instanceof TextRun) {
            $out = '';
            foreach ($element->getElements() as $child) {
                $out .= $this->extractText($child);
            }
            return $out;
        }
        if ($element instanceof AbstractContainer) {
            $out = '';
            foreach ($element->getElements() as $child) {
                $out .= ' ' . $this->extractText($child);
            }
            return trim($out);
        }
        return '';
    }

    private function renderTable(Table $table): string
    {
        $rows = [];
        foreach ($table->getRows() as $row) {
            $cells = [];
            foreach ($row->getCells() as $cell) {
                $cellText = '';
                foreach ($cell->getElements() as $cellElement) {
                    $cellText .= ' ' . $this->extractText($cellElement);
                }
                // Pipe-table cells must not contain raw `|`; escape them.
                $cells[] = trim(str_replace('|', '\|', $cellText));
            }
            $rows[] = '| ' . implode(' | ', $cells) . ' |';
        }
        if ($rows === []) {
            return '';
        }
        // Insert the markdown header separator right after the FIRST row so
        // the first row becomes the table header.
        $firstRow = $rows[0];
        $columnCount = substr_count($firstRow, '|') - 1;
        $separator = '|' . str_repeat(' --- |', $columnCount);

        return $rows[0] . "\n" . $separator . (count($rows) > 1 ? "\n" . implode("\n", array_slice($rows, 1)) : '');
    }

    /**
     * @param  list<string>  $blocks
     */
    private function renderMarkdown(string $filename, array $blocks): string
    {
        if ($blocks === []) {
            return '';
        }
        // Document-level H1 = basename so MarkdownChunker section_aware
        // mode can use heading nesting (H1 > H2 ... breadcrumb) to attribute
        // chunks back to the source file.
        return "# {$filename}\n\n" . implode("\n\n", $blocks) . "\n";
    }
}
