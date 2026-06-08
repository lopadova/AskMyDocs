/**
 * F1.7 — loader: risoluzione config da data-attributes + CSRF token.
 *
 * Importare il loader esegue init() una volta a module-load: senza key valida
 * (né window.AskMyDocsWidget né data-public-key) logga un errore e ritorna,
 * quindi l'import è innocuo. Testiamo le funzioni esportate resolveConfig /
 * readCsrfToken in isolamento (R16).
 */
import { afterEach, describe, expect, it } from 'vitest';
import { resolveConfig, readCsrfToken } from './loader';

/** Crea uno <script> di embed con i data-attributes dati. */
function embedScript(data: Record<string, string>): HTMLScriptElement {
    const s = document.createElement('script');
    for (const [k, v] of Object.entries(data)) {
        s.setAttribute(`data-${k}`, v);
    }

    return s;
}

afterEach(() => {
    delete window.AskMyDocsWidget;
    document.head.querySelectorAll('meta[name="csrf-token"]').forEach((m) => m.remove());
});

describe('resolveConfig', () => {
    it('reads the F1.7 host-tool config from script data-attributes', () => {
        const script = embedScript({
            'public-key': 'pk_live_xyz',
            'api-base': 'https://kb.example.com',
            skill: 'gescat-assistant@1',
            'host-manifest-url': '/admin/ai/tools-manifest',
            'host-exec-url': '/admin/ai/tools-exec',
        });

        const cfg = resolveConfig(script);

        expect(cfg.key).toBe('pk_live_xyz');
        expect(cfg.apiBase).toBe('https://kb.example.com');
        expect(cfg.skill).toBe('gescat-assistant@1');
        expect(cfg.hostManifestUrl).toBe('/admin/ai/tools-manifest');
        expect(cfg.hostExecUrl).toBe('/admin/ai/tools-exec');
    });

    it('lets data-attributes override the global window.AskMyDocsWidget object', () => {
        window.AskMyDocsWidget = { key: 'pk_global', apiBase: 'https://global' };
        const script = embedScript({ 'public-key': 'pk_data', 'host-exec-url': '/exec' });

        const cfg = resolveConfig(script);

        expect(cfg.key).toBe('pk_data'); // data-* prevale
        expect(cfg.apiBase).toBe('https://global'); // non sovrascritto → resta dal globale
        expect(cfg.hostExecUrl).toBe('/exec');
    });

    it('remains backward-compatible with the window-object-only embed (no script attrs)', () => {
        window.AskMyDocsWidget = { key: 'pk_only_global', apiBase: 'https://g' };
        const cfg = resolveConfig(null);
        expect(cfg.key).toBe('pk_only_global');
        expect(cfg.apiBase).toBe('https://g');
    });

    it('parses data-auto-open=true into autoOpen', () => {
        const cfg = resolveConfig(embedScript({ 'public-key': 'pk', 'auto-open': 'true' }));
        expect(cfg.autoOpen).toBe(true);
    });

    it('reads the CSRF token from <meta name="csrf-token"> into the config', () => {
        const meta = document.createElement('meta');
        meta.setAttribute('name', 'csrf-token');
        meta.setAttribute('content', 'meta_csrf_123');
        document.head.appendChild(meta);

        const cfg = resolveConfig(embedScript({ 'public-key': 'pk' }));
        expect(cfg.csrfToken).toBe('meta_csrf_123');
    });
});

describe('readCsrfToken', () => {
    it('prefers <meta name="csrf-token"> over data-csrf-token', () => {
        const meta = document.createElement('meta');
        meta.setAttribute('name', 'csrf-token');
        meta.setAttribute('content', 'from_meta');
        document.head.appendChild(meta);
        const script = embedScript({ 'csrf-token': 'from_script' });

        expect(readCsrfToken(script)).toBe('from_meta');
    });

    it('falls back to data-csrf-token on the embed script when no meta is present', () => {
        const script = embedScript({ 'csrf-token': 'from_script' });
        expect(readCsrfToken(script)).toBe('from_script');
    });

    it('returns undefined when neither source provides a token', () => {
        expect(readCsrfToken(embedScript({}))).toBeUndefined();
        expect(readCsrfToken(null)).toBeUndefined();
    });
});
