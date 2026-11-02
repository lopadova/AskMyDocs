<?php

namespace Tests\Unit\Decisions;

use App\Agent\Grounding\UncertainSourceSupportEvaluator;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UncertainSourceSupportEvaluatorTest extends TestCase
{
    private function state(): array
    {
        return ['question' => 'What did the team confirm?', 'subquestions' => [['identifiers' => ['CASE-A']]],
            'sources' => ['original' => 'Ada confirmed collection on June 7.', 'later' => 'Arrival remains unconfirmed.'],
            'pairs' => [['source_kind' => 'document', 'claim_id' => 'claim_0', 'subquestion_id' => 0, 'claim' => 'Ada confirmed collection on June 7.',
                'source_ref' => 'original', 'related_evidence' => [['source_ref' => 'later']]]]];
    }

    #[DataProvider('decisions')]
    public function test_only_an_ambiguous_supported_winner_gets_one_source_only_adjudication(string $category, float $p, bool $called): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::fake(['*' => Http::response(['id' => 'd', 'model' => 'test', 'answers' => ['supported' => ['type' => 'noul', 'noul' => .96]]])]);
        $initial = ['claim_0' => ['choice' => $category, 'probabilities' => [$category => $p]]];
        $result = app(UncertainSourceSupportEvaluator::class)->evaluate($this->state(), $initial, null, null, 'test');
        if ($called) {
            $this->assertSame(.96, $result['decisions']['claim_0']['probabilities']['supported_relevant']);
            $this->assertSame($initial['claim_0'], $result['trace']['claim_0']['initial_decision']);
            Http::assertSent(fn ($r) => count($r['state']['sources']) === 2 && isset($r['questions']['supported']));
        } else {
            $this->assertSame($initial, $result['decisions']);
            Http::assertNothingSent();
        }
    }

    public static function decisions(): array
    {
        return [['supported_relevant', .79, true], ['supported_relevant', .95, false],
            ['unsupported', .95, false], ['conflicting', .95, false], ['supported_off_topic', .95, false]];
    }

    #[DataProvider('abstentions')]
    public function test_unavailable_or_uncertain_adjudication_keeps_the_original_abstention(int $status, array $payload): void
    {
        config(['ai.providers.openrouter.key' => 'test']);
        Http::fake(['*' => Http::response($payload, $status)]);
        $initial = ['claim_0' => ['choice' => 'supported_relevant', 'probabilities' => ['supported_relevant' => .7]]];
        $this->assertSame($initial, app(UncertainSourceSupportEvaluator::class)->evaluate($this->state(), $initial, null, null, 'test')['decisions']);
    }
    public static function abstentions(): array
    {
        return [[503, []], [200, ['id' => 'd', 'model' => 'test', 'answers' => ['supported' => ['type' => 'noul', 'noul' => .6]]]],
            [200, ['id' => 'd', 'model' => 'test', 'answers' => ['supported' => ['type' => 'noul', 'noul' => .02]]]]];
    }

}
