<?php

namespace Tests\Unit\Agent;

use App\Agent\AgentAnswerSynthesizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentRepairCoverageSelectionTest extends TestCase
{
    public static function repairs(): array
    {
        return [
            'fewer paragraphs but restores missing independent task' => [[0, 0, 0, 0, 0], [0, 0, 0, 1], .19, .73, false, true],
            'higher confidence cannot erase an answered task' => [[0, 1], [1, 1, 1], .19, .99, true, false],
            'no coverage improvement' => [[0], [0, 1], .73, .73, false, false],
            'lower coverage is not an improvement' => [[0], [0, 1], .73, .7, false, false],
            'shorter answer in same task still requires confirmed coverage' => [[0, 0, 0], [0], .19, .73, false, false],
            'confirmed complete shorter answer retains all tasks' => [[0, 0, 1], [0, 1], .19, .9, true, true],
        ];
    }

    #[DataProvider('repairs')]
    public function test_repair_selection_preserves_answered_tasks_without_changing_thresholds(array $before, array $after, float $p, float $q, bool $sufficient, bool $expected): void
    {
        $claims = fn ($tasks) => array_map(fn ($id) => ['subquestion_id' => $id], $tasks);
        $result = (new \ReflectionMethod(AgentAnswerSynthesizer::class, 'repairImprovesCoverage'))->invoke(
            app(AgentAnswerSynthesizer::class), ['valid' => true, 'claims' => $claims($before)],
            ['valid' => true, 'claims' => $claims($after)], ['probability_true' => $p],
            ['probability_true' => $q, 'sufficient' => $sufficient]);
        $this->assertSame($expected, $result);
        $this->assertSame(.8, (float) config('reasoning.threshold'));
    }
}
