/**
 * Ambient declarations for non-code imports.
 *
 * TypeScript 7 stopped accepting side-effect imports of files it has no
 * declaration for, so `import './x.css'` — which Vite handles at build time
 * and which contributes no types — became a hard error rather than being
 * ignored. Declaring the modules restores the previous behaviour without
 * loosening anything: the import still has no exported shape, it is simply
 * known to exist.
 */
declare module '*.css';
declare module '*.scss';
