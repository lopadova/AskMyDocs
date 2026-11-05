import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

import { startApiServer } from '../api-server.mjs';
import { startMcpServer } from '../mcp-server.mjs';
import { catalogForValidation, COMPANY_KEYS, MCP_ENTITY_TYPES } from '../catalog-v2.mjs';

async function closeServer(server) {
  await new Promise((resolve, reject) => {
    server.close((error) => (error ? reject(error) : resolve()));
  });
}

test('linked catalog references checked-in gold emails and resolves relationships', () => {
  const catalog = catalogForValidation();
  for (const companyKey of COMPANY_KEYS) {
    const data = catalog[companyKey];
    const byType = Object.fromEntries(Object.entries(data).map(([type, records]) =>
      [type, new Set(records.map((record) => record.id))]));
    for (const [type, records] of Object.entries(data)) {
      assert.equal(byType[type].size, records.length, `${companyKey}/${type} duplicate ID`);
      for (const record of records) {
        assert.ok(record.emailEvidence?.length, `${companyKey}/${type}/${record.id} has no email link`);
        for (const evidence of record.emailEvidence) {
          assert.ok(evidence.mailboxKey.startsWith(companyKey), 'mailbox must stay in tenant');
          const messages = JSON.parse(readFileSync(new URL(`../../../database/seeders/emails/${evidence.mailboxKey}.json`, import.meta.url)));
          const message = messages.find((candidate) => candidate.subject === evidence.subject);
          assert.ok(message, `${companyKey}: email subject not found: ${evidence.subject}`);
          if (type === 'orders') assert.equal(record.observedAt, message.date.slice(0, 10));
          assert.ok([message.subject, message.from_name, message.body_text].join(' ').toLocaleLowerCase('it-IT')
            .includes(evidence.reference.toLocaleLowerCase('it-IT')), `${companyKey}: email reference not found: ${evidence.reference}`);
        }
        if (record.orderId) assert.ok(byType.orders.has(record.orderId), `${record.id}: missing order`);
        if (record.customerId) assert.ok(byType.customers.has(record.customerId), `${record.id}: missing customer`);
        if (record.productId) assert.ok(byType.products.has(record.productId), `${record.id}: missing product`);
        if (record.shipmentId) assert.ok(byType.shipments.has(record.shipmentId), `${record.id}: missing shipment`);
        for (const orderId of record.orderIds ?? []) assert.ok(byType.orders.has(orderId));
        for (const shipmentId of record.shipmentIds ?? []) assert.ok(byType.shipments.has(shipmentId));
        for (const productId of record.productIds ?? []) assert.ok(byType.products.has(productId));
      }
    }
  }
});

test('API serves only tenant-scoped claims and inventory', async (t) => {
  const { server, address } = await startApiServer({ host: '127.0.0.1', port: 0 });
  t.after(() => closeServer(server));

  const baseUrl = `http://127.0.0.1:${address.port}`;
  const health = await fetch(`${baseUrl}/health`);
  assert.equal(health.status, 200);
  assert.equal((await health.json()).service, 'askmydocs-local-api-fixture');

  const rottaClaims = await fetch(`${baseUrl}/v1/companies/rotta-logistics/claims?shipment_id=SPD-51230`);
  assert.equal(rottaClaims.status, 200);
  const rottaPayload = await rottaClaims.json();
  assert.equal(rottaPayload.companyKey, 'rotta-logistics');
  assert.equal(rottaPayload.claims[0].status, 'requested_not_confirmed');

  const prometeoInventory = await fetch(`${baseUrl}/v1/companies/prometeo-antincendio/inventory?product_id=PR-POLVERE-250`);
  assert.equal(prometeoInventory.status, 200);
  assert.equal((await prometeoInventory.json()).inventory[0].incomingQuantity, 250);

  const recent = await fetch(`${baseUrl}/v1/companies/passolibero-calzature/orders?from_date=2024-07-01&name=Chiara&limit=1`);
  assert.equal(recent.status, 200);
  assert.deepEqual((await recent.json()).orders.map((order) => order.id), ['CLB-6390']);
  for (const [companyKey, minimum] of [['rotta-logistics', 3], ['prometeo-antincendio', 4], ['passolibero-calzature', 9]]) {
    const response = await fetch(`${baseUrl}/v1/companies/${companyKey}/orders`);
    const payload = await response.json();
    assert.ok(payload.total >= minimum, `${companyKey}: too few curated orders`);
    assert.deepEqual(payload.orders.map((order) => order.observedAt),
      [...payload.orders.map((order) => order.observedAt)].sort().reverse());
  }
  assert.equal((await fetch(`${baseUrl}/v1/companies/passolibero-calzature/orders?limit=0`)).status, 400);

  const crossCompanyRecord = await fetch(
    `${baseUrl}/v1/companies/rotta-logistics/claims/RC-2024-014`,
  );
  assert.equal(crossCompanyRecord.status, 404);
  assert.equal((await fetch(`${baseUrl}/v1/companies/rotta-logistics/records`)).status, 404);

  const metrics = await fetch(`${baseUrl}/_dev/metrics`);
  assert.equal(metrics.status, 200);
  const metricPayload = await metrics.json();
  assert.equal(metricPayload.totals.requests, 9);
  assert.equal(metricPayload.totals.errors, 3);
  assert.equal(metricPayload.events[0].company_key, 'rotta-logistics');
});

test('MCP exposes tenant-scoped searches distinct from API routes', async (t) => {
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
    [...MCP_ENTITY_TYPES.map((type) => `search_${type}`), 'list_recent_orders'].sort(),
  );
  assert.ok(tools.tools.every((tool) => tool.annotations?.readOnlyHint === true));

  const result = await client.callTool({
    name: 'search_orders',
    arguments: {
      query: 'FRN-2024-241',
    },
  });

  assert.equal(result.isError, undefined);
  assert.equal(result.structuredContent.records[0].supplier, 'ConceriaToscana');

  const recentOrders = await client.callTool({ name: 'list_recent_orders', arguments: { limit: 1 } });
  assert.deepEqual(recentOrders.structuredContent.orders.map((order) => order.id), ['CLB-5801']);
  const emptySearch = await client.callTool({ name: 'search_orders', arguments: {} });
  assert.equal(emptySearch.structuredContent.total, 9);

  const rottaClient = new Client({ name: 'local-fixture-scope-test', version: '1.0.0' });
  const rottaTransport = new StreamableHTTPClientTransport(new URL(`${baseUrl}/mcp/rotta-logistics`));
  await rottaClient.connect(rottaTransport);
  t.after(() => rottaClient.close());

  const blockedResult = await rottaClient.callTool({
    name: 'search_orders',
    arguments: {
      query: 'FRN-2024-241',
    },
  });
  assert.deepEqual(blockedResult.structuredContent.records, []);

  const otherCompanyEndpoint = await fetch(`${baseUrl}/mcp/not-a-company`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { token: 'local-test-secret' } }),
  });
  assert.equal(otherCompanyEndpoint.status, 404);

  const metrics = await fetch(`${baseUrl}/_dev/metrics`);
  assert.equal(metrics.status, 200);
  const metricPayload = await metrics.json();
  assert.ok(metricPayload.totals.requests >= 6);
  assert.ok(metricPayload.events.some((event) => event.company_key === 'passolibero-calzature'));
  assert.ok(metricPayload.events.some((event) => event.status === 404));

  const toolCall = metricPayload.events.find(
    (event) => event.exchange?.request?.method === 'tools/call'
      && event.exchange?.request?.params?.name === 'search_orders',
  );
  assert.equal(toolCall.exchange.request.params.arguments.query, 'FRN-2024-241');
  assert.equal(toolCall.exchange.response.result.structuredContent.records[0].id, 'FRN-2024-241');

  const rejectedRequest = metricPayload.events.find((event) => event.company_key === 'not-a-company');
  assert.equal(rejectedRequest.exchange.request.params.token, '[redacted]');
});
