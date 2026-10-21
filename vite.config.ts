import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig(({ mode }) => {
    const appUrl = new URL(loadEnv(mode, projectRoot, 'APP_URL').APP_URL ?? 'http://askmydocsdev.test');
    return {
        plugins: [
            laravel({
                input: ['frontend/src/main.tsx', 'frontend/src/landing-select.tsx'],
                refresh: true,
                detectTls: appUrl.hostname.endsWith('.test') ? appUrl.hostname : false,
            }),
            react(),
            tailwindcss(),
        ],
        resolve: {
            alias: {
                '@': path.resolve(projectRoot, 'frontend/src'),
            },
        },
        server: {
            host: appUrl.hostname,
            port: 5173,
            strictPort: false,
            proxy: {
                '/api': appUrl.origin,
                '/sanctum': appUrl.origin,
                '/login': appUrl.origin,
                '/logout': appUrl.origin,
                '/forgot-password': appUrl.origin,
                '/reset-password': appUrl.origin,
            },
        },
    };
});
