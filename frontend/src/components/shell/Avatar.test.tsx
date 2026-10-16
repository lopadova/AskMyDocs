import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Avatar, ProjectDot } from './Avatar';

describe('Avatar', () => {
    it('derives uppercase initials from a name with extra spaces', () => {
        const { container } = render(<Avatar user={{ name: '  rotta   logistics  ' }} />);
        expect(container.firstChild).toHaveTextContent('RL');
    });

    it('keeps supplied monograms and normalises their whitespace and case', () => {
        const { container } = render(<Avatar user={{ name: 'Rotta Logistics', avatar: ' rl ' }} />);
        expect(container.firstChild).toHaveTextContent('RL');
    });

    it('falls back to the name for an empty monogram and preserves Unicode initials', () => {
        const { container } = render(<Avatar user={{ name: 'élena  ricci', avatar: '  ' }} />);
        expect(container.firstChild).toHaveTextContent('ÉR');
    });

    it('preserves custom dimensions without carrying seed colors into the personal avatar', () => {
        const { container } = render(<Avatar user={{ name: 'Rotta Logistics', color: '#8b5cf6', color2: '#22d3ee' }} size={32} />);
        const avatar = container.firstElementChild as HTMLElement;
        expect(avatar).toHaveStyle({ width: '32px', height: '32px' });
        expect(avatar.style.background).toBe('');
        expect(avatar.style.color).toBe('');
        expect(avatar).toHaveAttribute('aria-hidden', 'true');
    });

    it('keeps project marker colors independent from personal avatars', () => {
        const { container } = render(<ProjectDot color="#735c34" size={10} />);
        expect(container.firstChild).toHaveStyle({ background: '#735c34', width: '10px', height: '10px' });
    });
});
