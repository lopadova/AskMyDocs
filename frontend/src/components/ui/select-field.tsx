import * as React from 'react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './select';

type SelectOptionProps = Omit<React.ComponentProps<typeof SelectItem>, 'value'> & { value: string | number };

function SelectOption({ value, ...props }: SelectOptionProps) {
    const raw = String(value);
    return <SelectItem {...props} value={raw} data-value={raw} />;
}

function collectOptions(children: React.ReactNode): React.ReactElement<SelectOptionProps>[] {
    return React.Children.toArray(children).flatMap((child) => {
        if (!React.isValidElement<{ children?: React.ReactNode }>(child)) return [];
        if (child.type === SelectOption) return [child as React.ReactElement<SelectOptionProps>];
        return child.props.children ? collectOptions(child.props.children) : [];
    });
}

type SelectFieldProps = Omit<React.ComponentProps<typeof SelectTrigger>, 'value' | 'defaultValue' | 'onChange'> & {
    value?: string | number | null;
    defaultValue?: string | number;
    onValueChange?: (value: string) => void;
    name?: string;
    required?: boolean;
    form?: string;
    placeholder?: React.ReactNode;
};

/** Compact field composition of the shared shadcn Select, including clear/all options. */
function SelectField({ value, defaultValue, onValueChange, name, required, form, disabled, children, placeholder, ...triggerProps }: SelectFieldProps) {
    const [selection, setSelection] = React.useState(defaultValue === undefined ? undefined : String(defaultValue));
    const options = collectOptions(children);
    const current = value === undefined ? selection ?? String(options[0]?.props.value ?? '') : String(value ?? '');
    const emptyOption = options.find((option) => String(option.props.value) === '');

    return (
        <Select value={current} name={name} required={required} form={form} disabled={disabled} onValueChange={(next) => {
            setSelection(next);
            onValueChange?.(next);
        }}>
            <SelectTrigger {...triggerProps} data-value={current}>
                <SelectValue placeholder={emptyOption?.props.children ?? placeholder} />
            </SelectTrigger>
            <SelectContent>{children}</SelectContent>
        </Select>
    );
}

export { SelectField, SelectOption };
export type { SelectFieldProps, SelectOptionProps };
