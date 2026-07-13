import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { ConnectorEntry } from './connectors.api';
import { SourceTile } from './SourceTile';

/*
 * "Available sources" tile: add (+), count badge, and the credential-only
 * Import affordance (same testids the old card header exposed, so the
 * import E2E flow keeps working: connector-{key}-import-file / -import-account).
 */

function credentialEntry(): ConnectorEntry {
    return {
        key: 'imap',
        display_name: 'Email (IMAP)',
        icon_url: '',
        oauth_scopes: [],
        auth_kind: 'credential',
        credential_form_schema: [],
        installations: [],
    };
}

function oauthEntry(): ConnectorEntry {
    return {
        key: 'google-drive',
        display_name: 'Google Drive',
        icon_url: '/g.svg',
        oauth_scopes: ['x'],
        auth_kind: 'oauth',
        credential_form_schema: null,
        installations: [],
    };
}

function renderTile(
    entry: ConnectorEntry,
    overrides: Partial<ComponentProps<typeof SourceTile>> = {},
) {
    const props: ComponentProps<typeof SourceTile> = {
        entry,
        connectionCount: 0,
        onAdd: vi.fn(),
        onImport: vi.fn(),
        ...overrides,
    };
    render(<SourceTile {...props} />);
    return props;
}

describe('SourceTile', () => {
    it('renders name + key and fires onAdd with the connector key', async () => {
        const { onAdd } = renderTile(oauthEntry());
        expect(screen.getByText('Google Drive')).toBeVisible();
        expect(screen.getByText('google-drive')).toBeVisible();
        await userEvent.click(screen.getByTestId('connector-google-drive-add-account'));
        expect(onAdd).toHaveBeenCalledWith('google-drive');
    });

    it('shows the connection-count badge only when at least one account exists', () => {
        renderTile(oauthEntry(), { connectionCount: 3 });
        const tile = screen.getByTestId('connector-source-google-drive');
        expect(tile).toHaveAttribute('data-connection-count', '3');
        expect(screen.getByTestId('connector-source-google-drive-count')).toHaveTextContent('3');
    });

    it('hides the count badge at zero connections', () => {
        renderTile(oauthEntry(), { connectionCount: 0 });
        expect(screen.queryByTestId('connector-source-google-drive-count')).toBeNull();
    });

    it('fires onImport with the key + chosen file on a credential source', () => {
        const { onImport } = renderTile(credentialEntry());
        const file = new File(['{}'], 'cfg.json', { type: 'application/json' });
        fireEvent.change(screen.getByTestId('connector-imap-import-file'), {
            target: { files: [file] },
        });
        expect(onImport).toHaveBeenCalledWith('imap', file);
    });

    it('opens the hidden file picker from the Import button', async () => {
        renderTile(credentialEntry());
        const input = screen.getByTestId('connector-imap-import-file') as HTMLInputElement;
        const clickSpy = vi.spyOn(input, 'click');
        await userEvent.click(screen.getByTestId('connector-imap-import-account'));
        expect(clickSpy).toHaveBeenCalled();
    });

    it('does NOT render Import for an OAuth (non-credential) source', () => {
        renderTile(oauthEntry());
        expect(screen.queryByTestId('connector-google-drive-import-account')).toBeNull();
        expect(screen.queryByTestId('connector-google-drive-import-file')).toBeNull();
    });

    it('disables Add while an add/connect for this source is in flight', () => {
        renderTile(oauthEntry(), { addPending: true });
        expect(screen.getByTestId('connector-google-drive-add-account')).toBeDisabled();
    });
});
