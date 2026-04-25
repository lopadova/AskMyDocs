import { zodResolver } from '@hookform/resolvers/zod';
import { useEffect } from 'react';
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
                <input
                    data-testid="user-form-name"
                    {...register('name')}
                    className="focus-ring"
                    style={inputStyle}
                    autoComplete="off"
                />
            </Field>

            <Field label="Email" error={errors.email?.message} testid="user-form-email-error">
                <input
                    data-testid="user-form-email"
                    type="email"
                    {...register('email')}
                    className="focus-ring"
                    style={inputStyle}
                    autoComplete="off"
                />
            </Field>

            <Field
                label={mode === 'edit' ? 'New password (leave blank to keep current)' : 'Password'}
                error={errors.password?.message}
                testid="user-form-password-error"
            >
                <input
                    data-testid="user-form-password"
                    type="password"
                    {...register('password')}
                    className="focus-ring"
                    style={inputStyle}
                    autoComplete="new-password"
                />
            </Field>

            <div>
                <div style={labelStyle}>Roles</div>
                <div
                    data-testid="user-form-roles"
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
                    <div data-testid="user-form-roles-error" style={errorStyle}>
                        {errors.roles.message}
                    </div>
                ) : null}
            </div>

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
    children: React.ReactNode;
}

function Field({ label, error, testid, children }: FieldProps) {
    return (
        <div>
            <div style={labelStyle}>{label}</div>
            {children}
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
