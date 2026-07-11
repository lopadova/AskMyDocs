import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { CredentialConnectorForm } from './CredentialConnectorForm';
import type { ConnectorEntry, CredentialFieldSchema } from './connectors.api';

/*
 * v8.29 — the edit / prefill behaviours added on top of the create form. R16: the
 * "save without a test" test actually clicks save and asserts the submit fired.
 */

function field(partial: Partial<CredentialFieldSchema>): CredentialFieldSchema {
    return {
        name: 'x', label: 'X', type: 'text', target: 'connection', required: false, secret: false,
        default: null, options: {}, showIf: null, help: null, group: null, discovery: null, ...partial,
    };
}

const SCHEMA: CredentialFieldSchema[] = [
    field({ name: 'auth_mode', label: 'Auth', type: 'select', target: 'auth_mode', required: true, default: 'basic', options: { basic: 'Password' }, group: 'Authentication' }),
    field({ name: 'host', label: 'IMAP Host', target: 'connection', required: true, showIf: { field: 'auth_mode', equals: 'basic' }, group: 'Server' }),
    field({ name: 'port', label: 'Port', type: 'number', target: 'connection', default: 993, showIf: { field: 'auth_mode', equals: 'basic' }, group: 'Server' }),
    field({ name: 'username', label: 'Username', target: 'connection', required: true, group: 'Credentials' }),
    field({ name: 'password', label: 'Password', type: 'password', target: 'secret', required: true, secret: true, showIf: { field: 'auth_mode', equals: 'basic' }, group: 'Credentials' }),
];

function entry(): ConnectorEntry {
    return {
        key: 'imap', display_name: 'Email (IMAP)', icon_url: '/i.svg', oauth_scopes: [],
        auth_kind: 'credential', credential_form_schema: SCHEMA, installations: [],
    };
}

describe('CredentialConnectorForm — edit mode', () => {
    it('prefills the connection params, hides label/project, and leaves the password empty', () => {
        render(
            <CredentialConnectorForm
                entry={entry()}
                projects={[]}
                mode="edit"
                initialValues={{ auth_mode: 'basic', host: 'imap.example.com', port: 993, username: 'alice@example.com' }}
                onSubmit={vi.fn()}
                onClose={vi.fn()}
                onTest={vi.fn().mockResolvedValue({ ok: true })}
            />,
        );

        expect(screen.getByTestId('connector-imap-form-host')).toHaveValue('imap.example.com');
        expect(screen.getByTestId('connector-imap-form-username')).toHaveValue('alice@example.com');
        expect(screen.getByTestId('connector-imap-form-password')).toHaveValue('');
        // Password optional (blank = keep current) → not required in edit mode.
        expect(screen.getByTestId('connector-imap-form-password')).not.toBeRequired();
        // Account label / project fields belong to the Details tab, not here.
        expect(screen.queryByTestId('connector-imap-form-label')).not.toBeInTheDocument();
        expect(screen.queryByTestId('connector-imap-form-project_key')).not.toBeInTheDocument();
    });

    it('saves without requiring a passing test (blank password keeps the current one)', async () => {
        const onSubmit = vi.fn();
        render(
            <CredentialConnectorForm
                entry={entry()}
                projects={[]}
                mode="edit"
                initialValues={{ auth_mode: 'basic', host: 'imap.example.com', port: 993, username: 'alice@example.com' }}
                onSubmit={onSubmit}
                onClose={vi.fn()}
                onTest={vi.fn().mockResolvedValue({ ok: true })}
            />,
        );

        const save = screen.getByTestId('connector-imap-form-submit');
        expect(save).toBeEnabled();
        await userEvent.click(save);

        expect(onSubmit).toHaveBeenCalledTimes(1);
        const payload = onSubmit.mock.calls[0][0];
        expect(payload).toMatchObject({ host: 'imap.example.com', username: 'alice@example.com' });
        expect(payload).not.toHaveProperty('password');
        expect(payload).not.toHaveProperty('label');
    });

    it('includes a newly-typed password on save', async () => {
        const onSubmit = vi.fn();
        render(
            <CredentialConnectorForm
                entry={entry()}
                projects={[]}
                mode="edit"
                initialValues={{ auth_mode: 'basic', host: 'imap.example.com', username: 'alice@example.com' }}
                onSubmit={onSubmit}
                onClose={vi.fn()}
            />,
        );

        await userEvent.type(screen.getByTestId('connector-imap-form-password'), 'new-pw');
        await userEvent.click(screen.getByTestId('connector-imap-form-submit'));

        expect(onSubmit.mock.calls[0][0]).toMatchObject({ password: 'new-pw' });
    });
});

describe('CredentialConnectorForm — import prefill (create mode)', () => {
    it('seeds the account label + connection params but never a secret, and still gates Connect on a test', async () => {
        const onTest = vi.fn().mockResolvedValue({ ok: true });
        render(
            <CredentialConnectorForm
                entry={entry()}
                projects={[]}
                initialLabel="Imported"
                initialValues={{ auth_mode: 'basic', host: 'imap.example.com', username: 'alice@example.com' }}
                onSubmit={vi.fn()}
                onClose={vi.fn()}
                onTest={onTest}
            />,
        );

        // Create mode shows the label field, prefilled from the import.
        expect(screen.getByTestId('connector-imap-form-label')).toHaveValue('Imported');
        expect(screen.getByTestId('connector-imap-form-host')).toHaveValue('imap.example.com');
        expect(screen.getByTestId('connector-imap-form-password')).toHaveValue('');

        // Create-mode gate is intact: Connect is disabled until a test passes.
        expect(screen.getByTestId('connector-imap-form-submit')).toBeDisabled();

        // Enter the secret, test, then Connect becomes enabled.
        await userEvent.type(screen.getByTestId('connector-imap-form-password'), 's3cret');
        await userEvent.click(screen.getByTestId('connector-imap-form-test'));
        await waitFor(() => expect(screen.getByTestId('connector-imap-form-submit')).toBeEnabled());
    });
});
