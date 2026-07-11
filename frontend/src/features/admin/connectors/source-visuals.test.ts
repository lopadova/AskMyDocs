import { describe, expect, it } from 'vitest';
import { sourceAvatar } from './source-visuals';

describe('sourceAvatar', () => {
    it('uses the brand colour + @ glyph for imap (never a bare E from "Email…")', () => {
        const a = sourceAvatar('imap', 'Email (IMAP)');
        expect(a.letter).toBe('@');
        expect(a.bg).toBe('#6366F1');
        expect(a.fg).toBe('#ffffff');
    });

    it('uses the brand colour + first letter for a known source without a glyph override', () => {
        const a = sourceAvatar('google-drive', 'Google Drive');
        expect(a.letter).toBe('G');
        expect(a.bg).toBe('#F4B400');
        // Yellow background needs a dark glyph for contrast.
        expect(a.fg).toBe('#1a1a1a');
    });

    it('derives a deterministic palette colour for unknown connector keys', () => {
        const first = sourceAvatar('my-inhouse', 'My Inhouse');
        const second = sourceAvatar('my-inhouse', 'My Inhouse');
        expect(first).toEqual(second);
        expect(first.letter).toBe('M');
        expect(first.bg).toMatch(/^#[0-9a-f]{6}$/i);
    });

    it('falls back to the key initial when the display name is blank, then to ?', () => {
        expect(sourceAvatar('zulu-connector', '   ').letter).toBe('Z');
        expect(sourceAvatar('', '').letter).toBe('?');
    });
});
