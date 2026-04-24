import { zodResolver } from '@hookform/resolvers/zod';
import { useEffect, useId } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import type { AdminRole, AdminUser } from '../admin.api';

/*
 * Admin user create/edit form. Create needs { name, email, password };
 * Edit makes password optional (blank = unchanged). Roles are a
 * multi-select of the admin-visible role catalogue.
 *
 * Per-field errors surface with `data-testid="user-form-<field>-error"`
 * so Playwright can assert on the exact violation rather than
 * regex-scraping the DOM.
 */

const baseSchema = z.object({
    name: z.string().min(1, 'Name is required').max(255),
    email: z.string().min(1, 'Email is required').email('Invalid email'),
    is_active: z.boolean().default(true),
    roles: z.array(z.string()).default([]),
});

const createSchema = baseSchema.extend({
    password: z
        .string()
        .min(8, 'Password must be at least 8 characters')
        .max(255),
});

const editSchema = baseSchema.extend({
    password: z
        .string()
        .max(255)
        .optional()
        .refine(
            (v) => v === undefined || v === '' || v.length >= 8,
            'Password must be at least 8 characters',
        ),
});

export type UserFormValues = z.infer<typeof createSchema>;

export interface UserFormProps {
    mode: 'create' | 'edit';
    initial?: AdminUser | null;
    roles: AdminRole[];
    onSubmit: (values: UserFormValues) => void | Promise<void>;
    submitting?: boolean;
    /**
     * Server-side field errors (from 422). Merged into the RHF error
     * surface so "email already taken" lands next to the email input.
     */
    serverErrors?: Record<string, string>;
    onCancel?: () => void;
}

export function UserForm({
    mode,
    initial,
    roles,
    onSubmit,
    submitting,
    serverErrors,
    onCancel,
}: UserFormProps) {
    const schema = mode === 'create' ? createSchema : editSchema;

    const {
        register,
        handleSubmit,
        setValue,
        watch,
        setError,
        formState: { errors },
    } = useForm<UserFormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            name: initial?.name ?? '',
            email: initial?.email ?? '',
            password: '',
            is_active: initial?.is_active ?? true,
            roles: initial?.roles ?? [],
        },
    });

    useEffect(() => {
        if (!serverErrors) return;
        for (const [field, message] of Object.entries(serverErrors)) {
            setError(field as keyof UserFormValues, { type: 'server', message });
        }
    }, [serverErrors, setError]);

    const currentRoles = watch('roles') ?? [];

    function toggleRole(name: string) {
        if (currentRoles.includes(name)) {
            setValue(
                'roles',
                currentRoles.filter((r) => r !== name),
                { shouldDirty: true },
            );
        } else {
            setValue('roles', [...currentRoles, name], { shouldDirty: true });
        }
    }

    return (
        <form
            data-testid="user-form"
            data-mode={mode}
            onSubmit={handleSubmit(onSubmit)}
            style={{ display: 'flex', flexDirection: 'column', gap: 14 }}
        >
            <Field label="Name" error={errors.name?.message} testid="user-form-name-error">
                {({ id }) => (
                    <input
                        id={id}
                        data-testid="user-form-name"
                        {...register('name')}
                        className="focus-ring"
                        style={inputStyle}
                        autoComplete="off"
                    />
                )}
            </Field>

            <Field label="Email" error={errors.email?.message} testid="user-form-email-error">
                {({ id }) => (
                    <input
                        id={id}
                        data-testid="user-form-email"
                        type="email"
                        {...register('email')}
                        className="focus-ring"
                        style={inputStyle}
                        autoComplete="off"
                    />
                )}
            </Field>

            <Field
                label={mode === 'edit' ? 'New password (leave blank to keep current)' : 'Password'}
                error={errors.password?.message}
                testid="user-form-password-error"
            >
                {({ id }) => (
                    <input
                        id={id}
                        data-testid="user-form-password"
                        type="password"
                        {...register('password')}
                        className="focus-ring"
                        style={inputStyle}
                        autoComplete="new-password"
                    />
                )}
            </Field>

            {/*
              Roles is a multi-select chip group — not a single input.
              `<fieldset>` + `<legend>` is the correct a11y semantic
              here: screen readers announce the group name ("Roles")
              before each toggle. Each chip carries `aria-pressed` to
              describe its binary state.
            */}
            <fieldset
                style={{ border: 'none', padding: 0, margin: 0 }}
                aria-describedby={errors.roles?.message ? 'user-form-roles-error' : undefined}
            >
                <legend style={labelStyle}>Roles</legend>
                <div
                    data-testid="user-form-roles"
                    role="group"
                    aria-label="Assigned roles"
                    style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}
                >
                    {roles.length === 0 ? (
                        <span style={{ fontSize: 12, color: 'var(--fg-3)' }}>
                            No roles available.
                        </span>
                    ) : (
                        roles.map((r) => {
                            const active = currentRoles.includes(r.name);
                            return (
                                <button
                                    type="button"
                                    key={r.id}
                                    className="focus-ring"
                                    data-testid={`user-form-role-${r.name}`}
                                    data-active={active ? 'true' : 'false'}
                                    aria-pressed={active}
                                    onClick={() => toggleRole(r.name)}
                                    style={{
                                        padding: '4px 10px',
                                        borderRadius: 999,
                                        fontSize: 12,
                                        cursor: 'pointer',
                                        border: '1px solid ' + (active ? 'transparent' : 'var(--hairline)'),
                                        background: active ? 'var(--grad-accent-soft)' : 'transparent',
                                        color: active ? 'var(--fg-0)' : 'var(--fg-2)',
                                    }}
                                >
                                    {r.name}
                                </button>
                            );
                        })
                    )}
                </div>
                {errors.roles?.message ? (
                    <div
                        id="user-form-roles-error"
                        data-testid="user-form-roles-error"
                        style={errorStyle}
                    >
                        {errors.roles.message}
                    </div>
                ) : null}
            </fieldset>

            <label
                style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 8,
                    fontSize: 13,
                    color: 'var(--fg-1)',
                }}
            >
                <input
                    type="checkbox"
                    data-testid="user-form-is-active"
                    {...register('is_active')}
                />
                Active
            </label>

            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 6 }}>
                {onCancel ? (
                    <button
                        type="button"
                        className="focus-ring"
                        data-testid="user-form-cancel"
                        onClick={onCancel}
                        style={secondaryButtonStyle}
                    >
                        Cancel
                    </button>
                ) : null}
                <button
                    type="submit"
                    className="focus-ring"
                    data-testid="user-form-submit"
                    disabled={submitting}
                    style={{
                        ...primaryButtonStyle,
                        opacity: submitting ? 0.6 : 1,
                    }}
                >
                    {submitting ? 'Saving…' : mode === 'create' ? 'Create user' : 'Save changes'}
                </button>
            </div>
        </form>
    );
}

interface FieldProps {
    label: string;
    error?: string;
    testid: string;
    // Render prop so consumers receive the generated `id` and can
    // apply it to their input. The `<label htmlFor={id}>` wraps the
    // provided label text so screen readers announce the name of
    // every control (Copilot #4 a11y fix).
    children: (props: { id: string }) => React.ReactNode;
}

function Field({ label, error, testid, children }: FieldProps) {
    const id = useId();
    return (
        <div>
            <label htmlFor={id} style={labelStyle}>
                {label}
            </label>
            {children({ id })}
            {error ? (
                <div data-testid={testid} style={errorStyle}>
                    {error}
                </div>
            ) : null}
        </div>
    );
}

const labelStyle = {
    fontSize: 11,
    color: 'var(--fg-3)',
    fontFamily: 'var(--font-mono)',
    textTransform: 'uppercase' as const,
    letterSpacing: '0.05em',
    marginBottom: 4,
};

const inputStyle = {
    width: '100%',
    padding: '7px 10px',
    fontSize: 13,
    color: 'var(--fg-0)',
    background: 'var(--bg-0)',
    border: '1px solid var(--hairline)',
    borderRadius: 7,
    fontFamily: 'var(--font-sans)',
};

const errorStyle = {
    marginTop: 4,
    fontSize: 12,
    color: '#fca5a5',
};

const primaryButtonStyle = {
    padding: '7px 14px',
    fontSize: 13,
    border: '1px solid transparent',
    background: 'var(--grad-accent)',
    color: '#fff',
    borderRadius: 7,
    cursor: 'pointer',
};

const secondaryButtonStyle = {
    padding: '7px 14px',
    fontSize: 13,
    border: '1px solid var(--hairline)',
    background: 'transparent',
    color: 'var(--fg-1)',
    borderRadius: 7,
    cursor: 'pointer',
};
