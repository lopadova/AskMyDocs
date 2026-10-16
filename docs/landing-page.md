# Public editorial landing

Open `/landing?lang=it` on the application host (locally, `https://askmydocsdev.test`).
The public route renders `resources/views/landing.blade.php`; the existing root and sign-in flow
are unchanged. Legacy `/landing/index.html` links redirect here with their language query preserved.

The default visual direction combines a dark editorial palette, sage and brass accents,
painted illustrations, serif headlines and restrained technical details. The product story
addresses organisations and teams; fashion, design and luxury are one application of
the platform, alongside project knowledge, onboarding and hospitality.

## Editing

- `public/landing/i18n.js`: curated Italian, English, French, German and Spanish copy.
- `resources/views/landing.blade.php`: structure and Italian fallback content. Keep fallback
  text aligned with the Italian dictionary when editing copy.
- `public/landing/styles.css`: independent, responsive dark theme. Native buttons use
  the AskMyDocs compatibility contract. The language menu uses the application's shadcn Select.
- `public/landing/theme.css`: bridges the installation design tokens into the public layout;
  its alternate-theme rules preserve the default humanistic rendering.
- `public/landing/app.js`: language selection, keyboard tabs, source dialogs, mobile
  navigation and FAQ rendering. Locale and available languages are published to the React island
  through `#language-select-root` data and custom events.
- `frontend/src/landing-select.tsx`: Vite entry mounting the language Select island. Run the normal
  frontend build after changing its component; the remaining editorial interactions stay in `app.js`.

Language precedence is `?lang=`, the existing `askmydoc.landing.lang` preference,
supported browser languages, then Italian. Selection updates the URL, document title,
description, alternative text and accessible names without reloading the page.

The three interactive examples and their source excerpts are explicitly fictional.
They demonstrate materials, project decisions and onboarding without accessing customer
data or calling a model. Application CTAs open `/app/chat` and require workspace access.
Fonts are loaded from Google Fonts, with local serif, sans-serif and monospace fallbacks.

## Images

The landing shares the application's editorial `knowledge`, `archive` and `community`
subjects. Each subject has matching day/night lighting and responsive WebP exports
at 640 and 1280 pixels. The hero is preloaded; lower images load lazily. AI origin is
disclosed in the footer in every language.

Humanistic assets live in `/editorial`; alternate themes use `/editorial/themes/{theme}`.
Blade resolves the installation theme before rendering and serves night images initially,
matching the original dark landing. `data-editorial-artwork` identifies each subject for
the runtime to keep theme and appearance changes in sync without replacing content.
The header light-mode toggle shares `amd-theme` with the application: it validates the saved
value, defaults to dark, and refreshes on focus or cross-tab changes. Humanistic light uses
the same ivory/sage palette as the application; the original Humanistic dark remains intact.

## Installation design themes

One installation setting controls landing, authentication and the authenticated application.
Its identifiers are `humanistic`, `tech-pro`, `retro-future`, `dynamic-office` and `luxury`.
The light/dark appearance remains independent of this design identity.

Super-admins choose the identity under **System → Appearance** (`/app/system/appearance`).
Selection and day/night previews remain local until **Save platform theme** succeeds.
The public bootstrap and authenticated session read the same singleton setting; browser
preferences cannot override the installation identity. Existing open tabs refresh it on return.

| Theme | Typography | Palette and geometry |
| --- | --- | --- |
| Humanistic | Geist / Instrument Serif | Existing sage, brass and graphite; original geometry |
| Tech Pro | IBM Plex Sans / Space Grotesk | White/blue or navy/ice; precise corners |
| Retro Future | DM Sans / Fraunces | Cream, burnt orange and teal; soft corners |
| Dynamic Office | Manrope | Lime and navy; generous cards |
| Luxury | DM Sans / Cormorant Garamond | Cream, navy and champagne; restrained corners |

`frontend/src/styles/design-themes.css` owns palette, type, radii and content spacing.
Legacy font preferences apply only to Humanistic. The landing consumes these tokens through
its bridge and never imports the application layout stylesheet. New themes must preserve
focus rings, menu labels, mobile targets and reduced-motion behavior.

The four new identities include eight subjects in two lighting variants: 64 AI-generated
scenes exported in AVIF and WebP at 640/1280 px. Their original prompts, master filenames
and night-edit references are recorded in `public/editorial/themes/manifest.json`.
Night editions change lighting while keeping each corresponding daytime scene and subjects.
The existing Humanistic collection remains available at its original URLs.

## Verification

Run `npx vitest run --config vitest.config.mjs tests/js/landing.spec.mjs` for locale
coverage, URL precedence, real asset and anchor destinations, metadata updates,
source switching, keyboard tabs and the mobile menu.

Check the actual Herd page at desktop and mobile widths, including long German and
French labels. Source dialogs support Escape and return focus to the opening control.
Reduced-motion settings disable smooth scrolling and press movement.
