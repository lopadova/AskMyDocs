<?php

namespace Tests\Unit\Decisions;

use App\Agent\Grounding\VerifiedRepairAdditions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class VerifiedRepairAdditionsTest extends TestCase
{
    #[DataProvider('decisions')]
    public function test_only_verified_novel_consistent_material_can_be_added(float $novel, float $consistent, bool $keep): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::fake(function ($request) use ($novel, $consistent) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) $answers[$key] = ['type' => 'noul',
                'noul' => str_starts_with($key, 'adds_') ? $novel : $consistent];
            return Http::response(['id' => 'decision', 'model' => 'test', 'answers' => $answers]);
        });
        $original = ['valid' => true, 'claims' => [['text' => 'Expected ITEM-A.', 'fact_id' => 'original', 'subquestion_id' => 0]],
            'coverage_validation' => ['sufficient' => false, 'probability_true' => .7]];
        $candidate = ['valid' => true, 'claims' => [['text' => 'Warehouse actually received ITEM-B.', 'fact_id' => 'new', 'subquestion_id' => 0]],
            'coverage_validation' => ['sufficient' => false, 'probability_true' => .3]];
        $result = app(VerifiedRepairAdditions::class)->augment('Compare expected and received items.', $original, $candidate);
        if (! $keep) { $this->assertNull($result); return; }
        $this->assertSame(['original', 'new'], array_column($result['claims'], 'fact_id'));
        $this->assertTrue($result['partial']);
        $this->assertFalse($result['coverage_validation']['sufficient']);
        $this->assertSame($original['claims'][0], $result['claims'][0]);
    }

    public static function decisions(): array
    {
        return [[.98, .98, true], [.1, .98, false], [.55, .98, false], [.98, .1, false]];
    }

    public function test_an_invalid_candidate_or_full_claim_budget_cannot_trigger_an_addition_call(): void
    {
        Http::preventStrayRequests();
        $original = ['valid' => true, 'claims' => array_fill(0, 10, ['text' => 'Verified'])];
        $this->assertNull(app(VerifiedRepairAdditions::class)->augment('Question', $original, ['valid' => true, 'claims' => [['text' => 'New']]]));
        $this->assertNull(app(VerifiedRepairAdditions::class)->augment('Question', ['valid' => true, 'claims' => [['text' => 'Verified']]], ['valid' => false, 'claims' => [['text' => 'Unsupported']]]));
        Http::assertNothingSent();
    }
}
