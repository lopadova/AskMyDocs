<?php

declare(strict_types=1);

namespace App\Realtime;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;

/** Add the spoken progress capability only to the AskMyDocs Live handshake. */
final class OpenAILiveSessionRequest
{
    public function __invoke(RequestInterface $request): RequestInterface
    {
        if ($request->getMethod() !== 'POST' || ! str_ends_with($request->getUri()->getPath(), '/live/sessions')) {
            return $request;
        }
        $payload = json_decode((string) $request->getBody(), true);
        $tools = data_get($payload, 'session.delegation.responses.tools', []);
        if (! is_array($payload) || data_get($payload, 'transport.type') !== 'webrtc'
            || ! is_array($tools) || ! in_array('askmydocs_chat_turn', array_column($tools, 'name'), true)) {
            return $request;
        }

        $channel = data_get($payload, 'session.client.data_channel', []);
        $clientEvents = $channel['allowed_client_events'] ?? [];
        $serverEvents = $channel['allowed_server_events'] ?? [];
        $channel['allowed_client_events'] = array_values(array_unique([...$clientEvents, 'session.commentary.append']));
        if (! in_array(['type' => 'session.commentary.appended'], $serverEvents, true)) {
            $serverEvents[] = ['type' => 'session.commentary.appended'];
        }
        $channel['allowed_server_events'] = $serverEvents;
        data_set($payload, 'session.client.data_channel', $channel);
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $request->withBody(Utils::streamFor($body))->withHeader('Content-Length', (string) strlen($body));
    }
}
