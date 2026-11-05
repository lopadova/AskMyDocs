<?php

namespace Tests\Feature\Agent;

use App\Services\Qa\InferenceLedger;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InferenceLedgerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('app/private/qa/ledger-'.bin2hex(random_bytes(5)));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function ledger(float $budget = 1): void
    {
        $manifest = ['budget_usd' => $budget, 'max_calls' => 10, 'allowed_models' => [
            'economical/model' => ['paths' => ['/api/v1/chat/completions'], 'input_per_million' => .3,
                'output_per_million' => 1.2, 'output_reserve_tokens' => 4096]]];
        $path = $this->directory.'/manifest.json';
        file_put_contents($path, json_encode($manifest));
        (new InferenceLedger)->register($path);
    }

    public function test_a_disallowed_model_is_stopped_before_network_dispatch(): void
    {
        Http::fake();$this->ledger();
        try {
            Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'expensive/model']);
            $this->fail('Unapproved model was dispatched.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('qa_unapproved_inference_model', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_budget_is_reserved_before_dispatch_and_billed_cost_is_recorded_once(): void
    {
        Http::fake(['*' => Http::response(['id' => 'generation', 'model' => 'economical/model',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2500, 'cost' => .003]])]);
        $this->ledger(.004);
        Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'economical/model', 'max_tokens' => 3000]);
        $state = json_decode(file_get_contents($this->directory.'/inference-ledger.json'), true);
        $this->assertSame(.003, $state['cost_usd']);
        $this->assertCount(1, $state['calls']);
        $this->assertSame([], $state['pending']);
        try {
            Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'economical/model', 'max_tokens' => 4096]);
            $this->fail('Over-budget request was dispatched.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('qa_inference_stopped_before_dispatch', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_unknown_billing_does_not_count_as_zero_or_allow_another_paid_call(): void
    {
        Http::fake(['*' => Http::response(['model' => 'economical/model', 'choices' => []])]);$this->ledger();
        Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'economical/model']);
        $state = json_decode(file_get_contents($this->directory.'/inference-ledger.json'), true);
        $this->assertSame('unknown', $state['calls'][0]['billing_status']);
        $this->assertNull($state['calls'][0]['cost_usd']);
        $this->assertNotNull($state['stop_reason']);
        $this->expectException(\RuntimeException::class);
        Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'economical/model']);
    }
}
