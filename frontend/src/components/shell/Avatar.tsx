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
    if (user.avatar) {
        return user.avatar;
    }
    if (!user.name) {
        return '';
    }
    return user.name
        .split(' ')
        .map((n) => n[0])
        .slice(0, 2)
        .join('');
}

export function Avatar({ user, size = 28 }: AvatarProps) {
    return (
        <div
            style={{
                width: size,
                height: size,
                borderRadius: 999,
                background: `linear-gradient(135deg, ${user.color ?? '#8b5cf6'}, ${user.color2 ?? '#22d3ee'})`,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: Math.max(10, size * 0.38),
                fontWeight: 600,
                color: '#0a0a14',
                flex: '0 0 auto',
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
