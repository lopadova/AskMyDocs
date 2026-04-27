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
 * Limitations (intentional):
 *  - No images, no tables (a future test can extend the builder).
 *  - No theme / no fonts / no language tags.
 *  - ASCII text only (XML escapes anyway).
 */
final class DocxFixtureBuilder
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @param  list<array{type: 'heading'|'body', level?: int, text: string}>  $blocks
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
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to open zip archive for DocxFixtureBuilder.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('word/_rels/document.xml.rels', self::documentRelsXml());
        $zip->addFromString('word/styles.xml', self::stylesXml());
        $zip->addFromString('word/document.xml', self::documentXml($blocks));
        $zip->close();

        $bytes = file_get_contents($tmp);
        if (is_file($tmp)) {
            unlink($tmp);
        }
        if ($bytes === false) {
            throw new \RuntimeException('Failed to read generated docx bytes.');
        }
        return $bytes;
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
     * @param  list<array{type: 'heading'|'body', level?: int, text: string}>  $blocks
     */
    private static function documentXml(array $blocks): string
    {
        $body = '';
        foreach ($blocks as $block) {
            $text = htmlspecialchars($block['text'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            if ($block['type'] === 'heading') {
                $level = max(1, min(6, $block['level'] ?? 1));
                $body .= '<w:p>'
                    . '<w:pPr><w:pStyle w:val="Heading' . $level . '"/></w:pPr>'
                    . '<w:r><w:t>' . $text . '</w:t></w:r>'
                    . '</w:p>';
            } else {
                $body .= '<w:p><w:r><w:t>' . $text . '</w:t></w:r></w:p>';
            }
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="' . self::NS_W . '">'
            . '<w:body>' . $body . '<w:sectPr/></w:body>'
            . '</w:document>';
    }
}
