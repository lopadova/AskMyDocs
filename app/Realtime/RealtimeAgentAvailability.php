<?php

declare(strict_types=1);

namespace App\Realtime;

final class RealtimeAgentAvailability
{
    /** @return array{available:bool,reason:?string} */
    public function status(): array
    {
        if (! (bool) config('realtime-agent.enabled', false)) {
            return ['available' => false, 'reason' => 'disabled'];
        }

        return match ($this->provider()) {
            'fake' => ['available' => true, 'reason' => null],
            'openai' => $this->credentialStatus('realtime-agent.providers.openai.api_key'),
            'elevenlabs' => $this->elevenLabsStatus(),
            default => ['available' => false, 'reason' => 'provider_unavailable'],
        };
    }

    public function provider(): string
    {
        return trim((string) config('realtime-agent.default', 'fake'));
    }

    public function isAvailable(): bool
    {
        return $this->status()['available'];
    }

    /** @return array{available:bool,reason:?string} */
    private function credentialStatus(string $key): array
    {
        return trim((string) config($key, '')) !== ''
            ? ['available' => true, 'reason' => null]
            : ['available' => false, 'reason' => 'missing_credentials'];
    }

    /** @return array{available:bool,reason:?string} */
    private function elevenLabsStatus(): array
    {
        if (trim((string) config('realtime-agent.providers.elevenlabs.api_key', '')) === ''
            || trim((string) config('realtime-agent.providers.elevenlabs.agent_id', '')) === '') {
            return ['available' => false, 'reason' => 'missing_credentials'];
        }

        return ['available' => true, 'reason' => null];
    }
}
