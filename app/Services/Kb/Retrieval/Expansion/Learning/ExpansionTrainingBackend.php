<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion\Learning;

use App\Models\KbExpansionModel;

/**
 * W7 — where a fine-tuning dataset goes. `submit()` receives the run row with
 * its dataset already written (`dataset_path`) and moves it to `exported`
 * (nothing else to do) or `submitted` (a provider job exists); `refresh()`
 * polls a provider job until `ready` (with the provider's `model_id`) or
 * `failed`. Implementations persist the row themselves and never throw for a
 * provider-side failure — they record `failed` + a machine `failure_reason`.
 */
interface ExpansionTrainingBackend
{
    public function name(): string;

    public function submit(KbExpansionModel $run, string $datasetContents): void;

    public function refresh(KbExpansionModel $run): void;
}
