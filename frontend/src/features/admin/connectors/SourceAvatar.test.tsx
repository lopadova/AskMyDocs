import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SourceAvatar } from './SourceAvatar';
import { sourceMark } from './source-marks';

const props = { connectorKey: 'freshdesk', displayName: 'Freshdesk', testid: 'avatar' };
const img = () => screen.getByTestId('avatar').querySelector('img')!;

describe('SourceAvatar', () => {
    it('uses a bundled mark for missing and conventional published icons', () => {
        const { rerender } = render(<SourceAvatar {...props} />);
        expect(img()).toHaveAttribute('src', sourceMark('freshdesk')?.src);
        rerender(<SourceAvatar {...props} iconUrl="https://host.test/connectors/freshdesk.svg?v=old" />);
        expect(img()).toHaveAttribute('src', sourceMark('freshdesk')?.src);
        expect(screen.getByTestId('avatar')).toHaveAttribute('aria-hidden', 'true');
        expect(img()).toHaveAttribute('alt', '');
    });

    it('preserves a custom icon, falls back on failure and re-arms a changed URL', () => {
        const { rerender } = render(<SourceAvatar {...props} iconUrl="/custom.svg" />);
        expect(img()).toHaveAttribute('src', '/custom.svg');
        fireEvent.error(img());
        expect(img()).toHaveAttribute('src', sourceMark('freshdesk')?.src);
        fireEvent.error(img());
        expect(screen.getByTestId('avatar')).toHaveTextContent('F');
        rerender(<SourceAvatar {...props} iconUrl="/repaired.svg" />);
        expect(img()).toHaveAttribute('src', '/repaired.svg');
    });

    it('keeps third-party icons and a deterministic fallback for unknown sources', () => {
        render(<SourceAvatar connectorKey="community" displayName="Community" iconUrl="/community.svg" testid="avatar" />);
        fireEvent.error(img());
        expect(screen.getByTestId('avatar')).toHaveTextContent('C');
    });

    it('changes source identity without retaining the previous image failure', () => {
        const { rerender } = render(<SourceAvatar {...props} />);
        fireEvent.error(img());
        rerender(<SourceAvatar {...props} connectorKey="notion" displayName="Notion" />);
        expect(img()).toHaveAttribute('src', sourceMark('notion')?.src);
        expect(screen.getByTestId('avatar')).toHaveAttribute('data-monochrome', 'true');
    });
});
