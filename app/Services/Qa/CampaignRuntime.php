<?php

declare(strict_types=1);

namespace App\Services\Qa;

use App\Services\Admin\AppSettingsResolver;

final class CampaignRuntime
{
    /** Only allowlisted model/limit values, never credentials or tenant instructions. */
    public function snapshot(string $tenant, string $project): array
    {
        $settings = [];
        foreach (['ai.provider', 'ai.chat_model', 'ai.chat_routing', 'agent.synthesis.model', 'agent.synthesis.repair_model'] as $key) {
            $settings[$key] = app(AppSettingsResolver::class)->effective($key, $tenant, $project);
        }
        $providers = [];
        foreach ((array) config('ai.providers', []) as $name => $provider) {
            $providers[$name] = $provider['models'] ?? [];
        }
        return ['runtime' => app(\App\Agent\Debug\RunDiagnostics::class)->runtime(),
            'models' => $providers, 'effective_settings' => $settings,
            'provider' => config('ai.default'), 'embeddings_provider' => config('ai.embeddings_provider'),
            'preprocessor' => config('ai.question_preprocessor'), 'presentation' => config('agent.presentation'),
            'synthesis' => config('agent.synthesis'), 'grounding' => config('agent.grounding'),
            'decision_model' => config('decisions.models.jev.model'), 'threshold' => config('reasoning.threshold', .8),
            'limits' => config('agent.limits', []), 'finalization' => config('agent.finalization', [])];
    }
}
