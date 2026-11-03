<?php

declare(strict_types=1);

namespace App\Services\Qa;

use App\Models\QaInferenceAttempt;
use Illuminate\Support\Facades\Http;

/** A native embedding name is accepted only with matching provider billing metadata. */
final readonly class VerifiedEmbeddingIdentity
{
    private function __construct(
        private string $attemptId,
        private string $requested,
        private string $actual,
        private string $generation,
        private int $cost,
        public array $metadata,
    ) {}

    public static function verify(QaInferenceAttempt $attempt, ?string $actual, ?string $generation, ?int $cost): ?self
    {
        // Do not infer aliases by stripping arbitrary vendors or model versions.
        if ($attempt->path !== '/api/v1/embeddings' || $attempt->requested_model !== 'openai/text-embedding-3-small'
            || $actual !== 'text-embedding-3-small' || $cost === null
            || $generation === null || ! preg_match('/^gen-[a-zA-Z0-9_-]{1,144}$/D', $generation)) return null;
        try {
            $response = Http::withToken(config('ai.providers.openrouter.key'))->timeout(10)
                ->get('https://openrouter.ai/api/v1/generation', ['id' => $generation]);
            $data = $response->json('data');
            if (! $response->successful() || ! is_array($data) || ($data['id'] ?? null) !== $generation
                || ($data['api_type'] ?? null) !== 'embeddings' || ($data['model'] ?? null) !== $attempt->requested_model
                || CampaignManifest::nanos($data['total_cost'] ?? null) !== $cost) return null;
            return new self($attempt->id, $attempt->requested_model, $actual, $generation, $cost, [
                'schema_version' => 1, 'kind' => 'provider_generation_embedding_identity',
                'source' => 'https://openrouter.ai/api/v1/generation', 'generation_id' => $generation,
                'canonical_model' => $data['model'], 'native_model' => $actual, 'cost_nanos' => $cost,
                'response_sha256' => hash('sha256', $response->body()), 'verified_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function matches(QaInferenceAttempt $attempt, ?string $actual, ?string $generation, ?int $cost): bool
    {
        return $attempt->id === $this->attemptId && $attempt->path === '/api/v1/embeddings' && $attempt->requested_model === $this->requested
            && $actual === $this->actual && $generation === $this->generation && $cost === $this->cost;
    }
}
