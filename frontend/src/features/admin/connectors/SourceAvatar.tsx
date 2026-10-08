import { useState, type CSSProperties } from 'react';
import { sourceAvatar } from './source-visuals';
import { isPublishedSourceIcon, sourceMark } from './source-marks';
import './SourceAvatar.css';

/*
 * Shared source identity for gallery tiles, accounts and modal headers.
 * Known sources use the bundled mark unless an explicit custom icon is supplied.
 * A failed custom image falls back to the bundled mark, then the letter avatar.
 *
 * R15 — decorative: the source NAME is always rendered as adjacent text, so the
 * image carries an empty alt and the avatar is `aria-hidden` (announcing the
 * letter would be redundant noise for assistive tech).
 */

export interface SourceAvatarProps {
    connectorKey: string;
    displayName: string;
    iconUrl?: string | null;
    /** Square edge length in px. */
    size?: number;
    /** Corner radius in px. */
    radius?: number;
    testid?: string;
}

export function SourceAvatar({
    connectorKey,
    displayName,
    iconUrl,
    size = 36,
    radius = 9,
    testid,
}: SourceAvatarProps) {
    const mark = sourceMark(connectorKey);
    const customIcon = iconUrl && !isPublishedSourceIcon(connectorKey, iconUrl) ? iconUrl : null;
    const primary = customIcon || mark?.src || iconUrl;
    // Key failures by source and URL: changing either immediately re-arms loading.
    const identity = `${connectorKey}:${primary}`;
    const [failure, setFailure] = useState<{ identity: string; count: number } | null>(null);
    const failures = failure?.identity === identity ? failure.count : 0;
    const src = failures === 0 ? primary : failures === 1 && customIcon ? mark?.src : null;
    const { letter, bg, fg } = sourceAvatar(connectorKey, displayName);
    const showImage = !!src;
    const bundled = showImage && src === mark?.src;

    return (
        <div
            aria-hidden="true"
            data-testid={testid}
            className="amd-source-avatar"
            data-source={connectorKey}
            data-monochrome={bundled && !!mark?.monochrome}
            data-tile={bundled && !!mark?.tile}
            style={{
                '--source-accent': mark?.accent ?? bg,
                width: size,
                height: size,
                borderRadius: radius,
                fontWeight: 700,
                fontSize: Math.round(size * 0.42),
                lineHeight: 1,
                background: showImage ? undefined : bg,
                color: fg,
            } as CSSProperties}
        >
            {showImage ? (
                <img
                    src={src ?? undefined}
                    alt=""
                    width={size}
                    height={size}
                    onError={() => setFailure({ identity, count: failures + 1 })}
                />
            ) : (
                letter
            )}
        </div>
    );
}
