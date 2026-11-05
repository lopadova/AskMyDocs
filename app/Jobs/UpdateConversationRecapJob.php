<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Chat\ConversationRecapService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Async, incremental update of a conversation's "session recap" (see
 * {@see ConversationRecapService}).
 *
 * Dispatched by MessageController / MessageStreamController right after the
 * assistant message is saved, gated by `kb.session_recap.enabled`. Runs OFF
 * the request path deliberately: a slow or failed recap update must never
 * add latency to, or break, the turn the user is waiting on.
 *
 * Takes primitive ids (not the Eloquent model) so the queued payload is
 * small and the row is re-fetched fresh + tenant-scoped inside handle() —
 * same rationale as `AnalyzeDocumentChangeJob`.
 */
final class UpdateConversationRecapJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    /** @var array<int,int> */
    public array $backoff = [15, 60];

    public function __construct(
        public readonly int $conversationId,
        public readonly string $tenantId,
    ) {
        $this->onQueue((string) config('kb.session_recap.queue', 'default'));
    }

    public function handle(TenantContext $tenants, ConversationRecapService $service): void
    {
        $previousTenant = $tenants->current();
        try {
            $tenants->set($this->tenantId);

            $conversation = Conversation::query()
                ->forTenant($this->tenantId)
                ->find($this->conversationId);
            if ($conversation === null) {
                return; // conversation deleted between dispatch and run
            }

            $service->updateAfterTurn($conversation);
        } catch (Throwable $e) {
            // Best-effort enrichment: a failed recap update is logged, not
            // retried into a storm and never surfaced to the user — the
            // conversation works fine without a fresh recap, it just falls
            // back to the last one it had (or none).
            Log::warning('UpdateConversationRecapJob: recap update failed', [
                'conversation_id' => $this->conversationId,
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $tenants->set($previousTenant);
        }
    }
}
