const MAX_EVENTS = 120;

/**
 * In-memory request telemetry for the two loopback-only development mocks.
 * It intentionally contains request metadata only: never bodies, headers,
 * credentials, or MCP tool arguments.
 */
export function createRequestMonitor(service) {
  const startedAt = new Date();
  const events = [];
  let requestCount = 0;
  let errorCount = 0;

  function record({ method, path, status, durationMs, companyKey = null }) {
    requestCount += 1;
    if (status >= 400) {
      errorCount += 1;
    }

    events.push({
      id: requestCount,
      at: new Date().toISOString(),
      method,
      path,
      status,
      duration_ms: Math.max(0, Math.round(durationMs)),
      company_key: companyKey,
    });

    if (events.length > MAX_EVENTS) {
      events.splice(0, events.length - MAX_EVENTS);
    }
  }

  function snapshot() {
    return {
      service,
      started_at: startedAt.toISOString(),
      uptime_seconds: Math.max(0, Math.floor((Date.now() - startedAt.getTime()) / 1000)),
      totals: {
        requests: requestCount,
        errors: errorCount,
      },
      events: [...events],
    };
  }

  return { record, snapshot };
}

/**
 * Attach response-finish telemetry once per meaningful fixture call. Health
 * checks and monitoring polls are deliberately excluded so the chart reflects
 * API/MCP usage rather than the dashboard observing itself.
 */
export function monitorRequest({ monitor, request, response, url, companyKey = null }) {
  if (url.pathname === '/health' || url.pathname.startsWith('/_dev/')) {
    return;
  }

  const startedAt = performance.now();
  response.once('finish', () => {
    monitor.record({
      method: request.method || 'UNKNOWN',
      path: url.pathname,
      status: response.statusCode,
      durationMs: performance.now() - startedAt,
      companyKey,
    });
  });
}
