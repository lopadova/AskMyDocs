<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\FlowRunStatusTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locks the response contract across the tool's two branches (R27).
 *
 * Cloud review on PR #466 flagged the disabled branch as a different shape.
 * It was worse than reported: each branch dropped a key the other had —
 * `matching_runs` was missing when persistence was off, `note` was missing when
 * it was on. A caller could not parse one shape; it had to discover which keys
 * existed from the path it happened to take, which is exactly what R27 forbids.
 *
 * The values still differ, and deliberately so. Counts are NULL when nothing is
 * recorded rather than zero, because zero is a measurement and a dashboard will
 * render "0 failed" as healthy when the truth is that nobody is counting. The
 * key set is the contract; the sentinel carries the meaning.
 */
final class FlowRunStatusToolShapeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, string>
     */
    private function keysFor(bool $persistenceEnabled): array
    {
        config(['laravel-flow.persistence.enabled' => $persistenceEnabled]);

        $response = app(FlowRunStatusTool::class)->handle(
            new Request([]),
            app(\Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel::class),
        );

        $payload = json_decode($this->textOf($response), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload, 'The tool did not return a JSON object.');

        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }

    public function test_both_branches_emit_the_same_key_set(): void
    {
        // The tables exist here (RefreshDatabase ran the migrations), so the
        // flag alone decides the branch — which is what makes this a contract
        // test rather than a test of Schema::hasTable().
        $recording = $this->keysFor(true);
        $notRecording = $this->keysFor(false);

        $this->assertNotEmpty($recording, 'The recording branch returned no keys at all.');

        $this->assertSame(
            $recording,
            $notRecording,
            'The two branches disagree about which keys exist. A caller cannot parse one shape.',
        );
    }

    public function test_the_disabled_branch_reports_unknown_rather_than_zero(): void
    {
        config(['laravel-flow.persistence.enabled' => false]);

        $payload = json_decode(
            $this->textOf(app(FlowRunStatusTool::class)->handle(
                new Request([]),
                app(\Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel::class),
            )),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertFalse($payload['persistence_enabled']);

        // Zero would be a lie a dashboard renders as healthy. Assert the
        // sentinel explicitly so a future "tidy up the nulls" change has to
        // argue with this test rather than quietly pass it.
        $this->assertNull($payload['totals'], 'Counts must be null when nothing is recorded, never zero.');
        $this->assertNull($payload['matching_runs'], 'A match count must be null when nothing is recorded.');
        $this->assertSame([], $payload['recent_runs']);
        $this->assertIsString($payload['note'], 'The disabled branch must explain itself.');
    }

    /**
     * The partially-migrated state a reviewer asked about on PR #466.
     *
     * `flow_runs` and `flow_approvals` / `flow_webhook_outbox` are created by
     * DIFFERENT migrations, so a deployment can genuinely hold the first
     * without the others. `kpis()` counts pending approvals and the outbox, so
     * guarding on `flow_runs` alone would take the recording branch and throw
     * on the next query — contradicting the contract the tool documents.
     */
    #[DataProvider('tablesTheRecordingBranchNeeds')]
    public function test_a_missing_table_reports_not_recording_instead_of_throwing(string $missing): void
    {
        config(['laravel-flow.persistence.enabled' => true]);

        Schema::drop($missing);

        $payload = json_decode(
            $this->textOf(app(FlowRunStatusTool::class)->handle(
                new Request([]),
                app(\Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel::class),
            )),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertFalse(
            $payload['persistence_enabled'],
            sprintf('With [%s] missing the tool claimed to be recording, and the next query would have thrown.', $missing),
        );
        $this->assertNull($payload['totals']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tablesTheRecordingBranchNeeds(): iterable
    {
        // listRuns() reads flow_runs; kpis() also reads the other two.
        yield 'flow_runs' => ['flow_runs'];
        yield 'flow_approvals' => ['flow_approvals'];
        yield 'flow_webhook_outbox' => ['flow_webhook_outbox'];
    }

    /**
     * Pull the JSON body out of the MCP response without depending on the
     * package's internal content shape more than necessary.
     */
    private function textOf(mixed $response): string
    {
        $content = $response->content();

        if (is_array($content)) {
            $content = $content[0] ?? '';
        }

        // Laravel\Mcp\Server\Content\Text keeps its payload on a protected
        // property and exposes it through __toString(). Casting is the public
        // contract; reaching for the property is not.
        return (string) $content;
    }
}
