import { once } from 'node:events';

const LOOPBACK_HOSTS = new Set(['127.0.0.1', '::1', 'localhost']);

export function localHostFromEnv() {
  const host = process.env.LOCAL_INTEGRATIONS_HOST || '127.0.0.1';

  if (!LOOPBACK_HOSTS.has(host)) {
    throw new Error(
      'LOCAL_INTEGRATIONS_HOST must stay on loopback (127.0.0.1, ::1, or localhost).',
    );
  }

  return host;
}

export function portFromEnv(name, fallback) {
  const rawPort = process.env[name] ?? String(fallback);
  const port = Number(rawPort);

  if (!Number.isInteger(port) || port < 1 || port > 65535) {
    throw new Error(`${name} must be an integer between 1 and 65535.`);
  }

  return port;
}

export async function listen(server, { host, port }) {
  server.listen({ host, port });
  await once(server, 'listening');
  return server.address();
}

export function closeOnSignal(server, label) {
  let closing = false;

  const close = (signal) => {
    if (closing) {
      return;
    }

    closing = true;
    console.info(`${label} received ${signal}; stopping.`);
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(1), 5_000).unref();
  };

  process.once('SIGTERM', () => close('SIGTERM'));
  process.once('SIGINT', () => close('SIGINT'));
}

export function sendJson(response, statusCode, payload) {
  response.writeHead(statusCode, {
    'Cache-Control': 'no-store',
    'Content-Type': 'application/json; charset=utf-8',
    'X-Content-Type-Options': 'nosniff',
  });
  response.end(JSON.stringify(payload));
}
