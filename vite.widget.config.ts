import { defineConfig } from 'vite';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/*
 * Build dedicata del widget KITT embeddabile (M3).
 *
 * Bundle separato dalla SPA: lib mode → un singolo IIFE self-init
 * (`public/widget/askmydocs-widget.js`) che il sito ospite include con un
 * solo <script>. TS vanilla, nessun React → bundle leggero. Il CSS è inline
 * (stringa iniettata nello shadow root), quindi non viene emesso alcun file
 * .css separato. Output sotto `public/widget/` (gitignored — niente commit
 * di artefatti build).
 */
const projectRoot = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    // Il widget non copia una public dir: l'outDir vive DENTRO public/, quindi
    // disabilitiamo publicDir per evitare il warning di overlap e copie spurie.
    publicDir: false,
    build: {
        outDir: path.resolve(projectRoot, 'public/widget'),
        emptyOutDir: true,
        sourcemap: false,
        lib: {
            entry: path.resolve(projectRoot, 'frontend/src/widget/loader.ts'),
            name: 'AskMyDocsWidget',
            formats: ['iife'],
            fileName: () => 'askmydocs-widget.js',
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(projectRoot, 'frontend/src'),
        },
    },
});
