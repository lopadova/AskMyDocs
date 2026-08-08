<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentMessageCatalog;
use Tests\TestCase;

final class AgentMessageCatalogTest extends TestCase
{
    public function test_english_and_italian_catalogs_have_identical_keys(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $english = require $projectRoot.'/lang/en/agent.php';
        $italian = require $projectRoot.'/lang/it/agent.php';

        $this->assertSame($this->flattenKeys($english), $this->flattenKeys($italian));
    }

    public function test_it_formats_a_message_in_the_full_run_locale(): void
    {
        $event = app(AgentMessageCatalog::class)->format('it-IT', 'tool.progress', [
            'completed' => 4,
            'estimated' => 10,
        ]);

        $this->assertSame('it-IT', $event['locale']);
        $this->assertSame('tool.progress', $event['message_key']);
        $this->assertSame('Completate 4 richieste API su circa 10.', $event['message']);
    }

    public function test_unknown_keys_fall_back_without_leaking_the_key(): void
    {
        $event = app(AgentMessageCatalog::class)->format('en-US', 'future.event');

        $this->assertSame('The assistant is working.', $event['message']);
        $this->assertStringNotContainsString('future.event', $event['message']);
    }

    /** @param array<string,mixed> $messages @return list<string> */
    private function flattenKeys(array $messages, string $prefix = ''): array
    {
        $keys = [];
        foreach ($messages as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                array_push($keys, ...$this->flattenKeys($value, $path));
            } else {
                $keys[] = $path;
            }
        }
        sort($keys);

        return $keys;
    }
}
