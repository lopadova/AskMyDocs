import assert from 'node:assert/strict';
import test from 'node:test';

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

import { startApiServer } from '../api-server.mjs';
import { startMcpServer } from '../mcp-server.mjs';

async function closeServer(server) {
  await new Promise((resolve, reject) => {
    server.close((error) => (error ? reject(error) : resolve()));
  });
}

test('API serves only company-scoped fixture records', async (t) => {
  const { server, address } = await startApiServer({ host: '127.0.0.1', port: 0 });
  t.after(() => closeServer(server));

  const baseUrl = `http://127.0.0.1:${address.port}`;
  const health = await fetch(`${baseUrl}/health`);
  assert.equal(health.status, 200);
  assert.equal((await health.json()).service, 'askmydocs-local-api-fixture');

  const rottaRecords = await fetch(`${baseUrl}/v1/companies/rotta-logistics/records`);
  assert.equal(rottaRecords.status, 200);
  const rottaPayload = await rottaRecords.json();
  assert.equal(rottaPayload.company.key, 'rotta-logistics');
  assert.ok(rottaPayload.records.every((record) => record.id.startsWith('shipment-')));

  const crossCompanyRecord = await fetch(
    `${baseUrl}/v1/companies/rotta-logistics/records/purchase-order-FRN-2024-241`,
  );
  assert.equal(crossCompanyRecord.status, 404);
});

test('MCP exposes read-only company-scoped tools over the same fixture dataset', async (t) => {
  const { server, address } = await startMcpServer({ host: '127.0.0.1', port: 0 });
  t.after(() => closeServer(server));

  const baseUrl = `http://127.0.0.1:${address.port}`;
  const health = await fetch(`${baseUrl}/health`);
  assert.equal(health.status, 200);
  assert.equal((await health.json()).transport, 'streamable-http');

  const client = new Client({ name: 'local-fixture-smoke-test', version: '1.0.0' });
  const transport = new StreamableHTTPClientTransport(new URL(`${baseUrl}/mcp/passolibero-calzature`));
  await client.connect(transport);
  t.after(() => client.close());

  const tools = await client.listTools();
  assert.deepEqual(
    tools.tools.map((tool) => tool.name).sort(),
    [
      'get_company_context',
      'get_operational_record',
      'list_operational_records',
      'search_operational_records',
    ],
  );
  assert.ok(tools.tools.every((tool) => tool.annotations?.readOnlyHint === true));

  const result = await client.callTool({
    name: 'get_operational_record',
    arguments: {
      record_id: 'purchase-order-FRN-2024-241',
    },
  });

  assert.equal(result.isError, undefined);
  assert.match(result.content[0].text, /ConceriaToscana/);

  const rottaClient = new Client({ name: 'local-fixture-scope-test', version: '1.0.0' });
  const rottaTransport = new StreamableHTTPClientTransport(new URL(`${baseUrl}/mcp/rotta-logistics`));
  await rottaClient.connect(rottaTransport);
  t.after(() => rottaClient.close());

  const blockedResult = await rottaClient.callTool({
    name: 'get_operational_record',
    arguments: {
      record_id: 'purchase-order-FRN-2024-241',
    },
  });
  assert.equal(blockedResult.isError, true);

  const otherCompanyEndpoint = await fetch(`${baseUrl}/mcp/not-a-company`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: {} }),
  });
  assert.equal(otherCompanyEndpoint.status, 404);
});
