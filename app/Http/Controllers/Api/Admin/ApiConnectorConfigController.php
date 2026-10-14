<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\ApiConnectors\ConnectorConfigTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorApi\Http\Resources\ApiConnectorResource;

final class ApiConnectorConfigController
{
    public function __construct(private readonly ConnectorConfigTransferService $transfer) {}

    public function export(int $connector): JsonResponse
    {
        return response()->json(['data' => $this->transfer->export($connector)]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['config' => ['required', 'array']]);

        return response()->json(['data' => $this->transfer->preview($data['config'])]);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'config' => ['required', 'array'], 'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['array'], 'credentials.*.*' => ['string', 'max:16384'],
        ]);
        $connector = $this->transfer->import($data['config'], $data['credentials'] ?? []);

        return (new ApiConnectorResource($connector))->response()->setStatusCode(201);
    }
}
