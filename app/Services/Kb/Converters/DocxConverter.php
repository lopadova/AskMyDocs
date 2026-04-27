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
 *  - `Heading{N}` paragraph style → heading markdown offset by +1 level
 *    (H1 is reserved for `# {basename}`), clamped to `##`..`######`
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
        if ($tmp === false) {
            throw new \RuntimeException(sprintf(
                'DocxConverter failed to allocate a temporary file for "%s".',
                $doc->sourcePath,
            ));
        }
        if (file_put_contents($tmp, $doc->bytes) === false) {
            // tempnam() created the file even though our write failed; clean
            // up before throwing so we don't leak a 0-byte temp file.
            if (is_file($tmp)) {
                unlink($tmp);
            }
            throw new \RuntimeException(sprintf(
                'DocxConverter failed to write a temporary DOCX file for "%s".',
                $doc->sourcePath,
            ));
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

            try {
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
            } catch (Throwable $e) {
                // PhpWord can surface unexpected element shapes deep in the
                // walk (e.g. ListItem with no TextObject on certain documents).
                // Re-throw with the source path so ops debugging stays
                // actionable; the original exception remains chained as
                // `previous`.
                throw new \RuntimeException(sprintf(
                    'DocxConverter could not extract text from "%s": %s',
                    $doc->sourcePath,
                    $e->getMessage(),
                ), previous: $e);
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
            // Preserve explicit in-paragraph line breaks so adjacent text
            // runs do not get concatenated during conversion (Word's
            // shift+enter line break would otherwise glue `line1` + `line2`
            // into `line1line2`).
            return "\n";
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
            // PhpWord does not always promote `ListParagraph`-styled
            // paragraphs to ListItem elements (numbering.xml may be missing
            // from the package). Detect the style name as a fallback so
            // bullet lists from Word documents still render as `- {text}`.
            if ($this->isListParagraphStyle($element)) {
                return '- ' . $text;
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
     * Detects `ListParagraph` (Word's default bullet/numbered-list style)
     * via the same paragraph-style channel as headings. Used as a fallback
     * because PhpWord's reader only promotes paragraphs to ListItem when
     * the package ships a complete `word/numbering.xml` definition; many
     * real-world Word documents skip it.
     */
    private function isListParagraphStyle(AbstractElement $element): bool
    {
        $paragraphStyle = method_exists($element, 'getParagraphStyle')
            ? $element->getParagraphStyle()
            : null;
        if ($paragraphStyle === null) {
            return false;
        }
        $styleName = is_object($paragraphStyle) && method_exists($paragraphStyle, 'getStyleName')
            ? (string) $paragraphStyle->getStyleName()
            : (string) $paragraphStyle;

        return strcasecmp($styleName, 'ListParagraph') === 0;
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
        $columnCount = 0;
        foreach ($table->getRows() as $rowIndex => $row) {
            $cells = [];
            foreach ($row->getCells() as $cell) {
                $cellText = '';
                foreach ($cell->getElements() as $cellElement) {
                    $cellText .= ' ' . $this->extractText($cellElement);
                }
                // Pipe-table cells must not contain raw `|` (would break the
                // markdown column delimiter) or raw `\n` (would break the
                // row delimiter). Escape both.
                $cleaned = trim(str_replace(['|', "\r", "\n"], ['\|', ' ', ' '], $cellText));
                $cells[] = $cleaned;
            }
            if ($rowIndex === 0) {
                // Track the FIRST row's actual cell count — never derive it
                // from the rendered string, because escaped `\|` characters
                // would inflate `substr_count('|')` and produce an invalid
                // header separator that breaks the markdown table.
                $columnCount = count($cells);
            }
            // Real DOCX tables can have irregular rows (merged cells, body
            // rows with more or fewer cells than the header). Pad / truncate
            // each row to the header's column count so the resulting
            // markdown table stays structurally valid for every viewer.
            $cells = array_slice(array_pad($cells, $columnCount, ''), 0, $columnCount);
            $rows[] = '| ' . implode(' | ', $cells) . ' |';
        }
        if ($rows === [] || $columnCount === 0) {
            return '';
        }
        // Insert the markdown header separator right after the FIRST row so
        // the first row becomes the table header.
        $separator = '|' . str_repeat(' --- |', $columnCount);

        return $rows[0] . "\n" . $separator . (count($rows) > 1 ? "\n" . implode("\n", array_slice($rows, 1)) : '');
    }

    /**
     * For NON-EMPTY conversions, the output starts with `# {basename}\n\n`
     * so MarkdownChunker section_aware mode can use heading nesting
     * (H1 > H2 ... breadcrumb) to attribute chunks back to the source file.
     *
     * For an empty extraction (zero blocks recovered), returns an empty
     * string — same semantics as TextPassthroughConverter and PdfConverter:
     * the chunker then returns [] and no embeddings get created for a
     * filename-only document. (Whether an empty document row is still
     * persisted by DocumentIngestor is the pipeline's concern, not this
     * converter's.)
     *
     * @param  list<string>  $blocks
     */
    private function renderMarkdown(string $filename, array $blocks): string
    {
        if ($blocks === []) {
            return '';
        }
        return "# {$filename}\n\n" . implode("\n\n", $blocks) . "\n";
    }
}
