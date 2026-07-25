<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\EmailDataset\EmailDatasetQualityValidator;
use App\Services\Demo\EmailDataset\EmailDatasetReader;
use App\Services\Demo\EmailDataset\DatasetVersion;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only quality gate for an already-published generated email dataset.
 *
 * The generator runs the same gate before publication; this command lets CI
 * and operators verify a retained artifact without replacing or regenerating
 * it. Reader validation covers manifest/shard checksums and every record,
 * while the quality validator covers cross-record identity, thread and canary
 * invariants.
 */
final class ValidateCaseStudyEmailsCommand extends Command
{
    protected $signature = 'demo:validate-case-study-emails
        {--dataset-version= : Versione esatta da validare}
        {--profile=large : Risolve la versione standard del profilo gold, demo, large o stress}
        {--dataset-root=storage/app/demo-email-datasets : Directory radice dei dataset generati}';

    protected $description = 'Valida checksum, contratto, identità, thread e isolamento di un dataset email generato.';

    public function handle(
        EmailDatasetReader $reader,
        EmailDatasetQualityValidator $qualityValidator,
    ): int {
        try {
            $datasetVersion = $this->resolveDatasetVersion();
            $root = $this->resolveDatasetRoot();
            $directory = $reader->datasetDirectory($root, $datasetVersion);
            $manifest = $reader->manifestForVersion($root, $datasetVersion);

            $profile = trim((string) $this->option('profile'));
            if (
                trim((string) $this->option('dataset-version')) === ''
                && ($manifest['profile'] ?? null) !== $profile
            ) {
                throw new \RuntimeException(
                    "Il manifest {$datasetVersion} appartiene al profilo "
                    .(string) ($manifest['profile'] ?? 'sconosciuto').", non {$profile}.",
                );
            }

            $qualityValidator->validate($directory);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $statistics = (array) ($manifest['statistics'] ?? []);
        $this->components->info("Dataset valido: {$datasetVersion}");
        $this->table(
            ['Record', 'Shard', 'Thread', 'SHA-256 aggregato'],
            [[
                number_format((int) $manifest['total_records'], 0, ',', '.'),
                number_format((int) $manifest['total_shards'], 0, ',', '.'),
                number_format((float) ($statistics['thread_ratio'] ?? 0) * 100, 1, ',', '.').'%',
                (string) $manifest['aggregate_checksum'],
            ]],
        );

        return self::SUCCESS;
    }

    private function resolveDatasetVersion(): string
    {
        $datasetVersion = trim((string) $this->option('dataset-version'));
        if ($datasetVersion !== '') {
            if (preg_match('/^[a-z0-9-]+$/', $datasetVersion) !== 1) {
                throw new \InvalidArgumentException(
                    "Dataset version non valida: '{$datasetVersion}'.",
                );
            }

            return $datasetVersion;
        }

        $profile = trim((string) $this->option('profile'));
        return DatasetVersion::standard($profile);
    }

    private function resolveDatasetRoot(): string
    {
        $root = trim((string) $this->option('dataset-root'));
        if ($root === '') {
            throw new \InvalidArgumentException('--dataset-root non può essere vuoto.');
        }

        return str_starts_with($root, DIRECTORY_SEPARATOR) ? $root : base_path($root);
    }
}
