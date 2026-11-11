import { defineConfig } from '@playwright/test';

// Isolated, non-destructive local acceptance on the existing Herd site.
// KNOWLEDGE_E2E_FIXTURE supplies a dedicated user/team and two original messages.
export default defineConfig({
    testDir: './frontend/e2e',
    testMatch: 'knowledge-specifications.local.spec.ts',
    timeout: 60_000,
    workers: 1,
    outputDir: 'test-results/knowledge',
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'https://askmydocsdev.test',
        ignoreHTTPSErrors: true,
        launchOptions: { args: ['--host-resolver-rules=MAP askmydocsdev.test 127.0.0.1'] },
        trace: 'retain-on-failure',
    },
});
