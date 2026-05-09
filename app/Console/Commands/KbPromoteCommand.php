<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Flow\Definitions\PromotionFlow;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;

/**
 * Operator-side promotion of a local canonical markdown file.
 *
 * v4.2/W2 PR #116: now dispatches the {@see PromotionFlow} saga. Behaviour
 * is preserved for operators that confirm interactively or pass
 * `--auto-approve` for non-interactive scripts; the saga drives the same
 * validate → write → dispatch-ingest sequence as the legacy CanonicalWriter
 * + IngestDocumentJob path, with a built-in approval gate adding an
 * explicit operator confirmation between validation and disk write.
 *
 * The CLI signature is unchanged from the legacy command; the
 * `--auto-approve` flag is new (default: prompt). The `--dry-run` flag
 * still validates only and writes nothing.
 */
class KbPromoteCommand extends Command
{
    protected $signature = 'kb:promote
        {path : Local filesystem path to the canonical markdown file}
        {--project= : Project key for the canonical doc (required)}
        {--dry-run : Validate + print the resolved target path, write nothing}
        {--auto-approve : Skip the interactive approval prompt (for non-interactive scripts)}';

    protected $description = 'Promote a local canonical markdown file to the KB (write + dispatch ingest, via PromotionFlow).';

    public function handle(
        CanonicalParser $parser,
        ApprovalTokenManager $approvals,
        TenantContext $tenants,
    ): int {
        $path = (string) $this->argument('path');
        $projectKey = (string) ($this->option('project') ?? '');
        $dryRun = (bool) $this->option('dry-run');
        $autoApprove = (bool) $this->option('auto-approve');

        if ($projectKey === '') {
            $this->error('--project is required.');
            return self::INVALID;
        }
        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $markdown = @file_get_contents($path);
        if ($markdown === false) {
            $this->error("File is unreadable (permission denied or OS error): {$path}");
            return self::FAILURE;
        }
        if (trim($markdown) === '') {
            $this->error("File is empty: {$path}");
            return self::FAILURE;
        }

        // Pre-validation mirrors the controller's UX so the operator sees
        // schema errors as a regular CLI error, not as a Flow run failure.
        $parsed = $parser->parse($markdown);
        if ($parsed === null) {
            $this->error('No YAML frontmatter block detected at the top of the document.');
            return self::FAILURE;
        }
        $validation = $parser->validate($parsed);
        if (! $validation->valid) {
            $this->error('Canonical validation failed:');
            foreach ($validation->errors as $field => $errors) {
                foreach ($errors as $err) {
                    $this->line("  - [{$field}] {$err}");
                }
            }
            return self::FAILURE;
        }

        if ($dryRun) {
            $folder = (string) (config('kb.promotion.path_conventions.' . ($parsed->type?->value ?? ''), '?'));
            $destination = $folder === '.' || $folder === ''
                ? ($parsed->slug . '.md')
                : (trim($folder, '/') . '/' . $parsed->slug . '.md');

            $this->info("[dry-run] Would write to project '{$projectKey}' as:");
            $this->line("  slug: {$parsed->slug}");
            $this->line("  type: {$parsed->type?->value}");
            $this->line("  status: {$parsed->status?->value}");
            $this->line("  destination: {$destination}");
            $this->line("  disk: " . (string) config('kb.sources.disk', 'kb'));
            return self::SUCCESS;
        }

        $tenantId = $tenants->current();

        $run = Flow::execute(
            PromotionFlow::NAME,
            [
                'tenant_id' => $tenantId,
                'project_key' => $projectKey,
                'markdown' => $markdown,
                'title' => $parsed->slug ?? basename($path),
                'promotion_source' => 'cli',
            ],
            FlowExecutionOptions::make(
                correlationId: $tenantId,
            ),
        );

        if ($run->status !== FlowRun::STATUS_PAUSED) {
            $this->error("Promotion flow ended unexpectedly with status [{$run->status}].");
            return self::FAILURE;
        }

        // Issue the approval token tied to this paused approval-gate.
        $issued = $approvals->reissuePendingForStep($run->id, PromotionFlow::APPROVAL_STEP);
        if ($issued === null) {
            $this->error('Promotion flow paused but no pending approval token was found.');
            return self::FAILURE;
        }

        if (! $autoApprove) {
            $confirmed = $this->confirm(
                "About to promote '{$parsed->slug}' to project '{$projectKey}'. Approve and write to disk?",
                false,
            );
            if (! $confirmed) {
                Flow::reject($issued->plainTextToken, ['source' => 'kb:promote', 'reason' => 'cli_declined']);
                $this->warn("Rejected. Approval token expired. Nothing was written.");
                return self::FAILURE;
            }
        }

        $resumed = Flow::resume(
            $issued->plainTextToken,
            actor: ['source' => 'kb:promote', 'name' => 'cli'],
        );

        if ($resumed->status !== FlowRun::STATUS_SUCCEEDED) {
            $failedStep = $resumed->failedStep ?? '(unknown)';
            $this->error("Promotion flow failed at step [{$failedStep}] with status [{$resumed->status}].");
            return self::FAILURE;
        }

        $writeOutput = $resumed->stepResults['write-markdown'] ?? null;
        $relativePath = $writeOutput?->output['relative_path'] ?? '?';
        $this->info("Promoted '{$parsed->slug}' to {$relativePath}");
        return self::SUCCESS;
    }
}
