import { useState } from 'react';
import { sourceAvatar } from './source-visuals';

/*
 * A connector source's icon: renders the connector's real `icon_url` image and,
 * if that is missing or fails to load, falls back to a brand-coloured letter
 * avatar (see source-visuals.ts). This keeps the real provider logos when they
 * resolve while still giving every tile/row a polished, deterministic mark.
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
    const [broken, setBroken] = useState(false);
    const { letter, bg, fg } = sourceAvatar(connectorKey, displayName);
    const showImage = !!iconUrl && !broken;

    return (
        <div
            aria-hidden="true"
            data-testid={testid}
            style={{
                flex: 'none',
                width: size,
                height: size,
                borderRadius: radius,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                overflow: 'hidden',
                fontWeight: 700,
                fontSize: Math.round(size * 0.42),
                lineHeight: 1,
                background: showImage ? 'var(--bg-2)' : bg,
                color: fg,
            }}
        >
            {showImage ? (
                <img
                    src={iconUrl ?? undefined}
                    alt=""
                    width={size}
                    height={size}
                    onError={() => setBroken(true)}
                    style={{ width: '100%', height: '100%', objectFit: 'contain' }}
                />
            ) : (
                letter
            )}
        </div>
    );
}
