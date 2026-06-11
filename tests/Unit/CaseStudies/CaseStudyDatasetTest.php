<?php

declare(strict_types=1);

namespace Tests\Unit\CaseStudies;

use App\Services\Kb\Canonical\CanonicalParsedDocument;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Support\Canonical\CanonicalStatus;
use App\Support\Canonical\CanonicalType;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the case-study isolation dataset (docs/case-studies/data).
 *
 * The dataset exists to exercise per-project isolation, the canonical graph
 * and the rejected-approach injection: this suite turns its prose contract
 * into a CI gate, so a copy-paste slip (a company-A canary inside company B,
 * a rejected doc with a non-retrievable status, a dangling wikilink) fails
 * the build instead of producing false positives during manual test runs.
 *
 * Pure-unit on purpose: CanonicalParser::parse()/validate() are container-free,
 * the fixtures are the real markdown files in the repo.
 */
final class CaseStudyDatasetTest extends TestCase
{
    private const DATA_DIR = __DIR__ . '/../../../docs/case-studies/data';

    private const PROJECTS = [
        'rotta-logistics',
        'prometeo-antincendio',
        'passolibero-calzature',
    ];

    private const DOCS_PER_PROJECT = 11;

    /**
     * Each project's OWN brand tokens: a doc_id may carry them only inside
     * that project. Guards against the copy-paste class of defect where
     * company-A identifiers (ROTTA-…) leak into company B/C frontmatter.
     */
    private const BRAND_ID_TOKENS = [
        'rotta-logistics' => ['ROTTA'],
        'prometeo-antincendio' => ['PROMETEO', 'FENICE', 'SALAMANDRA'],
        'passolibero-calzature' => ['PL-', 'PASSO'],
    ];

    /**
     * Canary facts ("esche") from README §1 and the §6.5 password table:
     * each string must live ONLY in its own project's data files. A canary
     * present in two projects breaks the isolation-test design itself.
     */
    private const CANARIES = [
        'rotta-logistics' => [
            'HUB-MI-07',
            'HUB-NA-03',
            'HUB-RM-05',
            'VeloxCorriere',
            'TurboPony',
            'OrbitaWMS',
            '800-ROTTA1',
            'ORIZZONTE BLU',
            'FERMO QUERCIA',
            'NEBBIA GIALLA',
        ],
        'prometeo-antincendio' => [
            'Fenice-7',
            'Salamandra',
            'ScadenzarioPRO',
            'Cantiere Aurora',
            'VENTO DEL NORD',
            'FALCO 12',
            'CENERE SILENTE',
        ],
        'passolibero-calzature' => [
            'Zefiro Run',
            'AeroMesh',
            'GripFlex',
            'ClubPasso',
            'BrioExpress',
            '800-PASSO9',
            'MARE CALMO',
            'STELLA NOVE',
            'PONENTE ROSSO',
        ],
    ];

    /** @var array<string, array<string, CanonicalParsedDocument>>|null project => relative file => DTO */
    private static ?array $parsed = null;

    /** @var array<string, string>|null "project/file.md" => raw markdown */
    private static ?array $raw = null;

    // -----------------------------------------------------------------
    // Structure
    // -----------------------------------------------------------------

    public function test_dataset_has_exactly_the_three_expected_projects(): void
    {
        $dirs = array_map('basename', glob(self::DATA_DIR . '/*', GLOB_ONLYDIR) ?: []);
        sort($dirs);

        $expected = self::PROJECTS;
        sort($expected);

        self::assertSame($expected, $dirs, 'docs/case-studies/data must contain exactly the three case-study projects.');
    }

    public function test_every_project_has_the_expected_number_of_markdown_documents(): void
    {
        foreach (self::PROJECTS as $project) {
            $files = array_merge(
                glob(self::DATA_DIR . "/{$project}/*.md") ?: [],
                glob(self::DATA_DIR . "/{$project}/*.markdown") ?: [],
            );

            self::assertCount(
                self::DOCS_PER_PROJECT,
                $files,
                "Project [{$project}] must ship exactly " . self::DOCS_PER_PROJECT . ' markdown documents (README §3 expects this count).'
            );
        }
    }

    // -----------------------------------------------------------------
    // Frontmatter validity (CanonicalParser contract)
    // -----------------------------------------------------------------

    public function test_every_document_has_valid_canonical_frontmatter(): void
    {
        $parser = new CanonicalParser();

        foreach ($this->parsedDocuments() as $project => $docs) {
            foreach ($docs as $file => $doc) {
                self::assertSame([], $doc->parseErrors, "[{$project}/{$file}] frontmatter YAML must parse cleanly.");

                $result = $parser->validate($doc);
                self::assertTrue(
                    $result->valid,
                    "[{$project}/{$file}] frontmatter must validate; errors: " . json_encode($result->errors)
                );

                self::assertInstanceOf(CanonicalType::class, $doc->type, "[{$project}/{$file}] `type` must resolve to a canonical enum value.");
                self::assertInstanceOf(CanonicalStatus::class, $doc->status, "[{$project}/{$file}] `status` must resolve to a canonical enum value.");
                self::assertNotNull($doc->docId, "[{$project}/{$file}] must declare an `id` (doc_id).");
            }
        }
    }

    // -----------------------------------------------------------------
    // Uniqueness (per-project DB constraints + dataset design)
    // -----------------------------------------------------------------

    public function test_slugs_and_doc_ids_are_unique_within_each_project(): void
    {
        foreach ($this->parsedDocuments() as $project => $docs) {
            $slugs = [];
            $ids = [];

            foreach ($docs as $file => $doc) {
                $slugOwner = $slugs[(string) $doc->slug] ?? '';
                $idOwner = $ids[(string) $doc->docId] ?? '';

                self::assertArrayNotHasKey(
                    (string) $doc->slug,
                    $slugs,
                    "[{$project}/{$file}] slug `{$doc->slug}` duplicates {$slugOwner} — uq_kb_doc_slug would reject the second insert."
                );
                self::assertArrayNotHasKey(
                    (string) $doc->docId,
                    $ids,
                    "[{$project}/{$file}] id `{$doc->docId}` duplicates {$idOwner} — uq_kb_doc_doc_id would reject the second insert."
                );

                $slugs[(string) $doc->slug] = $file;
                $ids[(string) $doc->docId] = $file;
            }
        }
    }

    public function test_doc_ids_are_unique_across_projects(): void
    {
        $seen = [];

        foreach ($this->parsedDocuments() as $project => $docs) {
            foreach ($docs as $file => $doc) {
                $key = (string) $doc->docId;
                $firstOwner = $seen[$key] ?? '';
                self::assertArrayNotHasKey(
                    $key,
                    $seen,
                    "doc_id `{$key}` is reused by [{$firstOwner}] and [{$project}/{$file}]: legal for the tenant-scoped unique, but ambiguous for a dataset built to demonstrate clean per-company separation."
                );
                $seen[$key] = "{$project}/{$file}";
            }
        }
    }

    public function test_doc_ids_never_carry_another_companys_brand_token(): void
    {
        foreach ($this->parsedDocuments() as $project => $docs) {
            $forbidden = array_diff(
                array_unique(array_merge(...array_values(self::BRAND_ID_TOKENS))),
                self::BRAND_ID_TOKENS[$project]
            );

            foreach ($docs as $file => $doc) {
                foreach ($forbidden as $token) {
                    self::assertStringNotContainsStringIgnoringCase(
                        $token,
                        (string) $doc->docId,
                        "[{$project}/{$file}] doc_id `{$doc->docId}` carries the foreign brand token `{$token}` — a contamination canary inside the wrong company."
                    );
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // Canary isolation (the core purpose of this dataset)
    // -----------------------------------------------------------------

    public function test_every_canary_exists_in_its_own_project(): void
    {
        foreach (self::CANARIES as $project => $canaries) {
            $haystack = implode("\n", array_filter(
                $this->rawDocuments(),
                fn (string $path): bool => str_starts_with($path, $project . '/'),
                ARRAY_FILTER_USE_KEY
            ));

            foreach ($canaries as $canary) {
                self::assertStringContainsString(
                    $canary,
                    $haystack,
                    "Canary `{$canary}` must exist somewhere in [{$project}] — README §1/§6.5 declares it as that company's bait fact."
                );
            }
        }
    }

    public function test_no_canary_leaks_into_another_project(): void
    {
        foreach (self::CANARIES as $owner => $canaries) {
            foreach ($this->rawDocuments() as $path => $markdown) {
                [$project] = explode('/', $path, 2);
                if ($project === $owner) {
                    continue;
                }

                foreach ($canaries as $canary) {
                    self::assertStringNotContainsString(
                        $canary,
                        $markdown,
                        "Canary `{$canary}` belongs to [{$owner}] but appears in [{$path}]: the cross-company leak test would false-positive by design."
                    );
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // Rejected-approach injection requirements
    // -----------------------------------------------------------------

    public function test_each_project_has_exactly_one_accepted_rejected_approach_doc(): void
    {
        foreach ($this->parsedDocuments() as $project => $docs) {
            $rejected = array_filter($docs, fn (CanonicalParsedDocument $d): bool => $d->type === CanonicalType::RejectedApproach);

            self::assertCount(1, $rejected, "[{$project}] must ship exactly one rejected-approach doc (README §7 promises the ⚠ injection per company).");

            foreach ($rejected as $file => $doc) {
                self::assertSame(
                    CanonicalStatus::Accepted,
                    $doc->status,
                    "[{$project}/{$file}] rejected-approach doc must be status `accepted`: RejectedApproachInjector only picks accepted docs, any other status silently disables the feature for this company."
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Graph integrity (related / supersedes / wikilinks resolve in-project)
    // -----------------------------------------------------------------

    public function test_frontmatter_relations_resolve_within_the_same_project(): void
    {
        foreach ($this->parsedDocuments() as $project => $docs) {
            $slugs = array_map(fn (CanonicalParsedDocument $d): string => (string) $d->slug, $docs);

            foreach ($docs as $file => $doc) {
                $relations = [
                    'related' => $doc->relatedSlugs,
                    'supersedes' => $doc->supersedesSlugs,
                    'superseded_by' => $doc->supersededBySlugs,
                ];

                foreach ($relations as $field => $targets) {
                    foreach ($targets as $target) {
                        self::assertContains(
                            $target,
                            $slugs,
                            "[{$project}/{$file}] {$field} target `{$target}` does not match any slug in [{$project}]: the edge would dangle instead of linking the intended doc."
                        );
                    }
                }
            }
        }
    }

    public function test_body_wikilinks_resolve_within_the_same_project(): void
    {
        foreach ($this->parsedDocuments() as $project => $docs) {
            $slugs = array_map(fn (CanonicalParsedDocument $d): string => (string) $d->slug, $docs);

            foreach ($docs as $file => $doc) {
                preg_match_all('/\[\[([^\]]+)\]\]/', $doc->body, $matches);

                foreach ($matches[1] as $rawLink) {
                    // [[slug|alias]] and [[slug#anchor]] both target `slug`.
                    $target = trim(explode('#', explode('|', $rawLink, 2)[0], 2)[0]);

                    self::assertContains(
                        $target,
                        $slugs,
                        "[{$project}/{$file}] body wikilink [[{$rawLink}]] does not resolve to any slug in [{$project}]: it would create a dangling graph node instead of the intended edge."
                    );
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @return array<string, array<string, CanonicalParsedDocument>> */
    private function parsedDocuments(): array
    {
        if (self::$parsed !== null) {
            return self::$parsed;
        }

        $parser = new CanonicalParser();
        $parsed = [];

        foreach ($this->rawDocuments() as $path => $markdown) {
            [$project, $file] = explode('/', $path, 2);

            $doc = $parser->parse($markdown);
            self::assertNotNull($doc, "[{$path}] must start with a `---`-fenced frontmatter block (otherwise it ingests as non-canonical).");

            $parsed[$project][$file] = $doc;
        }

        return self::$parsed = $parsed;
    }

    /** @return array<string, string> "project/file.md" => raw markdown */
    private function rawDocuments(): array
    {
        if (self::$raw !== null) {
            return self::$raw;
        }

        $raw = [];

        foreach (self::PROJECTS as $project) {
            foreach (array_merge(
                glob(self::DATA_DIR . "/{$project}/*.md") ?: [],
                glob(self::DATA_DIR . "/{$project}/*.markdown") ?: [],
            ) as $absolute) {
                $content = file_get_contents($absolute);
                self::assertNotFalse($content, "Unable to read {$absolute}.");

                $raw[$project . '/' . basename($absolute)] = $content;
            }
        }

        self::assertNotSame([], $raw, 'Case-study dataset not found at ' . self::DATA_DIR);

        return self::$raw = $raw;
    }
}
