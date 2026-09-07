# Public editorial landing

Open `/landing?lang=it` on the application host (locally, `https://askmydocsdev.test`).
The public route serves `public/landing/index.html`; the existing root and sign-in flow
are unchanged. `/landing/index.html` also works as a static entry point.

The visual direction combines a dark editorial palette, bronze accents, a full-width
photographic hero, serif headlines and restrained technical details. The product story
addresses organisations and teams; fashion, design and luxury are one application of
the platform, alongside project knowledge, onboarding and hospitality.

## Editing

- `public/landing/i18n.js`: curated Italian, English, French, German and Spanish copy.
- `public/landing/index.html`: structure and Italian fallback content. Keep fallback
  text aligned with the Italian dictionary when editing copy.
- `public/landing/styles.css`: independent, responsive dark theme. Native buttons use
  the AskMyDocs compatibility contract; no React runtime is needed on this static page.
- `public/landing/app.js`: language selection, keyboard tabs, source dialogs, mobile
  navigation and FAQ rendering. No build, remote scripts or AI requests are required.

Language precedence is `?lang=`, the existing `askmydoc.landing.lang` preference,
supported browser languages, then Italian. Selection updates the URL, document title,
description, alternative text and accessible names without reloading the page.

The three interactive examples and their source excerpts are explicitly fictional.
They demonstrate materials, project decisions and onboarding without accessing customer
data or calling a model. Application CTAs open `/app/chat` and require workspace access.
Fonts are loaded from Google Fonts, with local serif, sans-serif and monospace fallbacks.

## Images

Three original images were generated with ImageGen for this page on 7 September 2026.
The direction is warm editorial photography of an atelier, an archive and a creative
team: walnut, natural stone, afternoon light and candid compositions. No real client,
brand endorsement or documented business event is implied. AI origin is disclosed
in the footer in every language.

| Asset | Original generated file | Export |
| --- | --- | --- |
| `hero` | `exec-114fbd4c-dd0d-4765-b8cf-221b3467e2d7.png` | Studio, materials and people |
| `archive` | `exec-d0de7655-8a58-4aa0-b87e-00be89b25a88.png` | Books and archival shelves |
| `team` | `exec-e092c2bb-2c8f-4dca-a0b4-66f76f9b7d6d.png` | Team collaboration |

Each image has 1536-pixel and 768-pixel WebP exports in `public/landing/images`.
All six exports total approximately 539 KiB. The hero is preloaded; lower images load
lazily. The original PNGs remain in the generation archive.

## Verification

Run `npx vitest run --config vitest.config.mjs tests/js/landing.spec.mjs` for locale
coverage, URL precedence, real asset and anchor destinations, metadata updates,
source switching, keyboard tabs and the mobile menu.

Check the actual Herd page at desktop and mobile widths, including long German and
French labels. Source dialogs support Escape and return focus to the opening control.
Reduced-motion settings disable smooth scrolling and press movement.
