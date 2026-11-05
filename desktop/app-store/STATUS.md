# Apple update — 27 October 2026

## Verified registration

- Existing app: **AskMyDocs - AI Hub**, Apple ID **6782962700**.
- Bundle ID: **com.surfacesrl.askmydocs**; SKU: **askmydocs**.
- Account: Marco Bramato, **Surface Srl**. The user explicitly selected this
  existing company registration instead of creating a personal-account copy.
- iOS and macOS versions: **1.0**, both **Prepare for Submission**.
- Draft-selected iOS build: version/build **0.1.0**, old app icon.
- Latest TestFlight build: **0.1.0 (0.1.0.4)**, **Missing Compliance**.
  Builds 0.1.0, 0.1.0.2 and 0.1.0.3 are expired. An existing internal
  group is present; no duplicate group or tester invitation was created.
- Primary/current localization: **English (UK)** (`en-GB`).

[App information](https://appstoreconnect.apple.com/apps/6782962700/distribution/info)

## Saved remotely

- App name capitalization: **AskMyDocs - AI Hub**.
- Subtitle: **Answers from your documents**.
- Primary category: **Productivity**; secondary: **Reference**.
- iOS and macOS: promotional text, description, keywords and copyright
  from `en-GB.json`; both draft releases set to **manual**.
- iOS screenshot: genuine native simulator capture
  `screenshots/iphone-6.9-login.png`, **1320×2868**, processed and visible as
  **1 of 10 screenshots** under large Dynamic Island iPhones. Apple also
  showed it as an inherited asset in the medium display group.
- A second genuine **1206×2622** iPhone screenshot is prepared locally as
  `screenshots/iphone-6.3-login.png`; its direct upload is not confirmed.

The iOS and macOS text and manual-release settings were read back after navigation;
App Information showed **Saved**. Evidence screenshots are in `evidence/`.
No review submission or public release was performed.

## Local icon and build

- Neutral graphite, ivory and stone-gray two-panel book identity, with the
  user-requested restrained reference to the unfolded iPhone Duo shape.
- Source: `src-tauri/icons/icon-master.png`, **1024×1024 RGB, no alpha**.
- All tracked Tauri platform icons regenerated; local frontend logo updated.
- iOS marketing icon also validated **1024×1024 without alpha**.
- Final macOS `.app` rebuilt successfully with the neutral icon.
- iOS development build compiled successfully after selecting SwiftPM's
  native engine and setting the minimum deployment target to **iOS 15**.
- Native login renders on iPhone 17 and iPhone 17 Pro Max simulators,
  iOS 26.5, using the loopback development server. Captures are genuine.
- Ten desktop tests pass; static no-zoom source/dist checks pass.

## Remaining requirements

1. Apple displayed an updated **Apple Developer Program License Agreement**
   requiring the Account Holder's acceptance before updating/submitting apps.
   This was handed to the user; acceptance is not yet verified.
2. Apple also displayed an expired **Paid Apps agreement**. No contracts,
   financial settings, tax or banking information were modified.
3. Official **Support URL** and **Privacy Policy URL** have not been supplied
   or verified. The support/marketing fields were empty; no URL was invented.
4. Content rights, age-rating and privacy questionnaires remain incomplete.
   Beta feedback/review contact fields and demo access are also unfilled;
   no contact or account credentials were invented.
   Privacy and legal declarations require factual information about the
   operating service; no blanket “data not collected” claim was entered.
5. The new icon has **not** been uploaded in a new iOS build. TestFlight
   processing and tester access for the new identity are **not verified**.
6. Native mobile QA is **BLOCKED** beyond initial login rendering. Keyboard,
   gestures, authenticated screens, accessibility and the full device matrix
   still require verification before distribution.

The [Tauri mobile view skill](/Users/marco/.codex/skills/tauri-mobile-view-check/SKILL.md)
requires: “Non firmare, caricare o pubblicare un pacchetto mobile con uno di
questi due esiti, salvo richiesta esplicita dell’utente dopo avergli mostrato
il rischio concreto.” Initial rendering and successful compilation do not
verify those remaining native interactions. No distribution package was
uploaded under that incomplete QA result.

Apple's icon workflow requires including the icon in a new uploaded build:
[Add an app icon](https://developer.apple.com/help/app-store-connect/manage-app-information/add-an-app-icon/).
