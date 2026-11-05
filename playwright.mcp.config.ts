import { defineConfig } from '@playwright/test';

// Non-destructive UI contract checks against the existing Herd site.
// Account, consent and import mutations are intercepted by the spec.
export default defineConfig({
    testDir: './frontend/e2e',
    testMatch: 'mcp-project-connection.spec.ts',
    outputDir: 'test-results/mcp-project-connection',
    use: { baseURL: process.env.E2E_BASE_URL ?? 'https://askmydocsdev.test', browserName: 'chromium' },
    workers: 1,
});
