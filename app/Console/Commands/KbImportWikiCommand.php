<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Import\KbWikiImportService;
use Illuminate\Console\Command;

/**
 * v8.38/W4c (ADR 0032 §10) — CLI surface (R44) for the round-trip:
 * `kb:export-wiki`'s counterpart, diffing a folder brought back from an
 * export against the server and turning edits into promotion candidates.
 * Never a direct write — see {@see KbWikiImportService::importDocument()}.
 *
 * `--tenant` and `--as-user` are mandatory for the exact same reason as
 * `kb:export-wiki`: a console process has no request tenant or principal of
 * its own, and a candidate without an attributed actor would violate the
 * attribution the whole promotion pipeline exists to preserve.
 */
final class KbImportWikiCommand extends Command
{
    protected $signature = 'kb:import-wiki
                            {folder : Local path to a folder previously produced by kb:export-wiki}
                            {--tenant= : Tenant the folder was exported from (required)}
                            {--as-user= : Email of the user the resulting candidates are attributed to (required)}';

    protected $description = 'Diff a portable wiki export folder against the server and propose edits as promotion candidates (ADR 0032).';

    public function handle(KbWikiImportService $importer): int
    {
        if (! (bool) config('kb.wiki_export.enabled', false)) {
            $this->error('Wiki export/import is disabled (KB_WIKI_EXPORT_ENABLED=false).');

            return self::FAILURE;
        }

        $folder = trim((string) $this->argument('folder'));
        $tenant = trim((string) $this->option('tenant'));
        $userEmail = trim((string) $this->option('as-user'));

        if ($tenant === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }
        if ($userEmail === '') {
            $this->error('--as-user is required (email of the user the resulting candidates are attributed to).');

            return self::FAILURE;
        }

        $user = User::where('email', $userEmail)->first();
        if ($user === null) {
            $this->error("No user found with email '{$userEmail}'.");

            return self::FAILURE;
        }

        $isMember = ProjectMembership::query()
            ->where('tenant_id', $tenant)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            $this->error("User '{$userEmail}' has no membership in tenant '{$tenant}'.");

            return self::FAILURE;
        }

        try {
            $results = $importer->importFolder($tenant, $folder, $user);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($results !== [] && ($results[0]['status'] ?? null) === 'disabled') {
            $this->error('Wiki export/import is disabled (KB_WIKI_EXPORT_ENABLED=false).');

            return self::FAILURE;
        }

        $counts = ['unchanged' => 0, 'created' => 0, 'replayed' => 0, 'invalid' => 0, 'other' => 0];
        foreach ($results as $result) {
            $status = (string) ($result['status'] ?? 'other');
            $counts[$status] ??= 0;
            $counts[$status]++;

            $path = (string) ($result['path'] ?? '?');
            match ($status) {
                'unchanged' => $this->line("  unchanged  {$path}"),
                'created' => $this->info("  proposed   {$path}  (flow_run_id: ".($result['flow_run_id'] ?? '?').')'),
                'replayed' => $this->info("  replayed   {$path}  (flow_run_id: ".($result['flow_run_id'] ?? '?').')'),
                'invalid' => $this->warn("  invalid    {$path}  ".json_encode($result['errors'] ?? [])),
                default => $this->warn("  {$status}  {$path}"),
            };
        }

        $this->newLine();
        $this->info(sprintf(
            'Import complete: %d unchanged, %d proposed, %d replayed, %d invalid, %d other.',
            $counts['unchanged'],
            $counts['created'],
            $counts['replayed'],
            $counts['invalid'],
            $counts['other'],
        ));

        return self::SUCCESS;
    }
}
