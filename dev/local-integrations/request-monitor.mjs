const MAX_EVENTS = 120;
const MAX_EXCHANGE_BYTES = 32 * 1024;
const SENSITIVE_KEY = /(?:authorization|cookie|password|secret|token|api[-_]?key|credential)/i;

/**
 * In-memory request telemetry for the two loopback-only development mocks.
 * API requests record metadata only. The local MCP fixture may attach its
 * parsed JSON-RPC request/response so the service console can aid debugging.
 * Headers and sensitive values are always omitted or redacted.
 */
export function createRequestMonitor(service) {
  const startedAt = new Date();
  const events = [];
  let requestCount = 0;
  let errorCount = 0;

  function record({ method, path, status, durationMs, companyKey = null, exchange = null }) {
    requestCount += 1;
    if (status >= 400) {
      errorCount += 1;
    }

    const event = {
      id: requestCount,
      at: new Date().toISOString(),
      method,
      path,
      status,
      duration_ms: Math.max(0, Math.round(durationMs)),
      company_key: companyKey,
    };

    if (exchange !== null) {
      event.exchange = exchange;
    }

    events.push(event);

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
export function monitorRequest({ monitor, request, response, url, companyKey = null, exchange = null }) {
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
      exchange,
    });
  });
}

/**
 * Capture a bounded, redacted MCP JSON-RPC exchange from the Node response.
 * The only consumer is the local service console; this state dies with the
 * fixture process and is never persisted to Laravel or a log file.
 */
export function captureMcpExchange(response, exchange = null) {
  const capturedExchange = exchange ?? {
    request: null,
    response: null,
    response_truncated: false,
  };
  const chunks = [];
  let capturedBytes = 0;
  let finalized = false;

  const appendChunk = (chunk, encoding) => {
    if (chunk === undefined || chunk === null || typeof chunk === 'function') {
      return;
    }

    const buffer = Buffer.isBuffer(chunk)
      ? chunk
      : ArrayBuffer.isView(chunk)
        ? Buffer.from(chunk.buffer, chunk.byteOffset, chunk.byteLength)
        : Buffer.from(String(chunk), typeof encoding === 'string' ? encoding : 'utf8');
    const remaining = MAX_EXCHANGE_BYTES - capturedBytes;

    if (remaining <= 0) {
      capturedExchange.response_truncated = true;
      return;
    }

    if (buffer.length > remaining) {
      chunks.push(buffer.subarray(0, remaining));
      capturedBytes += remaining;
      capturedExchange.response_truncated = true;
      return;
    }

    chunks.push(buffer);
    capturedBytes += buffer.length;
  };

  const finalize = () => {
    if (finalized) {
      return;
    }

    finalized = true;
    capturedExchange.response = redactSensitive(parseMcpResponse(Buffer.concat(chunks).toString('utf8')));
  };

  const originalWrite = response.write;
  response.write = function write(chunk, encoding, callback) {
    appendChunk(chunk, encoding);
    return originalWrite.call(this, chunk, encoding, callback);
  };

  const originalEnd = response.end;
  response.end = function end(chunk, encoding, callback) {
    appendChunk(chunk, encoding);
    finalize();
    return originalEnd.call(this, chunk, encoding, callback);
  };

  return {
    exchange: capturedExchange,
    setRequest(requestPayload) {
      capturedExchange.request = redactSensitive(requestPayload);
    },
  };
}

function parseMcpResponse(body) {
  if (body === '') {
    return null;
  }

  try {
    return JSON.parse(body);
  } catch {
    const ssePayloads = body
      .split(/\r?\n/)
      .filter((line) => line.startsWith('data:'))
      .map((line) => line.slice(5).trim())
      .filter(Boolean)
      .map((payload) => {
        try {
          return JSON.parse(payload);
        } catch {
          return payload;
        }
      });

    return ssePayloads.length > 0 ? ssePayloads : { raw: body };
  }
}

function redactSensitive(value, depth = 0) {
  if (depth > 12 || value === null || typeof value !== 'object') {
    return value;
  }

  if (Array.isArray(value)) {
    return value.map((item) => redactSensitive(item, depth + 1));
  }

  return Object.fromEntries(
    Object.entries(value).map(([key, item]) => [
      key,
      SENSITIVE_KEY.test(key) ? '[redacted]' : redactSensitive(item, depth + 1),
    ]),
  );
}
