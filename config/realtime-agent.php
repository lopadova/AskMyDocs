<?php

declare(strict_types=1);

use AgentsFullDuplex\RealtimeAgent\Providers\ElevenLabs\ElevenLabsProvider;
use AgentsFullDuplex\RealtimeAgent\Providers\Fake\FakeRealtimeProvider;
use AgentsFullDuplex\RealtimeAgent\Providers\OpenAI\OpenAIRealtimeProvider;

$routeMiddleware = ['web', 'auth', 'tenant.authorize', 'redact-chat-pii', 'ai.disclosure'];
$consentFeature = trim((string) env('AI_ACT_CONSENT_GATE_CHAT_FEATURE', ''));
if ($consentFeature !== '') {
    $routeMiddleware[] = 'ai.consent:'.$consentFeature;
}

return [
    // Host-level rollout fence. Vendor control routes stay registered so Fake
    // integration tests and persisted audit remain available; only the trusted
    // session-start endpoint is disabled by this switch.
    'enabled' => (bool) env('REALTIME_AGENT_ENABLED', false),

    'default' => env('REALTIME_AGENT_PROVIDER', 'fake'),

    'session_ttl_minutes' => (int) env('REALTIME_AGENT_SESSION_TTL_MINUTES', 30),
    'retention_days' => (int) env('REALTIME_AGENT_RETENTION_DAYS', 90),

    'providers' => [
        'fake' => [
            'driver' => FakeRealtimeProvider::class,
        ],
        'openai' => [
            'driver' => OpenAIRealtimeProvider::class,
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_LIVE_MODEL', 'gpt-live-1'),
            'backend_model' => env('OPENAI_LIVE_BACKEND_MODEL', 'gpt-5.6-terra'),
            'voice' => env('OPENAI_LIVE_VOICE', 'marin'),
            'store' => env('OPENAI_LIVE_STORE', false),
            'history_max_messages' => 64,
            'history_max_characters' => 24_000,
            'context_max_characters' => 1_600,
            'transcript_gap_ms' => 1_200,
            'close_timeout_ms' => 3_000,
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => 30,
        ],
        'elevenlabs' => [
            'driver' => ElevenLabsProvider::class,
            'api_key' => env('ELEVENLABS_API_KEY'),
            'agent_id' => env('ELEVENLABS_AGENT_ID'),
            'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),
            'cost_currency' => env('ELEVENLABS_COST_CURRENCY', 'USD'),
            'timeout' => 30,
        ],
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'realtime-agent',
        'middleware' => $routeMiddleware,
    ],

    'state' => [
        'driver' => env('REALTIME_AGENT_STATE_DRIVER', 'database'),
        'persist_events' => true,
        'snapshot_debounce_ms' => 150,
    ],

    'audit' => [
        'max_message_characters' => 65_535,
        'pricing' => [
            'fake' => [
                'version' => 'fake-v1',
                'default_model' => 'fake-realtime',
                'models' => [
                    'fake-realtime' => [
                        'currency' => 'USD',
                        'unit_size' => 1_000_000,
                        'rates' => [
                            'input_text_tokens' => 0,
                            'output_text_tokens' => 0,
                            'input_audio_tokens' => 0,
                            'output_audio_tokens' => 0,
                        ],
                        'source' => 'deterministic-test-provider',
                    ],
                ],
            ],
            'openai' => [
                'version' => 'openai-2026-09-11',
                'default_model' => 'gpt-live-1',
                'aliases' => [
                    'gpt-realtime-2025-08-28' => 'gpt-realtime',
                ],
                'models' => [
                    'gpt-live-1' => [
                        'currency' => 'USD',
                        'unit_size' => 60,
                        'rates' => [
                            'duration_seconds' => 0.05,
                        ],
                        'effective_at' => '2026-09-10',
                        'source' => 'https://developers.openai.com/api/docs/models/gpt-live-1',
                    ],
                    'gpt-5.6-terra' => [
                        'currency' => 'USD',
                        'unit_size' => 1_000_000,
                        'rates' => [
                            'input_text_tokens' => 2.00,
                            'cached_input_text_tokens' => 0.20,
                            'output_text_tokens' => 12.00,
                        ],
                        'effective_at' => '2026-09-11',
                        'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-terra',
                    ],
                    'gpt-realtime' => [
                        'currency' => 'USD',
                        'unit_size' => 1_000_000,
                        'rates' => [
                            'input_text_tokens' => 4.00,
                            'cached_input_text_tokens' => 0.40,
                            'output_text_tokens' => 16.00,
                            'input_audio_tokens' => 32.00,
                            'cached_input_audio_tokens' => 0.40,
                            'output_audio_tokens' => 64.00,
                            'input_image_tokens' => 5.00,
                            'cached_input_image_tokens' => 0.50,
                        ],
                        'source' => 'https://developers.openai.com/api/docs/models/gpt-realtime',
                    ],
                    'gpt-4o-mini-transcribe' => [
                        'currency' => 'USD',
                        'unit_size' => 1_000_000,
                        'rates' => [
                            'input_audio_tokens' => 1.25,
                            'output_text_tokens' => 5.00,
                        ],
                        'source' => 'https://developers.openai.com/api/docs/models/gpt-4o-mini-transcribe',
                    ],
                ],
            ],
        ],
    ],

    'security' => [
        'require_authorization' => true,
        'ui_command_ttl_seconds' => 15,
        'redact_tool_arguments' => true,
        'max_payload_kb' => 256,
        'authorization_ability' => 'useAskMyDocsRealtimeSession',
        'max_tool_calls_per_minute' => 120,
    ],
];
