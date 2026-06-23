import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CredentialConnectorForm } from './CredentialConnectorForm';
import type { ConnectorEntry, CredentialFieldSchema } from './connectors.api';

/*
 * v8.17 — the schema-driven credential form. These tests prove the form is
 * GENERIC (renders/branches purely from the schema) and matches what its
 * names promise (R16): showIf actually toggles fields, submit emits only the
 * visible values, and 422 errors surface.
 */

const SCHEMA: CredentialFieldSchema[] = [
    {
        name: 'auth_mode',
        label: 'Authentication Mode',
        type: 'select',
        target: 'auth_mode',
        required: true,
        secret: false,
        default: 'basic',
        options: { basic: 'Password', xoauth2: 'OAuth2' },
        showIf: null,
        help: null,
        group: 'Authentication',
    },
    {
        name: 'xoauth2_provider',
        label: 'OAuth2 Provider',
        type: 'select',
        target: 'provider',
        required: false,
        secret: false,
        default: 'google',
        options: { google: 'Gmail', microsoft: 'Microsoft 365' },
        showIf: { field: 'auth_mode', equals: 'xoauth2' },
        help: null,
        group: 'Authentication',
    },
    {
        name: 'host',
        label: 'IMAP Host',
        type: 'text',
        target: 'connection',
        required: true,
        secret: false,
        default: null,
        options: {},
        showIf: { field: 'auth_mode', equals: 'basic' },
        help: 'e.g. imap.example.com',
        group: 'Server',
    },
    {
        name: 'port',
        label: 'Port',
        type: 'number',
        target: 'connection',
        required: false,
        secret: false,
        default: 993,
        options: {},
        showIf: { field: 'auth_mode', equals: 'basic' },
        help: null,
        group: 'Server',
    },
    {
        name: 'username',
        label: 'Username / Email',
        type: 'text',
        target: 'connection',
        required: true,
        secret: false,
        default: null,
        options: {},
        showIf: null,
        help: null,
        group: 'Credentials',
    },
    {
        name: 'password',
        label: 'Password',
        type: 'password',
        target: 'secret',
        required: true,
        secret: true,
        default: null,
        options: {},
        showIf: { field: 'auth_mode', equals: 'basic' },
        help: null,
        group: 'Credentials',
    },
];

function makeEntry(): ConnectorEntry {
    return {
        key: 'imap',
        display_name: 'Email (IMAP)',
        icon_url: '/connectors/imap.svg',
        oauth_scopes: [],
        auth_kind: 'credential',
        credential_form_schema: SCHEMA,
        installations: [],
    };
}

const PROJECTS = [
    { id: 1, project_key: 'acme-hr', name: 'Acme HR', description: null, document_count: 0, member_count: 0 },
];

// v8.20 — every render needs the projects prop (binding dropdown, R18).
const NOOP = { onSubmit: vi.fn(), onClose: vi.fn(), projects: PROJECTS };

describe('CredentialConnectorForm', () => {
    it('renders the basic-auth fields from the schema (host/username/password) and hides xoauth2-only fields', () => {
        render(<CredentialConnectorForm entry={makeEntry()} {...NOOP} />);

        expect(screen.getByTestId('connector-imap-form-host')).toBeInTheDocument();
        expect(screen.getByTestId('connector-imap-form-username')).toBeInTheDocument();
        // password is type=password (R15) and never pre-filled.
        const pw = screen.getByTestId('connector-imap-form-password') as HTMLInputElement;
        expect(pw.type).toBe('password');
        expect(pw.value).toBe('');
        // auth_mode default is basic → the xoauth2 provider field is hidden.
        expect(screen.queryByTestId('connector-imap-form-xoauth2_provider')).toBeNull();
    });

    it('honours showIf: switching to xoauth2 hides host/password and reveals the provider', () => {
        render(<CredentialConnectorForm entry={makeEntry()} {...NOOP} />);

        fireEvent.change(screen.getByTestId('connector-imap-form-auth_mode'), {
            target: { value: 'xoauth2' },
        });

        expect(screen.queryByTestId('connector-imap-form-host')).toBeNull();
        expect(screen.queryByTestId('connector-imap-form-password')).toBeNull();
        expect(screen.getByTestId('connector-imap-form-xoauth2_provider')).toBeInTheDocument();
        // The always-visible username survives the switch.
        expect(screen.getByTestId('connector-imap-form-username')).toBeInTheDocument();
    });

    it('submits only the visible field values plus the injected label', () => {
        const onSubmit = vi.fn();
        render(<CredentialConnectorForm entry={makeEntry()} onSubmit={onSubmit} onClose={vi.fn()} projects={PROJECTS} />);

        fireEvent.change(screen.getByTestId('connector-imap-form-label'), {
            target: { value: 'Support' },
        });
        fireEvent.change(screen.getByTestId('connector-imap-form-host'), {
            target: { value: 'imap.example.com' },
        });
        fireEvent.change(screen.getByTestId('connector-imap-form-username'), {
            target: { value: 'alice@example.com' },
        });
        fireEvent.change(screen.getByTestId('connector-imap-form-password'), {
            target: { value: 'pw' },
        });
        fireEvent.click(screen.getByTestId('connector-imap-form-submit'));

        expect(onSubmit).toHaveBeenCalledTimes(1);
        const payload = onSubmit.mock.calls[0][0];
        expect(payload).toMatchObject({
            label: 'Support',
            auth_mode: 'basic',
            host: 'imap.example.com',
            username: 'alice@example.com',
            password: 'pw',
        });
        // xoauth2_provider is hidden in basic mode → must NOT be submitted.
        expect(payload).not.toHaveProperty('xoauth2_provider');
        // No project selected → project_key omitted (BE applies tenant default).
        expect(payload).not.toHaveProperty('project_key');
    });

    it('injects the selected project_key into the payload', () => {
        const onSubmit = vi.fn();
        render(<CredentialConnectorForm entry={makeEntry()} onSubmit={onSubmit} onClose={vi.fn()} projects={PROJECTS} />);

        fireEvent.change(screen.getByTestId('connector-imap-form-label'), { target: { value: 'Support' } });
        fireEvent.change(screen.getByTestId('connector-imap-form-project_key'), {
            target: { value: 'acme-hr' },
        });
        fireEvent.change(screen.getByTestId('connector-imap-form-host'), { target: { value: 'imap.example.com' } });
        fireEvent.change(screen.getByTestId('connector-imap-form-username'), { target: { value: 'a@b.c' } });
        fireEvent.change(screen.getByTestId('connector-imap-form-password'), { target: { value: 'pw' } });
        fireEvent.click(screen.getByTestId('connector-imap-form-submit'));

        expect(onSubmit.mock.calls[0][0].project_key).toBe('acme-hr');
    });

    it('pre-fills the number default and omits a cleared optional number from the payload (no NaN/null)', () => {
        const onSubmit = vi.fn();
        render(<CredentialConnectorForm entry={makeEntry()} onSubmit={onSubmit} onClose={vi.fn()} projects={PROJECTS} />);

        const port = screen.getByTestId('connector-imap-form-port') as HTMLInputElement;
        expect(port.value).toBe('993');

        // Fill the required fields so the submit isn't blocked by native validation.
        fireEvent.change(screen.getByTestId('connector-imap-form-label'), {
            target: { value: 'Support' },
        });
        fireEvent.change(screen.getByTestId('connector-imap-form-host'), {
            target: { value: 'imap.example.com' },
        });
        fireEvent.change(screen.getByTestId('connector-imap-form-username'), {
            target: { value: 'alice@example.com' },
        });
        fireEvent.change(screen.getByTestId('connector-imap-form-password'), {
            target: { value: 'pw' },
        });
        // Clear the pre-filled port → must NOT be submitted as NaN/null.
        fireEvent.change(port, { target: { value: '' } });
        expect(port.value).toBe('');
        fireEvent.click(screen.getByTestId('connector-imap-form-submit'));

        const payload = onSubmit.mock.calls[0][0];
        expect(payload).not.toHaveProperty('port');
        expect(Object.values(payload)).not.toContain(null);
        expect(Object.values(payload).some((v) => typeof v === 'number' && Number.isNaN(v))).toBe(
            false,
        );

        // A kept port submits as a real number.
        onSubmit.mockClear();
        fireEvent.change(port, { target: { value: '143' } });
        fireEvent.click(screen.getByTestId('connector-imap-form-submit'));
        expect(onSubmit.mock.calls[0][0].port).toBe(143);
    });

    it('surfaces a top-level error and a per-field 422 error', () => {
        render(
            <CredentialConnectorForm
                entry={makeEntry()}
                {...NOOP}
                submitError="IMAP login failed with provided credentials."
                fieldErrors={{ host: 'The host field is required.' }}
            />,
        );

        expect(screen.getByTestId('connector-imap-form-error')).toHaveTextContent('IMAP login failed');
        expect(screen.getByTestId('connector-imap-form-host-error')).toHaveTextContent(
            'The host field is required.',
        );
    });

    it('never pre-fills a secret even if the schema ships a default, and exposes data-state', () => {
        const schemaWithPwDefault: CredentialFieldSchema[] = SCHEMA.map((f) =>
            f.name === 'password' ? { ...f, default: 'should-not-render' } : f,
        );
        const entry = { ...makeEntry(), credential_form_schema: schemaWithPwDefault };

        const { rerender } = render(
            <CredentialConnectorForm entry={entry} {...NOOP} isSubmitting={false} />,
        );

        const pw = screen.getByTestId('connector-imap-form-password') as HTMLInputElement;
        expect(pw.value).toBe('');
        expect(screen.getByTestId('connector-imap-form')).toHaveAttribute('data-state', 'idle');

        // The submitting state is observable for E2E waits + screen readers.
        rerender(<CredentialConnectorForm entry={entry} {...NOOP} isSubmitting />);
        expect(screen.getByTestId('connector-imap-form')).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('connector-imap-form')).toHaveAttribute('aria-busy', 'true');
    });

    it('closes on Cancel', () => {
        const onClose = vi.fn();
        render(<CredentialConnectorForm entry={makeEntry()} onSubmit={vi.fn()} onClose={onClose} projects={PROJECTS} />);

        fireEvent.click(screen.getByTestId('connector-imap-form-cancel'));
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
