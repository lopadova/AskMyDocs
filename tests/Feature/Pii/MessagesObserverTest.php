<?php

declare(strict_types=1);

namespace Tests\Feature\Pii;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v4.3/W1 sub-PR 4.5 — A3 — MessageObserver feature test.
 */
final class MessagesObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
    }

    public function test_default_off_persists_content_verbatim(): void
    {
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.persist_chat_redacted', false);

        $msg = $this->createMessage('Reach me at mario@example.com');

        $this->assertStringContainsString('mario@example.com', (string) $msg->fresh()->content);
    }

    public function test_both_knobs_on_redacts_content_via_observer(): void
    {
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.persist_chat_redacted', true);

        $msg = $this->createMessage('My email is mario@example.com please reply');
        $persisted = (string) $msg->fresh()->content;

        // R26 — prove no leak via regex.
        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            $persisted,
            'Email pattern must not survive redaction.',
        );
        $this->assertStringNotContainsString('mario@example.com', $persisted);
    }

    private function createMessage(string $content): Message
    {
        $user = User::create([
            'name' => 't',
            'email' => 't_'.uniqid().'@example.com',
            'password' => bcrypt('x'),
        ]);
        $conv = Conversation::create([
            'user_id' => $user->id,
            'title' => 'plain title',
            'project_key' => 'hr-portal',
        ]);

        return Message::create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => $content,
            'created_at' => now(),
        ]);
    }
}
