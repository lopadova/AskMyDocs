<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\EmailDataset\DatasetGenerationRequest;
use App\Services\Demo\EmailDataset\EmailDatasetGenerator;
use Illuminate\Console\Command;
use Throwable;

final class GenerateCaseStudyEmailsCommand extends Command
{
    protected $signature = 'demo:generate-case-study-emails
        {--profile=large : Profilo gold, demo, large o stress}
        {--seed=20260723 : Seed deterministico non negativo}
        {--catalog-version=v1 : Versione immutabile dei cataloghi}
        {--company=* : Limita la generazione a una o più aziende}
        {--mailbox=* : Limita la generazione a una o più mailbox}
        {--output=storage/app/demo-email-datasets : Directory radice degli artefatti generati}
        {--check : Rigenera in temporanea e verifica byte-identità con l\'artefatto pubblicato}
        {--force : Rigenera la stessa versione e la accetta solo se byte-identica}
        {--stats : Stampa il riepilogo compatto del dataset}';

    protected $description = 'Genera dataset email case-study deterministici in shard JSONL, senza LLM o rete.';

    public function handle(EmailDatasetGenerator $generator): int
    {
        if ((bool) $this->option('check') && (bool) $this->option('force')) {
            $this->components->error('--check e --force sono mutuamente esclusivi.');

            return self::INVALID;
        }

        $seedOption = (string) $this->option('seed');
        if ($seedOption === '' || ! ctype_digit($seedOption)) {
            $this->components->error('--seed deve essere un intero non negativo.');

            return self::INVALID;
        }

        $output = (string) $this->option('output');
        if ($output === '') {
            $this->components->error('--output non può essere vuoto.');

            return self::INVALID;
        }
        if (! str_starts_with($output, DIRECTORY_SEPARATOR)) {
            $output = base_path($output);
        }

        try {
            $result = $generator->generate(new DatasetGenerationRequest(
                profile: (string) $this->option('profile'),
                seed: (int) $seedOption,
                catalogVersion: (string) $this->option('catalog-version'),
                outputDirectory: $output,
                companies: array_values(array_map('strval', (array) $this->option('company'))),
                mailboxes: array_values(array_map('strval', (array) $this->option('mailbox'))),
                force: (bool) $this->option('force'),
                check: (bool) $this->option('check'),
            ));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $verb = $result->checkOnly ? 'verificato' : 'generato';
        $this->components->info("Dataset {$verb}: {$result->datasetVersion}");
        $this->line("Directory: {$result->directory}");

        if ((bool) $this->option('stats')) {
            $this->table(
                ['Record', 'Shard', 'SHA-256 aggregato'],
                [[
                    number_format($result->totalRecords, 0, ',', '.'),
                    number_format($result->shardCount, 0, ',', '.'),
                    $result->aggregateChecksum,
                ]],
            );
        }

        return self::SUCCESS;
    }
}
