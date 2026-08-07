import type { WidgetTheme } from '../../../widget/types';
import { sanitizeTheme } from '../../../widget/ui/styles';

export const WIDGET_THEME_PROFILE_FORMAT = 'askmydocs.widget-theme';
export const WIDGET_THEME_PROFILE_VERSION = 1;
export const MAX_WIDGET_THEME_PROFILE_CHARS = 65_536;

export interface WidgetThemeProfile {
    _meta: {
        format: typeof WIDGET_THEME_PROFILE_FORMAT;
        version: typeof WIDGET_THEME_PROFILE_VERSION;
    };
    theme: WidgetTheme;
}

type FieldSpec =
    | { kind: 'color' }
    | { kind: 'enum'; values: readonly string[] }
    | { kind: 'integer'; min: number; max: number }
    | { kind: 'text'; max: number }
    | { kind: 'https-url'; max: number };

/**
 * Portable profile contract. Keep this exhaustive map aligned with
 * WidgetThemeService (PHP) and WidgetTheme/DEFAULT_THEME (runtime TS).
 */
export const WIDGET_THEME_FIELD_SPECS = {
    mode: { kind: 'enum', values: ['helper', 'inline', 'fullscreen'] },
    accent: { kind: 'color' },
    accentForeground: { kind: 'color' },
    background: { kind: 'color' },
    foreground: { kind: 'color' },
    muted: { kind: 'color' },
    border: { kind: 'color' },
    headerBackground: { kind: 'color' },
    headerForeground: { kind: 'color' },
    launcherBackground: { kind: 'color' },
    launcherForeground: { kind: 'color' },
    userBubbleBackground: { kind: 'color' },
    userBubbleForeground: { kind: 'color' },
    assistantBubbleBackground: { kind: 'color' },
    assistantBubbleForeground: { kind: 'color' },
    composerBackground: { kind: 'color' },
    inputBackground: { kind: 'color' },
    inputForeground: { kind: 'color' },
    inputPlaceholder: { kind: 'color' },
    citationBackground: { kind: 'color' },
    citationForeground: { kind: 'color' },
    focusRing: { kind: 'color' },
    systemBackground: { kind: 'color' },
    systemForeground: { kind: 'color' },
    errorBackground: { kind: 'color' },
    errorForeground: { kind: 'color' },
    confirmBackground: { kind: 'color' },
    confirmForeground: { kind: 'color' },
    confirmBorder: { kind: 'color' },
    sourceSidebarBackground: { kind: 'color' },
    sourceSidebarForeground: { kind: 'color' },
    sourceBackdrop: { kind: 'color' },
    fontFamily: { kind: 'enum', values: ['system', 'inter', 'roboto', 'georgia', 'mono'] },
    fontSize: { kind: 'integer', min: 12, max: 18 },
    launcherSide: { kind: 'enum', values: ['right', 'left'] },
    launcherShape: { kind: 'enum', values: ['pill', 'rounded', 'circle'] },
    launcherLabel: { kind: 'text', max: 60 },
    launcherIcon: { kind: 'enum', values: ['chat', 'sparkles', 'help', 'none'] },
    launcherIconUrl: { kind: 'https-url', max: 500 },
    launcherOffsetX: { kind: 'integer', min: 0, max: 96 },
    launcherOffsetY: { kind: 'integer', min: 0, max: 96 },
    launcherSize: { kind: 'integer', min: 40, max: 80 },
    launcherShadow: { kind: 'enum', values: ['none', 'soft', 'medium', 'strong'] },
    panelWidth: { kind: 'integer', min: 320, max: 720 },
    panelHeight: { kind: 'integer', min: 420, max: 900 },
    panelRadius: { kind: 'integer', min: 0, max: 24 },
    panelShadow: { kind: 'enum', values: ['none', 'soft', 'medium', 'strong'] },
    panelTitle: { kind: 'text', max: 60 },
    headerLogoUrl: { kind: 'https-url', max: 500 },
    headerPaddingX: { kind: 'integer', min: 0, max: 40 },
    headerPaddingY: { kind: 'integer', min: 0, max: 40 },
    messagesPadding: { kind: 'integer', min: 0, max: 40 },
    messageGap: { kind: 'integer', min: 0, max: 32 },
    bubblePaddingX: { kind: 'integer', min: 0, max: 32 },
    bubblePaddingY: { kind: 'integer', min: 0, max: 32 },
    bubbleRadius: { kind: 'integer', min: 0, max: 32 },
    bubbleMaxWidth: { kind: 'integer', min: 50, max: 100 },
    composerPadding: { kind: 'integer', min: 0, max: 32 },
    inputRadius: { kind: 'integer', min: 0, max: 32 },
    buttonRadius: { kind: 'integer', min: 0, max: 32 },
    logoHeight: { kind: 'integer', min: 16, max: 64 },
    sourceViewerWidth: { kind: 'integer', min: 560, max: 1200 },
    sourceViewerRadius: { kind: 'integer', min: 0, max: 32 },
} as const satisfies Record<keyof WidgetTheme, FieldSpec>;

const PROFILE_KEYS = ['_meta', 'theme'] as const;
const META_KEYS = ['format', 'version'] as const;
const THEME_KEYS = Object.keys(WIDGET_THEME_FIELD_SPECS) as (keyof WidgetTheme)[];
const LOWER_HEX_RE = /^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/;

export class WidgetThemeProfileError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'WidgetThemeProfileError';
    }
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) return false;
    const prototype = Object.getPrototypeOf(value);

    return prototype === Object.prototype || prototype === null;
}

function assertExactKeys(
    value: Record<string, unknown>,
    expected: readonly string[],
    path: string,
): void {
    const actual = Object.keys(value);
    const missing = expected.filter((key) => !Object.hasOwn(value, key));
    const unknown = actual.filter((key) => !expected.includes(key));

    if (missing.length > 0) {
        throw new WidgetThemeProfileError(`${path} is missing: ${missing.join(', ')}.`);
    }
    if (unknown.length > 0) {
        throw new WidgetThemeProfileError(
            `${path} contains unsupported fields: ${unknown.join(', ')}.`,
        );
    }
}

function validateHttpsUrl(value: unknown, path: string, max: number): void {
    if (value === '') return;
    if (
        typeof value !== 'string' ||
        Array.from(value).length > max ||
        !/^https:\/\//.test(value) ||
        /["'()<>\s\\]/.test(value)
    ) {
        throw new WidgetThemeProfileError(
            `${path} must be empty or a safe HTTPS URL with at most ${max} characters.`,
        );
    }
    try {
        if (new URL(value).protocol !== 'https:') throw new Error('wrong protocol');
    } catch {
        throw new WidgetThemeProfileError(`${path} must be empty or a safe HTTPS URL.`);
    }
}

function validateThemeField(key: keyof WidgetTheme, value: unknown): void {
    const spec: FieldSpec = WIDGET_THEME_FIELD_SPECS[key];
    const path = `theme.${key}`;

    switch (spec.kind) {
        case 'color':
            if (typeof value !== 'string' || !LOWER_HEX_RE.test(value)) {
                throw new WidgetThemeProfileError(
                    `${path} must be a lower-case hex colour (#rgb, #rrggbb or #rrggbbaa).`,
                );
            }
            return;
        case 'enum':
            if (typeof value !== 'string' || !spec.values.includes(value)) {
                throw new WidgetThemeProfileError(
                    `${path} must be one of: ${spec.values.join(', ')}.`,
                );
            }
            return;
        case 'integer':
            if (
                !Number.isInteger(value) ||
                (value as number) < spec.min ||
                (value as number) > spec.max
            ) {
                throw new WidgetThemeProfileError(
                    `${path} must be an integer from ${spec.min} to ${spec.max}.`,
                );
            }
            return;
        case 'text':
            if (
                typeof value !== 'string' ||
                value !== value.trim() ||
                /[\u0000-\u001F\u007F]/.test(value) ||
                Array.from(value).length > spec.max
            ) {
                throw new WidgetThemeProfileError(
                    `${path} must be trimmed text with at most ${spec.max} characters.`,
                );
            }
            return;
        case 'https-url':
            validateHttpsUrl(value, path, spec.max);
    }
}

function unwrapOptionalMarkdownFence(value: string): string {
    const trimmed = value.trim();
    if (!trimmed.startsWith('```')) return trimmed;
    const match = /^```(?:json)?[\t ]*\r?\n([\s\S]*?)\r?\n```$/i.exec(trimmed);
    if (!match) {
        throw new WidgetThemeProfileError('The JSON code fence must not contain surrounding text.');
    }

    return match[1].trim();
}

export function createWidgetThemeProfile(theme: WidgetTheme): WidgetThemeProfile {
    return {
        _meta: {
            format: WIDGET_THEME_PROFILE_FORMAT,
            version: WIDGET_THEME_PROFILE_VERSION,
        },
        theme: sanitizeTheme(theme),
    };
}

export function serializeWidgetThemeProfile(theme: WidgetTheme): string {
    return JSON.stringify(createWidgetThemeProfile(theme), null, 2);
}

/** Parse and validate atomically. No value is clamped, defaulted or ignored. */
export function parseWidgetThemeProfile(source: string): WidgetThemeProfile {
    if (source.length > MAX_WIDGET_THEME_PROFILE_CHARS) {
        throw new WidgetThemeProfileError('The JSON profile exceeds the 64 KB limit.');
    }

    let raw: unknown;
    try {
        raw = JSON.parse(unwrapOptionalMarkdownFence(source));
    } catch (error) {
        if (error instanceof WidgetThemeProfileError) throw error;
        throw new WidgetThemeProfileError('The imported content is not valid JSON.');
    }

    if (!isPlainObject(raw)) {
        throw new WidgetThemeProfileError('The JSON root must be an object.');
    }
    assertExactKeys(raw, PROFILE_KEYS, 'The JSON root');

    if (!isPlainObject(raw._meta)) {
        throw new WidgetThemeProfileError('_meta must be an object.');
    }
    assertExactKeys(raw._meta, META_KEYS, '_meta');
    if (raw._meta.format !== WIDGET_THEME_PROFILE_FORMAT) {
        throw new WidgetThemeProfileError(
            `_meta.format must be "${WIDGET_THEME_PROFILE_FORMAT}".`,
        );
    }
    if (raw._meta.version !== WIDGET_THEME_PROFILE_VERSION) {
        throw new WidgetThemeProfileError(
            `Unsupported profile version. Expected ${WIDGET_THEME_PROFILE_VERSION}.`,
        );
    }

    if (!isPlainObject(raw.theme)) {
        throw new WidgetThemeProfileError('theme must be an object.');
    }
    assertExactKeys(raw.theme, THEME_KEYS, 'theme');
    for (const key of THEME_KEYS) validateThemeField(key, raw.theme[key]);

    return {
        _meta: {
            format: WIDGET_THEME_PROFILE_FORMAT,
            version: WIDGET_THEME_PROFILE_VERSION,
        },
        theme: raw.theme as unknown as WidgetTheme,
    };
}

function handoffConstraints(): string {
    const colors = THEME_KEYS.filter((key) => WIDGET_THEME_FIELD_SPECS[key].kind === 'color');
    const ranges = THEME_KEYS.flatMap((key) => {
        const spec: FieldSpec = WIDGET_THEME_FIELD_SPECS[key];
        return spec.kind === 'integer' ? [`${key}=${spec.min}..${spec.max}`] : [];
    });
    const enums = THEME_KEYS.flatMap((key) => {
        const spec: FieldSpec = WIDGET_THEME_FIELD_SPECS[key];
        return spec.kind === 'enum' ? [`${key}=${spec.values.join('|')}`] : [];
    });

    return [
        `Colours (lower-case hex only): ${colors.join(', ')}.`,
        `Enums: ${enums.join('; ')}.`,
        `Integer ranges (inclusive): ${ranges.join('; ')}.`,
        'launcherLabel and panelTitle: trimmed text, at most 60 Unicode characters, with no control characters or line breaks.',
        'launcherIconUrl and headerLogoUrl: empty string or an existing, verified URL beginning with lower-case https://, at most 500 characters, with no whitespace, quotes, parentheses, angle brackets or backslashes.',
    ].join('\n');
}

/**
 * Self-contained prompt to hand to a coding agent inside the host site repo.
 * It intentionally contains appearance data only: never tenant/key credentials.
 */
export function buildWidgetThemeAgentHandoff(theme: WidgetTheme): string {
    const profile = createWidgetThemeProfile(theme);
    profile.theme = {
        ...profile.theme,
        // These are the only free-form strings in WidgetTheme. Omitting them
        // prevents both credential leakage and indirect prompt instructions.
        launcherLabel: '',
        panelTitle: '',
        launcherIconUrl: '',
        headerLogoUrl: '',
    };
    const startingProfile = JSON.stringify(profile, null, 2);

    return `ROLE
You are configuring the visual appearance of an AskMyDocs embeddable widget for
the web interface whose codebase you can currently inspect.

TASK
1. Inspect the host interface read-only: its design tokens, CSS variables,
   typography, spacing, radii, light/dark palette, responsive layout and existing
   public brand assets.
2. Adapt the complete widget profile below so the launcher, chat, composer,
   system states, citation chips and source viewer look native to that interface.
3. Preserve a readable WCAG-conscious contrast, visible focus states and usable
   mobile dimensions. If a value cannot be inferred safely, keep the supplied
   starting value.

ABSOLUTE RULES
- Do not modify files, run migrations or change the host application.
- Return ONLY one valid JSON object: no Markdown fence, prose or comments.
- Keep exactly the envelope and every theme field shown below. Do not add,
  remove or rename fields.
- Do not emit arbitrary CSS, CSS expressions, var(), calc(), URLs inside colours,
  or any value outside the listed enum/range.
- Never include widget keys, secrets, tokens, tenant/project identifiers,
  origins, skills, API endpoints, user data or other credentials.
- Free-form labels and asset URLs are redacted to "" in the starting profile.
  Infer suitable public values from the host interface or keep them empty.
- Do not invent an asset URL. Use an existing verified HTTPS URL or "".
- mode controls newly generated embeds: helper=floating launcher,
  inline=host-container block, fullscreen=entire viewport. Preserve it unless
  the integration requirement clearly specifies another mode.
- Host --askmydocs-* variables and an inline embed theme can override the saved
  profile, so do not rely on them in this JSON.

VALIDATION CONTRACT
_meta.format must be "${WIDGET_THEME_PROFILE_FORMAT}" and _meta.version must be
${WIDGET_THEME_PROFILE_VERSION}. The theme must be complete and strict.
${handoffConstraints()}

COMPLETE STARTING PROFILE TO ADAPT
${startingProfile}`;
}
