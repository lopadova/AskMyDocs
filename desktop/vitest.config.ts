import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  resolve: { dedupe: ['react', 'react-dom'] },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./desktop/src/test-setup.ts'],
    include: ['desktop/src/**/*.test.{ts,tsx}'],
  },
});
