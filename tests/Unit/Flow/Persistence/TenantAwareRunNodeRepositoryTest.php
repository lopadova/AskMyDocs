<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Persistence;

use App\Flow\Persistence\TenantAwareRunNodeRepository;
use App\Support\TenantContext;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;
use PHPUnit\Framework\TestCase;

/**
 * The decorator is the only thing that puts tenant_id on a node row: the
 * vendor repository writes them with a query-builder upsert, which fires no
 * Eloquent events, so the model creating hook in FlowServiceProvider never
 * runs for this table. Before this file there was no direct coverage of it at
 * all — a single assertion loop inside IngestDocumentFlowTest was the whole
 * regression net.
 */
final class TenantAwareRunNodeRepositoryTest extends TestCase
{
    public function test_create_or_update_stamps_the_active_tenant(): void
    {
        $inner = $this->recordingInner();
        $repository = new TenantAwareRunNodeRepository($inner, $this->tenantContext('acme'));

        $repository->createOrUpdate('run-1', 'parse-markdown', ['node_type' => 'legacy.step']);

        $this->assertSame('acme', $inner->lastAttributes['tenant_id']);
    }

    public function test_an_explicitly_passed_tenant_wins_over_the_context(): void
    {
        $inner = $this->recordingInner();
        $repository = new TenantAwareRunNodeRepository($inner, $this->tenantContext('acme'));

        $repository->createOrUpdate('run-1', 'parse-markdown', [
            'node_type' => 'legacy.step',
            'tenant_id' => 'globex',
        ]);

        $this->assertSame('globex', $inner->lastAttributes['tenant_id']);
    }

    public function test_the_decorator_does_not_invent_a_node_type(): void
    {
        // The engine already supplies node_type on every write. If the
        // decorator ever started defaulting it, a genuinely missing one would
        // stop being a loud failure.
        $inner = $this->recordingInner();
        $repository = new TenantAwareRunNodeRepository($inner, $this->tenantContext('acme'));

        $repository->createOrUpdate('run-1', 'parse-markdown', []);

        $this->assertArrayNotHasKey('node_type', $inner->lastAttributes);
    }

    public function test_the_read_and_compare_and_set_methods_pass_through_untouched(): void
    {
        // Deliberate: every one of these is keyed by a run id whose access is
        // already tenant-scoped upstream. A tenant predicate here would turn a
        // legitimate compare-and-set into a silent false under a queue worker,
        // where TenantContext has no request to read from.
        $inner = $this->recordingInner();
        $repository = new TenantAwareRunNodeRepository($inner, $this->tenantContext('acme'));
        $at = new DateTimeImmutable('2026-08-31 10:00:00');

        $this->assertSame($inner->collection, $repository->forRun('run-1'));
        $this->assertSame(['node-a' => 'x'], $repository->states('run-1'));
        $this->assertTrue($repository->claim('run-1', 'node-a', $at));
        $this->assertTrue($repository->releaseClaim('run-1', 'node-a'));
        $this->assertTrue($repository->terminate('run-1', 'node-a', 'running', 'succeeded', $at, 12));

        $this->assertSame(
            ['forRun', 'states', 'claim', 'releaseClaim', 'terminate'],
            $inner->calls,
        );
    }

    public function test_terminate_forwards_every_argument_in_order(): void
    {
        $inner = $this->recordingInner();
        $repository = new TenantAwareRunNodeRepository($inner, $this->tenantContext('acme'));
        $at = new DateTimeImmutable('2026-08-31 10:00:00');

        $repository->terminate('run-1', 'node-a', 'running', 'failed', $at, 34, 'LogicException', 'boom');

        $this->assertSame(
            ['run-1', 'node-a', 'running', 'failed', $at, 34, 'LogicException', 'boom'],
            $inner->lastTerminateArgs,
        );
    }

    private function tenantContext(string $tenantId): TenantContext
    {
        $context = new TenantContext;
        $context->set($tenantId);

        return $context;
    }

    private function recordingInner(): RunNodeRepository
    {
        return new class implements RunNodeRepository
        {
            /** @var array<string, mixed> */
            public array $lastAttributes = [];

            /** @var list<string> */
            public array $calls = [];

            /** @var list<mixed> */
            public array $lastTerminateArgs = [];

            public Collection $collection;

            public function __construct()
            {
                $this->collection = new Collection;
            }

            public function createOrUpdate(string $runId, string $nodeId, array $attributes): FlowRunNodeRecord
            {
                $this->calls[] = 'createOrUpdate';
                $this->lastAttributes = $attributes;

                return new FlowRunNodeRecord;
            }

            public function forRun(string $runId): Collection
            {
                $this->calls[] = 'forRun';

                return $this->collection;
            }

            public function states(string $runId): array
            {
                $this->calls[] = 'states';

                return ['node-a' => 'x'];
            }

            public function claim(string $runId, string $nodeId, DateTimeInterface $startedAt): bool
            {
                $this->calls[] = 'claim';

                return true;
            }

            public function releaseClaim(string $runId, string $nodeId): bool
            {
                $this->calls[] = 'releaseClaim';

                return true;
            }

            public function terminate(
                string $runId,
                string $nodeId,
                string $expectedStatus,
                string $newStatus,
                DateTimeInterface $finishedAt,
                ?int $durationMs,
                ?string $errorClass = null,
                ?string $errorMessage = null,
            ): bool {
                $this->calls[] = 'terminate';
                $this->lastTerminateArgs = [
                    $runId, $nodeId, $expectedStatus, $newStatus,
                    $finishedAt, $durationMs, $errorClass, $errorMessage,
                ];

                return true;
            }
        };
    }
}
