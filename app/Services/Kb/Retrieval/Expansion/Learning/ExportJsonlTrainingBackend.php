<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion\Learning;

use App\Models\KbExpansionModel;

/**
 * W7 — the default backend: the dataset stays on the operator's private disk
 * as an OpenAI-chat-format JSONL (system / user / assistant), ready for an
 * external trainer (Unsloth, TRL, Axolotl …) that fine-tunes a self-hosted
 * model; the result is then served through the `self_hosted` expansion
 * profile. No data leaves the deployment. The run ends at `exported`.
 */
final class ExportJsonlTrainingBackend implements ExpansionTrainingBackend
{
    public function name(): string
    {
        return 'export';
    }

    public function submit(KbExpansionModel $run, string $datasetContents): void
    {
        $run->forceFill(['status' => 'exported'])->save();
    }

    public function refresh(KbExpansionModel $run): void
    {
        // Nothing to poll: an exported dataset is trained outside the platform.
    }
}
