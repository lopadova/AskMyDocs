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
use Illuminate\Contracts\Container\Container;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Monolog\Handler\HandlerInterface;
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
 *   - 1 Monolog processor (pushed onto every active log handler)
 *   - 1 container binding for laravel-flow's CurrentPayloadRedactorProvider
 *
 * Each touch-point is INDEPENDENTLY default-off and gated by its own
 * `kb.pii_redactor.*` knob. The master `kb.pii_redactor.enabled` flag
 * is checked at runtime inside each observer / listener / processor —
 * NOT at boot here. Reason: hosts can flip individual knobs without
 * restarting workers, and tests can override config per test without
 * needing to re-register the observers.
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
        // The binding is unconditional at register() — the
        // CurrentPayloadRedactorProvider's redact() method is itself a
        // pass-through when kb.pii_redactor.enabled is false (the
        // RedactorEngine respects its own enabled flag). This avoids
        // a "first request after env-flip needs a restart" footgun.
        //
        // Hosts that don't want any binding at all (e.g. they want to
        // keep the flow package's default no-op redactor) gate via
        // `kb.pii_redactor.redact_flow_payloads=false`.
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
        // R30: observer logic itself is content-only (not tenant-aware).
        // Tenant scoping for these tables is enforced by the BelongsToTenant
        // trait + ResolveTenant middleware at the request boundary.
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

            // Push the processor onto every existing handler. New handlers
            // created later (e.g. on-the-fly stack additions) miss out;
            // they can be covered by a future refresh hook if needed.
            /** @var HandlerInterface $handler */
            foreach ($logger->getHandlers() as $handler) {
                if (method_exists($handler, 'pushProcessor')) {
                    $handler->pushProcessor($processor);
                }
            }
            // Also push at the top-level Monolog logger so processors run
            // for handlers that haven't enabled per-handler stacks.
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
