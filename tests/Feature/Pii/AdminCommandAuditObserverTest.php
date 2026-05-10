<?php

declare(strict_types=1);

namespace Tests\Feature\Pii;

use App\Models\AdminCommandAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v4.3/W1 sub-PR 4.5 — A5 — AdminCommandAuditObserver feature test.
 */
final class AdminCommandAuditObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
    }

    public function test_default_off_keeps_args_and_stdout_verbatim(): void
    {
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.redact_command_audit', false);

        $row = AdminCommandAudit::query()->create([
            'user_id' => null,
            'command' => 'kb:delete',
            'args_json' => ['owner-email' => 'mario@example.com', 'path' => 'docs/x.md'],
            'status' => AdminCommandAudit::STATUS_COMPLETED,
            'stdout_head' => 'Deleted file owned by mario@example.com',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $persisted = $row->fresh();
        $this->assertSame('mario@example.com', $persisted->args_json['owner-email']);
        $this->assertStringContainsString('mario@example.com', (string) $persisted->stdout_head);
    }

    public function test_both_knobs_on_redact_args_stdout_and_error(): void
    {
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_command_audit', true);

        $row = AdminCommandAudit::query()->create([
            'user_id' => null,
            'command' => 'kb:delete',
            'args_json' => ['owner-email' => 'mario@example.com', 'path' => 'docs/x.md'],
            'status' => AdminCommandAudit::STATUS_FAILED,
            'stdout_head' => 'Deleted file owned by mario@example.com',
            'error_message' => 'permission denied for paolo@example.it',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $persisted = $row->fresh();

        // R26 — every email-shaped value must be redacted.
        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            (string) $persisted->stdout_head,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            (string) $persisted->error_message,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            (string) $persisted->args_json['owner-email'],
        );

        // operator identity columns NOT touched.
        $this->assertSame('kb:delete', $persisted->command);
        $this->assertSame(AdminCommandAudit::STATUS_FAILED, $persisted->status);
    }
}
