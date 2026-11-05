<?php

namespace Tests\Unit\Decisions;

use App\Services\Qa\SourceBackedAnswerAuditor;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SourceBackedAnswerAuditorTest extends TestCase
{
    public static function verdicts(): array
    {
        return ['all checks pass' => [.8, .8, 'pass'], 'faithfulness uncertain' => [.79, .99, 'inconclusive'],
            'coverage uncertain' => [.99, .79, 'inconclusive'], 'unsupported fact' => [.1, .99, 'incorrect'],
            'missing requirement' => [.99, .1, 'incomplete']];
    }

    #[DataProvider('verdicts')]
    public function test_every_check_must_meet_the_unchanged_threshold(float $faithful, float $complete, string $outcome): void
    {
        config(['ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($faithful, $complete) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                $answers[$key] = ['type' => 'noul', 'noul' => match ($key) {
                    'faithful_1' => $faithful, 'complete_1' => $complete, default => .99,
                }];
            }
            return Http::response(['model' => 'jev-test', 'answers' => $answers]);
        });
        $result = app(SourceBackedAnswerAuditor::class)->audit('Status and total?', "Status shipped.\n\nTotal EUR 10.",
            [['text' => 'Status shipped, total EUR 10.']], ['Current status', 'Total with currency']);
        $this->assertSame($outcome, $result['outcome']);
        $this->assertSame(.8, $result['threshold']);
        $this->assertSame($faithful, $result['faithful_probability']);
        $this->assertSame($complete, $result['complete_probability']);
        $this->assertCount(4, $result['checks']);
    }

    public function test_unavailable_provider_never_produces_a_pass(): void
    {
        config(['ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([], 503));
        $this->expectException(\App\Decisions\DecisionException::class);
        app(SourceBackedAnswerAuditor::class)->audit('Status?', 'Shipped.', [['text' => 'Shipped.']], ['Status']);
    }
}
