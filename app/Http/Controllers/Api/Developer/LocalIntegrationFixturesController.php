<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Developer;

use App\Services\Dev\LocalIntegrationFixtureEnvironment;
use App\Services\Dev\LocalIntegrationFixtureLifecycle;
use App\Services\Dev\LocalIntegrationFixtureStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
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

        match ($action) {
            'start' => $this->lifecycle->start(),
            'stop' => $this->lifecycle->stop(),
            'restart' => $this->lifecycle->restart(),
            default => throw new NotFoundHttpException(),
        };

        return response()->json([
            'message' => match ($action) {
                'start' => 'Local API and MCP fixtures started.',
                'stop' => 'Local API and MCP fixtures stopped.',
                default => 'Local API and MCP fixtures restarted.',
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
