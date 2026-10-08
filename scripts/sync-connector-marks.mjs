import { readFileSync, writeFileSync, existsSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve, join } from 'node:path';

// Canonical app marks and publisher sources must stay byte-for-byte aligned.
// Default: check only. --write updates existing mirrors; it never edits Composer.
const root = fileURLToPath(new URL('../', import.meta.url));
const args = process.argv.slice(2);
const write = args.includes('--write');
const packagesAt = args.indexOf('--packages-dir');
if (packagesAt !== -1 && !args[packagesAt + 1]) throw new Error('--packages-dir requires a directory');
const packages = packagesAt === -1 ? null : resolve(args[packagesAt + 1]);
const canonical = join(root, 'frontend/src/assets/connectors');
const failures = [];
let checked = 0;

for (const name of readdirSync(canonical).filter((name) => name.endsWith('.svg'))) {
    const key = name.slice(0, -4);
    const bytes = readFileSync(join(canonical, name));
    const mirrors = [join(root, 'public/connectors', name)];
    if (packages) mirrors.push(join(packages, `askmydocs-connector-${key}`, 'public/icons', name));
    for (const mirror of mirrors) {
        if (!existsSync(mirror)) {
            failures.push(`Missing mirror: ${mirror}`);
        } else if (!readFileSync(mirror).equals(bytes)) {
            if (write) writeFileSync(mirror, bytes);
            else failures.push(`Outdated mirror: ${mirror}`);
        }
        checked++;
    }
}

if (failures.length) {
    console.error(failures.join('\n'));
    process.exitCode = 1;
} else {
    console.log(`${checked} connector asset mirrors ${write ? 'synchronized' : 'verified'}.`);
}
