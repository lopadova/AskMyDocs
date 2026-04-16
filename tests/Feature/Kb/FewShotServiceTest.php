<?php

namespace Tests\Feature\Kb;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Kb\FewShotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FewShotServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Test',
            'email' => 'test'.uniqid().'@example.com',
            'password' => 'x',
        ], $overrides));
    }

    private function makeConversation(User $user, ?string $projectKey = null): Conversation
    {
        return Conversation::create([
            'user_id' => $user->id,
            'title' => 'Test conv',
            'project_key' => $projectKey,
        ]);
    }

    private function makeTurn(
        Conversation $conv,
        string $question,
        string $answer,
        ?string $rating = null,
        int $offsetMinutes = 0,
    ): Message {
        $base = now()->subMinutes(60 - $offsetMinutes);

        Message::create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => $question,
            'created_at' => $base,
        ]);

        return Message::create([
            'conversation_id' => $conv->id,
            'role' => 'assistant',
            'content' => $answer,
            'rating' => $rating,
            'created_at' => $base->copy()->addSeconds(30),
        ]);
    }

    public function test_returns_empty_when_no_positive_messages(): void
    {
        $user = $this->makeUser();
        $conv = $this->makeConversation($user);
        $this->makeTurn($conv, 'Q', 'A', rating: null);

        $this->assertSame([], (new FewShotService())->getExamples($user->id));
    }

    public function test_returns_positive_q_a_pairs(): void
    {
        $user = $this->makeUser();
        $conv = $this->makeConversation($user);

        $this->makeTurn($conv, 'How to config OAuth?', 'Do A, B, C', rating: 'positive', offsetMinutes: 1);
        $this->makeTurn($conv, 'What about SAML?', 'Look at docs', rating: 'negative', offsetMinutes: 2);

        $examples = (new FewShotService())->getExamples($user->id);

        $this->assertCount(1, $examples);
        $this->assertSame('How to config OAuth?', $examples[0]['question']);
        $this->assertSame('Do A, B, C', $examples[0]['answer']);
    }

    public function test_filters_by_user(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $conv1 = $this->makeConversation($user1);
        $conv2 = $this->makeConversation($user2);

        $this->makeTurn($conv1, 'U1 Q', 'U1 A', rating: 'positive', offsetMinutes: 5);
        $this->makeTurn($conv2, 'U2 Q', 'U2 A', rating: 'positive', offsetMinutes: 10);

        $examples = (new FewShotService())->getExamples($user1->id);

        $this->assertCount(1, $examples);
        $this->assertSame('U1 Q', $examples[0]['question']);
    }

    public function test_filters_by_project_key(): void
    {
        $user = $this->makeUser();
        $convA = $this->makeConversation($user, 'proj-a');
        $convB = $this->makeConversation($user, 'proj-b');

        $this->makeTurn($convA, 'A-Q', 'A-A', rating: 'positive', offsetMinutes: 5);
        $this->makeTurn($convB, 'B-Q', 'B-A', rating: 'positive', offsetMinutes: 10);

        $examples = (new FewShotService())->getExamples($user->id, 'proj-a');

        $this->assertCount(1, $examples);
        $this->assertSame('A-Q', $examples[0]['question']);
    }

    public function test_respects_limit(): void
    {
        $user = $this->makeUser();
        $conv = $this->makeConversation($user);

        for ($i = 1; $i <= 5; $i++) {
            $this->makeTurn($conv, "Q{$i}", "A{$i}", rating: 'positive', offsetMinutes: $i * 5);
        }

        $examples = (new FewShotService())->getExamples($user->id, null, limit: 2);

        $this->assertCount(2, $examples);
    }

    public function test_truncates_long_content(): void
    {
        $user = $this->makeUser();
        $conv = $this->makeConversation($user);

        $longQ = str_repeat('q', 800);
        $longA = str_repeat('a', 1500);

        $this->makeTurn($conv, $longQ, $longA, rating: 'positive', offsetMinutes: 5);

        $examples = (new FewShotService())->getExamples($user->id);

        $this->assertSame(500, mb_strlen($examples[0]['question']));
        $this->assertSame(1000, mb_strlen($examples[0]['answer']));
    }

    public function test_skips_assistant_messages_with_no_preceding_user(): void
    {
        $user = $this->makeUser();
        $conv = $this->makeConversation($user);

        // Orphan assistant message with no prior user message
        Message::create([
            'conversation_id' => $conv->id,
            'role' => 'assistant',
            'content' => 'orphan',
            'rating' => 'positive',
            'created_at' => now(),
        ]);

        $this->assertSame([], (new FewShotService())->getExamples($user->id));
    }
}
