import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.dirname(fileURLToPath(import.meta.url));
const ui4WorkbenchSource = '/Users/marco/packages/Ui4VocalAgent/packages/workbench-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['frontend/src/main.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        dedupe: ['react', 'react-dom'],
        alias: {
            '@': path.resolve(projectRoot, 'frontend/src'),
        },
    },
    optimizeDeps: {
        exclude: ['@ui4/workbench-react'],
    },
    server: {
        host: 'localhost',
        port: 5173,
        strictPort: false,
        fs: {
            allow: [projectRoot, ui4WorkbenchSource],
        },
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
