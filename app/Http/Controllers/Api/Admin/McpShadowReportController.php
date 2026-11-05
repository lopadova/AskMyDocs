<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\McpConnectorShadowReport;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class McpShadowReportController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:match,drift,error,expected_exception'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ]);
        $reports = McpConnectorShadowReport::query()
            ->where('tenant_id', $this->tenants->current())
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->latest('compared_at')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get();

        return response()->json(['data' => $reports]);
    }
}
