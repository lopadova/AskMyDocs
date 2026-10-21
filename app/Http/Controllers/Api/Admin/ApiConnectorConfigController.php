<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\ApiConnectors\ConnectorConfigTransferService;
use App\ApiConnectors\ConnectorPackageService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorApi\Http\Resources\ApiConnectorResource;
use Symfony\Component\HttpFoundation\Response;

final class ApiConnectorConfigController
{
    public function __construct(
        private readonly ConnectorConfigTransferService $transfer,
        private readonly ConnectorPackageService $packages,
    ) {}

    public function export(Request $request, int $connector): Response
    {
        $data = $request->validate(['format' => ['sometimes', 'in:json,zip']]);
        $config = $this->transfer->export($connector);
        if (($data['format'] ?? 'json') === 'zip') {
            return response($this->packages->zip($config), 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="api-connector.askmydocs-api.zip"',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->json(['data' => $config])->header('Cache-Control', 'no-store, private');
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->transfer->preview($this->configuration($request))])
            ->header('Cache-Control', 'no-store, private');
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credentials' => ['sometimes', 'array', 'max:101'],
            'credentials.*' => ['array'], 'credentials.*.*' => ['string', 'max:16384'],
        ]);
        try {
            $connector = $this->transfer->import($this->configuration($request), $data['credentials'] ?? []);
        } catch (QueryException $exception) {
            // The service transaction has already rolled back. Do not expose SQL or bindings.
            return response()->json(['message' => 'The API connector could not be saved. No connector was imported. Please retry.'], 409);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => 'The API import failed. No connector was imported. Please retry or contact an administrator.'], 500);
        }

        return (new ApiConnectorResource($connector))->response()->setStatusCode(201);
    }

    private function configuration(Request $request): array
    {
        $data = $request->validate([
            'config' => ['required_without:file', 'prohibits:file', 'array'],
            'file' => ['required_without:config', 'prohibits:config', 'file', 'max:5120'],
        ]);

        return isset($data['file']) ? $this->packages->read($data['file']) : $data['config'];
    }
}
