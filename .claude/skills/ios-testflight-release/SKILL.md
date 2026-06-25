---
name: ios-testflight-release
description: Build, version-bump, sign and upload the AskMyDocs Desktop Tauri app (desktop/) to TestFlight / App Store Connect. Codifies the exact flow that shipped build 0.1.0 on 2026-06-23 — `tauri ios build --export-method app-store-connect`, automatic distribution signing, the transient export-error retry, and the Xcode Organizer GUI upload. Trigger when the user asks to "send/upload the app to TestFlight", "build the iOS app", "ship a new beta", bump the iOS version/build number, or distribute desktop/ to Apple.
---

# iOS / TestFlight release (AskMyDocs Desktop)

The desktop client lives in `desktop/` (Tauri v2 + React, its own `package.json`
and Rust crate — NOT wired into the Laravel CI). This skill is the proven path
to get a build into TestFlight. Build `0.1.0 (0.1.0)` shipped this way on
2026-06-23.

## Known-good facts (from the shipping build)

| Thing | Value |
|---|---|
| Working dir | `desktop/` |
| Bundle id | `com.surfacesrl.askmydocs` |
| Team | `8749Q438Y6` (Surface Srl) — `tauri.conf.json` → `bundle.iOS.developmentTeam` |
| Version (CFBundleShortVersionString) | `tauri.conf.json` → `version` |
| Build number (CFBundleVersion) | `--build-number N` flag (defaults to the version) |
| IPA output | `desktop/src-tauri/gen/apple/build/arm64/AskMyDocs Desktop.ipa` |
| Archive | `desktop/src-tauri/gen/apple/build/desktop_iOS.xcarchive` |
| Default backend | `https://askmydocs.surfacesrl.com` (see `desktop/src/lib/api.ts`) |

## Prerequisites — verify BEFORE building (these are what actually bit us)

1. **Xcode + iOS Rust targets + tauri-cli present**
   ```bash
   xcodebuild -version
   rustup target list --installed | grep ios   # aarch64-apple-ios at minimum
   cd desktop && bunx tauri --version
   ```
2. **Node deps installed** (the build's `beforeBuildCommand` runs `npm run build`):
   ```bash
   cd desktop && bun install      # or: npm install
   ```
3. **THE BIG ONE — Developer Program team membership.** Xcode must be logged in
   with an Apple ID that is a **member of the Developer Program team
   `8749Q438Y6`** — *App Store Connect user access is NOT enough* (signing needs
   the Developer Program team, a separate thing). Verify the team is visible:
   ```bash
   defaults read com.apple.dt.Xcode IDEProvisioningTeamByIdentifier 2>/dev/null \
     | grep -E "teamID|teamName"
   ```
   You MUST see `teamID = 8749Q438Y6;  teamName = "Surface Srl"`. If you only see
   the personal team, the build fails with
   `error: No Account for Team "8749Q438Y6"` → the user must accept the team
   invite at <https://developer.apple.com/account> → People (or log the correct
   Apple ID into Xcode → Settings → Accounts). This is a user action; do not
   loop the build until the team appears.
4. **App record exists on App Store Connect** for `com.surfacesrl.askmydocs`
   (My Apps). Without it the upload fails "no app record".

## Build + sign (one command)

```bash
cd desktop
# First/repeat upload: ALWAYS bump the build number — ASC rejects a duplicate
# (version, build) pair. Build 0.1.0 used build number 0.1.0; next must differ.
bunx tauri ios build --export-method app-store-connect --build-number <N>
```

- `--export-method app-store-connect` → IPA for App Store / TestFlight.
- Distribution cert + Store provisioning profile are created automatically
  during export (no manual cert handling). The cert is minted into a temp
  keychain, so `security find-identity` may still only show "Apple Development"
  — that is fine; verify the IPA instead (below).
- Run it in the background and poll (`bunx tauri ios build` can be long on a
  cold cargo cache); a warm cache finishes in ~1 min.

### Transient export failure — retry ONCE (R42)

If export fails with:
```
error: exportArchive The Internet connection appears to be offline.
error: exportArchive No profiles for 'com.surfacesrl.askmydocs' were found
```
…and `** BUILD SUCCEEDED **` appeared just above it, this is the well-known
misleading xcodebuild message (provisioning handshake blip), NOT a real network
or membership problem. Re-run the exact same command once — it succeeded on the
second try for build 0.1.0. (Only stop and surface to the user if the failure is
`No Account for Team` — that is the membership prereq, not transient.)

## Verify the IPA is App-Store-signed (do this before uploading)

```bash
cd desktop/src-tauri/gen/apple/build
TMP=$(mktemp -d); cd "$TMP"
unzip -q "$OLDPWD/arm64/AskMyDocs Desktop.ipa"
APP=$(ls -d Payload/*.app)
codesign -dvv "$APP" 2>&1 | grep -iE "Authority|TeamIdentifier"
security cms -D -i "$APP/embedded.mobileprovision" > p.plist 2>/dev/null
/usr/libexec/PlistBuddy -c "Print :Name" p.plist                 # → "...Store Provisioning Profile"
/usr/libexec/PlistBuddy -c "Print :Entitlements:get-task-allow" p.plist  # → false
/usr/libexec/PlistBuddy -c "Print :ProvisionedDevices" p.plist 2>&1 | head -1  # should error (no such key) for App Store
rm -rf "$TMP"
```
Expect: `Authority=Apple Distribution: Surface Srl (8749Q438Y6)`,
profile name contains **Store**, `get-task-allow = false`, **no**
`ProvisionedDevices` key. If you see "Apple Development" / a key list of devices,
it is a dev/ad-hoc build and ASC will reject it.

## Upload

Pick by what credentials exist on the machine.

### Path A — Xcode Organizer GUI (what we used; no API key/password needed)

Uses the logged-in Xcode account session. The tauri archive lives at a custom
path, so load it into Organizer explicitly:
```bash
open "desktop/src-tauri/gen/apple/build/desktop_iOS.xcarchive"
```
Then in Organizer (drivable via computer-use; Xcode is tier-"click", so
left-clicks only — no typing):
1. **Distribute App** → method **App Store Connect** → **Distribute**.
2. It goes Preparing → Uploading → "Sending analysis…" → "Waiting for App Store
   Connect analysis response…" (this last wait can take **several minutes** —
   keep waiting, it is server-side).
3. Finishes at **"Upload completed with warnings"** → **Done**. The two warnings
   (*"A non-validation error occurred during validation. Skipping validation."*
   and *"The entity has been replaced …"*) are **benign** — the binary was
   accepted. Organizer status flips to **"Uploaded"**.
   No 2FA prompt appeared because the account session was already established; if
   one does pop, the user types the code (Xcode tier-click blocks typing).

### Path B — CLI (faster, needs a credential)

```bash
IPA="desktop/src-tauri/gen/apple/build/arm64/AskMyDocs Desktop.ipa"
# App-specific password (appleid.apple.com → Sign-In and Security):
xcrun altool --upload-app -f "$IPA" -t ios -u <apple-id> -p "<app-specific-password>"
# OR App Store Connect API key (.p8 in ~/.appstoreconnect/private_keys/):
xcrun altool --upload-app -f "$IPA" -t ios --apiKey <KEY_ID> --apiIssuer <ISSUER_ID>
```
Interactive Xcode login alone does NOT authenticate altool — that is why Path A
(or an explicit credential) is required.

## After upload

1. Apple **processes** the build (minutes → ~1h): App Store Connect → My Apps →
   AskMyDocs Desktop → **TestFlight** tab.
2. **Export compliance**: first build asks the encryption question. The app uses
   only standard HTTPS/TLS → answer "uses only exempt encryption". To skip the
   prompt on every future upload, add `ITSAppUsesNonExemptEncryption=false` to
   the iOS Info.plist.
3. **Testers**: internal testers (team members) get it right after processing.
   External testers need a Beta App Review.

## Gotchas (ranked by how much time they cost us)

- **App Store Connect user ≠ Developer Program team member.** Signing failed
  with `No Account for Team "8749Q438Y6"` until the Apple ID was actually added
  to the *Developer Program* team and the invite accepted. Verify with the
  `defaults read … IDEProvisioningTeamByIdentifier` check above — do not retry
  the build blindly.
- **"Internet connection appears to be offline" at export is transient.** Retry
  once (R42); don't chase a network/cert rabbit hole.
- **Bump the build number every upload.** ASC rejects a duplicate
  `(CFBundleShortVersionString, CFBundleVersion)`. Use `--build-number N`.
- **Production backend.** The IPA defaults `API_BASE` to
  `https://askmydocs.surfacesrl.com`; testers hit the real backend — ensure
  prod has the Bearer endpoints (`/api/auth/token`, `/api/kb/chat`, …) and the
  `personal_access_tokens` table migrated, or login fails.
- **Don't commit build output.** `desktop/.gitignore` ignores `dist/`,
  `node_modules`, and `src-tauri/gen/apple/` — never add IPAs/archives to git.
