import type { Config } from 'tailwindcss';

/*
 * Tailwind is used *sparingly* here. The design system lives in
 * `frontend/src/styles/tokens.css` — prefer inline `style={{...}}` with
 * `var(--token)` references over utility classes. Tailwind is wired so
 * existing utilities (spacing/flex/grid) are available when the design
 * already expresses them that way.
 */
export default {
    content: [
        './frontend/src/**/*.{ts,tsx}',
        './resources/views/app.blade.php',
    ],
    darkMode: ['class', '[data-theme="dark"]'],
    theme: {
        extend: {
            fontFamily: {
                sans: ['var(--font-sans)', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                mono: ['var(--font-mono)', 'ui-monospace', 'Menlo', 'monospace'],
            },
            colors: {
                'accent-a': '#8b5cf6',
                'accent-b': '#22d3ee',
            },
        },
    },
    plugins: [],
} satisfies Config;
