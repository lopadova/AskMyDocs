<?php

declare(strict_types=1);

namespace App\Services\Qa;

use Illuminate\Support\Facades\Validator;

/** Public metadata only. Original messages, expected answers and credentials stay private. */
final class CampaignManifest
{
    public const PATHS = ['/api/v1/chat/completions', '/api/v1/responses', '/api/v1/embeddings', '/api/alpha/decisions'];

    public static function validate(array $data): array
    {
        Validator::make($data, [
            'schema_version' => ['required', 'integer', 'in:1'],
            'budget_usd' => ['required', 'numeric', 'gt:0', 'max:'.config('qa.max_budget_usd', 10)],
            'max_calls' => ['required', 'integer', 'between:1,5000'],
            'corpus_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'allowed_models' => ['required', 'array', 'min:1', 'max:20'],
            'allowed_models.*' => ['array:paths,input_per_million,output_per_million,output_reserve_tokens,response_models,price_source,price_observed_at,identity_source'],
            'allowed_models.*.paths' => ['required', 'array', 'min:1', 'max:4'],
            'allowed_models.*.paths.*' => ['required', 'string', 'in:'.implode(',', self::PATHS)],
            'allowed_models.*.input_per_million' => ['required', 'numeric', 'min:0', 'max:10000'],
            'allowed_models.*.output_per_million' => ['required', 'numeric', 'min:0', 'max:10000'],
            'allowed_models.*.output_reserve_tokens' => ['required', 'integer', 'between:1,1000000'],
            'allowed_models.*.response_models' => ['required', 'array', 'min:1', 'max:10'],
            'allowed_models.*.response_models.*' => ['required', 'string', 'max:160', 'regex:~^[a-zA-Z0-9_./:-]+$~D'],
            'allowed_models.*.price_source' => ['required', 'url:https', 'max:300'],
            'allowed_models.*.price_observed_at' => ['required', 'date'],
            'allowed_models.*.identity_source' => ['sometimes', 'url:https', 'max:300'],
            'live_sources' => ['required', 'array:api,mcp'],
            'live_sources.api' => ['present', 'array', 'max:50'],
            'live_sources.mcp' => ['present', 'array', 'max:50'],
            'live_sources.*.*' => ['string', 'max:100', 'regex:~^[a-zA-Z0-9_.:-]+$~D'],
        ])->validate();
        foreach (array_keys($data['allowed_models']) as $model) {
            if (! is_string($model) || strlen($model) > 160 || ! preg_match('~^[a-zA-Z0-9_./:-]+$~D', $model)) {
                throw new \InvalidArgumentException('qa_invalid_model_identifier');
            }
        }

        return [...array_intersect_key($data, array_flip(['schema_version', 'budget_usd', 'max_calls',
            'corpus_sha256', 'allowed_models', 'live_sources'])),
            'evaluation' => ['version' => 'qa-rubric-v1', 'first_turn' => 'fixed', 'followups' => 'adaptive_separate',
                'dimensions' => ['source_support', 'subquestion_coverage', 'temporal_consistency',
                    'case_separation', 'literal_values', 'authorization', 'table_boundaries', 'courteous_request_separation'],
                'outcomes' => ['pass', 'fail', 'unverifiable'], 'oracle' => 'independent_original_sources']];
    }

    public static function nanos(mixed $usd): int
    {
        if (! is_numeric($usd) || ! is_finite((float) $usd) || (float) $usd < 0 || (float) $usd > 100000) {
            throw new \DomainException('qa_invalid_cost');
        }
        return (int) ceil((float) $usd * 1_000_000_000);
    }

    public static function usd(int $nanos): string
    {
        return sprintf('%d.%09d', intdiv($nanos, 1_000_000_000), $nanos % 1_000_000_000);
    }
}
