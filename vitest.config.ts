import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.dirname(fileURLToPath(import.meta.url));

/*
 * React/TypeScript test config. Separate from the legacy
 * `vitest.config.mjs` (which still runs the `tests/js/*.spec.mjs` suite
 * for `resources/js/rich-content.mjs`). Both configs remain wired so
 * npm scripts can target either suite.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': path.resolve(projectRoot, 'frontend/src'),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./frontend/src/test-setup.ts'],
        include: ['frontend/src/**/*.test.{ts,tsx}'],
        css: false,
    },
});
