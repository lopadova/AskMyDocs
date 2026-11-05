import { createServer } from 'node:http';
import { randomUUID } from 'node:crypto';

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import * as z from 'zod/v4';

import {
  COMPANY_KEYS,
  DATASET_REVISION,
  MCP_ENTITY_TYPES,
  recentOrders,
  searchEntities,
} from './catalog-v2.mjs';
import {
  closeOnSignal,
  listen,
  localHostFromEnv,
  portFromEnv,
  sendJson,
} from './server-utils.mjs';
import { captureMcpExchange, createRequestMonitor, monitorRequest } from './request-monitor.mjs';

export const MCP_PORT = 4311;
const MAX_BODY_BYTES = 1_000_000;
const companyKeySchema = z.enum(COMPANY_KEYS);
const dateSchema = z.iso.date();
const filterInput = {
  query: z.string().max(200).optional().default(''),
  from_date: dateSchema.optional(),
  to_date: dateSchema.optional(),
  name: z.string().max(100).optional(),
  limit: z.number().int().min(1).max(100).optional().default(20),
};
const readOnlyAnnotations = {
  readOnlyHint: true,
  destructiveHint: false,
  idempotentHint: true,
  openWorldHint: false,
};

function asToolResult(payload) {
  return {
    content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }],
    structuredContent: payload,
  };
}

function unknownCompanyResult(companyKey) {
  return {
    content: [{ type: 'text', text: `Unknown local fixture company: ${companyKey}` }],
    isError: true,
  };
}

function resolveCompanyKey(scopeCompanyKey, argumentsObject) {
  return scopeCompanyKey ?? argumentsObject.company_key;
}

/**
 * When `scopeCompanyKey` is set, the endpoint itself is the company boundary.
 * This is the endpoint that a per-tenant connector should use. The unscoped
 * `/mcp` endpoint remains useful for protocol exploration and explicitly
 * requires `company_key` on every tool call.
 */
export function createMcpFixtureServer({ scopeCompanyKey = null } = {}) {
  if (scopeCompanyKey !== null && !COMPANY_KEYS.includes(scopeCompanyKey)) {
    throw new Error(`Unknown local fixture company scope: ${scopeCompanyKey}`);
  }

  const companyScopeInput = scopeCompanyKey === null ? { company_key: companyKeySchema } : {};
  const server = new McpServer(
    {
      name: 'askmydocs-local-mcp-fixture',
      version: '1.0.0',
    },
    {
      capabilities: { logging: {} },
      instructions:
        'Local deterministic fixture only. Every request must explicitly select one case-study company.',
    },
  );

  for (const type of MCP_ENTITY_TYPES) {
    server.registerTool(
      `search_${type}`,
      {
        title: `Search local ${type}`,
        description: `Search ${type} in this company by optional query or name. Empty filters list available records. For orders, from_date and to_date filter the date of the cited email (observedAt), not an invented creation date; results are newest first. Dates use YYYY-MM-DD. Limit 1–100. Returns explicit relationships.`,
        inputSchema: { ...companyScopeInput, ...filterInput },
        annotations: readOnlyAnnotations,
      },
      async (argumentsObject) => {
        const companyKey = resolveCompanyKey(scopeCompanyKey, argumentsObject);
        const result = searchEntities(companyKey, type, argumentsObject.query, {
          fromDate: argumentsObject.from_date, toDate: argumentsObject.to_date,
          name: argumentsObject.name, limit: argumentsObject.limit,
        });
        return result === null ? unknownCompanyResult(companyKey) : asToolResult({ datasetRevision: DATASET_REVISION, ...result });
      },
    );
  }

  server.registerTool('list_recent_orders', {
    title: 'List recent local orders',
    description: 'List the latest orders of this company without a required search query. Optional from_date, to_date (YYYY-MM-DD), name (customer or supplier), and limit (1–100). Dates are the dates of the cited fixture emails, exposed as observedAt, not asserted order creation dates.',
    inputSchema: { ...companyScopeInput, from_date: dateSchema.optional(), to_date: dateSchema.optional(), name: z.string().max(100).optional(), limit: z.number().int().min(1).max(100).optional().default(20) },
    annotations: readOnlyAnnotations,
  }, async (argumentsObject) => {
    const companyKey = resolveCompanyKey(scopeCompanyKey, argumentsObject);
    const result = recentOrders(companyKey, { fromDate: argumentsObject.from_date, toDate: argumentsObject.to_date, name: argumentsObject.name, limit: argumentsObject.limit });
    return result === null ? unknownCompanyResult(companyKey) : asToolResult({ datasetRevision: DATASET_REVISION, ...result });
  });

  return server;
}

async function parseJsonBody(request) {
  const chunks = [];
  let bytes = 0;

  for await (const chunk of request) {
    bytes += chunk.length;
    if (bytes > MAX_BODY_BYTES) {
      throw new Error('Request body exceeds the 1 MB local fixture limit.');
    }
    chunks.push(chunk);
  }

  const text = Buffer.concat(chunks).toString('utf8');
  return text === '' ? undefined : JSON.parse(text);
}

function mcpMethodNotAllowed(response) {
  sendJson(response, 405, {
    jsonrpc: '2.0',
    error: { code: -32000, message: 'Method not allowed.' },
    id: null,
  });
}

/**
 * The host's PHP MCP transport is JSON-RPC compatible but predates the
 * Streamable HTTP Accept-header requirement. Keep the compatibility shim
 * inside this loopback-only fixture so real remote MCP endpoints retain their
 * own protocol policy. Modern clients already send both values unchanged.
 */
function normalizeAcceptHeaderForHostTransport(request) {
  const accept = request.headers.accept || '';

  if (accept.includes('application/json') && accept.includes('text/event-stream')) {
    return;
  }

  request.headers.accept = 'application/json, text/event-stream';
}

/**
 * The PHP connector's legacy protocol fallback emits an empty PHP array for
 * the no-argument `tools/list` call. JSON encodes that as `[]`, while MCP
 * requires the `params` member to be an object. This is deliberately limited
 * to an empty parameter collection in the loopback fixture.
 */
function normalizeLegacyEmptyParams(payload) {
  if (Array.isArray(payload)) {
    return payload.map(normalizeLegacyEmptyParams);
  }

  if (payload !== null && typeof payload === 'object') {
    if (Array.isArray(payload.params) && payload.params.length === 0) {
      return { ...payload, params: {} };
    }

    if (
      payload.params !== null
      && typeof payload.params === 'object'
      && Array.isArray(payload.params.arguments)
      && payload.params.arguments.length === 0
    ) {
      return {
        ...payload,
        params: { ...payload.params, arguments: {} },
      };
    }
  }

  return payload;
}

function isInitializeRequest(payload) {
  return !Array.isArray(payload)
    && payload !== null
    && typeof payload === 'object'
    && payload.method === 'initialize';
}

function isModernDiscoveryRequest(payload) {
  return !Array.isArray(payload)
    && payload !== null
    && typeof payload === 'object'
    && payload.method === 'server/discover';
}

function requestSessionId(request) {
  const value = request.headers['mcp-session-id'];
  return Array.isArray(value) ? value[0] : value;
}

function invalidSession(response, message, status = 400) {
  sendJson(response, status, {
    jsonrpc: '2.0',
    error: { code: -32000, message },
    id: null,
  });
}

/**
 * A stateful Streamable HTTP MCP endpoint. The local PHP connector starts with
 * the legacy MCP lifecycle, whose `notifications/initialized` and `tools/list`
 * calls must reuse the session established by `initialize`.
 */
export function createMcpHttpServer() {
  const sessions = new Map();
  const monitor = createRequestMonitor('mcp');
  const httpServer = createServer(async (request, response) => {
    const url = new URL(request.url ?? '/', 'http://localhost');
    const pathCompanyKey = url.pathname.match(/^\/mcp\/([^/]+)$/)?.[1] ?? null;

    if (request.method === 'GET' && url.pathname === '/_dev/metrics') {
      sendJson(response, 200, monitor.snapshot());
      return;
    }

    if (request.method === 'GET' && url.pathname === '/health') {
      sendJson(response, 200, {
        status: 'ok',
        service: 'askmydocs-local-mcp-fixture',
        datasetRevision: DATASET_REVISION,
        transport: 'streamable-http',
        endpoint: '/mcp',
      });
      return;
    }

    const mcpCapture = captureMcpExchange(response);
    monitorRequest({
      monitor,
      request,
      response,
      url,
      companyKey: pathCompanyKey ? decodeURIComponent(pathCompanyKey) : null,
      exchange: mcpCapture.exchange,
    });

    const endpoint = url.pathname.match(/^\/mcp(?:\/([^/]+))?$/);

    if (!endpoint) {
      sendJson(response, 404, { error: 'not_found' });
      return;
    }

    if (request.method !== 'POST') {
      mcpMethodNotAllowed(response);
      return;
    }

    try {
      normalizeAcceptHeaderForHostTransport(request);
      const parsedBody = normalizeLegacyEmptyParams(await parseJsonBody(request));
      mcpCapture.setRequest(parsedBody);
      const scopeCompanyKey = endpoint[1] ? decodeURIComponent(endpoint[1]) : null;
      if (scopeCompanyKey !== null && !COMPANY_KEYS.includes(scopeCompanyKey)) {
        sendJson(response, 404, { error: 'not_found' });
        return;
      }

      const sessionId = requestSessionId(request);
      let session = sessionId ? sessions.get(sessionId) : null;

      // The fixture implements the standard session-based MCP lifecycle. The
      // host probes its newer discovery method first, so reject precisely that
      // probe as an unknown JSON-RPC method and let it select its legacy flow.
      if (session === null && !sessionId && isModernDiscoveryRequest(parsedBody)) {
        sendJson(response, 200, {
          jsonrpc: '2.0',
          error: { code: -32601, message: 'Method not found.' },
          id: parsedBody.id ?? null,
        });
        return;
      }

      if (session !== null && session.scopeCompanyKey !== scopeCompanyKey) {
        invalidSession(response, 'Session does not belong to this fixture endpoint.', 404);
        return;
      }

      if (session === null && sessionId) {
        invalidSession(response, 'Session not found.', 404);
        return;
      }

      if (session === null && !isInitializeRequest(parsedBody)) {
        invalidSession(response, 'Initialize the fixture MCP session before making this request.');
        return;
      }

      if (session === null) {
        const mcpServer = createMcpFixtureServer({ scopeCompanyKey });
        const transport = new StreamableHTTPServerTransport({
          sessionIdGenerator: randomUUID,
          enableJsonResponse: true,
          onsessioninitialized: (createdSessionId) => {
            sessions.set(createdSessionId, session);
          },
        });
        session = { scopeCompanyKey, mcpServer, transport };
        transport.onclose = () => {
          const createdSessionId = transport.sessionId;
          if (createdSessionId) {
            sessions.delete(createdSessionId);
          }
          void mcpServer.close();
        };
        await mcpServer.connect(transport);
      }

      await session.transport.handleRequest(request, response, parsedBody);
    } catch (error) {
      console.error('Local MCP fixture request failed:', error);
      if (!response.headersSent) {
        sendJson(response, 400, {
          jsonrpc: '2.0',
          error: { code: -32700, message: 'Invalid JSON-RPC request.' },
          id: null,
        });
      }
    }
  });

  httpServer.on('close', () => {
    for (const session of sessions.values()) {
      void session.transport.close();
      void session.mcpServer.close();
    }
    sessions.clear();
  });

  return httpServer;
}

export async function startMcpServer({ host = localHostFromEnv(), port = portFromEnv('LOCAL_INTEGRATIONS_MCP_PORT', MCP_PORT) } = {}) {
  const server = createMcpHttpServer();
  const address = await listen(server, { host, port });
  return { server, address };
}

if (import.meta.url === new URL(process.argv[1], 'file:').href) {
  const { server, address } = await startMcpServer();
  const { address: host, port } = address;
  console.info(`Local MCP fixture listening at http://${host}:${port}/mcp`);
  closeOnSignal(server, 'Local MCP fixture');
}
