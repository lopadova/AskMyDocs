<?php

declare(strict_types=1);

namespace App\Console\Commands\Benchmark;

use Illuminate\Console\Command;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Regenerates the binary benchmark fixtures (PDF + DOCX) under
 * resources/benchmark/corpus/. The markdown fixtures are authored by hand
 * and committed directly; only the binary docs need a generator so they
 * stay reproducible (no opaque committed blobs nobody can rebuild).
 *
 * - DOCX via PhpWord (a hard dependency, used by DocxConverter).
 * - PDF via a tiny dependency-free writer (dompdf is only a suggest), then
 *   validated by running the REAL PdfConverter so the fixture is provably
 *   text-extractable by the production smalot/pdfparser path.
 *
 * Dev/CI tool — not part of any runtime flow. Run:
 *   php artisan kb:make-benchmark-fixtures
 */
final class MakeBenchmarkFixturesCommand extends Command
{
    protected $signature = 'kb:make-benchmark-fixtures';

    protected $description = 'Regenerate the binary (PDF + DOCX) benchmark corpus fixtures.';

    public function handle(): int
    {
        $dir = resource_path('benchmark/corpus');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("Cannot create {$dir}");

            return self::FAILURE;
        }

        $pdfPath = $dir.'/incident-runbook.pdf';
        $docxPath = $dir.'/onboarding-guide.docx';

        file_put_contents($pdfPath, $this->buildPdf($this->pdfPages()));
        $this->info("Wrote {$pdfPath} (".filesize($pdfPath)." bytes)");

        $this->buildDocx($docxPath);
        $this->info("Wrote {$docxPath} (".filesize($docxPath)." bytes)");

        // Prove the PDF is extractable by the production converter path.
        $extracted = $this->verifyPdf($pdfPath);
        $this->line('PDF extraction check: '.mb_strlen($extracted).' chars, '
            .substr_count($extracted, '## page').' page header(s)');
        if (! str_contains($extracted, 'database incident') || substr_count($extracted, '## page') < 2) {
            $this->error('PDF extraction did NOT contain expected text — fixture is unusable.');

            return self::FAILURE;
        }

        $this->info('Fixtures regenerated + verified.');

        return self::SUCCESS;
    }

    /** @return list<list<string>> pages, each a list of text lines */
    private function pdfPages(): array
    {
        return [
            [
                'Database Incident Response Runbook',
                '',
                'This runbook covers responding to a database incident in',
                'production: connection exhaustion, replication lag, and a',
                'primary failover. It is distinct from the cache runbook.',
                '',
                'Connection exhaustion: when the pool is saturated, new',
                'requests time out. Raise the pool ceiling, kill idle-in-',
                'transaction sessions, and shed non-critical background jobs.',
            ],
            [
                'Replication lag and failover',
                '',
                'Replication lag: if a read replica falls behind by more than',
                'thirty seconds, route reads back to the primary until the',
                'replica catches up. Alert the on-call database engineer.',
                '',
                'Primary failover: promote the healthiest replica, repoint the',
                'connection string, and run a post-incident review within two',
                'business days. Record the timeline in the incident log.',
            ],
        ];
    }

    private function buildDocx(string $path): void
    {
        $word = new PhpWord();
        $section = $word->addSection();

        $section->addTitle('Engineering Onboarding Guide', 1);
        $section->addText(
            'Welcome to the engineering team. This guide walks a new engineer '
            .'through their first week: accounts, local environment, and the '
            .'review workflow.'
        );

        $section->addTitle('Day one: accounts and access', 2);
        $section->addText(
            'Request access to the source repository, the secrets vault, and '
            .'the staging environment. Enable two-factor authentication on '
            .'every account before requesting production access.'
        );

        $section->addTitle('Local environment', 2);
        $section->addText(
            'Clone the repository, copy .env.example to .env, install '
            .'dependencies, and run the test suite. A green suite confirms your '
            .'local environment matches CI.'
        );

        $section->addTitle('Review workflow', 2);
        $section->addText(
            'Open a pull request, request the Copilot reviewer, wait for CI to '
            .'pass, address review comments, and merge only when both the '
            .'review and the checks are green.',
            null,
            ['alignment' => Jc::START]
        );

        IOFactory::createWriter($word, 'Word2007')->save($path);
    }

    private function verifyPdf(string $path): string
    {
        $bytes = (string) file_get_contents($path);
        $source = new \App\Services\Kb\Pipeline\SourceDocument(
            sourcePath: 'incident-runbook.pdf',
            mimeType: 'application/pdf',
            bytes: $bytes,
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );
        $converter = app(\App\Services\Kb\Pipeline\PipelineRegistry::class)
            ->resolveConverter('application/pdf');

        return strtolower($converter->convert($source)->markdown);
    }

    /**
     * Minimal, dependency-free PDF writer: one Helvetica text block per
     * page, correct xref byte offsets, parseable by smalot/pdfparser.
     *
     * @param  list<list<string>>  $pages
     */
    private function buildPdf(array $pages): string
    {
        $objects = [];
        $pageCount = count($pages);

        // Object numbering: 1=Catalog, 2=Pages, 3=Font, then per page a
        // Page obj and a Contents obj.
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        $kids = [];
        $pageObjNum = 4;
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = ($pageObjNum + $i * 2).' 0 R';
        }
        $objects[2] = "<< /Type /Pages /Kids [".implode(' ', $kids)."] /Count {$pageCount} >>";
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

        foreach ($pages as $i => $lines) {
            $pageObj = $pageObjNum + $i * 2;
            $contentObj = $pageObj + 1;

            // PdfConverter emits its OWN "## Page N" header per extracted
            // page, which PdfPageChunker splits on — so we don't embed page
            // markers in the text stream (that would just duplicate them).
            $stream = $this->textStream($lines);

            $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R "
                ."/MediaBox [0 0 612 792] "
                ."/Resources << /Font << /F1 3 0 R >> >> "
                ."/Contents {$contentObj} 0 R >>";
            $objects[$contentObj] = "<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $maxObj = max(array_keys($objects));
        $pdf .= "xref\n0 ".($maxObj + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($n = 1; $n <= $maxObj; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n] ?? 0);
        }
        $pdf .= "trailer\n<< /Size ".($maxObj + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }

    /** @param  list<string>  $lines */
    private function textStream(array $lines): string
    {
        $s = "BT\n/F1 12 Tf\n14 TL\n72 720 Td\n";
        foreach ($lines as $i => $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            if ($i === 0) {
                $s .= "({$escaped}) Tj\n";
            } else {
                $s .= "T* ({$escaped}) Tj\n";
            }
        }
        $s .= "ET";

        return $s;
    }
}
