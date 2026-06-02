<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Chat;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\SuggestedFollowupGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Unit-test the LLM-output parser + retry/fallback semantics.
 * Database-backed because we exercise the Conversation/Message Eloquent
 * relationship to fetch the last user/assistant pair.
 */
final class SuggestedFollowupGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_strict_json_array(): void
    {
        $gen = new SuggestedFollowupGenerator();
        $conv = $this->seedConversationWithPair();

        $ai = $this->mockAi('["one", "two", "three"]');

        $out = $gen->generate($conv, $ai);

        $this->assertSame(['one', 'two', 'three'], $out);
    }

    public function test_extracts_array_substring_from_chatty_response(): void
    {
        // LLM ignored the "STRICT JSON" instruction and added prose.
        $gen = new SuggestedFollowupGenerator();
        $conv = $this->seedConversationWithPair();

        $ai = $this->mockAi('Sure! Here are three follow-ups: ["alpha", "beta", "gamma"] hope this helps.');

        $out = $gen->generate($conv, $ai);

        $this->assertSame(['alpha', 'beta', 'gamma'], $out);
    }

    public function test_falls_back_to_bullet_parse_when_json_absent(): void
    {
        $gen = new SuggestedFollowupGenerator();
        $conv = $this->seedConversationWithPair();

        // Numbered list — no JSON array.
        $ai = $this->mockAi("1. First question\n2. Second question\n3. Third question");

        $out = $gen->generate($conv, $ai);

        $this->assertSame(['First question', 'Second question', 'Third question'], $out);
    }

    public function test_caps_output_at_three_suggestions(): void
    {
        $gen = new SuggestedFollowupGenerator();
        $conv = $this->seedConversationWithPair();

        $ai = $this->mockAi('["a", "b", "c", "d", "e"]');

        $out = $gen->generate($conv, $ai);

        $this->assertCount(3, $out);
        $this->assertSame(['a', 'b', 'c'], $out);
    }

    public function test_returns_empty_when_no_assistant_turn_exists(): void
    {
        $gen = new SuggestedFollowupGenerator();
        $user = \App\Models\User::create([
            'name' => 'alice',
            'email' => 'alice-'.uniqid().'@demo.local',
            'password' => bcrypt('x'),
        ]);
        $conv = $user->conversations()->create(['title' => 'empty']);
        $conv->messages()->create(['role' => 'user', 'content' => 'hello']);

        $ai = $this->mock(AiManager::class, function (MockInterface $m) {
            $m->shouldNotReceive('chat');
        });

        $out = $gen->generate($conv, $ai);

        $this->assertSame([], $out);
    }

    public function test_returns_empty_on_provider_exception(): void
    {
        $gen = new SuggestedFollowupGenerator();
        $conv = $this->seedConversationWithPair();

        $ai = $this->mock(AiManager::class, function (MockInterface $m) {
            $m->shouldReceive('chat')->andThrow(new \RuntimeException('boom'));
        });

        $out = $gen->generate($conv, $ai);

        $this->assertSame([], $out);
    }

    public function test_filters_empty_strings_out_of_json_array(): void
    {
        $gen = new SuggestedFollowupGenerator();
        $conv = $this->seedConversationWithPair();

        $ai = $this->mockAi('["valid one", "", "  ", "valid two"]');

        $out = $gen->generate($conv, $ai);

        $this->assertSame(['valid one', 'valid two'], $out);
    }

    private function seedConversationWithPair(): Conversation
    {
        $user = \App\Models\User::create([
            'name' => 'alice',
            'email' => 'alice-'.uniqid().'@demo.local',
            'password' => bcrypt('x'),
        ]);
        $conv = $user->conversations()->create(['title' => 't']);
        $conv->messages()->create(['role' => 'user', 'content' => 'How does PTO work?']);
        $conv->messages()->create([
            'role' => 'assistant',
            'content' => 'PTO accrues monthly at 1.5 days.',
        ]);
        return $conv;
    }

    private function mockAi(string $content): AiManager
    {
        return $this->mock(AiManager::class, function (MockInterface $m) use ($content) {
            $m->shouldReceive('chat')->once()->andReturn(new AiResponse(
                content: $content,
                provider: 'openai',
                model: 'gpt-4o-mini',
                promptTokens: 50,
                completionTokens: 30,
                totalTokens: 80,
            ));
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
