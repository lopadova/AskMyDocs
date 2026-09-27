import { openSync } from 'node:fs';
import { spawn } from 'node:child_process';

const [serverFile, logFile] = process.argv.slice(2);

if (!serverFile || !logFile) {
  console.error('Usage: spawn-server.mjs <server-file> <log-file>');
  process.exit(2);
}

const log = openSync(logFile, 'a');
const child = spawn(process.execPath, [serverFile], {
  detached: true,
  stdio: ['ignore', log, log],
});

child.unref();
console.log(child.pid);
