<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\Evernote;

use App\Jobs\IngestDocumentJob;
use App\Models\ConnectorInstallation;
use App\Support\KbPath;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Padosoft\PiiRedactor\RedactorEngine;

/**
 * v4.5/W4 — Parse an Evernote `.enex` export file and ingest each
 * `<note>` element as a standalone KB document.
 *
 * Evernote's `.enex` is a single XML file holding any number of notes:
 *
 *   <?xml version="1.0" encoding="UTF-8"?>
 *   <!DOCTYPE en-export ...>
 *   <en-export ...>
 *     <note>
 *       <title>...</title>
 *       <content><![CDATA[<en-note>...</en-note>]]></content>
 *       <created>20260511T100000Z</created>
 *       <updated>20260511T110000Z</updated>
 *       <tag>...</tag><tag>...</tag>
 *       <note-attributes>
 *         <source-url>...</source-url>
 *         ...
 *       </note-attributes>
 *     </note>
 *     <note>...</note>
 *   </en-export>
 *
 * We parse with `XMLReader` (streaming) so a 500 MB export doesn't
 * blow up PHP memory. Each `<note>` element is fully buffered (one note
 * at a time), converted to markdown via {@see EnmlToMarkdown}, optionally
 * PII-redacted at the ingest boundary, written to the KB disk under
 * `{project_key}/connectors/evernote/import-{importId}/{note-slug}-{n}.md`,
 * and then handed to {@see IngestDocumentJob}.
 *
 * Returns an {@see EnexImportResult} carrying counts + per-note errors.
 * R14: a malformed `.enex` (XMLReader::open() fails OR the root element
 * is not `en-export`) raises {@see InvalidEnexException} BEFORE any
 * <note> child is ingested — callers MUST translate this to HTTP 422;
 * never silent success-on-empty, never partial writes from a non-Evernote
 * XML file that happens to contain <note> tags.
 *
 * Tested in tests/Feature/Connectors/EvernoteEnexImportTest.php.
 */
final class EnexImporter
{
    public function __construct(
        private readonly RedactorEngine $redactor,
    ) {}

    /**
     * @throws InvalidEnexException when the file cannot be parsed
     *         (missing, unreadable, malformed XML, or root element is
     *         not `<en-export>`).
     */
    public function import(
        string $localFilePath,
        ConnectorInstallation $installation,
        string $projectKey,
    ): EnexImportResult {
        if (! is_file($localFilePath) || ! is_readable($localFilePath)) {
            throw new InvalidEnexException(
                "ENEX file '{$localFilePath}' is missing or unreadable."
            );
        }

        // Capture libxml errors explicitly instead of suppressing them
        // with `@` — operators need actionable error messages when a
        // .enex file is malformed (R14: surface failures loudly).
        $previousLibxmlState = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new \XMLReader;
        // LIBXML_NONET — never fetch external entities; ENEX DOCTYPE
        // points at a publicly-hosted DTD that we have no business
        // fetching at parse time.
        try {
            $opened = $reader->open($localFilePath, 'UTF-8', LIBXML_NONET);
            if ($opened === false) {
                $detail = $this->collectLibxmlErrors();
                throw new InvalidEnexException(sprintf(
                    "Failed to open ENEX file '%s' — XMLReader rejected it as non-XML.%s",
                    $localFilePath,
                    $detail === '' ? '' : ' '.$detail,
                ));
            }

            // R14 / Finding #3: validate the root element FIRST, before
            // iterating any <note> elements. A non-Evernote XML file
            // that happens to contain <note> tags MUST be rejected
            // before we write a single byte to the KB disk or dispatch
            // a single ingest job.
            $rootName = $this->advanceToFirstElement($reader);
            if ($rootName === null) {
                $detail = $this->collectLibxmlErrors();
                throw new InvalidEnexException(sprintf(
                    "ENEX file '%s' contains no XML elements (truncated or non-XML).%s",
                    $localFilePath,
                    $detail === '' ? '' : ' '.$detail,
                ));
            }
            if ($rootName !== 'en-export') {
                throw new InvalidEnexException(sprintf(
                    'ENEX file is not a valid Evernote export — expected <en-export> root element, got <%s>.',
                    $rootName,
                ));
            }

            $imported = 0;
            $skipped = 0;
            $errors = [];
            $importId = (string) Str::ulid();
            $noteIndex = 0;

            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->name !== 'note') {
                    continue;
                }
                $noteIndex++;
                try {
                    $note = $this->readNote($reader);
                    if ($this->ingestNote($note, $installation, $projectKey, $importId, $noteIndex)) {
                        $imported++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = sprintf('note #%d: %s', $noteIndex, $e->getMessage());
                }
            }

            return new EnexImportResult($imported, $skipped, $errors);
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Advance the reader to the first ELEMENT node and return its name,
     * or `null` if the document holds no elements. We treat DOCTYPE +
     * processing-instruction + comments as ignorable header noise so a
     * file starting with `<?xml ...?><!DOCTYPE ...><en-export>` parses
     * correctly.
     */
    private function advanceToFirstElement(\XMLReader $reader): ?string
    {
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                return $reader->name;
            }
        }

        return null;
    }

    /**
     * Drain queued libxml errors into a single human-readable line.
     * Returns an empty string when no errors are pending.
     */
    private function collectLibxmlErrors(): string
    {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        if ($errors === []) {
            return '';
        }

        $messages = [];
        foreach ($errors as $error) {
            $messages[] = trim($error->message);
        }

        return 'libxml: '.implode('; ', array_slice($messages, 0, 3));
    }

    /**
     * Buffer the current `<note>` element + all its children. The
     * reader is positioned at the opening `<note>` tag when this is
     * called; on return it sits at the closing `</note>` tag (or end
     * of document if the file was truncated).
     *
     * @return array{title:string,content:string,created:?string,updated:?string,tags:list<string>,source_url:?string}
     */
    private function readNote(\XMLReader $reader): array
    {
        $title = '';
        $content = '';
        $created = null;
        $updated = null;
        $tags = [];
        $sourceUrl = null;

        // `readInnerXml` is the simplest way to slurp the note's
        // children — but it loads the full subtree into memory at once.
        // For per-note safety (each note is typically <1 MB) this is
        // fine; the streaming benefit comes from never holding the
        // ENTIRE export in memory simultaneously.
        $innerXml = $reader->readInnerXml();
        if ($innerXml === '' || $innerXml === false) {
            return [
                'title' => '',
                'content' => '',
                'created' => null,
                'updated' => null,
                'tags' => [],
                'source_url' => null,
            ];
        }

        $note = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $note->loadXML(
            '<note>'.$innerXml.'</note>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $note->documentElement;
        if ($root === null) {
            return [
                'title' => '',
                'content' => '',
                'created' => null,
                'updated' => null,
                'tags' => [],
                'source_url' => null,
            ];
        }

        foreach ($root->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if ($name === 'title') {
                $title = trim($child->textContent);
                continue;
            }
            if ($name === 'content') {
                // <content> is CDATA-wrapped ENML. textContent yields
                // the inner ENML directly.
                $content = $child->textContent;
                continue;
            }
            if ($name === 'created') {
                $created = trim($child->textContent);
                continue;
            }
            if ($name === 'updated') {
                $updated = trim($child->textContent);
                continue;
            }
            if ($name === 'tag') {
                $tags[] = trim($child->textContent);
                continue;
            }
            if ($name === 'note-attributes') {
                foreach ($child->childNodes as $attr) {
                    if ($attr instanceof \DOMElement && strtolower($attr->nodeName) === 'source-url') {
                        $sourceUrl = trim($attr->textContent);
                    }
                }
            }
        }

        return [
            'title' => $title,
            'content' => $content,
            'created' => $created,
            'updated' => $updated,
            'tags' => array_values(array_filter($tags, static fn ($t) => $t !== '')),
            'source_url' => $sourceUrl,
        ];
    }

    /**
     * Convert one note's ENML to markdown + dispatch the ingest job.
     * Returns true on success, false when the note body converts to
     * empty markdown (we skip empty notes rather than write 0-byte
     * ingest files).
     *
     * @param  array{title:string,content:string,created:?string,updated:?string,tags:list<string>,source_url:?string}  $note
     */
    private function ingestNote(
        array $note,
        ConnectorInstallation $installation,
        string $projectKey,
        string $importId,
        int $noteIndex,
    ): bool {
        $converter = new EnmlToMarkdown;
        $markdown = $converter->convert($note['content']);
        if ($markdown === '') {
            return false;
        }

        // PII redaction at the ingest boundary (R26). Wrapped here
        // rather than via BaseConnector::maybeRedactContent because the
        // importer is a stand-alone helper (so the controller can
        // invoke it without instantiating the full Evernote connector).
        if ((bool) config('kb.pii_redactor.enabled', false)) {
            $markdown = $this->redactor->redact($markdown);
        }

        $title = $note['title'] !== '' ? $note['title'] : 'Evernote note';

        // Prepend the title as an h1 so the ingest pipeline indexes it
        // alongside the body (mirrors NotionConnector::ingestPage).
        $body = "# {$title}\n\n".$markdown;

        $slug = Str::slug($title) !== '' ? Str::slug($title) : 'note';
        // Cap slug length so very long titles don't produce
        // 200+-char path segments.
        $slug = Str::limit($slug, 80, '');

        $relativePath = sprintf(
            '%s/connectors/evernote/import-%s/%s-%04d.md',
            $projectKey,
            $importId,
            $slug,
            $noteIndex,
        );

        $disk = (string) config('kb.sources.disk', 'kb');
        $prefix = (string) config('kb.sources.path_prefix', '');
        $absolute = $prefix === ''
            ? KbPath::normalize($relativePath)
            : KbPath::normalize($prefix.'/'.$relativePath);

        $written = Storage::disk($disk)->put($absolute, $body);
        if ($written === false) {
            throw new \RuntimeException("Failed to write {$absolute} to KB disk [{$disk}].");
        }

        IngestDocumentJob::dispatch(
            projectKey: $projectKey,
            relativePath: KbPath::normalize($relativePath),
            disk: $disk,
            title: $title,
            metadata: [
                'connector' => 'evernote',
                'installation_id' => $installation->id,
                'evernote_import_id' => $importId,
                'evernote_note_index' => $noteIndex,
                'evernote_source' => 'enex',
                'evernote_created' => $note['created'],
                'evernote_updated' => $note['updated'],
                'evernote_tags' => $note['tags'],
                'evernote_source_url' => $note['source_url'],
            ],
            mimeType: 'text/markdown',
            tenantId: $installation->tenant_id,
        );

        return true;
    }
}
