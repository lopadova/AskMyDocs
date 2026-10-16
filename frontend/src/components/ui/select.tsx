import * as React from 'react';
import * as SelectPrimitive from '@radix-ui/react-select';
import { CheckIcon, ChevronDownIcon, ChevronUpIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import './select.css';

// shadcn/ui's Radix composition, using the application's shared theme tokens.
// Select and Dialog must share Radix focus scopes and dismissable layers;
// keep their releases compatible (covered by the nested-dialog test).
function Select(props: React.ComponentProps<typeof SelectPrimitive.Root>) {
    return <SelectPrimitive.Root {...props} />;
}

function SelectGroup(props: React.ComponentProps<typeof SelectPrimitive.Group>) {
    return <SelectPrimitive.Group data-slot="select-group" {...props} />;
}

function SelectValue(props: React.ComponentProps<typeof SelectPrimitive.Value>) {
    return <SelectPrimitive.Value data-slot="select-value" {...props} />;
}

function SelectTrigger({ className, children, ...props }: React.ComponentProps<typeof SelectPrimitive.Trigger>) {
    return (
        <SelectPrimitive.Trigger data-slot="select-trigger" className={cn('amd-select-trigger', className)} {...props}>
            {children}
            <SelectPrimitive.Icon asChild>
                <ChevronDownIcon className="amd-select-chevron" size={15} aria-hidden="true" />
            </SelectPrimitive.Icon>
        </SelectPrimitive.Trigger>
    );
}

function SelectScrollUpButton({ className, ...props }: React.ComponentProps<typeof SelectPrimitive.ScrollUpButton>) {
    return <SelectPrimitive.ScrollUpButton data-slot="select-scroll-up-button" className={cn('amd-select-scroll', className)} {...props}><ChevronUpIcon size={15} aria-hidden="true" /></SelectPrimitive.ScrollUpButton>;
}

function SelectScrollDownButton({ className, ...props }: React.ComponentProps<typeof SelectPrimitive.ScrollDownButton>) {
    return <SelectPrimitive.ScrollDownButton data-slot="select-scroll-down-button" className={cn('amd-select-scroll', className)} {...props}><ChevronDownIcon size={15} aria-hidden="true" /></SelectPrimitive.ScrollDownButton>;
}

function SelectContent({ className, children, position = 'popper', sideOffset = 5, ...props }: React.ComponentProps<typeof SelectPrimitive.Content>) {
    return (
        <SelectPrimitive.Portal>
            <SelectPrimitive.Content data-slot="select-content" className={cn('amd-select-content', className)} position={position} sideOffset={sideOffset} collisionPadding={12} {...props}>
                <SelectScrollUpButton />
                <SelectPrimitive.Viewport className="amd-select-viewport">{children}</SelectPrimitive.Viewport>
                <SelectScrollDownButton />
            </SelectPrimitive.Content>
        </SelectPrimitive.Portal>
    );
}

function SelectLabel({ className, ...props }: React.ComponentProps<typeof SelectPrimitive.Label>) {
    return <SelectPrimitive.Label data-slot="select-label" className={cn('amd-select-label', className)} {...props} />;
}

function SelectItem({ className, children, ...props }: React.ComponentProps<typeof SelectPrimitive.Item>) {
    return (
        <SelectPrimitive.Item data-slot="select-item" className={cn('amd-select-item', className)} {...props}>
            <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
            <SelectPrimitive.ItemIndicator className="amd-select-indicator"><CheckIcon size={15} aria-hidden="true" /></SelectPrimitive.ItemIndicator>
        </SelectPrimitive.Item>
    );
}

function SelectSeparator({ className, ...props }: React.ComponentProps<typeof SelectPrimitive.Separator>) {
    return <SelectPrimitive.Separator data-slot="select-separator" className={cn('amd-select-separator', className)} {...props} />;
}

export { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectScrollDownButton, SelectScrollUpButton, SelectSeparator, SelectTrigger, SelectValue };
