import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = fileURLToPath(new URL('../', import.meta.url));
const result = spawnSync(path.join(root, 'node_modules/.bin/tauri'), ['ios', ...process.argv.slice(2)], {
  cwd: root,
  stdio: 'inherit',
  env: { ...process.env, PATH: `${path.join(root, 'scripts/apple-toolchain')}${path.delimiter}${process.env.PATH ?? ''}` },
});
if (result.error) throw result.error;
process.exit(result.status ?? 1);
