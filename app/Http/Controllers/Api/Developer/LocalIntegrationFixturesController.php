<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Developer;

use App\Services\Dev\LocalIntegrationFixtureEnvironment;
use App\Services\Dev\LocalIntegrationFixtureLifecycle;
use App\Services\Dev\LocalIntegrationFixtureStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Local-only control plane for deterministic API + MCP fixture processes. */
final class LocalIntegrationFixturesController extends Controller
{
    public function __construct(
        private readonly LocalIntegrationFixtureEnvironment $environment,
        private readonly LocalIntegrationFixtureLifecycle $lifecycle,
        private readonly LocalIntegrationFixtureStatus $status,
    ) {}

    public function show(): JsonResponse
    {
        $this->assertLocal();

        return response()->json($this->status->snapshot());
    }

    public function control(string $action): JsonResponse
    {
        $this->assertLocal();

        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new NotFoundHttpException();
        }

        try {
            match ($action) {
                'start' => $this->lifecycle->start(),
                'stop' => $this->lifecycle->stop(),
                'restart' => $this->lifecycle->restart(),
            };
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'local_integration_control_failed',
            ], 422);
        }

        return response()->json([
            'message' => match ($action) {
                'start' => 'Server mock API e MCP avviati.',
                'stop' => 'Server mock API e MCP arrestati.',
                default => 'Server mock API e MCP riavviati.',
            },
            'status' => $this->status->snapshot(),
        ]);
    }

    private function assertLocal(): void
    {
        if (! app()->environment('local')) {
            throw new NotFoundHttpException();
        }

        $this->environment->assertLocal();
    }
}
