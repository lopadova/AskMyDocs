import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    plugins: [
        laravel({
            input: ['frontend/src/main.tsx'],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(projectRoot, 'frontend/src'),
        },
    },
    server: {
        host: 'localhost',
        port: 5173,
        strictPort: false,
        proxy: {
            '/api': 'http://localhost:8000',
            '/sanctum': 'http://localhost:8000',
            '/login': 'http://localhost:8000',
            '/logout': 'http://localhost:8000',
            '/forgot-password': 'http://localhost:8000',
            '/reset-password': 'http://localhost:8000',
        },
    },
});
