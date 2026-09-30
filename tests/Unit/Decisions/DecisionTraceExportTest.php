<?php

declare(strict_types=1);

namespace Tests\Unit\Decisions;

use App\Http\Controllers\Api\Admin\ConversationDebugTranscriptController;
use ReflectionMethod;
use Tests\TestCase;

final class DecisionTraceExportTest extends TestCase
{
    public function test_export_reports_model_answer_and_skipped_repair_separately(): void
    {
        $validation = (new ReflectionMethod(ConversationDebugTranscriptController::class, 'validation'))
            ->invoke(new ConversationDebugTranscriptController, [
                'status' => 'blocked', 'reason' => 'quote_not_in_tool_result',
                'semantic_validation' => [
                    ['stage' => 'initial_answer', 'attempted' => true, 'used' => true,
                        'status' => 'rejected', 'answer' => false, 'probability_true' => 0.04,
                        'model' => 'typesafe/jev-1.13', 'threshold' => 0.9],
                    ['stage' => 'repair', 'attempted' => false, 'used' => false,
                        'status' => 'not_used', 'reason' => 'deterministic_invalid'],
                ],
            ]);

        $this->assertTrue($validation['semantic']['used']);
        $this->assertSame('rejected', $validation['semantic']['status']);
        $this->assertFalse($validation['semantic']['answer']);
        $this->assertCount(2, $validation['semantic']['checks']);
        $this->assertSame('quote_not_in_tool_result', $validation['deterministic']['reason']);
    }

    public function test_old_runs_are_marked_not_recorded_rather_than_assumed_unused(): void
    {
        $validation = (new ReflectionMethod(ConversationDebugTranscriptController::class, 'validation'))
            ->invoke(new ConversationDebugTranscriptController, ['status' => 'grounded']);

        $this->assertSame('not_recorded', $validation['semantic']['status']);
        $this->assertNull($validation['semantic']['used']);
        $this->assertSame([], $validation['semantic']['checks']);
    }

    public function test_batch_export_exposes_each_claim_decision_and_shared_cost(): void
    {
        $validation = (new ReflectionMethod(ConversationDebugTranscriptController::class, 'validation'))
            ->invoke(new ConversationDebugTranscriptController, [
                'status' => 'grounded', 'semantic_validation' => [[
                    'stage' => 'claim_batch', 'attempted' => true, 'used' => true,
                    'status' => 'completed', 'model' => 'typesafe/jev-1.13',
                    'usage' => ['cost' => 0.001], 'latency_ms' => 42.0,
                    'checks' => [['claim_index' => 0, 'status' => 'accepted', 'probability_true' => 0.97]],
                ]],
            ]);

        $this->assertSame('accepted', $validation['semantic']['claims'][0]['status']);
        $this->assertSame(0.001, $validation['semantic']['usage']['cost']);
        $this->assertSame('typesafe/jev-1.13', $validation['semantic']['model']);
    }
}
