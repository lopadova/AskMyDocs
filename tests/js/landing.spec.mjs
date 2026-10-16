// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { COPY, LANGUAGES, resolveLocale, translate } from '../../public/landing/i18n.js';

const publicRoot = resolve(process.cwd(), 'public');
const html = readFileSync(resolve(process.cwd(), 'resources/views/landing.blade.php'), 'utf8')
  .replace(/@php[\s\S]*?@endphp/g, '')
  .replace(/{{\s*\$designTheme\s*}}/g, 'humanistic')
  .replace(/{{\s*\$editorialBase\s*}}/g, '/editorial')
  .replace(/^\s*@vite.*$/gm, '');
const keys = (value, prefix = '') => Object.entries(value).flatMap(([key, item]) => {
  const path = prefix ? `${prefix}.${key}` : key;
  return typeof item === 'object' ? keys(item, path) : [path];
});

describe('public landing localisation', () => {
  it('resolves explicit links, saved preferences, regional browser languages and safe fallbacks', () => {
    expect(resolveLocale({ search: '?lang=fr', saved: 'en', languages: ['de'] })).toBe('fr');
    expect(resolveLocale({ search: '?lang=xx', saved: 'es', languages: ['en'] })).toBe('es');
    expect(resolveLocale({ languages: ['nl-NL', 'de-CH', 'en-US'] })).toBe('de');
    expect(resolveLocale({ search: '?lang=__proto__', saved: 'constructor', languages: ['ja'] })).toBe('it');
    expect(resolveLocale()).toBe('it');
  });

  it.each(LANGUAGES.map(({ code }) => code))('%s has complete copy, examples, sources and accessible labels', (locale) => {
    expect(keys(COPY[locale]).sort()).toEqual(keys(COPY.it).sort());
    for (const key of keys(COPY.it)) expect(translate(locale, key)?.trim(), `${locale}.${key}`).toBeTruthy();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    for (const attribute of ['data-i18n', 'data-i18n-alt', 'data-i18n-aria']) {
      for (const node of doc.querySelectorAll(`[${attribute}]`)) {
        expect(typeof translate(locale, node.getAttribute(attribute))).toBe('string');
      }
    }
  });

  it('ships every local asset and keeps internal navigation connected to a real destination', () => {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    for (const link of doc.querySelectorAll('a[href^="#"]')) expect(doc.querySelector(link.hash)).not.toBeNull();
    for (const node of doc.querySelectorAll('[src^="/"], link[href^="/"]')) {
      const path = node.getAttribute('src') ?? node.getAttribute('href');
      expect(existsSync(resolve(publicRoot, new URL(path, 'https://askmydocsdev.test').pathname.slice(1))), path).toBe(true);
    }
    expect(doc.querySelector('h1').textContent).toContain(COPY.it.hero.emphasis);
    expect(doc.querySelectorAll('h1')).toHaveLength(1);
  });
});

describe('public landing interactions', () => {
  beforeEach(() => {
    vi.resetModules();
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    document.head.innerHTML = parsed.head.innerHTML;
    document.body.innerHTML = parsed.body.innerHTML;
    history.replaceState(null, '', '/landing?lang=it#experience');
    localStorage.clear();
    vi.stubGlobal('matchMedia', vi.fn(() => ({ addEventListener: vi.fn() })));
    HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    HTMLDialogElement.prototype.close = function () { this.open = false; };
  });

  it('switches the entire experience, metadata and shareable URL while retaining the selected example', async () => {
    await import('../../public/landing/app.js');
    document.getElementById('scenario-1').click();
    const languageRoot = document.getElementById('language-select-root');
    languageRoot.dispatchEvent(new CustomEvent('amd:landing-locale-request', { detail: { locale: 'fr' } }));
    expect(languageRoot.dataset.locale).toBe('fr');
    expect(JSON.parse(languageRoot.dataset.languages)).toEqual(LANGUAGES);
    expect(document.documentElement.lang).toBe('fr');
    expect(document.title).toBe(COPY.fr.title);
    expect(document.querySelector('meta[name="description"]').content).toBe(COPY.fr.description);
    expect(document.querySelector('.demo-question').textContent).toBe(COPY.fr.scenarios[1].question);
    expect(document.querySelector('.hero-image').alt).toBe(COPY.fr.images.hero);
    expect(location.search).toBe('?lang=fr');
    expect(location.hash).toBe('#experience');
    expect(localStorage.getItem('askmydoc.landing.lang')).toBe('fr');
    document.querySelector('.source-link').click();
    expect(document.querySelector('dialog').open).toBe(true);
    expect(document.getElementById('source-excerpt').textContent).toBe(COPY.fr.scenarios[1].sources[0].excerpt);
    document.getElementById('close-source').click();
    expect(document.querySelector('dialog').open).toBe(false);
  });

  it('initialises the React island contract from the URL and ignores unsupported locale requests', async () => {
    history.replaceState(null, '', '/landing?lang=de');
    await import('../../public/landing/app.js');
    const languageRoot = document.getElementById('language-select-root');
    expect(languageRoot.dataset.locale).toBe('de');
    const changed = vi.fn();
    languageRoot.addEventListener('amd:landing-locale-changed', changed);
    languageRoot.dispatchEvent(new CustomEvent('amd:landing-locale-request', { detail: { locale: '__proto__' } }));
    expect(languageRoot.dataset.locale).toBe('de');
    expect(document.documentElement.lang).toBe('de');
    expect(changed).not.toHaveBeenCalled();
    languageRoot.dispatchEvent(new CustomEvent('amd:landing-locale-request', { detail: { locale: 'es' } }));
    expect(changed).toHaveBeenCalledOnce();
    expect(languageRoot.dataset.locale).toBe('es');
  });

  it('starts from the application appearance preference without changing the installation identity', async () => {
    document.documentElement.dataset.designTheme = 'humanistic';
    localStorage.setItem('amd-theme', 'light');
    await import('../../public/landing/app.js');
    expect(document.documentElement.dataset.theme).toBe('light');
    expect(document.documentElement.dataset.designTheme).toBe('humanistic');
    expect(document.getElementById('theme-toggle').getAttribute('aria-pressed')).toBe('true');
    expect(document.getElementById('theme-toggle').getAttribute('aria-label')).toBe(COPY.it.theme);
  });

  it('switches and persists light/dark while preserving the selected language and example', async () => {
    history.replaceState(null, '', '/landing?lang=fr#experience');
    await import('../../public/landing/app.js');
    document.getElementById('scenario-1').click();
    const toggle = document.getElementById('theme-toggle');
    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(toggle.getAttribute('aria-label')).toBe(COPY.fr.theme);
    toggle.click();
    expect(document.documentElement.dataset.theme).toBe('light');
    expect(toggle.getAttribute('aria-pressed')).toBe('true');
    expect(localStorage.getItem('amd-theme')).toBe('light');
    expect(document.querySelector('.demo-question').textContent).toBe(COPY.fr.scenarios[1].question);
    expect(location.search).toBe('?lang=fr');
    toggle.click();
    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(toggle.getAttribute('aria-pressed')).toBe('false');
    expect(localStorage.getItem('amd-theme')).toBe('dark');
  });

  it('uses dark for an invalid appearance value and persists a valid choice on activation', async () => {
    localStorage.setItem('amd-theme', 'sepia');
    await import('../../public/landing/app.js');
    expect(document.documentElement.dataset.theme).toBe('dark');
    document.getElementById('theme-toggle').click();
    expect(localStorage.getItem('amd-theme')).toBe('light');
  });

  it('refreshes appearance from another application page on focus or storage changes', async () => {
    await import('../../public/landing/app.js');
    localStorage.setItem('amd-theme', 'light');
    window.dispatchEvent(new Event('focus'));
    expect(document.documentElement.dataset.theme).toBe('light');
    expect(document.getElementById('theme-toggle').getAttribute('aria-pressed')).toBe('true');
    localStorage.setItem('amd-theme', 'dark');
    window.dispatchEvent(new StorageEvent('storage', { key: 'amd-theme', newValue: 'dark' }));
    expect(document.documentElement.dataset.theme).toBe('dark');
  });

  it('supports keyboard tabs, wraps between examples and gives one tab a focus stop', async () => {
    await import('../../public/landing/app.js');
    const tabs = document.getElementById('scenario-tabs');
    tabs.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
    expect(document.activeElement.id).toBe('scenario-2');
    expect(document.getElementById('demo-answer').getAttribute('aria-labelledby')).toBe('scenario-2');
    tabs.dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
    expect(document.activeElement.id).toBe('scenario-0');
    expect(document.querySelectorAll('[role="tab"][tabindex="0"]')).toHaveLength(1);
  });

  it('opens mobile navigation, closes with Escape and returns focus to its trigger', async () => {
    await import('../../public/landing/app.js');
    const button = document.querySelector('.menu-toggle');
    button.click();
    expect(document.getElementById('mobile-nav').hidden).toBe(false);
    expect(button.getAttribute('aria-expanded')).toBe('true');
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(document.getElementById('mobile-nav').hidden).toBe(true);
    expect(document.activeElement).toBe(button);
  });
});
