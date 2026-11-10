<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion\Learning;

use App\Models\KbExpansionModel;
use App\Support\Http\AllowListedEndpoint;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * W7 — submits the dataset to the OpenAI fine-tuning API
 * (`POST /files` purpose=fine-tune, then `POST /fine_tuning/jobs`) and polls
 * `GET /fine_tuning/jobs/{id}` until the provider reports the fine-tuned model.
 *
 * Guardrails (SEC-LLM-001, GDPR):
 *  - the base model must be in the CODE-OWNED list {@see self::BASE_MODELS};
 *  - the API base URL must be an exact allow-listed https host
 *    ({@see AllowListedEndpoint}, default `api.openai.com`);
 *  - the caller ({@see ExpansionLearningService::train()}) has already checked
 *    the tenant's explicit training consent — this class is never reached
 *    without it;
 *  - provider responses are shape-checked; a 4xx is permanent (no retry), any
 *    failure is recorded as `failed` with a machine reason, never thrown at
 *    the user, never logged with the provider body.
 */
final class OpenAiFineTuningBackend implements ExpansionTrainingBackend
{
    /** Fine-tunable base models we accept. Tenant/admin input can only pick from these. */
    public const BASE_MODELS = ['gpt-4.1-mini-2025-04-14', 'gpt-4.1-nano-2025-04-14', 'gpt-4o-mini-2024-07-18'];

    private const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,200}$/';

    public function name(): string
    {
        return 'openai';
    }

    public function submit(KbExpansionModel $run, string $datasetContents): void
    {
        if (! in_array($run->base_model, self::BASE_MODELS, true)) {
            $this->fail($run, 'base_model_not_allowed');

            return;
        }
        $base = $this->baseUrl();
        if ($base === null) {
            $this->fail($run, 'endpoint_not_allowed');

            return;
        }

        try {
            $file = $this->client()->attach('file', $datasetContents, 'expansion.jsonl')
                ->post($base.'/files', ['purpose' => 'fine-tune']);
            $fileId = $this->idFrom($file, 'id');
            if ($fileId === null) {
                $this->fail($run, 'file_upload_'.$file->status());

                return;
            }
            $job = $this->client()->asJson()->post($base.'/fine_tuning/jobs', [
                'training_file' => $fileId,
                'model' => $run->base_model,
                'suffix' => 'amd-expansion',
            ]);
            $jobId = $this->idFrom($job, 'id');
            if ($jobId === null) {
                $this->fail($run, 'job_create_'.$job->status());

                return;
            }
        } catch (Throwable) {
            $this->fail($run, 'transport');

            return;
        }

        $run->forceFill(['status' => 'submitted', 'provider_file_id' => $fileId, 'provider_job_id' => $jobId])->save();
    }

    public function refresh(KbExpansionModel $run): void
    {
        if (! in_array($run->status, ['submitted', 'training'], true) || $run->provider_job_id === null) {
            return;
        }
        $base = $this->baseUrl();
        if ($base === null) {
            $this->fail($run, 'endpoint_not_allowed');

            return;
        }

        try {
            $response = $this->client()->get($base.'/fine_tuning/jobs/'.rawurlencode((string) $run->provider_job_id));
        } catch (Throwable) {
            return; // transient: poll again later
        }
        if (! $response->successful()) {
            if ($response->status() >= 400 && $response->status() < 500) {
                $this->fail($run, 'job_status_'.$response->status());
            }

            return;
        }

        $status = (string) $response->json('status');
        if ($status === 'succeeded') {
            $model = $this->idFrom($response, 'fine_tuned_model');
            $model === null ? $this->fail($run, 'no_model_id') : $run->forceFill(['status' => 'ready', 'model_id' => $model])->save();

            return;
        }
        if (in_array($status, ['failed', 'cancelled'], true)) {
            $this->fail($run, 'provider_'.$status);

            return;
        }
        $run->forceFill(['status' => 'training'])->save();
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()->timeout(60)->connectTimeout(10)
            ->withToken((string) config('ai.providers.openai.key', ''));
    }

    private function baseUrl(): ?string
    {
        $base = rtrim((string) config('kb.expansion_learning.training.openai_base_url', 'https://api.openai.com/v1'), '/');
        $hosts = (array) config('kb.expansion_learning.training.openai_allowed_hosts', ['api.openai.com']);

        return AllowListedEndpoint::violation($base, $hosts, false) === null ? $base : null;
    }

    private function idFrom(Response $response, string $key): ?string
    {
        $value = $response->successful() ? $response->json($key) : null;

        return is_string($value) && preg_match(self::ID_PATTERN, $value) === 1 ? $value : null;
    }

    private function fail(KbExpansionModel $run, string $reason): void
    {
        $run->forceFill(['status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 120)])->save();
    }
}
