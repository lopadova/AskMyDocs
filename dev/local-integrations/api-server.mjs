import { createServer } from 'node:http';

import {
  DATASET_REVISION,
  companyContext,
  findRecord,
  listCompanies,
  recordsForCompany,
} from './dataset.mjs';
import {
  closeOnSignal,
  listen,
  localHostFromEnv,
  portFromEnv,
  sendJson,
} from './server-utils.mjs';

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
 * Local, static API. There is intentionally no unscoped records collection:
 * every operational request must name a known case-study company in its path.
 */
export function createApiServer() {
  return createServer((request, response) => {
    const url = new URL(request.url ?? '/', 'http://localhost');

    if (request.method !== 'GET') {
      response.setHeader('Allow', 'GET');
      sendJson(response, 405, { error: 'method_not_allowed' });
      return;
    }

    if (url.pathname === '/health') {
      sendJson(response, 200, healthPayload());
      return;
    }

    if (url.pathname === '/v1/companies') {
      sendJson(response, 200, {
        datasetRevision: DATASET_REVISION,
        companies: listCompanies(),
      });
      return;
    }

    const path = url.pathname.match(/^\/v1\/companies\/([^/]+)(?:\/(context|records)(?:\/([^/]+))?)?$/);

    if (!path) {
      notFound(response);
      return;
    }

    const companyKey = decodeURIComponent(path[1]);
    const collection = path[2];
    const recordId = path[3] ? decodeURIComponent(path[3]) : null;

    if (collection === 'context') {
      const context = companyContext(companyKey);
      if (context === null) {
        notFound(response);
        return;
      }

      sendJson(response, 200, { datasetRevision: DATASET_REVISION, ...context });
      return;
    }

    if (collection === 'records' && recordId !== null) {
      const result = findRecord(companyKey, recordId);
      if (result === null) {
        notFound(response);
        return;
      }

      sendJson(response, 200, { datasetRevision: DATASET_REVISION, ...result });
      return;
    }

    if (collection === 'records') {
      const result = recordsForCompany(companyKey, {
        kind: url.searchParams.get('kind') || undefined,
        status: url.searchParams.get('status') || undefined,
      });

      if (result === null) {
        notFound(response);
        return;
      }

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
