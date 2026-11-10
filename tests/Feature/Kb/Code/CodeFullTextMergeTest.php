<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Code;

use App\Models\KnowledgeChunk;
use App\Services\Kb\KbSearchService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0035 D7 — the code-only `simple` full-text pass is merged with the prose
 * pass by chunk id, best score wins. The SQL itself only runs on PostgreSQL (the
 * E2E job); the merge is pure and is proven here. The flag gate is a plain
 * config check inside fullTextSearch(), exercised on the Postgres job.
 */
final class CodeFullTextMergeTest extends TestCase
{
    private function merge(Collection $prose, Collection $code, int $limit = 10): Collection
    {
        $method = new \ReflectionMethod(KbSearchService::class, 'mergeFtsPasses');
        $method->setAccessible(true);

        return $method->invoke($this->app->make(KbSearchService::class), $prose, $code, $limit);
    }

    private function chunk(int $id, float $score): KnowledgeChunk
    {
        $c = new KnowledgeChunk();
        $c->id = $id;
        $c->fts_score = $score;

        return $c;
    }

    #[Test]
    public function the_code_pass_is_merged_by_id_best_score_wins_and_ordered(): void
    {
        $prose = collect([$this->chunk(2, 0.6), $this->chunk(1, 0.2)]);
        $code = collect([$this->chunk(2, 0.9), $this->chunk(3, 0.4)]);

        $result = $this->merge($prose, $code);

        $this->assertSame([2, 3, 1], $result->pluck('id')->all());
        $this->assertSame(0.9, (float) $result->first()->fts_score, 'the better score of the duplicate wins');
    }

    #[Test]
    public function an_empty_code_pass_returns_the_prose_pass_untouched(): void
    {
        $prose = collect([$this->chunk(2, 0.6), $this->chunk(1, 0.2)]);

        $this->assertSame($prose, $this->merge($prose, collect()));
    }

    #[Test]
    public function the_merge_respects_the_limit(): void
    {
        $prose = collect([$this->chunk(2, 0.6), $this->chunk(1, 0.2)]);

        $this->assertSame([3, 2], $this->merge($prose, collect([$this->chunk(3, 0.95)]), 2)->pluck('id')->all());
    }

    #[Test]
    public function the_full_text_branch_is_postgres_only_so_off_sqlite_it_is_empty_in_both_flag_states(): void
    {
        $service = $this->app->make(KbSearchService::class);
        $method = new \ReflectionMethod(KbSearchService::class, 'fullTextSearch');
        $method->setAccessible(true);

        foreach ([false, true] as $enabled) {
            config(['kb.code.ingestion_enabled' => $enabled]);
            $this->assertTrue($method->invoke($service, 'calculateVatRounding', 'p', 10, 'default')->isEmpty());
        }
    }
}
