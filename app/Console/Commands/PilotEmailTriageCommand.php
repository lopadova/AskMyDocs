<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Connectors\Imap\Backfill\ImapBackfillClientProviderContract;
use App\Models\EmailTriageProfile;
use App\Services\EmailTriage\TriageEngine;
use App\Services\EmailTriage\TriageExamples;
use App\Services\EmailTriage\TriageProfiles;
use App\Services\EmailTriage\TriageFailure;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorImap\Imap\AttachmentPolicy;
use Padosoft\AskMyDocsConnectorImap\Imap\EmailToMarkdown;

/** Real read-only IMAP + paid decision preview, with no KB writes or ingest dispatch. */
final class PilotEmailTriageCommand extends Command
{
    protected $signature = 'email-triage:pilot {tenant} {installation} {--mailbox=INBOX} {--rounds=10}';
    protected $description = 'Run bounded read-only download/decision rounds and write a content-free pilot report.';

    public function handle(TenantContext $context, ConnectorTenantContext $connectorContext, ImapBackfillClientProviderContract $clients, TriageProfiles $profiles, TriageEngine $engine): int
    {
        $rounds = filter_var($this->option('rounds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($rounds === false) {
            $this->error('Choose between 1 and 100 rounds.');
            return self::FAILURE;
        }
        $previous = $context->current();
        $previousConnector = $connectorContext->current();
        $context->set((string) $this->argument('tenant'));
        $connectorContext->set($context->current());
        $client = null;
        $root = sys_get_temp_dir().'/email-triage-pilot-'.bin2hex(random_bytes(8));
        config(['filesystems.disks.email-triage-pilot' => ['driver' => 'local', 'root' => $root, 'throw' => true]]);
        $report = ['started_at' => now()->toIso8601String(), 'read_only' => true, 'ingest_dispatched' => 0, 'rounds' => [], 'phase' => 'configuration'];
        try {
            $installation = ConnectorInstallation::query()->where('tenant_id', $context->current())->where('connector_name', 'imap')->findOrFail((int) $this->argument('installation'));
            $profile = $profiles->current($installation);
            if (! $profile || collect($profile->rules)->filter(fn ($r) => $r['enabled'])->isEmpty()) {
                $profile = new EmailTriageProfile(['rules' => TriageExamples::rules(), 'revision' => 0, 'model' => config('decisions.models.jev.model')]);
            }
            $profiles->validate(['enabled' => true, 'rules' => $profile->rules]);
            $report['phase'] = 'connect';
            $client = $clients->forInstallation($installation);
            $mailbox = (string) $this->option('mailbox');
            $report['phase'] = 'mailbox_snapshot';
            $snapshot = $client->snapshotMailbox($mailbox);
            $report['phase'] = 'sample_selection';
            $uids = $client->uidsBetween($mailbox, \Carbon\Carbon::create(1970, 1, 1), now()->addDay()->startOfDay(),
                max(0, $snapshot->maxUid - 1000), $snapshot->maxUid, $rounds);
            if ($uids === []) {
                throw new \RuntimeException('No emails found in the bounded UID sample.');
            }
            $config = (array) $installation->config_json;
            $policy = new AttachmentPolicy(array_merge((array) config('connectors.providers.imap.defaults.attachments', []), (array) ($config['attachments'] ?? [])));
            $report += ['tenant_id' => $context->current(), 'installation_id' => $installation->id, 'model' => $profile->model,
                'profile_revision' => $profile->revision, 'mailbox_messages' => $snapshot->messageCount];
            for ($round = 0; $round < $rounds; $round++) {
                $report['phase'] = 'rounds';
                $uid = $uids[$round % count($uids)];
                $data = ['round' => $round + 1, 'uid' => $uid, 'phase' => 'download'];
                $files = [];
                try {
                    $started = microtime(true);
                    $email = $client->fetchMessages($mailbox, [$uid])[0] ?? throw new \RuntimeException('missing_pilot_email');
                    $downloadMs = round((microtime(true) - $started) * 1000, 2);
                    $data['download_ms'] = $downloadMs;
                    $body = (new EmailToMarkdown)->render($email, ($config['body_format'] ?? 'prefer_text') === 'prefer_text', (bool) ($config['strip_quoted_history'] ?? false));
                    $files = [];
                    $accepted = 0;
                    foreach ($email->attachments as $index => $attachment) {
                        $file = ['name' => $attachment->filename, 'mime' => $attachment->mimeType, 'status' => 'excluded'];
                        if ($accepted < $policy->limit() && $policy->accepts($attachment)) {
                            $accepted++;
                            if (strlen($attachment->contents) <= (int) config('email-triage.extraction_max_bytes', 5242880)) {
                                $file['snapshot'] = $round.'-'.$index.'.source';
                                Storage::disk('email-triage-pilot')->put($file['snapshot'], $attachment->contents);
                                $file['status'] = 'saved';
                            } else {
                                $file['status'] = 'extraction_size_limit';
                            }
                        }
                        $files[] = $file;
                    }
                    $data['phase'] = 'decision';
                    $data['body_bytes'] = strlen($body);
                    $result = $engine->analyze($profile, $engine->prepare($body, $files, 'email-triage-pilot'));
                    $data['phase'] = 'integrity_reread';
                    $beforeFlags = $email->flags;
                    $beforeHash = hash('sha256', $body);
                    $bodyBytes = strlen($body);
                    // Release large decoded attachments before the integrity reread.
                    unset($email, $attachment, $body);
                    $again = $client->fetchMessages($mailbox, [$uid])[0] ?? throw new \RuntimeException('missing_pilot_email');
                    $afterFlags = $again->flags;
                    sort($beforeFlags); sort($afterFlags);
                    $afterBody = (new EmailToMarkdown)->render($again, ($config['body_format'] ?? 'prefer_text') === 'prefer_text', (bool) ($config['strip_quoted_history'] ?? false));
                    $data += ['status' => 'completed', 'download_ms' => $downloadMs,
                        'decision_ms' => $result['latency_ms'], 'cost_usd' => $result['usage']['cost'] ?? null,
                        'input_tokens' => $result['usage']['input_tokens'] ?? null, 'body_bytes' => $bodyBytes,
                        'attachment_count' => count($files), 'coverage_partial' => $result['coverage']['partial'], 'tags' => $result['tags'],
                        'body_unchanged' => $beforeHash === hash('sha256', $afterBody), 'flags_unchanged' => $beforeFlags === $afterFlags,
                        'card_count' => substr_count($result['card'], '## Scheda di triage automatica'),
                        'memory_peak_bytes' => memory_get_peak_usage(true)];
                    if (! $data['body_unchanged'] || ! $data['flags_unchanged'] || $data['card_count'] !== 1) {
                        throw new \RuntimeException('A pilot integrity check failed.');
                    }
                    $data['phase'] = 'completed';
                } catch (\Throwable $exception) {
                    $data['status'] = 'failed';
                    $data['error_code'] = TriageFailure::code($exception);
                } finally {
                    foreach ($files as $file) {
                        if (isset($file['snapshot'])) {
                            Storage::disk('email-triage-pilot')->delete($file['snapshot']);
                        }
                    }
                    unset($email, $again, $body, $afterBody, $result, $files, $attachment);
                    gc_collect_cycles();
                }
                $report['rounds'][] = $data;
                Log::info('Email triage read-only pilot round', $data + ['tenant_id' => $context->current(), 'installation_id' => $installation->id]);
                $this->line(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            $report['status'] = collect($report['rounds'])->contains(fn ($row) => $row['status'] === 'failed') ? 'failed' : 'completed';
            return $report['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $report['status'] = 'failed';
            $report['error_code'] = TriageFailure::code($exception);
            $report['error_location'] = str_replace(base_path().'/', '', $exception->getFile()).':'.$exception->getLine();
            $this->error($report['error_code'].' at '.$report['error_location'].' ('.$report['phase'].')');
            return self::FAILURE;
        } finally {
            $context->set($previous);
            $connectorContext->set($previousConnector);
            try {
                $client?->close();
            } catch (\Throwable $exception) {
                $report['close_error_code'] = TriageFailure::code($exception);
            }
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($root);
            $report['finished_at'] = now()->toIso8601String();
            $path = 'email-triage/pilot-'.now()->format('Ymd-His').'.json';
            Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->line('Report: '.Storage::disk('local')->path($path));
        }
    }
}
