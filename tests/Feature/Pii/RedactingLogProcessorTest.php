<?php

declare(strict_types=1);

namespace Tests\Feature\Pii;

use App\Pii\Logging\PiiRedactingProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Padosoft\PiiRedactor\RedactorEngine;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Tests\TestCase;

/**
 * v4.3/W1 sub-PR 4.5 — A1 — PiiRedactingProcessor unit-style feature
 * test. Drives a real Monolog\Logger with our processor + a TestHandler
 * (in-memory record sink) so we can read back exactly what would have
 * landed on disk.
 *
 * R16 alignment: the test body runs `$logger->info(...)` with PII in
 * the message, context, AND a nested array — then reads `getRecords()`
 * back and asserts the literal email pattern is NOT present anywhere.
 */
final class RedactingLogProcessorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
    }

    public function test_processor_redacts_message_and_nested_context_strings(): void
    {
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_logs', true);

        /** @var RedactorEngine $engine */
        $engine = app(RedactorEngine::class);
        $processor = new PiiRedactingProcessor($engine);

        $handler = new TestHandler;
        $logger = new MonologLogger('test');
        $logger->pushHandler($handler);
        $logger->pushProcessor($processor);

        $logger->info('user signed up: mario@example.com', [
            'email' => 'mario@example.com',
            'meta' => ['contact' => 'reach giulia@example.org'],
        ]);

        $records = $handler->getRecords();
        $this->assertCount(1, $records);

        $record = $records[0];
        $message = $record['message'];
        $context = $record['context'];

        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            $message,
            'Log message must not retain raw email pattern.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            (string) $context['email'],
            'Top-level context value must be redacted.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            (string) $context['meta']['contact'],
            'Nested context value must be redacted.',
        );
    }
}
