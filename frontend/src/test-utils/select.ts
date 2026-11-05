import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/** Exercise the actual portalled shadcn menu instead of changing a hidden form select. */
export async function chooseSelectOption(trigger: HTMLElement, value: string): Promise<void> {
    const user = userEvent.setup();
    await user.click(trigger);
    const menu = await screen.findByRole('listbox');
    const option = within(menu).getAllByRole('option').find((item) => item.dataset.value === value);
    if (!option) throw new Error(`Select option "${value}" is missing`);
    await user.click(option);
}
