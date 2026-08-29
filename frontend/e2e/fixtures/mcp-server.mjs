import { createServer } from 'node:http';

const port = Number(process.env.E2E_MCP_PORT ?? 3536);
const origin = `http://127.0.0.1:${port}`;

const tools = [
    {
        name: 'docs.search',
        title: 'Search documents',
        description: 'Returns fresh document evidence.',
        inputSchema: { type: 'object', properties: { query: { type: 'string' } } },
        annotations: { readOnlyHint: true, destructiveHint: false },
    },
    {
        name: 'docs.update',
        title: 'Update document',
        description: 'Writes a document.',
        inputSchema: { type: 'object', properties: { id: { type: 'string' } } },
        annotations: { readOnlyHint: false, destructiveHint: true },
    },
];

const server = createServer(async (request, response) => {
    if (request.method === 'GET' && request.url === '/healthz') {
        response.writeHead(200, { 'content-type': 'text/plain' });
        response.end('ok');
        return;
    }

    if (request.method === 'GET' && request.url === '/.well-known/oauth-protected-resource/oauth/mcp') {
        response.writeHead(200, { 'content-type': 'application/json' });
        response.end(JSON.stringify({
            resource: `${origin}/oauth/mcp`,
            authorization_servers: [`${origin}/oauth`],
            scopes_supported: ['tools:read', 'resources:read'],
        }));
        return;
    }

    if (request.method === 'GET' && request.url === '/.well-known/oauth-authorization-server/oauth') {
        response.writeHead(200, { 'content-type': 'application/json' });
        response.end(JSON.stringify({
            issuer: `${origin}/oauth`,
            authorization_endpoint: `${origin}/oauth/authorize`,
            token_endpoint: `${origin}/oauth/token`,
            registration_endpoint: `${origin}/oauth/register`,
            code_challenge_methods_supported: ['S256'],
            authorization_response_iss_parameter_supported: true,
        }));
        return;
    }

    if (request.method === 'GET' && request.url?.startsWith('/oauth/authorize?')) {
        const url = new URL(request.url, origin);
        const redirectUri = url.searchParams.get('redirect_uri');
        const state = url.searchParams.get('state');
        if (!redirectUri || !state || url.searchParams.get('code_challenge_method') !== 'S256') {
            response.writeHead(400).end();
            return;
        }
        const callback = new URL(redirectUri);
        callback.searchParams.set('code', 'e2e-authorization-code');
        callback.searchParams.set('state', state);
        callback.searchParams.set('iss', `${origin}/oauth`);
        response.writeHead(302, { location: callback.toString() });
        response.end();
        return;
    }

    if (request.method === 'POST' && request.url === '/oauth/register') {
        response.writeHead(201, { 'content-type': 'application/json' });
        response.end(JSON.stringify({
            client_id: 'askmydocs-e2e-client',
            token_endpoint_auth_method: 'none',
        }));
        return;
    }

    if (request.method === 'POST' && request.url === '/oauth/token') {
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        const form = new URLSearchParams(Buffer.concat(chunks).toString('utf8'));
        if (!form.get('code_verifier') || form.get('resource') !== `${origin}/oauth/mcp`) {
            response.writeHead(400, { 'content-type': 'application/json' });
            response.end(JSON.stringify({ error: 'invalid_grant' }));
            return;
        }
        response.writeHead(200, { 'content-type': 'application/json' });
        response.end(JSON.stringify({
            access_token: 'e2e-access-token',
            refresh_token: 'e2e-refresh-token',
            token_type: 'Bearer',
            scope: 'tools:read resources:read',
            expires_in: 3600,
        }));
        return;
    }

    if (request.url === '/oauth/mcp' && request.headers.authorization !== 'Bearer e2e-access-token') {
        response.writeHead(401, {
            'content-type': 'application/json',
            'www-authenticate': `Bearer resource_metadata="${origin}/.well-known/oauth-protected-resource/oauth/mcp", scope="tools:read resources:read"`,
        });
        response.end(JSON.stringify({ error: 'authorization_required' }));
        return;
    }

    if (request.method !== 'POST') {
        response.writeHead(404).end();
        return;
    }

    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    let message;
    try {
        message = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    } catch {
        response.writeHead(400).end();
        return;
    }

    const method = request.headers['mcp-method'] ?? message.method;
    let result;
    switch (method) {
        case 'server/discover':
            result = {
                protocolVersion: '2026-07-28',
                capabilities: { tools: {}, resources: {} },
                serverInfo: { name: 'AskMyDocs E2E MCP', version: '1.0.0' },
            };
            break;
        case 'tools/list':
            result = { tools };
            break;
        case 'resources/list':
            result = {
                resources: [{
                    uri: 'docs://handbook',
                    name: 'Handbook',
                    title: 'Employee handbook',
                    mimeType: 'text/plain',
                }],
            };
            break;
        case 'resources/read':
            result = {
                contents: [{
                    uri: 'docs://handbook',
                    mimeType: 'text/plain',
                    text: 'Deterministic handbook content from the MCP E2E fixture.',
                }],
            };
            break;
        case 'tools/call':
            result = {
                content: [{ type: 'text', text: 'Fresh deterministic MCP evidence.' }],
                structuredContent: { source: 'e2e-fixture' },
            };
            break;
        default:
            response.writeHead(200, { 'content-type': 'application/json' });
            response.end(JSON.stringify({ jsonrpc: '2.0', id: message.id ?? null, error: { code: -32601, message: 'Method not found' } }));
            return;
    }

    response.writeHead(200, {
        'content-type': 'application/json',
        'mcp-protocol-version': '2026-07-28',
    });
    response.end(JSON.stringify({ jsonrpc: '2.0', id: message.id ?? null, result }));
});

server.listen(port, '127.0.0.1');

for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => server.close(() => process.exit(0)));
}
