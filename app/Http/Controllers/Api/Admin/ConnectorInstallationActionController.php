<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Admin\Connectors\ConnectorInstallationActionRegistry;
use App\Services\Admin\Connectors\ConnectorInstallationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class ConnectorInstallationActionController extends Controller
{
    public function __construct(private readonly ConnectorInstallationService $installations, private readonly ConnectorInstallationActionRegistry $actions) {}

    public function show(int $installationId, string $action): JsonResponse
    {
        $installation = $this->installations->findOr404($installationId);

        return response()->json(['data' => $this->actions->get($installation, $action)->status($installation)]);
    }

    public function store(int $installationId, string $action): JsonResponse
    {
        $installation = $this->installations->findOr404($installationId);
        $handler = $this->actions->get($installation, $action);
        abort_unless($handler->descriptor($installation)['enabled'] ?? false, 422, 'Enable this installation before starting an import.');

        return response()->json(['data' => $handler->execute($installation)], 202);
    }
}
