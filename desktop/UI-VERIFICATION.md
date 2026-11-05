# Desktop UI verification — 2026-10-27

The editorial palette follows `frontend/src/styles/editorial.css`: warm paper,
forest green, serif display headings, quiet sans-serif operational controls.
The standalone app uses local Georgia rather than downloading a display font.
Buttons share the web component and extracted `buttons.css`; icons are local
SVG geometry. No native HTTP or IPC capabilities were expanded.

## Verified

- Ten Vitest tests: old histories, tenant/project persistence, pin/archive/
  restore/filter/rename, delete confirmation, failed-question recovery,
  storage-read failure without overwriting history, source opening,
  older-server fallback/retry, reader API context/error propagation, and
  dialog focus/Escape fallback for older WebViews.
- Five existing shared Button tests.
- TypeScript/Vite production build and native macOS release bundle.
- macOS app launched from the resulting `.app`: login renders in WKWebView;
  email/password controls and actions are exposed in the accessibility tree.
- Chromium runtime with explicit API/store fixtures (no production requests):
  chat submission and citation opening, native dialog/Escape, Knowledge source
  opening, light/dark theme, tenant/project selection, login and registration.
- Sessions, Search and Knowledge at 320×568, 390×844, 430×932, 667×375,
  768×1024 and 1100×740: no horizontal document/content overflow; no page errors.
- Source and built HTML pass the Tauri no-zoom static viewport checker.

Screenshots from the fixture review are local at
`/tmp/askmydocs-desktop-review/`. They contain sample data, not live backend
integration evidence. Authenticated production requests were not exercised.

## Native mobile review

Mobile UI review: BLOCKED

Screens: login, registration, workspace picker, Sessions, Search, Knowledge,
source dialog. Landing sync: updated. Source review covers 48px mobile actions,
16px form fields, safe-area padding, reduced motion and dialog focus containment.
Browser viewport checks cover the dimensions above. Native VoiceOver/TalkBack,
large text, software keyboard and real safe-area behavior remain unverified.

Tauri mobile view check: BLOCKED

Target: iOS, Android. No-zoom source/dist: PASS. Native pinch, double-tap,
input-focus and device matrix: not executed. CoreSimulatorService could not be
accessed in this session; Android emulator was not exercised. No mobile package
was signed/uploaded and no TestFlight success is claimed. The mobile skill gate
requires native runtime verification before a distribution build.

## Functional boundary

Session organization remains local and scoped to the existing tenant/project
store keys. Web conversation/folder synchronization, agent sessions and voice
are not part of this desktop refresh. Each chat question still uses the
existing stateless Bearer endpoint independently. Tokens retain the existing
plugin-store persistence behavior.


## Icon / Apple follow-up — 27 October 2026

Neutral icon resources and the macOS app bundle were rebuilt. Native iOS
login rendering is now verified on iPhone 17 (1206×2622) and iPhone 17 Pro Max
(1320×2868), iOS 26.5; screenshots are in `app-store/screenshots/`. The initial
CoreSimulator access issue was resolved by using the approved execution context.
The iOS development compile also required iOS 15 minimum and a project-scoped
SwiftPM native-engine workaround. Full gesture, keyboard, authenticated flow
and accessibility QA is still BLOCKED; no distribution upload was performed.
See `app-store/STATUS.md` for actual remote changes and remaining Apple inputs.
