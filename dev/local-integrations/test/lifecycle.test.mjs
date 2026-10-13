import assert from 'node:assert/strict';
import { execFile, execFileSync } from 'node:child_process';
import { copyFile, mkdtemp, readFile, realpath, rm, symlink } from 'node:fs/promises';
import { createServer } from 'node:http';
import { createServer as createPortProbe } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const execute = promisify(execFile);
const fixtureDirectory = fileURLToPath(new URL('../', import.meta.url));

async function freePort() {
  const server = createPortProbe();
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  await new Promise((resolve) => server.close(resolve));
  return port;
}

async function launcher(t, apiPort, mcpPort) {
  const directory = await realpath(await mkdtemp(path.join(tmpdir(), 'askmydocs-launcher-')));
  const bin = path.join(directory, 'bin');
  await execute('/bin/mkdir', ['-p', bin]);
  // Simulate Herd: the launcher has its system utilities, but no Node in PATH.
  for (const command of ['bash', 'dirname', 'mkdir', 'ps', 'curl', 'rm', 'sleep']) {
    const binary = execFileSync('/usr/bin/which', [command], { encoding: 'utf8' }).trim();
    await symlink(binary, path.join(bin, command));
  }
  for (const file of ['local-integrations.sh', 'spawn-server.mjs', 'api-server.mjs', 'mcp-server.mjs', 'catalog-v2.mjs', 'request-monitor.mjs', 'server-utils.mjs']) {
    await copyFile(path.join(fixtureDirectory, file), path.join(directory, file));
  }
  await symlink(fileURLToPath(new URL('../../../node_modules', import.meta.url)), path.join(directory, 'node_modules'));

  const env = {
    ...process.env,
    PATH: bin,
    LOCAL_INTEGRATIONS_HOST: '127.0.0.1',
    LOCAL_INTEGRATIONS_API_PORT: String(apiPort),
    LOCAL_INTEGRATIONS_MCP_PORT: String(mcpPort),
    LOCAL_INTEGRATIONS_NODE_BINARY: '',
  };
  const run = (action) => execute('/bin/bash', [path.join(directory, 'local-integrations.sh'), action], { env, timeout: 30_000 });
  t.after(async () => {
    try {
      await run('stop');
    } finally {
      await rm(directory, { recursive: true, force: true });
    }
  });
  return { run, directory };
}

test('launcher finds Node outside Herd PATH and starts, restarts, and stops both mocks', async (t) => {
  const apiPort = await freePort();
  const mcpPort = await freePort();
  const { run } = await launcher(t, apiPort, mcpPort);

  const { stdout } = await run('start');
  assert.match(stdout, /api started/);
  assert.match(stdout, /mcp started/);
  assert.match((await run('status')).stdout, /mcp is running and healthy/);
  assert.equal((await (await fetch(`http://127.0.0.1:${apiPort}/health`)).json()).service, 'askmydocs-local-api-fixture');
  assert.equal((await (await fetch(`http://127.0.0.1:${mcpPort}/health`)).json()).service, 'askmydocs-local-mcp-fixture');
  assert.match((await run('restart')).stdout, /mcp started/);
  assert.match((await run('stop')).stdout, /api stopped/);
  await assert.rejects(run('status'), (error) => error.code === 1);
});

test('launcher rejects an occupied port and leaves the unrelated service running', async (t) => {
  const foreign = createServer((_request, response) => {
    response.setHeader('Content-Type', 'application/json');
    response.end(JSON.stringify({ status: 'ok', service: 'another-local-service' }));
  });
  await new Promise((resolve) => foreign.listen(0, '127.0.0.1', resolve));
  t.after(() => new Promise((resolve) => foreign.close(resolve)));
  const apiPort = foreign.address().port;
  const { run, directory } = await launcher(t, apiPort, await freePort());

  await assert.rejects(run('start'), (error) => error.code === 1 && /già occupata/.test(error.stderr));
  await assert.rejects(readFile(path.join(directory, '.runtime', 'api.pid')), { code: 'ENOENT' });
  await assert.rejects(readFile(path.join(directory, '.runtime', 'mcp.pid')), { code: 'ENOENT' });
  await run('stop');
  assert.equal((await (await fetch(`http://127.0.0.1:${apiPort}/health`)).json()).service, 'another-local-service');
});
