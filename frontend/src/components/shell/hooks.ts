import { useEffect, useState } from 'react';

export type Theme = 'dark' | 'light';
export type Density = 'compact' | 'balanced' | 'comfortable';
export type FontPair = 'geist' | 'inter' | 'plex' | 'satoshi';

type Setter<T> = (v: T) => void;

function readStorage<T extends string>(key: string, fallback: T): T {
    if (typeof window === 'undefined') {
        return fallback;
    }
    try {
        const raw = window.localStorage.getItem(key);
        return (raw as T) || fallback;
    } catch {
        return fallback;
    }
}

function writeStorage(key: string, value: string): void {
    if (typeof window === 'undefined') {
        return;
    }
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Storage can be disabled or full — the UI still works with the
        // in-memory state, so a failed persist is not a fatal error.
    }
}

export function useTheme(initial: Theme = 'dark'): [Theme, Setter<Theme>] {
    const [theme, setTheme] = useState<Theme>(() => readStorage<Theme>('amd-theme', initial));
    useEffect(() => {
        document.documentElement.setAttribute('data-theme', theme);
        writeStorage('amd-theme', theme);
    }, [theme]);
    return [theme, setTheme];
}

export function useDensity(initial: Density = 'balanced'): [Density, Setter<Density>] {
    const [density, setDensity] = useState<Density>(() => readStorage<Density>('amd-density', initial));
    useEffect(() => {
        document.documentElement.setAttribute('data-density', density);
        writeStorage('amd-density', density);
    }, [density]);
    return [density, setDensity];
}

const FONT_MAP: Record<FontPair, [string, string]> = {
    geist: ["'Geist'", "'Geist Mono'"],
    inter: ["'Inter'", "'JetBrains Mono'"],
    plex: ["'IBM Plex Sans'", "'IBM Plex Mono'"],
    satoshi: ["'Satoshi'", "'JetBrains Mono'"],
};

export function useFontPair(initial: FontPair = 'geist'): [FontPair, Setter<FontPair>] {
    const [font, setFont] = useState<FontPair>(() => readStorage<FontPair>('amd-font', initial));
    useEffect(() => {
        const [sans, mono] = FONT_MAP[font] ?? FONT_MAP.geist;
        document.documentElement.style.setProperty(
            '--font-sans',
            `${sans}, ui-sans-serif, system-ui, sans-serif`,
        );
        document.documentElement.style.setProperty(
            '--font-mono',
            `${mono}, ui-monospace, Menlo, monospace`,
        );
        writeStorage('amd-font', font);
    }, [font]);
    return [font, setFont];
}
