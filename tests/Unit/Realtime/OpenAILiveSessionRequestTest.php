<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime;

use App\Realtime\OpenAILiveSessionRequest;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class OpenAILiveSessionRequestTest extends TestCase
{
    public function test_only_the_spoken_progress_pair_is_added_and_reapplying_is_idempotent(): void
    {
        $request = new Request('POST', 'https://api.openai.com/v1/live/sessions', [], json_encode([
            'transport' => ['type' => 'webrtc'],
            'session' => ['delegation' => ['responses' => ['tools' => [['name' => 'askmydocs_chat_turn']]]],
                'client' => ['data_channel' => ['allowed_client_events' => ['response.create'],
                    'allowed_server_events' => [['type' => 'error']]]]],
        ]));
        $middleware = new OpenAILiveSessionRequest;
        $updated = $middleware($request);
        $channel = json_decode((string) $updated->getBody(), true)['session']['client']['data_channel'];
        $this->assertSame(['response.create', 'session.commentary.append'], $channel['allowed_client_events']);
        $this->assertSame([['type' => 'error'], ['type' => 'session.commentary.appended']], $channel['allowed_server_events']);
        $this->assertSame((string) $updated->getBody(), (string) $middleware($updated)->getBody());
    }

    public function test_other_requests_and_agent_definitions_are_untouched(): void
    {
        $middleware = new OpenAILiveSessionRequest;
        foreach ([
            new Request('POST', 'https://api.openai.com/v1/responses', [], '{}'),
            new Request('GET', 'https://api.openai.com/v1/live/sessions', [], '{}'),
            new Request('POST', 'https://api.openai.com/v1/live/sessions', [], json_encode([
                'transport' => ['type' => 'webrtc'], 'session' => ['delegation' => ['responses' => ['tools' => [['name' => 'other_tool']]]]],
            ])),
        ] as $request) {
            $this->assertSame($request, $middleware($request));
        }
    }
}
