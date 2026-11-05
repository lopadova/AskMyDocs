<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Code;

use App\Models\KnowledgeChunk;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\RetrievalFilters;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0035 D7 — the full-text branch adds a CODE-only `simple` pass when code
 * ingestion is on, merges by chunk id keeping the best score, and is exactly
 * the single prose pass when it is off (R43). The SQL itself only runs on
 * PostgreSQL (the E2E job); here the two passes are replaced so the merge and
 * the flag gate are proven on the unit suite.
 */
final class CodeFullTextMergeTest extends TestCase
{
    /** @var list<bool> the `$codeOnly` argument of every pass that ran */
    public array $passes = [];

    private function service(Collection $prose, Collection $code): KbSearchService
    {
        $test = $this;

        return new class($this->app, $prose, $code, $test) extends KbSearchService {
            public function __construct($app, private Collection $prose, private Collection $code, private CodeFullTextMergeTest $probe)
            {
                $base = $app->make(KbSearchService::class);
                foreach ((array) $base as $k => $v) {
                    // copy collaborators from a fully-built instance
                    $name = preg_replace('/^\0.*\0/', '', (string) $k);
                    $this->{$name} = $v;
                }
            }

            protected function fullTextSearchOnce(string $query, ?string $projectKey, int $limit, string $tenantId, ?RetrievalFilters $filters, bool $codeOnly): Collection
            {
                $this->probe->passes[] = $codeOnly;

                return $codeOnly ? $this->code : $this->prose;
            }

            public function run(string $q, int $limit = 10): Collection
            {
                $m = new \ReflectionMethod(KbSearchService::class, 'fullTextSearch');
                $m->setAccessible(true);

                return $m->invoke($this, $q, 'p', $limit, 'default', null);
            }
        };
    }

    private function chunk(int $id, float $score): KnowledgeChunk
    {
        $c = new KnowledgeChunk();
        $c->id = $id;
        $c->fts_score = $score;

        return $c;
    }

    #[Test]
    public function off_only_the_prose_pass_runs(): void
    {
        config(['kb.code.ingestion_enabled' => false]);
        $prose = collect([$this->chunk(1, 0.5)]);

        $result = $this->service($prose, collect([$this->chunk(2, 0.9)]))->run('calculateVatRounding');

        $this->assertSame([false], $this->passes);
        $this->assertSame([1], $result->pluck('id')->all());
    }

    #[Test]
    public function on_the_code_pass_is_merged_by_id_best_score_wins_and_ordered(): void
    {
        config(['kb.code.ingestion_enabled' => true]);
        $prose = collect([$this->chunk(1, 0.2), $this->chunk(2, 0.6)]);
        $code = collect([$this->chunk(2, 0.9), $this->chunk(3, 0.4)]);

        $result = $this->service($prose, $code)->run('calculateVatRounding');

        $this->assertSame([false, true], $this->passes);
        $this->assertSame([2, 3, 1], $result->pluck('id')->all());
        $this->assertSame(0.9, (float) $result->first()->fts_score, 'the better score of the duplicate wins');
    }

    #[Test]
    public function on_the_merge_respects_the_limit_and_skips_work_when_the_code_pass_is_empty(): void
    {
        config(['kb.code.ingestion_enabled' => true]);
        // The prose pass arrives already ordered by score (SQL ORDER BY).
        $prose = collect([$this->chunk(2, 0.6), $this->chunk(1, 0.2)]);

        $this->assertSame([2, 1], $this->service($prose, collect())->run('x')->pluck('id')->all());

        $this->passes = [];
        $merged = $this->service($prose, collect([$this->chunk(3, 0.95)]))->run('x', 2);
        $this->assertSame([3, 2], $merged->pluck('id')->all());
    }
}
