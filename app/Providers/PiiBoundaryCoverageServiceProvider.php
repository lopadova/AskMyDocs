<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AdminCommandAudit;
use App\Models\AdminInsightsSnapshot;
use App\Models\ChatLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Pii\AskMyDocsFlowPayloadRedactor;
use App\Pii\Listeners\RedactFailedJobPayload;
use App\Pii\Logging\PiiRedactingProcessor;
use App\Pii\Observers\AdminCommandAuditObserver;
use App\Pii\Observers\AdminInsightsSnapshotObserver;
use App\Pii\Observers\ChatLogObserver;
use App\Pii\Observers\ConversationObserver;
use App\Pii\Observers\MessageObserver;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger as MonologLogger;
use Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider;
use Throwable;

/**
 * v4.3/W1 sub-PR 4.5 — central wiring for the comprehensive PII boundary
 * coverage. Registers in ONE place:
 *
 *   - 5 Eloquent observers (Conversation / Message / ChatLog /
 *     AdminCommandAudit / AdminInsightsSnapshot)
 *   - 1 Queue listener (Illuminate\Queue\Events\JobFailed)
 *   - 1 Monolog processor (attached once at the root Monolog logger)
 *   - 1 container binding for laravel-flow's CurrentPayloadRedactorProvider
 *
 * Each touch-point is INDEPENDENTLY default-off and gated by its own
 * `kb.pii_redactor.*` knob. Per-touchpoint flags are checked at RUNTIME
 * inside the Eloquent observers and the JobFailed listener (so toggling
 * the env var without a worker restart takes effect on the next event).
 * The Monolog log processor and the laravel-flow CurrentPayloadRedactorProvider
 * binding are wired at BOOT / register() respectively, so toggling
 * `KB_PII_REDACT_LOGS` or `KB_PII_REDACT_FLOW_PAYLOADS` requires
 * `php artisan config:clear` plus a worker / FPM restart.
 *
 * Defence-in-depth: every wired component catches its own redactor
 * Throwables and logs + lets the original write proceed (R14 inversion
 * — the redactor is a safety net, never a load-bearing wall).
 *
 * Listed in `bootstrap/providers.php` AFTER PiiRedactorServiceProvider
 * so the package's RedactorEngine binding is already in the container
 * when this provider's observers are constructed.
 */
final class PiiBoundaryCoverageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the Flow contract → AskMyDocs implementation. The flow
        // package's RedactorAwareFlowStore resolves the contract on
        // every payload write; binding here is enough to wire EVERY
        // persistence point inside laravel-flow in ONE shot.
        //
        // Binding is CONDITIONAL on `kb.pii_redactor.enabled` AND
        // `kb.pii_redactor.redact_flow_payloads` at register() — when
        // either flag is false, the SP simply does NOT bind, and the
        // flow package falls back to its default no-op redactor.
        // Toggling either flag therefore requires `php artisan config:clear`
        // plus a worker / FPM restart so register() re-evaluates the
        // gate. This trade-off (boot-time gate vs runtime check) keeps
        // the host control over whether the AskMyDocs implementation is
        // wired at all — see `shouldBindFlowProvider()` below.
        if ($this->shouldBindFlowProvider()) {
            $this->app->singleton(
                CurrentPayloadRedactorProvider::class,
                AskMyDocsFlowPayloadRedactor::class,
            );
        }
    }

    public function boot(): void
    {
        $this->registerObservers();
        $this->registerFailedJobListener();
        $this->registerLogProcessor();
    }

    private function registerObservers(): void
    {
        // R30 note: BelongsToTenant auto-stamps `tenant_id` on CREATE; the
        // trait does NOT enforce tenant scoping on reads/queries (read-side
        // scoping is the query author's responsibility per R30). The
        // redaction touch-points wired below are content-only — they mutate
        // the model attributes about to be persisted and do NOT read
        // tenant-aware tables — so this concern doesn't apply directly to
        // PII boundary coverage. The existing `forTenant()` discipline in
        // the v4.1 detokenize path (LogViewerController::chatDetokenize)
        // remains intact.
        Conversation::observe(ConversationObserver::class);
        Message::observe(MessageObserver::class);
        ChatLog::observe(ChatLogObserver::class);
        AdminCommandAudit::observe(AdminCommandAuditObserver::class);
        AdminInsightsSnapshot::observe(AdminInsightsSnapshotObserver::class);
    }

    private function registerFailedJobListener(): void
    {
        Event::listen(JobFailed::class, [RedactFailedJobPayload::class, 'handle']);
    }

    private function registerLogProcessor(): void
    {
        if (! (bool) config('kb.pii_redactor.enabled', false)) {
            return;
        }
        if (! (bool) config('kb.pii_redactor.redact_logs', false)) {
            return;
        }

        try {
            /** @var LogManager $logManager */
            $logManager = $this->app->make('log');
            $processor = $this->app->make(PiiRedactingProcessor::class);

            $logger = $logManager->getLogger();
            if (! $logger instanceof MonologLogger) {
                return;
            }

            // Push the processor ONCE at the logger level. Monolog runs
            // logger-level processors for EVERY handler automatically, so
            // pushing into each handler's individual processor stack would
            // execute the redactor twice per record (and double-redact
            // already-replaced sentinel tokens). The logger-level push
            // also covers handlers added on-the-fly later in the request.
            $logger->pushProcessor($processor);
        } catch (Throwable $e) {
            Log::warning('PiiBoundaryCoverageServiceProvider: failed to attach log processor.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function shouldBindFlowProvider(): bool
    {
        if (! interface_exists(CurrentPayloadRedactorProvider::class)) {
            // laravel-flow not installed — skip silently. The boundary
            // touch-point is a no-op when the package is absent.
            return false;
        }

        if (! (bool) config('kb.pii_redactor.enabled', false)) {
            return false;
        }

        return (bool) config('kb.pii_redactor.redact_flow_payloads', false);
    }
}
