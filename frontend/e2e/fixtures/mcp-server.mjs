import { createServer } from 'node:http';

const port = Number(process.env.E2E_MCP_PORT ?? 3536);

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
