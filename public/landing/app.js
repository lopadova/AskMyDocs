import { COPY, LANGUAGES, resolveLocale, translate } from './i18n.js?v=editorial-1';

const STORAGE_KEY = 'askmydoc.landing.lang'; // Preserve the previous landing's preference.
let saved;
try { saved = localStorage.getItem(STORAGE_KEY); } catch { /* Private browsing can disable storage. */ }
let locale = resolveLocale({ search: location.search, saved, languages: navigator.languages ?? [navigator.language] });
let scenarioIndex = 0;
const languageSelect = document.getElementById('language');
const tabs = document.getElementById('scenario-tabs');
const answer = document.getElementById('demo-answer');
const sourceDialog = document.getElementById('source-dialog');
const menuButton = document.querySelector('.menu-toggle');
const mobileNav = document.getElementById('mobile-nav');

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function showSource(index) {
  const copy = COPY[locale];
  const source = copy.scenarios[scenarioIndex].sources[index];
  document.getElementById('source-title').textContent = source.title;
  document.getElementById('source-meta').textContent = `${copy.experience.excerpt} · ${copy.experience.page} ${source.page}`;
  document.getElementById('source-excerpt').textContent = source.excerpt;
  sourceDialog.showModal();
}

function renderScenario({ focusTab = false } = {}) {
  const copy = COPY[locale];
  const scenario = copy.scenarios[scenarioIndex];
  tabs.replaceChildren(...copy.scenarios.map((item, index) => {
    const tab = element('button', 'btn btn-quiet scenario-tab', item.label);
    tab.type = 'button';
    tab.id = `scenario-${index}`;
    tab.setAttribute('role', 'tab');
    tab.setAttribute('aria-selected', String(index === scenarioIndex));
    tab.setAttribute('aria-controls', 'demo-answer');
    tab.tabIndex = index === scenarioIndex ? 0 : -1;
    tab.addEventListener('click', () => { scenarioIndex = index; renderScenario({ focusTab: true }); });
    return tab;
  }));
  const label = element('p', 'answer-label');
  const star = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); star.setAttribute('viewBox', '0 0 24 24'); star.setAttribute('width', '16'); star.setAttribute('height', '16'); star.innerHTML = '<path d="M12 3v18M3 12h18M6 6l12 12M6 18 18 6" fill="none" stroke="currentColor" stroke-width="1.5"/>';
  star.setAttribute('aria-hidden', 'true');
  label.append(star, document.createTextNode(copy.experience.answer));
  const sources = element('div', 'demo-sources');
  scenario.sources.forEach((source, index) => {
    const button = element('button', 'btn btn-quiet source-link');
    button.type = 'button';
    button.setAttribute('aria-haspopup', 'dialog');
    button.append(element('span', 'source-number', `0${index + 1}`), document.createTextNode(source.title));
    const arrow = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); arrow.setAttribute('viewBox', '0 0 24 24'); arrow.setAttribute('width', '16'); arrow.setAttribute('height', '16'); arrow.innerHTML = '<path d="M7 17 17 7M7 7h10v10" fill="none" stroke="currentColor" stroke-width="1.5"/>';
    arrow.setAttribute('aria-hidden', 'true');
    button.append(arrow);
    button.addEventListener('click', () => showSource(index));
    sources.append(button);
  });
  answer.setAttribute('aria-labelledby', `scenario-${scenarioIndex}`);
  answer.replaceChildren(element('p', 'question-label', copy.experience.question), element('h3', 'demo-question', scenario.question), label, element('p', 'answer-text', scenario.answer), sources);
  if (focusTab) document.getElementById(`scenario-${scenarioIndex}`).focus({ preventScroll: true });
}

tabs.addEventListener('keydown', (event) => {
  if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;
  event.preventDefault();
  const count = COPY[locale].scenarios.length;
  if (event.key === 'Home') scenarioIndex = 0;
  else if (event.key === 'End') scenarioIndex = count - 1;
  else scenarioIndex = (scenarioIndex + (event.key === 'ArrowRight' ? 1 : -1) + count) % count;
  renderScenario({ focusTab: true });
});

function renderLanguage() {
  const copy = COPY[locale];
  document.documentElement.lang = locale;
  document.title = copy.title;
  document.querySelector('meta[name="description"]').content = copy.description;
  document.querySelector('meta[property="og:title"]').content = copy.title;
  document.querySelector('meta[property="og:description"]').content = copy.description;
  languageSelect.value = locale;
  document.querySelectorAll('[data-i18n]').forEach((node) => { node.textContent = translate(locale, node.dataset.i18n).replace(/[↗↑↓]/g, '').trim(); });
  document.querySelectorAll('[data-i18n-alt]').forEach((node) => { node.alt = translate(locale, node.dataset.i18nAlt); });
  document.querySelectorAll('[data-i18n-aria]').forEach((node) => { node.setAttribute('aria-label', translate(locale, node.dataset.i18nAria)); });
  const faqList = document.getElementById('faq-list');
  const open = [...faqList.children].map((node) => node.open);
  faqList.replaceChildren(...copy.faq.items.map((item, index) => {
    const details = element('details');
    details.open = open[index] ?? false;
    details.append(element('summary', '', item.q), element('p', '', item.a));
    return details;
  }));
  renderScenario();
}

function setLocale(next) {
  locale = next;
  try { localStorage.setItem(STORAGE_KEY, next); if (next === 'it' || next === 'en') localStorage.setItem('amd-ui-locale', next); } catch { /* In-memory and URL selection still work. */ }
  const url = new URL(location.href);
  url.searchParams.set('lang', next);
  history.replaceState(null, '', url);
  renderLanguage();
}

languageSelect.replaceChildren(...LANGUAGES.map(({ code, name }) => {
  const option = element('option', '', code.toUpperCase());
  option.value = code;
  option.lang = code;
  option.label = `${code.toUpperCase()} · ${name}`;
  return option;
}));
languageSelect.addEventListener('change', () => setLocale(languageSelect.value));
window.addEventListener('popstate', () => {
  locale = resolveLocale({ search: location.search, saved: locale });
  renderLanguage();
});

function closeMenu({ restoreFocus = false } = {}) {
  mobileNav.hidden = true;
  menuButton.setAttribute('aria-expanded', 'false');
  if (restoreFocus) menuButton.focus();
}
menuButton.addEventListener('click', () => {
  const open = menuButton.getAttribute('aria-expanded') !== 'true';
  menuButton.setAttribute('aria-expanded', String(open));
  mobileNav.hidden = !open;
});
mobileNav.addEventListener('click', (event) => {
  const link = event.target.closest('a');
  if (!link) return;
  closeMenu();
  if (link.hash) document.querySelector(link.hash)?.querySelector('h2')?.focus({ preventScroll: true });
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && !mobileNav.hidden) closeMenu({ restoreFocus: true });
});
document.addEventListener('click', (event) => {
  if (!event.target.closest('.header')) closeMenu();
});
window.matchMedia('(min-width: 761px)').addEventListener('change', ({ matches }) => { if (matches) closeMenu(); });
document.getElementById('close-source').addEventListener('click', () => sourceDialog.close());
sourceDialog.addEventListener('click', (event) => {
  if (event.target !== sourceDialog) return;
  const rect = sourceDialog.getBoundingClientRect();
  if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) sourceDialog.close();
});
document.getElementById('year').textContent = new Date().getFullYear();
renderLanguage();
