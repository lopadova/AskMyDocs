import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

const alertVariants = cva(
    'ui-alert',
    {
        variants: {
            variant: {
                default: '',
                info: '',
                success: '',
                warning: '',
                destructive: '',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

function Alert({
    className,
    variant,
    ...props
}: React.ComponentProps<'div'> & VariantProps<typeof alertVariants>) {
    return (
        <div
            data-slot="alert"
            data-variant={variant ?? 'default'}
            role="alert"
            className={cn(alertVariants({ variant }), className)}
            {...props}
        />
    );
}

function AlertTitle({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="alert-title"
            className={cn('ui-alert-title', className)}
            {...props}
        />
    );
}

function AlertDescription({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="alert-description"
            className={cn('ui-alert-description', className)}
            {...props}
        />
    );
}

function AlertIcon({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="alert-icon"
            className={cn('ui-alert-icon', className)}
            aria-hidden="true"
            {...props}
        />
    );
}

export { Alert, AlertTitle, AlertDescription, AlertIcon };
