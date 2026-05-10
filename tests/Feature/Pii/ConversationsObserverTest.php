<?php

declare(strict_types=1);

namespace Tests\Feature\Pii;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v4.3/W1 sub-PR 4.5 — A3 — ConversationObserver feature test.
 *
 * Three observable contracts (R16 alignment — the body MUST trigger
 * the redaction path the name promises):
 *   1. Default (both knobs off) — title persisted verbatim.
 *   2. Master switch off + persist knob on — master beats: verbatim.
 *   3. Both knobs on — title is redacted via mask strategy before
 *      persistence; the raw email NEVER lands in `conversations.title`.
 */
final class ConversationsObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
    }

    public function test_default_off_persists_title_verbatim(): void
    {
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.persist_chat_redacted', false);

        $user = User::create([
            'name' => 't',
            'email' => 't1@example.com',
            'password' => bcrypt('x'),
        ]);
        $title = 'Email mario.rossi@example.com about leave';
        Conversation::create([
            'user_id' => $user->id,
            'title' => $title,
            'project_key' => 'hr-portal',
        ]);

        $persisted = (string) Conversation::query()->latest('id')->first()?->title;
        $this->assertSame($title, $persisted, 'Default-off must keep the title verbatim.');
    }

    public function test_master_switch_off_short_circuits_persist_knob(): void
    {
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.persist_chat_redacted', true);

        $user = User::create([
            'name' => 't',
            'email' => 't2@example.com',
            'password' => bcrypt('x'),
        ]);
        $title = 'Reach giulia.bianchi@example.org tomorrow';
        Conversation::create([
            'user_id' => $user->id,
            'title' => $title,
            'project_key' => 'hr-portal',
        ]);

        $persisted = (string) Conversation::query()->latest('id')->first()?->title;
        $this->assertSame(
            $title,
            $persisted,
            'Master switch off must beat persist knob on.',
        );
    }

    public function test_both_knobs_on_redacts_pii_in_title(): void
    {
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.persist_chat_redacted', true);

        $user = User::create([
            'name' => 't',
            'email' => 't3@example.com',
            'password' => bcrypt('x'),
        ]);
        Conversation::create([
            'user_id' => $user->id,
            'title' => 'Email mario.rossi@example.com today',
            'project_key' => 'hr-portal',
        ]);

        $persisted = (string) Conversation::query()->latest('id')->first()?->title;
        // R26 — prove no leak via regex.
        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            $persisted,
            'Email pattern must not survive redaction.',
        );
        $this->assertStringNotContainsString('mario.rossi@example.com', $persisted);
    }
}
