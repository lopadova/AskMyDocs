import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'node',
        include: ['tests/js/**/*.spec.mjs'],
        reporters: ['default'],
    },
});
