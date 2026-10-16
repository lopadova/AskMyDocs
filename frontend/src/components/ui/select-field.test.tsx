import { useState } from 'react';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { chooseSelectOption } from '@/test-utils/select';
import { SelectField, SelectOption } from './select-field';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from './dialog';

describe('SelectField', () => {
    it('preserves empty filter values, labels and selection state', async () => {
        const onChange = vi.fn();
        function Filter() {
            const [value, setValue] = useState('');
            return <label>Project<SelectField value={value} onValueChange={(next) => { setValue(next); onChange(next); }}>
                <SelectOption value="">All projects</SelectOption>
                <SelectOption value="support">Support</SelectOption>
            </SelectField></label>;
        }
        render(<Filter />);
        const trigger = screen.getByRole('combobox', { name: 'Project' });
        expect(trigger).toHaveTextContent('All projects');
        await chooseSelectOption(trigger, 'support');
        expect(trigger).toHaveTextContent('Support');
        expect(onChange).toHaveBeenLastCalledWith('support');
        await chooseSelectOption(trigger, '');
        expect(trigger).toHaveTextContent('All projects');
        expect(onChange).toHaveBeenLastCalledWith('');
        expect(onChange).toHaveBeenCalledTimes(2);
        await userEvent.click(trigger);
        expect(screen.getByRole('option', { name: 'All projects' })).toHaveAttribute('aria-selected', 'true');
    });

    it('supports keyboard choice, skips disabled options, and returns focus on Escape', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<SelectField aria-label="Language" defaultValue="it" onValueChange={onChange}>
            <SelectOption value="it">Italiano</SelectOption>
            <SelectOption value="fr" disabled>Français</SelectOption>
            <SelectOption value="en">English</SelectOption>
        </SelectField>);
        const trigger = screen.getByRole('combobox', { name: 'Language' });
        trigger.focus();
        await user.keyboard(' {ArrowDown}{Enter}');
        expect(onChange).toHaveBeenLastCalledWith('en');
        expect(trigger).toHaveTextContent('English');
        expect(trigger).toHaveFocus();
        await user.keyboard(' ');
        expect(screen.getByRole('listbox')).toBeInTheDocument();
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
    });

    it('keeps numeric values and the real empty value in native form data and validation', async () => {
        render(<form aria-label="Settings"><label htmlFor="project-select">Project</label>
            <SelectField id="project-select" name="project_id" required defaultValue="">
                <SelectOption value="">Choose a project</SelectOption>
                <SelectOption value={42}>Editorial</SelectOption>
            </SelectField>
        </form>);
        const form = screen.getByRole('form', { name: 'Settings' }) as HTMLFormElement;
        const trigger = screen.getByRole('combobox', { name: 'Project' });
        expect(new FormData(form).get('project_id')).toBe('');
        expect(form.checkValidity()).toBe(false);
        await chooseSelectOption(trigger, '42');
        expect(new FormData(form).get('project_id')).toBe('42');
        expect(form.checkValidity()).toBe(true);
        await chooseSelectOption(trigger, '');
        expect(new FormData(form).get('project_id')).toBe('');
        expect(form.checkValidity()).toBe(false);
    });

    it('updates controlled labels and preserves accessible errors and disabled controls', async () => {
        const onChange = vi.fn();
        const view = (value: string, disabled = false) => <SelectField aria-label="Status" aria-describedby="error" aria-invalid="true" value={value} disabled={disabled} onValueChange={onChange}>
            <SelectOption value="active">Active</SelectOption><SelectOption value="paused">Paused</SelectOption>
        </SelectField>;
        const { rerender } = render(view('active'));
        const trigger = screen.getByRole('combobox', { name: 'Status' });
        expect(trigger).toHaveTextContent('Active');
        expect(trigger).toHaveAttribute('aria-describedby', 'error');
        expect(trigger).toHaveAttribute('aria-invalid', 'true');
        rerender(view('paused', true));
        expect(trigger).toHaveTextContent('Paused');
        expect(trigger).toBeDisabled();
        await userEvent.click(trigger);
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        expect(onChange).not.toHaveBeenCalled();
    });

    it('renders options loaded asynchronously in a portal outside clipped containers', async () => {
        const { rerender } = render(<div style={{ overflow: 'hidden' }}><SelectField aria-label="Project" value="" /></div>);
        rerender(<div data-testid="container" style={{ overflow: 'hidden' }}><SelectField aria-label="Project" value="">
            <><SelectOption value="">All projects</SelectOption><SelectOption value="support">Support</SelectOption></>
        </SelectField></div>);
        await userEvent.click(screen.getByRole('combobox'));
        const list = screen.getByRole('listbox');
        expect(within(list).getByRole('option', { name: 'Support' })).toBeInTheDocument();
        expect(screen.getByTestId('container')).not.toContainElement(list);
    });

    it('does not emit a patch when choosing the already selected empty value', async () => {
        const patch = vi.fn();
        render(<SelectField value="" onValueChange={patch} aria-label="Scope">
            <SelectOption value="">All</SelectOption><SelectOption value="a">Alpha</SelectOption>
        </SelectField>);
        await chooseSelectOption(screen.getByRole('combobox'), '');
        expect(patch).not.toHaveBeenCalled();
    });

    it('focuses the selected empty option even when it is not the first item', async () => {
        render(<SelectField value="" aria-label="Scope">
            <SelectOption value="a">Alpha</SelectOption><SelectOption value="">All</SelectOption><SelectOption value="b">Beta</SelectOption>
        </SelectField>);
        await userEvent.click(screen.getByRole('combobox'));
        expect(screen.getByRole('option', { name: 'All' })).toHaveFocus();
    });

    it('emits one callback per actual change inside a form, including clearing', async () => {
        const patch = vi.fn();
        function Settings() {
            const [value, setValue] = useState('a');
            return <form><SelectField value={value} name="scope" aria-label="Scope" onValueChange={next => { setValue(next); patch(next); }}>
                <SelectOption value="">All</SelectOption><SelectOption value="a">Alpha</SelectOption><SelectOption value="b">Beta</SelectOption>
            </SelectField></form>;
        }
        render(<Settings />);
        await chooseSelectOption(screen.getByRole('combobox'), 'b');
        expect(patch.mock.calls.map(call => call[0])).toEqual(['b']);
        await chooseSelectOption(screen.getByRole('combobox'), '');
        expect(patch.mock.calls.map(call => call[0])).toEqual(['b', '']);
    });

    it('updates controlled values, including null, without invoking a save handler', async () => {
        const patch = vi.fn();
        const view = (value: string | null) => <form><SelectField name="scope" value={value} onValueChange={patch} aria-label="Scope">
            <SelectOption value="">All</SelectOption><SelectOption value="a">Alpha</SelectOption><SelectOption value="b">Beta</SelectOption>
        </SelectField></form>;
        const { rerender } = render(view('a'));
        rerender(view('b'));
        await waitFor(() => expect(screen.getByRole('combobox')).toHaveTextContent('Beta'));
        rerender(view(null));
        await waitFor(() => expect(screen.getByRole('combobox')).toHaveTextContent('All'));
        expect(patch).not.toHaveBeenCalled();
    });

    it('preserves external form association and excludes a disabled field from submission', async () => {
        const view = (disabled = false) => <><form id="settings" aria-label="Settings" />
            <SelectField name="scope" form="settings" required defaultValue="" disabled={disabled} aria-label="Scope">
                <SelectOption value="">Choose</SelectOption><SelectOption value="a">Alpha</SelectOption>
            </SelectField>
        </>;
        const { rerender } = render(view());
        const form = screen.getByRole('form') as HTMLFormElement;
        expect(new FormData(form).get('scope')).toBe('');
        expect(form.checkValidity()).toBe(false);
        await chooseSelectOption(screen.getByRole('combobox'), 'a');
        expect(new FormData(form).get('scope')).toBe('a');
        expect(form.checkValidity()).toBe(true);
        rerender(view(true));
        expect(new FormData(form).has('scope')).toBe(false);
    });

    it('restores the initial uncontrolled value when its form is reset', async () => {
        render(<form aria-label="Settings"><SelectField defaultValue="a" name="scope" aria-label="Scope">
            <SelectOption value="a">Alpha</SelectOption><SelectOption value="b">Beta</SelectOption>
        </SelectField></form>);
        await chooseSelectOption(screen.getByRole('combobox'), 'b');
        const form = screen.getByRole('form') as HTMLFormElement;
        await act(() => form.reset());
        expect(screen.getByRole('combobox')).toHaveTextContent('Alpha');
        expect(new FormData(form).get('scope')).toBe('a');
    });

    it('shares focus management with a parent dialog and closes only its menu on Escape', async () => {
        render(<Dialog open><DialogContent>
            <DialogTitle>Preferences</DialogTitle><DialogDescription>Choose a language.</DialogDescription>
            <SelectField aria-label="Language" defaultValue="it"><SelectOption value="it">Italiano</SelectOption><SelectOption value="en">English</SelectOption></SelectField>
        </DialogContent></Dialog>);
        const trigger = screen.getByRole('combobox', { name: 'Language' });
        await chooseSelectOption(trigger, 'en');
        expect(trigger).toHaveTextContent('English');
        await userEvent.click(trigger);
        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        expect(screen.getByRole('dialog', { name: 'Preferences' })).toBeInTheDocument();
        expect(trigger).toHaveFocus();
    });
});
