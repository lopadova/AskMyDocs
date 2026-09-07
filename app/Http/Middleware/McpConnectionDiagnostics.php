<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Mcp\Diagnostics\McpConnectionDiagnosticContext;
use App\Support\TenantContext;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlates an MCP connection failure with its backend exception and every
 * safe outbound probe, then returns that diagnostic to the authenticated UI.
 */
final readonly class McpConnectionDiagnostics
{
    public function __construct(
        private McpConnectionDiagnosticContext $diagnostics,
        private TenantContext $tenantContext,
        private ExceptionHandler $exceptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $rendered = $this->exceptions->render($request, $exception);

            return $this->failureResponse($request, $rendered, $exception);
        }

        if ($response->getStatusCode() >= 400) {
            return $this->failureResponse($request, $response);
        }

        return $response;
    }

    private function failureResponse(Request $request, Response $response, ?\Throwable $exception = null): JsonResponse
    {
        $status = $response->getStatusCode();
        $existing = $this->jsonPayload($response);
        $message = is_string($existing['message'] ?? null) && $existing['message'] !== ''
            ? $existing['message']
            : Response::$statusTexts[$status] ?? 'MCP connection request failed.';
        $diagnostic = [
            'id' => $this->diagnostics->id(),
            'occurred_at' => now()->toIso8601String(),
            'method' => strtoupper($request->method()),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $status,
            'tenant_id' => $this->tenantContext->current(),
            'target_endpoint' => $this->targetEndpoint($request),
            'exception' => $exception === null ? null : class_basename($exception),
            'detail' => $exception === null ? $this->diagnostics->sanitizeText($message) : $this->exceptionDetail($exception),
            'causes' => $this->exceptionCauses($exception),
            'outbound_attempts' => $this->diagnostics->outboundAttempts(),
            'configuration' => [
                'connector_enabled' => (bool) config('connector-mcp.enabled', false),
                'oauth_enabled' => (bool) config('connector-mcp.oauth.enabled', false),
                'runtime_mode' => (string) config('connector-mcp.runtime_mode', 'off'),
            ],
            'backend_log_channel' => (string) config('logging.default', 'stack'),
        ];

        Log::error('mcp.connection.request_failed', [
            'diagnostic_id' => $diagnostic['id'],
            'tenant_id' => $diagnostic['tenant_id'],
            'user_id' => $request->user()?->getAuthIdentifier(),
            'method' => $diagnostic['method'],
            'path' => $diagnostic['path'],
            'status' => $status,
            'target_endpoint' => $diagnostic['target_endpoint'],
            'exception_class' => $exception === null ? null : $exception::class,
            'exception_message' => $exception === null ? null : $this->diagnostics->sanitizeText($exception->getMessage()),
            'exception_trace' => $exception === null ? null : $this->diagnostics->sanitizeText($exception->getTraceAsString()),
            'outbound_attempts' => $diagnostic['outbound_attempts'],
            'configuration' => $diagnostic['configuration'],
        ]);

        $payload = ['message' => $message];
        if (is_array($existing['errors'] ?? null)) {
            $payload['errors'] = $existing['errors'];
        }
        $payload['diagnostic'] = $diagnostic;

        return response()
            ->json($payload, $status)
            ->header('X-MCP-Diagnostic-ID', (string) $diagnostic['id']);
    }

    /** @return array<string, mixed> */
    private function jsonPayload(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function targetEndpoint(Request $request): ?string
    {
        $endpoint = $request->input('endpoint');

        return is_string($endpoint) && $endpoint !== ''
            ? $this->diagnostics->sanitizeUrl($endpoint)
            : null;
    }

    private function exceptionDetail(\Throwable $exception): string
    {
        $detail = trim($this->diagnostics->sanitizeText($exception->getMessage()));

        return $detail !== '' ? $detail : class_basename($exception);
    }

    /** @return list<array{exception: string, detail: string}> */
    private function exceptionCauses(?\Throwable $exception): array
    {
        $causes = [];
        $cause = $exception?->getPrevious();

        while ($cause !== null && count($causes) < 3) {
            $causes[] = [
                'exception' => class_basename($cause),
                'detail' => $this->exceptionDetail($cause),
            ];
            $cause = $cause->getPrevious();
        }

        return $causes;
    }
}
