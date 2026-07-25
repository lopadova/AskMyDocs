<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\KbPath;
use App\Support\TenantContext;
use Database\Seeders\CaseStudyUsersSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\TestEmailFixtures;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Orchestratore "one-shot" dell'ambiente di test case-study: aziende + utenti,
 * documenti markdown ingeriti in KB, e e-mail (purge + APPEND su IMAP). Riusa i
 * comandi/seeder esistenti — è solo colla, nessuna logica duplicata.
 *
 * Passi (idempotenti, ognuno disattivabile):
 *   1. Aziende + utenti  → RbacSeeder poi CaseStudyUsersSeeder (3 account/azienda
 *      viewer/admin/super-admin, membership isolata).
 *   2. Documenti         → copia docs/case-studies/data/<key>/ sul disco kb e
 *      `kb:ingest-folder case-studies/<key> --project=<key> --recursive --sync`.
 *   3. E-mail + connettori → fixture gold legacy oppure dataset generato
 *      (`--profile` / `--dataset-version`). Per i dataset v2 esegue sempre un
 *      preflight dal manifest, quindi APPEND scoped alla versione con purge o
 *      resume. `connector:imap:install --all` aggiorna le installazioni in
 *      place; con `--ingest-emails` aggiunge `--sync`.
 *
 *   php artisan demo:init-case-studies
 *   php artisan demo:init-case-studies --fresh --ingest-emails
 *   php artisan demo:init-case-studies --profile=large --generate-email-dataset
 *   php artisan demo:init-case-studies --profile=large --resume --ingest-emails
 *   php artisan demo:init-case-studies --skip-emails        # solo aziende+doc
 *
 * Prerequisiti per i passi reali: disco kb locale (o remoto), provider AI per gli
 * embeddings (doc + ingest e-mail), e CONNECTOR_TEST_GMAIL_PASSWORD in .env per
 * le e-mail. NON usare la config cache (il fixture e-mail legge env()).
 */
class InitCaseStudiesCommand extends Command
{
    private const DATA_DIR = 'docs/case-studies/data';

    private const KB_SUBDIR = 'case-studies';

    protected $signature = 'demo:init-case-studies
        {--tenant=default : Tenant in cui inizializzare}
        {--fresh : Esegue migrate:fresh PRIMA di tutto (DISTRUTTIVO)}
        {--skip-docs : Salta l\'ingest dei documenti markdown}
        {--skip-emails : Salta purge + APPEND delle e-mail su IMAP}
        {--profile= : Profilo email generato: gold, demo, large o stress}
        {--dataset-version= : Versione email generata specifica}
        {--dataset-root=storage/app/demo-email-datasets : Directory radice dei dataset generati}
        {--generate-email-dataset : Genera atomicamente il profilo prima del preflight}
        {--resume : Riprende i checkpoint email invece di purgare la dataset version}
        {--ingest-emails : Dopo l\'APPEND installa il connettore e ingerisce le e-mail in KB}';

    protected $description = 'Inizializza aziende, utenti, documenti e dataset email case-study con preflight e resume.';

    public function handle(TenantContext $tenant): int
    {
        $tenantId = (string) $this->option('tenant');
        $tenant->set($tenantId);

        $emailOptionsError = $this->validateEmailOptions();
        if ($emailOptionsError !== null) {
            $this->error($emailOptionsError);

            return self::INVALID;
        }

        if ((bool) $this->option('fresh')) {
            $this->warn('migrate:fresh (DISTRUTTIVO) — azzero il database…');
            $exitCode = $this->callChecked('migrate:fresh', ['--force' => true]);
            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        // 1) AZIENDE + UTENTI — RbacSeeder PRIMA (ruoli + backfill), poi il
        //    case-study seeder che crea i 3 account/azienda e ripristina
        //    l'isolamento delle membership.
        $this->components->info('1/4 — Aziende + utenti');
        $exitCode = $this->callChecked('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);
        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }
        $exitCode = $this->callChecked('db:seed', ['--class' => CaseStudyUsersSeeder::class, '--force' => true]);
        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        // 2) DOCUMENTI
        if (! (bool) $this->option('skip-docs')) {
            $this->components->info('2/4 — Documenti (copia su disco kb + ingest, un tenant per azienda)');
            $exitCode = $this->ingestDocuments();
            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        } else {
            $this->components->warn('2/4 — Documenti: saltato (--skip-docs)');
        }

        // 3) E-MAIL: generazione/preflight, APPEND su IMAP e installazione dei
        //    CONNETTORI per azienda.
        //    I connettori NON sono nei seeder puri (servono ping IMAP reale +
        //    segreto nel vault, impossibili in un seeder/test senza credenziali):
        //    si creano qui, una installazione per casella (label/project_key).
        //    `--sync` (con --ingest-emails) avvia anche l'ingest in KB.
        if (! (bool) $this->option('skip-emails')) {
            $this->components->info('3/4 — E-mail: dataset, APPEND su IMAP + connettori');
            $datasetArgs = $this->generatedDatasetArguments();

            if ((bool) $this->option('generate-email-dataset')) {
                $this->line('  Generazione deterministica del dataset email…');
                $exitCode = $this->callChecked('demo:generate-case-study-emails', [
                    '--profile' => (string) $this->option('profile'),
                    '--output' => (string) $this->option('dataset-root'),
                    '--force' => true,
                    '--stats' => true,
                ]);
                if ($exitCode !== self::SUCCESS) {
                    return $exitCode;
                }
            }

            if ($datasetArgs !== []) {
                $this->line('  Preflight manifest, volumi e costo potenziale…');
                $exitCode = $this->callChecked('mail:seed-imap', [
                    '--all' => true,
                    ...$datasetArgs,
                    '--summary-only' => true,
                    '--estimate-cost' => true,
                ]);
                if ($exitCode !== self::SUCCESS) {
                    return $exitCode;
                }
            }

            if ($this->emailPasswordPresent()) {
                $this->line('  Delivery IMAP…');
                $seedArgs = ['--all' => true];
                if ($datasetArgs === []) {
                    $seedArgs['--purge'] = true;
                } else {
                    $seedArgs = [
                        ...$seedArgs,
                        ...$datasetArgs,
                        '--summary-only' => true,
                    ];
                    $seedArgs[(bool) $this->option('resume') ? '--resume' : '--purge-dataset'] = true;
                }

                $exitCode = $this->callChecked('mail:seed-imap', $seedArgs);
                if ($exitCode !== self::SUCCESS) {
                    return $exitCode;
                }

                $this->line('  Installazione/aggiornamento connettori IMAP…');
                $installArgs = ['--all' => true];
                if ((bool) $this->option('ingest-emails')) {
                    $installArgs['--sync'] = true;
                }
                $exitCode = $this->callChecked('connector:imap:install', $installArgs);
                if ($exitCode !== self::SUCCESS) {
                    return $exitCode;
                }

                if ((bool) $this->option('ingest-emails')) {
                    $this->components->warn(
                        'I sync email sono stati accodati: il comando non attende il drenaggio dei worker.',
                    );
                }
            } else {
                $this->components->warn(
                    'Delivery IMAP e connettori saltati: CONNECTOR_TEST_GMAIL_PASSWORD non impostata in .env.',
                );
            }
        } else {
            $this->components->warn('3/4 — E-mail + connettori: saltato (--skip-emails)');
        }

        $this->newLine();
        $this->components->info('Riepilogo (tutti i tenant):');
        // Un tenant per azienda → niente filtro: mostra tutte le aziende.
        $exitCode = $this->callChecked('demo:list-companies');
        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $this->components->info(
            (bool) $this->option('ingest-emails') && ! (bool) $this->option('skip-emails')
                ? 'Inizializzazione completata; verifica il drenaggio dei sync email accodati.'
                : 'Inizializzazione completata.',
        );

        return self::SUCCESS;
    }

    /**
     * Copia i dataset markdown sul disco kb e li ingerisce, un progetto per
     * cartella. Le cartelle in docs/case-studies/data/ sono la fonte di verità
     * dei project_key (gating: tests/Unit/CaseStudies/CaseStudyDatasetTest).
     */
    private function ingestDocuments(): int
    {
        $disk = (string) config('kb.sources.disk', 'kb');
        $prefix = trim((string) config('kb.sources.path_prefix', ''), '/');
        $base = base_path(self::DATA_DIR);

        $dirs = glob($base.'/*', GLOB_ONLYDIR) ?: [];
        if ($dirs === []) {
            $this->components->warn("Nessun dataset in {$base} — niente documenti da ingerire.");

            return self::SUCCESS;
        }

        foreach ($dirs as $dir) {
            $projectKey = basename($dir);
            $files = glob($dir.'/*.md') ?: [];

            $this->copyDatasetToDisk($disk, $prefix, $projectKey, $files);

            $this->line(sprintf('  [%s] %d documenti → ingest (tenant %s)', $projectKey, count($files), $projectKey));
            // Un tenant per azienda: ingest nel tenant dell'azienda (= project_key).
            $exitCode = $this->callChecked('kb:ingest-folder', [
                'path' => self::KB_SUBDIR.'/'.$projectKey,
                '--project' => $projectKey,
                '--tenant' => $projectKey,
                '--recursive' => true,
                '--sync' => true,
            ]);
            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Esegue un sotto-comando e rende il suo exit code parte del contratto
     * dell'orchestratore. Un fallimento interrompe la pipeline nel chiamante e
     * viene propagato senza normalizzarlo a 1, così CI e operatori conservano la
     * causa originale.
     *
     * @param  array<string,mixed>  $arguments
     */
    private function callChecked(string $command, array $arguments = []): int
    {
        $exitCode = $this->call($command, $arguments);

        if ($exitCode !== self::SUCCESS) {
            $this->error(sprintf(
                "Comando '%s' fallito con exit code %d — interrompo.",
                $command,
                $exitCode,
            ));
        }

        return $exitCode;
    }

    /**
     * Copia i markdown di un progetto sul disco kb. `throw` è forzato SOLO per la
     * durata della copia e ripristinato in `finally`, così la fase di ingest
     * successiva (kb:ingest-folder, stesso processo) NON eredita le eccezioni del
     * filesystem abilitate — il flag vale unicamente per lo step di copia.
     *
     * @param  list<string>  $files
     */
    private function copyDatasetToDisk(string $disk, string $prefix, string $projectKey, array $files): void
    {
        // R14 — il disco KB (es. "s3" su Laravel Cloud) gira con `throw => false`,
        // quindi Flysystem inghiotte l'errore AWS reale e `put()` ritorna solo
        // `false`. Forziamo `throw` (e ri-risolviamo il disco perché il setting
        // sia effettivo) così l'eccezione S3 vera — AccessDenied / NoSuchBucket /
        // SignatureDoesNotMatch / endpoint irraggiungibile — emerge nel log invece
        // del generico "Copia fallita". Override runtime: vale anche con config:cache.
        $previousThrow = config("filesystems.disks.{$disk}.throw");
        config(["filesystems.disks.{$disk}.throw" => true]);
        Storage::forgetDisk($disk);

        try {
            foreach ($files as $file) {
                $relative = self::KB_SUBDIR.'/'.$projectKey.'/'.basename($file);
                $target = KbPath::normalize($prefix === '' ? $relative : $prefix.'/'.$relative);

                $contents = file_get_contents($file);
                if ($contents === false) {
                    throw new RuntimeException("Lettura fallita: {$file}");
                }

                // R4 — non ignorare il fallimento di Storage::put(). Con `throw`
                // forzato sopra una scrittura fallita lancia l'eccezione AWS reale,
                // che ri-lanciamo come `previous` col contesto (disco + target).
                // Ma alcuni adapter ritornano `false` SENZA lanciare: copriamo anche
                // quel caso, così il fallimento non passa mai silenzioso (R4 + R14).
                try {
                    $copied = Storage::disk($disk)->put($target, $contents);
                } catch (\Throwable $e) {
                    throw new RuntimeException(
                        "Copia su disco '{$disk}' fallita: {$target} — {$e->getMessage()}",
                        0,
                        $e,
                    );
                }

                if ($copied === false) {
                    throw new RuntimeException("Copia su disco '{$disk}' fallita: {$target}");
                }
            }
        } finally {
            // Ripristina il valore originale di `throw` e ri-risolvi il disco:
            // l'ingest successivo gira con la config di partenza.
            config(["filesystems.disks.{$disk}.throw" => $previousThrow]);
            Storage::forgetDisk($disk);
        }
    }

    private function emailPasswordPresent(): bool
    {
        $password = env(TestEmailFixtures::ACCOUNT_PASSWORD_ENV);

        return is_string($password) && $password !== '';
    }

    private function validateEmailOptions(): ?string
    {
        $profile = trim((string) $this->option('profile'));
        $datasetVersion = trim((string) $this->option('dataset-version'));
        $usesGeneratedDataset = $profile !== '' || $datasetVersion !== '';

        if ((bool) $this->option('generate-email-dataset') && $profile === '') {
            return '--generate-email-dataset richiede --profile.';
        }

        if ((bool) $this->option('generate-email-dataset') && $datasetVersion !== '') {
            return '--generate-email-dataset non può essere combinato con --dataset-version.';
        }

        if ((bool) $this->option('resume') && ! $usesGeneratedDataset) {
            return '--resume richiede --profile o --dataset-version.';
        }

        if ($usesGeneratedDataset && trim((string) $this->option('dataset-root')) === '') {
            return '--dataset-root non può essere vuoto.';
        }

        if ($usesGeneratedDataset && (bool) $this->option('ingest-emails')) {
            if (config('connectors.case_study_email_dataset.require_fixture_index', true) !== true) {
                return '--ingest-emails con un dataset v2 richiede CASE_STUDY_EMAIL_REQUIRE_FIXTURE_INDEX=true.';
            }

            $deliveryRoot = $this->absoluteDatasetRoot((string) $this->option('dataset-root'));
            $workerRoot = $this->absoluteDatasetRoot((string) config(
                'connectors.case_study_email_dataset.root',
                storage_path('app/demo-email-datasets'),
            ));
            if ($deliveryRoot !== $workerRoot) {
                return 'Il --dataset-root del delivery deve coincidere con '
                    .'CASE_STUDY_EMAIL_DATASET_ROOT per i queue worker.';
            }
        }

        return null;
    }

    private function absoluteDatasetRoot(string $root): string
    {
        $root = rtrim(trim($root), DIRECTORY_SEPARATOR);

        return str_starts_with($root, DIRECTORY_SEPARATOR)
            ? $root
            : base_path($root);
    }

    /**
     * Opzioni condivise tra preflight e delivery. Un array vuoto seleziona il
     * corpus gold legacy; i dataset v2 vengono sempre identificati
     * esplicitamente e quindi possono essere purgati senza wildcard.
     *
     * @return array<string, string>
     */
    private function generatedDatasetArguments(): array
    {
        $profile = trim((string) $this->option('profile'));
        $datasetVersion = trim((string) $this->option('dataset-version'));
        if ($profile === '' && $datasetVersion === '') {
            return [];
        }

        $arguments = [
            '--dataset-root' => (string) $this->option('dataset-root'),
        ];
        if ($profile !== '') {
            $arguments['--profile'] = $profile;
        }
        if ($datasetVersion !== '') {
            $arguments['--dataset-version'] = $datasetVersion;
        }

        return $arguments;
    }
}
