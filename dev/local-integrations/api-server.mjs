import { createServer } from 'node:http';

import {
  DATASET_REVISION,
  COMPANY_KEYS,
  claimsForCompany,
  findClaim,
  findInventory,
  inventoryForCompany,
  recentOrders,
} from './catalog-v2.mjs';
import {
  closeOnSignal,
  listen,
  localHostFromEnv,
  portFromEnv,
  sendJson,
} from './server-utils.mjs';
import { createRequestMonitor, monitorRequest } from './request-monitor.mjs';

export const API_PORT = 4310;

function healthPayload() {
  return {
    status: 'ok',
    service: 'askmydocs-local-api-fixture',
    datasetRevision: DATASET_REVISION,
  };
}

function notFound(response) {
  sendJson(response, 404, {
    error: 'not_found',
    message: 'The requested local fixture resource does not exist.',
  });
}

/**
 * Complementary HTTP surfaces: claims and inventory only. Operational entity
 * search belongs to MCP, so the two connectors do not duplicate each other.
 */
export function createApiServer() {
  const monitor = createRequestMonitor('api');

  return createServer((request, response) => {
    const url = new URL(request.url ?? '/', 'http://localhost');
    const pathCompanyKey = url.pathname.match(/^\/v1\/companies\/([^/]+)/)?.[1] ?? null;
    monitorRequest({
      monitor,
      request,
      response,
      url,
      companyKey: pathCompanyKey ? decodeURIComponent(pathCompanyKey) : null,
    });

    if (request.method === 'GET' && url.pathname === '/_dev/metrics') {
      sendJson(response, 200, monitor.snapshot());
      return;
    }

    if (request.method !== 'GET') {
      response.setHeader('Allow', 'GET');
      sendJson(response, 405, { error: 'method_not_allowed' });
      return;
    }

    if (url.pathname === '/health') {
      sendJson(response, 200, healthPayload());
      return;
    }

    const path = url.pathname.match(/^\/v1\/companies\/([^/]+)\/(claims|inventory|orders)(?:\/([^/]+))?$/);

    if (!path) {
      notFound(response);
      return;
    }

    const companyKey = decodeURIComponent(path[1]);
    const collection = path[2];
    const recordId = path[3] ? decodeURIComponent(path[3]) : null;
    if (!COMPANY_KEYS.includes(companyKey)) return notFound(response);

    if (collection === 'orders') {
      if (recordId !== null) return notFound(response);
      const fromDate = url.searchParams.get('from_date') || undefined;
      const toDate = url.searchParams.get('to_date') || undefined;
      const limitRaw = url.searchParams.get('limit');
      const limit = limitRaw === null ? 20 : Number(limitRaw);
      if ((fromDate && !/^\d{4}-\d{2}-\d{2}$/.test(fromDate))
        || (toDate && !/^\d{4}-\d{2}-\d{2}$/.test(toDate))
        || !Number.isInteger(limit) || limit < 1 || limit > 100) {
        sendJson(response, 400, { error: 'invalid_filter', message: 'Dates must use YYYY-MM-DD; limit must be 1–100.' });
        return;
      }
      sendJson(response, 200, { datasetRevision: DATASET_REVISION, ...recentOrders(companyKey, {
        fromDate, toDate, name: url.searchParams.get('name') || undefined, limit,
      }) });
      return;
    }

    const result = collection === 'claims'
      ? recordId === null
        ? claimsForCompany(companyKey, {
            shipmentId: url.searchParams.get('shipment_id') || undefined,
            orderId: url.searchParams.get('order_id') || undefined,
          })
        : findClaim(companyKey, recordId)
      : recordId === null
        ? inventoryForCompany(companyKey, { productId: url.searchParams.get('product_id') || undefined })
        : findInventory(companyKey, recordId);

    if (result !== null) {
      sendJson(response, 200, { datasetRevision: DATASET_REVISION, ...result });
      return;
    }

    notFound(response);
  });
}

export async function startApiServer({ host = localHostFromEnv(), port = portFromEnv('LOCAL_INTEGRATIONS_API_PORT', API_PORT) } = {}) {
  const server = createApiServer();
  const address = await listen(server, { host, port });
  return { server, address };
}

if (import.meta.url === new URL(process.argv[1], 'file:').href) {
  const { server, address } = await startApiServer();
  const { address: host, port } = address;
  console.info(`Local API fixture listening at http://${host}:${port}`);
  closeOnSignal(server, 'Local API fixture');
}
