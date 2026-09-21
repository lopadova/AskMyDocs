<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Export\KbWikiExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * v8.38/W4a (ADR 0032) — CLI surface (R44) for the portable wiki export.
 *
 * `--tenant` and `--as-user` are NOT optional (ADR 0032 §5, restated from
 * ADR 0028): `project_key` is only tenant-scoped, and a console process has
 * no request tenant or principal of its own, so this command resolves the
 * named user's membership IN the named tenant and refuses to run otherwise
 * — the same contract `kb:import-wiki` will carry (W4c).
 *
 * HTTP (async, retained downloads) + MCP (`KbCreateExportTool`/`KbGetExportTool`)
 * land in W4b/W4c over the same `KbWikiExportService` core.
 */
final class KbExportWikiCommand extends Command
{
    protected $signature = 'kb:export-wiki
                            {--tenant= : Tenant to export from (required)}
                            {--project= : project_key to export (required)}
                            {--as-user= : Email of the user whose retrieval ACL scopes the export (required)}
                            {--output= : Destination directory (defaults to a tmp path under storage/app)}';

    protected $description = 'Export a project\'s wiki as a portable, ACL-scoped folder (ADR 0032).';

    public function handle(KbWikiExportService $exporter): int
    {
        if (! (bool) config('kb.wiki_export.enabled', false)) {
            $this->error('Wiki export is disabled (KB_WIKI_EXPORT_ENABLED=false).');

            return self::FAILURE;
        }

        $tenant = trim((string) $this->option('tenant'));
        $project = trim((string) $this->option('project'));
        $userEmail = trim((string) $this->option('as-user'));

        if ($tenant === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }
        if ($project === '') {
            $this->error('--project is required.');

            return self::FAILURE;
        }
        if ($userEmail === '') {
            $this->error('--as-user is required (email of the user whose retrieval ACL scopes the export).');

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

        $output = trim((string) $this->option('output'));
        if ($output === '') {
            // --tenant/--project are operator input, not filesystem-safe by
            // construction: an unsanitized `--project=../../outside` would
            // make the default destination escape kb-wiki-exports/. Slug
            // both segments (falling back to a short hash if slugging
            // strips everything) rather than concatenating them raw. The
            // trailing random suffix (not just a to-the-second timestamp)
            // keeps two exports for the same tenant/project started within
            // the same second from resolving to the same directory and
            // tripping the "destination is not empty" refusal against each
            // other.
            $output = storage_path('app/kb-wiki-exports/'.$this->safeSegment($tenant).'-'.$this->safeSegment($project).'-'.now()->format('YmdHis').'-'.Str::random(8));
        }

        $result = $exporter->export($tenant, $project, $user, $output);

        $this->info("Export written to {$result['path']} ({$result['status']}, {$result['document_count']} document(s)).");
        if ($result['raw_missing'] !== []) {
            $this->warn(count($result['raw_missing']).' document(s) have no stored conversion artifact — see MANIFEST.json raw_missing.');
        }

        return self::SUCCESS;
    }

    private function safeSegment(string $value): string
    {
        $slug = Str::slug($value);

        return $slug !== '' ? $slug : substr(hash('sha256', $value), 0, 16);
    }
}
