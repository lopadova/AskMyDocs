import './Avatar.css';

export type AvatarUser = {
    name?: string;
    avatar?: string;
    color?: string;
    color2?: string;
};

export type AvatarProps = {
    user: AvatarUser;
    size?: number;
};

function initials(user: AvatarUser): string {
    const monogram = user.avatar?.trim();
    if (monogram) {
        return monogram.toUpperCase();
    }
    if (!user.name) {
        return '';
    }
    return user.name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => Array.from(part)[0])
        .join('')
        .toUpperCase();
}

export function Avatar({ user, size = 28 }: AvatarProps) {
    return (
        <div
            className="shell-avatar"
            aria-hidden="true"
            style={{
                width: size,
                height: size,
                borderRadius: Math.max(6, Math.round(size * 0.26)),
                fontSize: Math.max(10, size * 0.38),
            }}
        >
            {initials(user)}
        </div>
    );
}

export function ProjectDot({ color, size = 8 }: { color: string; size?: number }) {
    return (
        <span
            style={{
                width: size,
                height: size,
                borderRadius: 99,
                background: color,
                display: 'inline-block',
                flex: '0 0 auto',
            }}
        />
    );
}
