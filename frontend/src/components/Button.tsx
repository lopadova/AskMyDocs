import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';

export type ButtonVariant = 'primary' | 'secondary' | 'quiet' | 'danger';
export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant;
    size?: ButtonSize;
    leadingIcon?: ReactNode;
    trailingIcon?: ReactNode;
    busy?: boolean;
    iconOnly?: boolean;
    caps?: boolean;
}

/**
 * Canonical AskMyDocs action button.
 *
 * Visual states live in tokens.css so native buttons and legacy `.btn`
 * call-sites can share the same interaction contract during migration.
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    {
        variant = 'secondary',
        size = 'md',
        leadingIcon,
        trailingIcon,
        busy = false,
        iconOnly = false,
        caps = false,
        disabled,
        'aria-busy': ariaBusy,
        className,
        children,
        type = 'button',
        ...props
    },
    ref,
) {
    const classes = [
        'ui-button',
        iconOnly ? 'is-icon-only' : '',
        caps ? 'is-caps' : '',
        className ?? '',
    ].filter(Boolean).join(' ');

    return (
        <button
            {...props}
            ref={ref}
            type={type}
            className={classes}
            data-variant={variant}
            data-size={size}
            data-busy={busy ? 'true' : 'false'}
            aria-busy={busy ? true : ariaBusy}
            disabled={disabled || busy}
        >
            {busy ? (
                <span className="ui-button-spinner" aria-hidden="true" />
            ) : leadingIcon ? (
                <span className="ui-button-icon" aria-hidden="true">{leadingIcon}</span>
            ) : null}
            {!iconOnly && <span className="ui-button-label">{children}</span>}
            {iconOnly && !busy && children}
            {!busy && trailingIcon && (
                <span className="ui-button-icon" aria-hidden="true">{trailingIcon}</span>
            )}
        </button>
    );
});
