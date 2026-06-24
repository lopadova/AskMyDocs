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
 *   3. E-mail            → `mail:seed-imap --all --purge` (crea le label, ripulisce
 *      i messaggi di test e ri-appende le ~751 e-mail).
 *   4. (opz.) Ingest e-mail → `connector:imap:install --all --sync`.
 *
 *   php artisan demo:init-case-studies
 *   php artisan demo:init-case-studies --fresh --ingest-emails
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
        {--ingest-emails : Dopo l\'APPEND installa il connettore e ingerisce le e-mail in KB}';

    protected $description = 'Inizializza l\'ambiente case-study: aziende, utenti, documenti e e-mail (purge+load).';

    public function handle(TenantContext $tenant): int
    {
        $tenantId = (string) $this->option('tenant');
        $tenant->set($tenantId);

        if ((bool) $this->option('fresh')) {
            $this->warn('migrate:fresh (DISTRUTTIVO) — azzero il database…');
            if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
                $this->error('migrate:fresh fallito — interrompo.');

                return self::FAILURE;
            }
        }

        // 1) AZIENDE + UTENTI — RbacSeeder PRIMA (ruoli + backfill), poi il
        //    case-study seeder che crea i 3 account/azienda e ripristina
        //    l'isolamento delle membership.
        $this->components->info('1/4 — Aziende + utenti');
        $this->call('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => CaseStudyUsersSeeder::class, '--force' => true]);

        // 2) DOCUMENTI
        if (! (bool) $this->option('skip-docs')) {
            $this->components->info('2/4 — Documenti (copia su disco kb + ingest)');
            $this->ingestDocuments($tenantId);
        } else {
            $this->components->warn('2/4 — Documenti: saltato (--skip-docs)');
        }

        // 3) E-MAIL (purge + APPEND su IMAP)
        if (! (bool) $this->option('skip-emails')) {
            $this->components->info('3/4 — E-mail: purge + APPEND su IMAP');
            if ($this->emailPasswordPresent()) {
                $this->call('mail:seed-imap', ['--all' => true, '--purge' => true]);
            } else {
                $this->components->warn('E-mail saltate: CONNECTOR_TEST_GMAIL_PASSWORD non impostata in .env.');
            }
        } else {
            $this->components->warn('3/4 — E-mail: saltato (--skip-emails)');
        }

        // 4) (opzionale) INGEST E-MAIL via connettore
        if ((bool) $this->option('ingest-emails')) {
            $this->components->info('4/4 — Ingest e-mail (connettore IMAP)');
            $this->call('connector:imap:install', ['--all' => true, '--sync' => true]);
        }

        $this->newLine();
        $this->components->info('Fatto. Riepilogo:');
        $this->call('demo:list-companies', ['--tenant' => $tenantId]);

        return self::SUCCESS;
    }

    /**
     * Copia i dataset markdown sul disco kb e li ingerisce, un progetto per
     * cartella. Le cartelle in docs/case-studies/data/ sono la fonte di verità
     * dei project_key (gating: tests/Unit/CaseStudies/CaseStudyDatasetTest).
     */
    private function ingestDocuments(string $tenantId): void
    {
        $disk = (string) config('kb.sources.disk', 'kb');
        $prefix = trim((string) config('kb.sources.path_prefix', ''), '/');
        $base = base_path(self::DATA_DIR);

        $dirs = glob($base.'/*', GLOB_ONLYDIR) ?: [];
        if ($dirs === []) {
            $this->components->warn("Nessun dataset in {$base} — niente documenti da ingerire.");

            return;
        }

        foreach ($dirs as $dir) {
            $projectKey = basename($dir);
            $files = glob($dir.'/*.md') ?: [];

            foreach ($files as $file) {
                $relative = self::KB_SUBDIR.'/'.$projectKey.'/'.basename($file);
                $target = KbPath::normalize($prefix === '' ? $relative : $prefix.'/'.$relative);

                $contents = file_get_contents($file);
                if ($contents === false) {
                    throw new RuntimeException("Lettura fallita: {$file}");
                }

                // R4 — non ignorare il ritorno di Storage::put().
                if (Storage::disk($disk)->put($target, $contents) === false) {
                    throw new RuntimeException("Copia su disco '{$disk}' fallita: {$target}");
                }
            }

            $this->line(sprintf('  [%s] %d documenti → ingest', $projectKey, count($files)));
            $this->call('kb:ingest-folder', [
                'path' => self::KB_SUBDIR.'/'.$projectKey,
                '--project' => $projectKey,
                '--tenant' => $tenantId,
                '--recursive' => true,
                '--sync' => true,
            ]);
        }
    }

    private function emailPasswordPresent(): bool
    {
        $password = env(TestEmailFixtures::ACCOUNT_PASSWORD_ENV);

        return is_string($password) && $password !== '';
    }
}
