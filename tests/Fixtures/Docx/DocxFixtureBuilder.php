<?php

declare(strict_types=1);

namespace Tests\Fixtures\Docx;

use ZipArchive;

/**
 * Pure-PHP minimal .docx builder for test fixtures.
 *
 * Generates a deterministic Office Open XML (OOXML) WordprocessingML
 * document — a ZIP container with the minimum-viable subset PhpWord
 * accepts:
 *  - `[Content_Types].xml`     (MIME registry for the package)
 *  - `_rels/.rels`              (root relationships)
 *  - `word/document.xml`        (the main body)
 *  - `word/_rels/document.xml.rels` (document-level relationships)
 *  - `word/styles.xml`          (Heading1..N style declarations)
 *
 * Same rationale as PdfFixtureBuilder (T1.5 LESSONS rule 1): inline pure-PHP
 * fixtures beat checked-in binary blobs because (a) `git diff` shows what
 * changed, (b) reproducibility doesn't depend on Word/LibreOffice/Pandoc
 * being installed, (c) the builder doubles as living documentation of the
 * minimum DOCX subset PhpWord can parse, (d) unit and feature tests share
 * the same builder so assertion text stays aligned.
 *
 * Supported block types (in `build()`):
 *  - `heading` (with `level` 1..6)
 *  - `body` (plain paragraph)
 *  - `list` (bullet list — uses `ListParagraph` style + numPr stub)
 *  - `table` (with `rows` as `list<list<string>>`; row 0 = header)
 *
 * Limitations (intentional):
 *  - No images or footnotes.
 *  - No theme / no fonts / no language tags.
 *  - ASCII text only (XML escapes anyway).
 */
final class DocxFixtureBuilder
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @param  list<array{type: 'heading'|'body'|'list'|'table', level?: int, text?: string, rows?: list<list<string>>}>  $blocks
     *         For `heading`/`body`/`list` use `text`. For `heading` optionally
     *         set `level` 1..6 (default 1). For `table` use `rows` as
     *         `list<list<string>>` — row 0 is the header row by convention.
     *
     * @throws \InvalidArgumentException when `$blocks` is empty (an empty doc
     *         produces an empty `<w:body>` that PhpWord parses as zero
     *         sections — meaningless for tests).
     */
    public static function build(array $blocks): string
    {
        if ($blocks === []) {
            throw new \InvalidArgumentException(
                'DocxFixtureBuilder::build() requires at least one block; got empty array.',
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'kb_docx_');
        if ($tmp === false) {
            throw new \RuntimeException('Failed to allocate temp file for DocxFixtureBuilder.');
        }

        $zip = new ZipArchive();
        $zipOpened = false;

        try {
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to open zip archive for DocxFixtureBuilder.');
            }
            $zipOpened = true;

            // Each addFromString() returns false on failure — silently ignoring
            // would emit a corrupt/partial .docx that PhpWord then rejects in
            // non-obvious ways. CLAUDE.md R4: surface side-effect failures.
            $entries = [
                '[Content_Types].xml'              => self::contentTypesXml(),
                '_rels/.rels'                      => self::rootRelsXml(),
                'word/_rels/document.xml.rels'     => self::documentRelsXml(),
                'word/styles.xml'                  => self::stylesXml(),
                'word/document.xml'                => self::documentXml($blocks),
            ];
            foreach ($entries as $name => $content) {
                if ($zip->addFromString($name, $content) !== true) {
                    throw new \RuntimeException(
                        sprintf('DocxFixtureBuilder failed to add zip entry "%s".', $name),
                    );
                }
            }
            if ($zip->close() !== true) {
                throw new \RuntimeException('DocxFixtureBuilder failed to close the zip archive.');
            }
            $zipOpened = false;

            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new \RuntimeException('Failed to read generated docx bytes.');
            }
            return $bytes;
        } finally {
            // Belt-and-braces cleanup: ensure the archive is always closed
            // (re-throw + half-open zip otherwise leaves the file locked on
            // Windows) and the temp file is unlinked on every failure path
            // (open() failure, addFromString() failure, file_get_contents()
            // failure — each of which would leak the tempnam'd file).
            if ($zipOpened) {
                $zip->close();
            }
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    /**
     * Convenience: 3-block sample with H1 + body + H2 — shared assertion
     * surface for the unit converter test and the feature ingestion test.
     */
    public static function buildHeadingsSample(): string
    {
        return self::build([
            ['type' => 'heading', 'level' => 1, 'text' => 'Introduction'],
            ['type' => 'body', 'text' => 'This is the intro paragraph body.'],
            ['type' => 'heading', 'level' => 2, 'text' => 'Background'],
            ['type' => 'body', 'text' => 'This is the background paragraph body.'],
        ]);
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . self::NS_REL . '/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';
    }

    private static function documentRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . self::NS_REL . '/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /**
     * Declares Heading1..Heading6 paragraph styles (PhpWord matches by
     * styleId; both Heading1 and Heading 1 are accepted, but the
     * non-spaced form is what every Word-generated docx ships).
     */
    private static function stylesXml(): string
    {
        $headings = '';
        for ($i = 1; $i <= 6; $i++) {
            $headings .= '<w:style w:type="paragraph" w:styleId="Heading' . $i . '">'
                . '<w:name w:val="heading ' . $i . '"/>'
                . '</w:style>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="' . self::NS_W . '">' . $headings . '</w:styles>';
    }

    /**
     * @param  list<array{type: 'heading'|'body'|'list'|'table', level?: int, text?: string, rows?: list<list<string>>}>  $blocks
     */
    private static function documentXml(array $blocks): string
    {
        $body = '';
        foreach ($blocks as $block) {
            $body .= match ($block['type']) {
                'heading' => self::headingXml(
                    max(1, min(6, $block['level'] ?? 1)),
                    self::escapeText($block['text'] ?? ''),
                ),
                'list'    => self::listItemXml(self::escapeText($block['text'] ?? '')),
                'table'   => self::tableXml($block['rows'] ?? []),
                default   => '<w:p><w:r><w:t>' . self::escapeText($block['text'] ?? '') . '</w:t></w:r></w:p>',
            };
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="' . self::NS_W . '">'
            . '<w:body>' . $body . '<w:sectPr/></w:body>'
            . '</w:document>';
    }

    private static function escapeText(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function headingXml(int $level, string $escapedText): string
    {
        return '<w:p>'
            . '<w:pPr><w:pStyle w:val="Heading' . $level . '"/></w:pPr>'
            . '<w:r><w:t>' . $escapedText . '</w:t></w:r>'
            . '</w:p>';
    }

    private static function listItemXml(string $escapedText): string
    {
        // ListParagraph + numPr is the standard OOXML markup for list items.
        // PhpWord's reader recognises this as a ListItem element.
        return '<w:p>'
            . '<w:pPr>'
            . '<w:pStyle w:val="ListParagraph"/>'
            . '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>'
            . '</w:pPr>'
            . '<w:r><w:t>' . $escapedText . '</w:t></w:r>'
            . '</w:p>';
    }

    /**
     * @param  list<list<string>>  $rows  rows[0] = header row by convention
     */
    private static function tableXml(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $tr = '';
        foreach ($rows as $row) {
            $tc = '';
            foreach ($row as $cell) {
                $tc .= '<w:tc>'
                    . '<w:p><w:r><w:t>' . self::escapeText($cell) . '</w:t></w:r></w:p>'
                    . '</w:tc>';
            }
            $tr .= '<w:tr>' . $tc . '</w:tr>';
        }
        return '<w:tbl>' . $tr . '</w:tbl>';
    }
}
