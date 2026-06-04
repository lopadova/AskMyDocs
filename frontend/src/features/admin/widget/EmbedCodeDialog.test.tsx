import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { EmbedCodeDialog } from './EmbedCodeDialog';

function renderDialog(props?: Partial<React.ComponentProps<typeof EmbedCodeDialog>>) {
    return render(
        <EmbedCodeDialog
            open
            onOpenChange={() => {}}
            publicKey="pk_live_abc123"
            projectKey="modelsgenerator"
            label="Production"
            apiBase="https://kb.example.com"
            {...props}
        />,
    );
}

describe('EmbedCodeDialog', () => {
    it('builds the quick-start snippet with key, apiBase and script src', () => {
        renderDialog();

        const snippet = screen.getByTestId('admin-widget-embed-snippet');
        const code = snippet.textContent ?? '';
        expect(code).toContain('window.AskMyDocsWidget');
        expect(code).toContain("key: 'pk_live_abc123'");
        expect(code).toContain("apiBase: 'https://kb.example.com'");
        expect(code).toContain(
            '<script src="https://kb.example.com/widget/askmydocs-widget.js" defer></script>',
        );
    });

    it('does not double the slash when apiBase has a trailing slash', () => {
        renderDialog({ apiBase: 'https://kb.example.com/' });

        const code = screen.getByTestId('admin-widget-embed-snippet').textContent ?? '';
        expect(code).toContain('https://kb.example.com/widget/askmydocs-widget.js');
        expect(code).not.toContain('com//widget');
    });

    it('injects optional config only after the operator sets it (R16)', async () => {
        const user = userEvent.setup();
        renderDialog();

        // Default snippet must NOT contain a title line.
        expect(screen.getByTestId('admin-widget-embed-snippet').textContent).not.toContain(
            'title:',
        );

        await user.click(screen.getByTestId('admin-widget-embed-tab-options'));

        const titleInput = await screen.findByTestId('admin-widget-embed-opt-title');
        await user.type(titleInput, 'Help Bot');

        await waitFor(() => {
            const code =
                screen.getByTestId('admin-widget-embed-snippet-options').textContent ?? '';
            expect(code).toContain("title: 'Help Bot'");
        });
    });

    it('escapes a single quote in an option value', async () => {
        const user = userEvent.setup();
        renderDialog();
        await user.click(screen.getByTestId('admin-widget-embed-tab-options'));

        const launcher = await screen.findByTestId('admin-widget-embed-opt-launcher');
        await user.type(launcher, "Ask O'Brien");

        await waitFor(() => {
            const code =
                screen.getByTestId('admin-widget-embed-snippet-options').textContent ?? '';
            expect(code).toContain("launcherLabel: 'Ask O\\'Brien'");
        });
    });

    it('embeds the real secret in the proxy server snippet when provided', async () => {
        const user = userEvent.setup();
        renderDialog({ secret: 'sk_super_secret_value' });

        await user.click(screen.getByTestId('admin-widget-embed-tab-proxy'));

        const server = await screen.findByTestId('admin-widget-embed-proxy-server');
        expect(server.textContent ?? '').toContain('Bearer sk_super_secret_value');
    });

    it('falls back to a sk_ placeholder in proxy mode when no secret is known', async () => {
        const user = userEvent.setup();
        renderDialog({ secret: null });

        await user.click(screen.getByTestId('admin-widget-embed-tab-proxy'));

        const server = await screen.findByTestId('admin-widget-embed-proxy-server');
        expect(server.textContent ?? '').toContain('Bearer sk_…');
    });
});
