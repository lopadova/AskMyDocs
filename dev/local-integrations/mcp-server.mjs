import { createServer } from 'node:http';
import { randomUUID } from 'node:crypto';

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import * as z from 'zod/v4';

import {
  COMPANY_KEYS,
  DATASET_REVISION,
  companyContext,
  findRecord,
  recordsForCompany,
  searchRecords,
} from './dataset.mjs';
import {
  closeOnSignal,
  listen,
  localHostFromEnv,
  portFromEnv,
  sendJson,
} from './server-utils.mjs';

export const MCP_PORT = 4311;
const MAX_BODY_BYTES = 1_000_000;
const companyKeySchema = z.enum(COMPANY_KEYS);
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
  if (scopeCompanyKey !== null && companyContext(scopeCompanyKey) === null) {
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

  server.registerTool(
    'get_company_context',
    {
      title: 'Get local company context',
      description:
        'Returns the selected local case-study company identity, its configured mailbox keys and its correlated document IDs.',
      inputSchema: companyScopeInput,
      annotations: readOnlyAnnotations,
    },
    async (argumentsObject) => {
      const companyKey = resolveCompanyKey(scopeCompanyKey, argumentsObject);
      const context = companyContext(companyKey);
      return context === null ? unknownCompanyResult(companyKey) : asToolResult({ datasetRevision: DATASET_REVISION, ...context });
    },
  );

  server.registerTool(
    'list_operational_records',
    {
      title: 'List local operational records',
      description:
        'Lists static operational records for exactly one selected local case-study company. Optional filters never cross company boundaries.',
      inputSchema: {
        ...companyScopeInput,
        kind: z.string().min(1).optional(),
        status: z.string().min(1).optional(),
      },
      annotations: readOnlyAnnotations,
    },
    async (argumentsObject) => {
      const companyKey = resolveCompanyKey(scopeCompanyKey, argumentsObject);
      const { kind, status } = argumentsObject;
      const records = recordsForCompany(companyKey, { kind, status });
      return records === null ? unknownCompanyResult(companyKey) : asToolResult({ datasetRevision: DATASET_REVISION, ...records });
    },
  );

  server.registerTool(
    'get_operational_record',
    {
      title: 'Get local operational record',
      description:
        'Reads one static operational record only within the selected case-study company. A record ID from another company is not disclosed.',
      inputSchema: {
        ...companyScopeInput,
        record_id: z.string().min(1),
      },
      annotations: readOnlyAnnotations,
    },
    async (argumentsObject) => {
      const companyKey = resolveCompanyKey(scopeCompanyKey, argumentsObject);
      const recordId = argumentsObject.record_id;
      const result = findRecord(companyKey, recordId);
      return result === null
        ? {
            content: [{ type: 'text', text: 'The requested record is not available for this local fixture company.' }],
            isError: true,
          }
        : asToolResult({ datasetRevision: DATASET_REVISION, ...result });
    },
  );

  server.registerTool(
    'search_operational_records',
    {
      title: 'Search local operational records',
      description:
        'Performs a case-insensitive search over the static records of one local case-study company only.',
      inputSchema: {
        ...companyScopeInput,
        query: z.string().max(200),
      },
      annotations: readOnlyAnnotations,
    },
    async (argumentsObject) => {
      const companyKey = resolveCompanyKey(scopeCompanyKey, argumentsObject);
      const query = argumentsObject.query;
      const result = searchRecords(companyKey, query);
      return result === null ? unknownCompanyResult(companyKey) : asToolResult({ datasetRevision: DATASET_REVISION, ...result });
    },
  );

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

  if (
    payload !== null
    && typeof payload === 'object'
    && Array.isArray(payload.params)
    && payload.params.length === 0
  ) {
    return { ...payload, params: {} };
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
  const httpServer = createServer(async (request, response) => {
    const url = new URL(request.url ?? '/', 'http://localhost');

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
      const scopeCompanyKey = endpoint[1] ? decodeURIComponent(endpoint[1]) : null;
      if (scopeCompanyKey !== null && companyContext(scopeCompanyKey) === null) {
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
