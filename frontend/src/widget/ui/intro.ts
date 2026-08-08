import type {
    WidgetIntro,
    WidgetIntroIcon,
    WidgetIntroSuggestion,
    WidgetIntroVariant,
} from '../types';

export const DEFAULT_INTRO: WidgetIntro = {
    enabled: false,
    variant: 'card',
    eyebrow: '',
    title: '',
    subtitle: '',
    body: '',
    imageUrl: '',
    imageAlt: '',
    icon: 'sparkles',
    bullets: [],
    suggestions: [],
    dismissible: true,
    hideAfterFirstMessage: true,
};

const VARIANTS: WidgetIntroVariant[] = ['compact', 'card', 'hero'];
const ICONS: WidgetIntroIcon[] = ['sparkles', 'chat', 'search', 'help', 'none'];

function text(value: unknown, max: number, multiline = false): string {
    if (typeof value !== 'string') return '';
    const controls = multiline
        ? /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g
        : /[\u0000-\u001F\u007F]/g;

    return Array.from(value.trim().replace(controls, '')).slice(0, max).join('');
}

function httpsUrl(value: unknown): string {
    if (typeof value !== 'string' || value === '' || Array.from(value).length > 500) return '';
    if (!value.startsWith('https://') || /["'()<>\s\\]/.test(value)) return '';
    try {
        return new URL(value).protocol === 'https:' ? value : '';
    } catch {
        return '';
    }
}

export function sanitizeIntro(input: unknown): WidgetIntro {
    const raw = typeof input === 'object' && input !== null && !Array.isArray(input)
        ? input as Record<string, unknown>
        : {};
    const variant = typeof raw.variant === 'string' && VARIANTS.includes(raw.variant as WidgetIntroVariant)
        ? raw.variant as WidgetIntroVariant
        : DEFAULT_INTRO.variant;
    const icon = typeof raw.icon === 'string' && ICONS.includes(raw.icon as WidgetIntroIcon)
        ? raw.icon as WidgetIntroIcon
        : DEFAULT_INTRO.icon;
    const bullets = (Array.isArray(raw.bullets) ? raw.bullets : [])
        .slice(0, 4)
        .map((item) => text(item, 160))
        .filter(Boolean);
    const suggestions: WidgetIntroSuggestion[] = (Array.isArray(raw.suggestions) ? raw.suggestions : [])
        .slice(0, 4)
        .flatMap((item) => {
            if (typeof item !== 'object' || item === null || Array.isArray(item)) return [];
            const candidate = item as Record<string, unknown>;
            const label = text(candidate.label, 80);
            const prompt = text(candidate.prompt, 500, true);

            return label !== '' && prompt !== '' ? [{ label, prompt }] : [];
        });

    return {
        enabled: typeof raw.enabled === 'boolean' ? raw.enabled : DEFAULT_INTRO.enabled,
        variant,
        eyebrow: text(raw.eyebrow, 60),
        title: text(raw.title, 120),
        subtitle: text(raw.subtitle, 180),
        body: text(raw.body, 600, true),
        imageUrl: httpsUrl(raw.imageUrl),
        imageAlt: text(raw.imageAlt, 160),
        icon,
        bullets,
        suggestions,
        dismissible: typeof raw.dismissible === 'boolean' ? raw.dismissible : DEFAULT_INTRO.dismissible,
        hideAfterFirstMessage: typeof raw.hideAfterFirstMessage === 'boolean'
            ? raw.hideAfterFirstMessage
            : DEFAULT_INTRO.hideAfterFirstMessage,
    };
}

export function mergeIntroLayers(
    server?: Partial<WidgetIntro> | null,
    inline?: Partial<WidgetIntro> | false,
): WidgetIntro {
    if (inline === false) return DEFAULT_INTRO;
    const base = sanitizeIntro({ ...DEFAULT_INTRO, ...(server ?? {}) });

    return sanitizeIntro({ ...base, ...(inline ?? {}) });
}

const ICON_SVGS: Record<Exclude<WidgetIntroIcon, 'none'>, string> = {
    sparkles: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3Z"/><path d="m19 15 .8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15Z"/></svg>',
    chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 18.5 3.5 21v-5A8 8 0 1 1 7 18.5Z"/><path d="M8 10h8M8 14h5"/></svg>',
    search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>',
    help: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.7 9a2.5 2.5 0 1 1 3.5 2.3c-.8.4-1.2.9-1.2 1.7M12 17h.01"/></svg>',
};

export class IntroCard {
    private element: HTMLElement | null = null;
    private config: WidgetIntro = DEFAULT_INTRO;

    constructor(
        private readonly container: HTMLElement,
        private readonly onSuggestion: (prompt: string) => void,
    ) {}

    show(config: WidgetIntro): void {
        this.remove();
        this.config = config;
        if (!config.enabled || config.title === '') return;

        const section = document.createElement('section');
        section.className = 'amd-intro';
        section.dataset.variant = config.variant;
        section.dataset.testid = 'askmydocs-widget-intro';
        section.setAttribute('aria-labelledby', 'amd-intro-title');

        if (config.imageUrl !== '' && config.imageAlt !== '') {
            const image = document.createElement('img');
            image.className = 'amd-intro-image';
            image.src = config.imageUrl;
            image.alt = config.imageAlt;
            image.referrerPolicy = 'no-referrer';
            section.append(image);
        }

        const content = document.createElement('div');
        content.className = 'amd-intro-content';
        if (config.icon !== 'none') {
            const icon = document.createElement('span');
            icon.className = 'amd-intro-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.innerHTML = ICON_SVGS[config.icon];
            content.append(icon);
        }
        if (config.dismissible) {
            const dismiss = document.createElement('button');
            dismiss.className = 'amd-intro-dismiss';
            dismiss.type = 'button';
            dismiss.dataset.testid = 'askmydocs-widget-intro-dismiss';
            dismiss.setAttribute('aria-label', 'Nascondi introduzione');
            dismiss.textContent = '×';
            dismiss.addEventListener('click', () => this.hide());
            content.append(dismiss);
        }
        if (config.eyebrow !== '') content.append(this.textElement('p', 'amd-intro-eyebrow', config.eyebrow));
        const title = this.textElement('h2', 'amd-intro-title', config.title);
        title.id = 'amd-intro-title';
        content.append(title);
        if (config.subtitle !== '') content.append(this.textElement('p', 'amd-intro-subtitle', config.subtitle));
        if (config.body !== '') content.append(this.textElement('p', 'amd-intro-body', config.body));

        if (config.bullets.length > 0) {
            const list = document.createElement('ul');
            list.className = 'amd-intro-bullets';
            for (const bullet of config.bullets) list.append(this.textElement('li', '', bullet));
            content.append(list);
        }

        if (config.suggestions.length > 0) {
            const suggestions = document.createElement('div');
            suggestions.className = 'amd-intro-suggestions';
            suggestions.setAttribute('aria-label', 'Domande suggerite');
            for (const suggestion of config.suggestions) {
                const button = this.textElement('button', 'amd-intro-suggestion', suggestion.label) as HTMLButtonElement;
                button.type = 'button';
                button.dataset.testid = 'askmydocs-widget-intro-suggestion';
                button.addEventListener('click', () => this.onSuggestion(suggestion.prompt));
                suggestions.append(button);
            }
            content.append(suggestions);
        }

        section.append(content);
        this.container.prepend(section);
        this.element = section;
    }

    beforeFirstMessage(): void {
        if (this.config.hideAfterFirstMessage) this.hide();
    }

    hide(): void {
        if (!this.element) return;
        const current = this.element;
        current.classList.add('amd-intro-exit');
        current.addEventListener('animationend', () => {
            if (this.element === current) this.remove();
        }, { once: true });
        window.setTimeout(() => {
            if (this.element === current) this.remove();
        }, 220);
    }

    remove(): void {
        this.element?.remove();
        this.element = null;
    }

    private textElement(tag: string, className: string, value: string): HTMLElement {
        const element = document.createElement(tag);
        if (className !== '') element.className = className;
        element.textContent = value;

        return element;
    }
}
