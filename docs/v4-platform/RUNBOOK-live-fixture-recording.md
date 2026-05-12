# RUNBOOK — Live Connector Fixture Recording (v4.5/W5.5)

**Audience**: someone who has NEVER opened that provider's developer console before.

**Goal**: get from zero to a working `.env.live` and a freshly recorded `tests/Fixtures/connectors/<provider>/recorded/` directory in under 30 minutes per provider.

**Scope**: the seven W1-W6 connectors — Google Drive, Notion, Evernote, Fabric, OneDrive, Confluence, **Jira**.

**Out-of-band assumptions** (you have these already):
- PHP 8.3 or 8.4 on PATH (`php --version` works).
- The AskMyDocs repo cloned, `composer install` already run.
- A personal email address you can use to register a *test tenant* on each provider. **Never use your production tenant.**

---

## How recording works (1-minute orientation)

1. You create test-tenant credentials per provider (the bulk of this runbook).
2. You put those credentials in `.env.live` (NEVER commit it — `.gitignore` already excludes it).
3. You run `php vendor/bin/phpunit tests/Live/Connectors/<Provider>LiveTest.php` with `CONNECTOR_RECORD_FIXTURES=1` and `CONNECTOR_<PROVIDER>_LIVE=1` set.
4. The `HttpResponseRecorder` middleware persists every response body to `tests/Fixtures/connectors/<provider>/recorded/<endpoint>-<hash>.json` after PII redaction + identifier scrubbing.
5. You eyeball the recorded JSON to confirm no real names / emails / tokens slipped through.
6. You commit the scrubbed fixtures. The chunker tests load them via `JsonFixtureLoader::load()`.

---

## Provider 1 — Google Drive

### 1.1 Create the Google Cloud project

1. Open https://console.cloud.google.com/ in your browser. Sign in with your Google account.
2. In the top header next to the Google Cloud logo, click the **project selector dropdown** (it says either "Select a project" or the name of your last project).
3. In the dialog that opens, click **"NEW PROJECT"** (top-right, blue button).
4. Set **Project name**: `askmydocs-live-test`. Leave **Organization** as "No organization" if your account isn't part of a Google Workspace org. Click **"CREATE"**. Wait ~20 seconds for the project to spin up.
5. After creation, the top header should auto-switch to `askmydocs-live-test`. If it doesn't, click the project selector and pick it.

### 1.2 Enable the Drive API

1. In the **left sidebar** (click the ☰ hamburger menu at the top-left to reveal it), click **"APIs & Services"** → **"Library"**. The URL becomes https://console.cloud.google.com/apis/library .
2. In the search box, type `Google Drive API` and press Enter.
3. Click the **"Google Drive API"** result card.
4. Click the blue **"ENABLE"** button. Wait ~15 seconds. The page should update to show "API enabled".

### 1.3 Configure the OAuth consent screen

1. Left sidebar → **"APIs & Services"** → **"OAuth consent screen"**.
2. **User Type**: select **External** (Internal is only available with a Workspace org). Click **"CREATE"**.
3. **App information**:
    - App name: `AskMyDocs Live Test`
    - User support email: your email
    - Developer contact email: your email
    - Leave everything else blank. Click **"SAVE AND CONTINUE"**.
4. **Scopes**: click **"ADD OR REMOVE SCOPES"**. Search and tick:
    - `https://www.googleapis.com/auth/drive.readonly` — read-only access to file metadata + content. This is the only scope the live test needs.
    Click **"UPDATE"** at the bottom of the dialog, then **"SAVE AND CONTINUE"**.
5. **Test users**: click **"+ ADD USERS"**. Add your own email address (the one signed in). Click **"ADD"**, then **"SAVE AND CONTINUE"**.
6. Review the summary, click **"BACK TO DASHBOARD"**.

### 1.4 Create the OAuth client credentials

1. Left sidebar → **"APIs & Services"** → **"Credentials"**.
2. Click **"+ CREATE CREDENTIALS"** (top of the page) → **"OAuth client ID"**.
3. **Application type**: **Desktop app**.
4. **Name**: `askmydocs-live-test-desktop`. Click **"CREATE"**.
5. A modal opens with the **Client ID** and **Client secret**. Copy both. Click **"DOWNLOAD JSON"** for safekeeping.

### 1.5 Mint an access token for the live test

The Drive live test needs an OAuth access token (not a refresh token loop). Quick path with the OAuth Playground:

1. Open https://developers.google.com/oauthplayground/
2. Click the gear icon (top-right) → tick **"Use your own OAuth credentials"**. Paste your Client ID and Client secret. Close the panel.
3. In the left column, scroll to **"Drive API v3"** → tick `https://www.googleapis.com/auth/drive.readonly`.
4. Click **"Authorize APIs"** (blue button). Sign in with the test-user email you added in step 1.3.5. Approve the consent.
5. You land back on the Playground with **Step 2: Exchange authorization code for tokens**. Click **"Exchange authorization code for tokens"**.
6. The **Access token** appears. Copy it. (It expires in ~1 hour; rotate per recording session.)

### 1.6 Write credentials to `.env.live`

In the repo root:

```bash
cp .env.example .env.live   # if you don't already have it
```

Add (or update) these lines in `.env.live`:

```
CONNECTOR_GOOGLE_DRIVE_LIVE=1
CONNECTOR_GOOGLE_DRIVE_TOKEN=ya29.your-access-token-from-step-1.5
CONNECTOR_RECORD_FIXTURES=1
```

### 1.7 Verify

```bash
curl -s "https://www.googleapis.com/drive/v3/files?pageSize=1" \
  -H "Authorization: Bearer $CONNECTOR_GOOGLE_DRIVE_TOKEN"
```

Expected output (real file IDs will differ):
```json
{"kind":"drive#fileList","incompleteSearch":false,"files":[{"id":"1abc...","name":"some-file","mimeType":"application/vnd.google-apps.document"}]}
```

If you see `401 unauthorized`: your access token expired — re-run step 1.5.

### 1.8 Record

```bash
set -a; source .env.live; set +a
php vendor/bin/phpunit tests/Live/Connectors/GoogleDriveLiveTest.php --testdox
```

Inspect the new fixture under `tests/Fixtures/connectors/google_drive/recorded/`. **Spot-check for any real email, drive file name, or personal data — the scrubber catches IDs but NOT free-text fields**. Edit by hand before committing.

### 1.9 Common errors

- `403 access_denied — User has not granted the application "..." access to scope` — Test-user not added (step 1.3.5) or scope mismatch (step 1.3.4).
- `Token has been revoked` — Re-do step 1.5; tokens from OAuth Playground are short-lived.

---

## Provider 2 — Notion

### 2.1 Create the Notion integration

1. Open https://www.notion.so/my-integrations in your browser. Sign in.
2. Click **"+ New integration"** (top-left).
3. **Basic Information**:
    - Name: `AskMyDocs Live Test`
    - Logo: skip
    - Associated workspace: pick a workspace where you can create throwaway pages
4. **Capabilities** (most important):
    - **Content Capabilities**: tick **Read content**. Leave **Update content** and **Insert content** unticked — we only need read for fixture recording.
    - **Comment Capabilities**: tick **Read comments**.
    - **User Capabilities**: select **Read user information including email addresses**.
5. Click **"Submit"**. The integration page appears.

### 2.2 Capture the secret

1. Under **Internal Integration Secret**, click **"Show"**. Copy the value (starts with `secret_` or `ntn_`).

### 2.3 Share at least one page with the integration

Notion integrations only see pages explicitly shared with them. Do this once.

1. In your Notion workspace, open or create a page named `AskMyDocs Live Test` (any content — paste a few paragraphs).
2. Top-right corner of that page click **"..."** (three dots) → scroll to **"Add connections"** → search for `AskMyDocs Live Test` → click it. Confirm.
3. The integration can now read this page (and any sub-pages).

### 2.4 Write credentials

In `.env.live`:
```
CONNECTOR_NOTION_LIVE=1
CONNECTOR_NOTION_TOKEN=secret_your-integration-secret
```

### 2.5 Verify

```bash
curl -s https://api.notion.com/v1/users \
  -H "Authorization: Bearer $CONNECTOR_NOTION_TOKEN" \
  -H "Notion-Version: 2022-06-28"
```

Expected (truncated):
```json
{"object":"list","results":[{"object":"user","id":"...","name":"...","type":"bot",...}]}
```

If `401 unauthorized — API token is invalid`: re-copy the secret in step 2.2.

### 2.6 Record

```bash
set -a; source .env.live; set +a
php vendor/bin/phpunit tests/Live/Connectors/NotionLiveTest.php --testdox
```

### 2.7 Common errors

- `404 path not found — Could not find page with ID` — Page not shared with the integration (step 2.3).
- `403 restricted_resource` — The integration's capabilities don't include the action you tried; revisit step 2.1.4.

---

## Provider 3 — Confluence (Atlassian Cloud)

### 3.1 Create the test workspace

1. Open https://www.atlassian.com/try/cloud/signup?bundle=confluence in your browser.
2. Sign up with your test email. Pick a workspace URL (e.g. `askmydocs-test.atlassian.net`).
3. Confirm via email, complete the setup wizard, create at least one **Space** (sidebar → "+ Create" → "Space" → "Blank space" → name it `ENGINEERING`).
4. Create one **Page** inside that space (any content).

### 3.2 Create an API token

1. Open https://id.atlassian.com/manage-profile/security/api-tokens in your browser.
2. Click **"Create API token"**. Label: `askmydocs-live-test`. Click **"Create"**.
3. Copy the token IMMEDIATELY — you can't view it again.

### 3.3 Find your Cloud ID

1. Visit https://api.atlassian.com/oauth/token/accessible-resources after signing in via OAuth — OR — easier path: visit your Confluence instance, click **"..."** (top-right next to your avatar) → **"Settings"** → **"Site administration"**. Your Cloud ID appears in the URL: `https://admin.atlassian.com/s/<CLOUD_ID>/...`.

### 3.4 Write credentials

`.env.live`:
```
CONNECTOR_CONFLUENCE_LIVE=1
CONNECTOR_CONFLUENCE_TOKEN=your-api-token-from-step-3.2
CONNECTOR_CONFLUENCE_CLOUD_ID=your-cloud-id-from-step-3.3
```

The live test (`ConfluenceLiveTest`) hits the Cloud OAuth path via
`Authorization: Bearer <token>` against
`https://api.atlassian.com/ex/confluence/<CLOUD_ID>/wiki/api/v2/...`.
An Atlassian Cloud API token issued under
`https://id.atlassian.com/manage-profile/security/api-tokens` is
accepted on this endpoint without the email pair — the recorder
relies on this single-credential path. If you have a workflow that
requires the legacy Basic-auth pair (`email:token`), that is a
separate path not exercised by the live test; do NOT add
`CONNECTOR_CONFLUENCE_EMAIL` to `.env.live` expecting it to be
picked up.

### 3.5 Verify

```bash
curl -s "https://api.atlassian.com/ex/confluence/$CONNECTOR_CONFLUENCE_CLOUD_ID/wiki/api/v2/spaces?limit=1" \
  -H "Authorization: Bearer $CONNECTOR_CONFLUENCE_TOKEN" \
  -H "Accept: application/json"
```

Expected: `{"results":[{"id":"...","key":"ENGINEERING","name":"Engineering","type":"global",...}]}`

If `401`: token expired (90-day rotation policy) — repeat step 3.2.

### 3.6 Record

```bash
set -a; source .env.live; set +a
php vendor/bin/phpunit tests/Live/Connectors/ConfluenceLiveTest.php --testdox
```

### 3.7 Common errors

- `404 Not Found` on `/wiki/api/v2/spaces` — Cloud ID wrong. Re-check step 3.3.
- `401` with a body containing `OAUTH2_LOGIN_REQUIRED` — you're hitting the OAuth-only endpoint with a Basic token; some endpoints require OAuth. The live test only hits OAuth-compatible v2 endpoints — if you see this, file an issue.

---

## Provider 3b — Atlassian Jira Cloud

Same Atlassian workspace as Confluence — Jira and Confluence share the OAuth 2.0 3LO surface and `cloudId`. If you already finished the Confluence setup, you can reuse the same workspace, but you must create a **new OAuth 2.0 (3LO) app** scoped to Jira (Atlassian's developer console scopes one app per product family) and re-run the recording step against the Jira endpoints.

### 3b.1 Create the test workspace (skip if you finished Confluence)

1. Open https://www.atlassian.com/try/cloud/signup?bundle=jira-software in your browser.
2. Sign up with your test email. Pick a workspace URL (e.g. `askmydocs-test.atlassian.net`).
3. Confirm via email, complete the setup wizard, create at least one **Project** (sidebar → "+ Project" → pick the "Scrum" template → name it `Sample Project`, key `PROJ`).
4. Create **one Issue** inside that project (issue type Bug, any summary, any description).
5. Add **one Comment** to that issue so the live recording captures the comments-appendix path.

### 3b.2 Create an OAuth 2.0 (3LO) integration app

1. Open https://developer.atlassian.com/console/myapps/ in your browser. Sign in with the same Atlassian id you used in step 3b.1.
2. Click **"Create"** → **"OAuth 2.0 integration"**. Name: `askmydocs-jira-live-test`.
3. In the left sidebar of the new app: click **"Permissions"** → next to **"Jira API"** click **"Add"**. After it appears, click **"Configure"** and enable these three scopes (each is a Jira API permission documented at https://developer.atlassian.com/cloud/jira/platform/scopes-for-oauth-2-3LO-and-forge-apps/):
   - `read:jira-work` — read issues, projects, fields. This is the primary scope; without it, the live test gets 403 on every `/search` call.
   - `read:jira-user` — read user display names + email (when not GDPR-hidden). Needed so the connector can populate the assignee/reporter slots in the frontmatter.
   - `offline_access` — required for refresh tokens. Without it, every access token expires after 1h and the connector can't sync past the first ingest.
4. In the left sidebar: **"Authorization"** → **"OAuth 2.0 (3LO)"** → set the callback URL to `https://localhost/callback` (or your AskMyDocs host's `APP_URL` + `/api/admin/connectors/jira/oauth/callback` if you're testing the full install flow). Click **"Save changes"**.
5. In the left sidebar: **"Settings"** → copy the **"Client ID"** and **"Secret"**. Treat the Secret as a password.

### 3b.3 Get an access token (OAuth 3LO interactive flow)

Atlassian doesn't issue static API tokens for the OAuth 3LO path — you need to complete the authorisation handshake once and capture the resulting access token.

1. Visit (replace `<CLIENT_ID>` and `<REDIRECT_URI>`):
   ```
   https://auth.atlassian.com/authorize?audience=api.atlassian.com&client_id=<CLIENT_ID>&scope=read:jira-work%20read:jira-user%20offline_access&redirect_uri=<REDIRECT_URI>&state=runbook&response_type=code&prompt=consent
   ```
2. Authorise the workspace from step 3b.1. You'll land on your `<REDIRECT_URI>` with `?code=<CODE>&state=runbook`. Copy the `<CODE>`.
3. Exchange the code for tokens:
   ```bash
   curl -X POST -H "Content-Type: application/json" \
     -d '{"grant_type":"authorization_code","client_id":"<CLIENT_ID>","client_secret":"<CLIENT_SECRET>","code":"<CODE>","redirect_uri":"<REDIRECT_URI>"}' \
     https://auth.atlassian.com/oauth/token
   ```
   Expected output: `{"access_token":"...","refresh_token":"...","expires_in":3600,"scope":"...","token_type":"Bearer"}`.
4. Copy the `access_token` — this is your `CONNECTOR_JIRA_TOKEN`. (Auto-expires in 1h; for repeat recording sessions, save the refresh_token and exchange it before each run.)

### 3b.4 Find your Cloud ID

```bash
curl -H "Authorization: Bearer $CONNECTOR_JIRA_TOKEN" \
     -H "Accept: application/json" \
     https://api.atlassian.com/oauth/token/accessible-resources
```

Expected output (excerpt):
```json
[{"id":"abc12345-...","url":"https://askmydocs-test.atlassian.net","scopes":["read:jira-work","read:jira-user","offline_access"]}]
```

Copy the `id` of the resource whose scopes include `read:jira-*`. That's `CONNECTOR_JIRA_CLOUD_ID`.

### 3b.5 Write credentials

`.env.live`:
```
CONNECTOR_JIRA_LIVE=1
CONNECTOR_JIRA_TOKEN=eyJraWQiOiJh...
CONNECTOR_JIRA_CLOUD_ID=abc12345-6789-0abc-defg-h0123456789a
```

### 3b.6 Verify

```bash
curl -H "Authorization: Bearer $CONNECTOR_JIRA_TOKEN" \
     -H "Accept: application/json" \
     "https://api.atlassian.com/ex/jira/$CONNECTOR_JIRA_CLOUD_ID/rest/api/3/myself"
```

Expected output: `{"accountId":"...","emailAddress":"you@example.test","displayName":"...","active":true,...}`.

If `401`: access token expired (60-min default). Re-run step 3b.3, or use the saved refresh_token to mint a fresh access_token.

If `403`: the OAuth app doesn't have the workspace installed on it. Visit `https://admin.atlassian.com/s/$CONNECTOR_JIRA_CLOUD_ID/connected-apps` and confirm your app appears in the list. If not, redo step 3b.3 (the authorize URL is what installs the app onto the workspace).

### 3b.7 Record

```bash
set -a; source .env.live; set +a
php vendor/bin/phpunit tests/Live/Connectors/JiraLiveTest.php --testdox
```

The five test methods record:
- `/myself` — current user (cloud_id resolution sanity check)
- `/project/search` — paginated project listing
- `/search` — JQL-driven issue listing with `order by updated DESC`
- `/search` again — incremental sync shape with `updated >= "YYYY-MM-DD HH:mm"`
- `/oauth/token/accessible-resources` — workspace metadata

### 3b.8 Common errors

- `400 invalid_grant` on token exchange — code already consumed (single-use) or expired (10 min). Restart from step 3b.3.
- `400 Unable to parse JQL` on `/search` — you passed an ISO-8601 timestamp (`2026-05-12T08:30:15Z`) instead of the Jira-specific `"YYYY-MM-DD HH:mm"` format. The connector handles the wire format automatically via `JqlBuilder::updatedSince()` — but operator-issued ad-hoc JQL via curl must follow the same rule.
- `403 Forbidden` on a v3 endpoint that worked in v2 — some endpoints require an extra granular scope (e.g. `read:issue-details:jira`). Check the API doc page for the endpoint to confirm the exact granular scope, then add it to your app's permissions list and redo step 3b.3.
- `429 Too Many Requests` — Atlassian rate-limits unauthenticated and lightly-authenticated callers. The live test makes only 5 requests; if you see 429s you likely have a runaway script somewhere. Wait 60s and retry.

---

## Provider 4 — Evernote

Evernote uses a separate developer sandbox so your real notes are NEVER touched.

### 4.1 Get a sandbox developer token

1. Open https://sandbox.evernote.com/Registration.action in your browser. Create a sandbox account (separate from any real Evernote account you have). The sandbox is free and isolated.
2. Confirm via email.
3. Open https://sandbox.evernote.com/api/DeveloperToken.action while logged into the sandbox. Click **"Create a developer token"**. Copy the token.

### 4.2 Create at least one note in the sandbox

In the sandbox web UI, create one notebook + one note with a few tags. This gives the live test something to record.

### 4.3 Write credentials

`.env.live`:
```
CONNECTOR_EVERNOTE_LIVE=1
CONNECTOR_EVERNOTE_TOKEN=S=s1:U=...:E=...:C=...:P=...:A=...:V=2:H=...
CONNECTOR_EVERNOTE_BASE=https://sandbox.evernote.com
```

### 4.4 Verify

Evernote is Thrift-based on the production API but exposes a REST shim on the sandbox. The verification call:

```bash
curl -s "https://sandbox.evernote.com/shard/s1/v2/users/me" \
  -H "Authorization: Bearer $CONNECTOR_EVERNOTE_TOKEN"
```

Expected: `{"id":12345,"username":"yourSandboxUser","email":"...","name":"...","timezone":"...",...}`

If `401 oauth_problem=token_expired`: tokens are 1-year valid; just regenerate in step 4.1.

### 4.5 Record

```bash
set -a; source .env.live; set +a
php vendor/bin/phpunit tests/Live/Connectors/EvernoteLiveTest.php --testdox
```

### 4.6 Common errors

- `Authentication failed: invalidAuth` — token mistyped; copy-paste the full single-line string from step 4.1.
- `Thrift compaction error` — you're hitting a Thrift endpoint instead of the REST shim. The live test only uses REST shim URLs — if you hit this, the test framework is mis-targeted; file an issue.

---

## Provider 5 — Fabric

Fabric (fabric.so) is a smaller commercial product. The live test path uses API keys, not OAuth.

### 5.1 Create a Fabric workspace + API key

1. Open https://app.fabric.so/signup. Sign up with your test email.
2. After onboarding, in the workspace settings sidebar → **"Developers"** → **"API Keys"** → **"Generate new key"**.
3. **Scopes** to grant:
    - `notes:read` — read notes (only scope the test needs).
4. Copy the API key. Note the **Workspace ID** displayed next to the key (you'll need both).

### 5.2 Create one note

In the Fabric web UI, create at least one note in the default collection. Add a couple of tags.

### 5.3 Write credentials

`.env.live`:
```
CONNECTOR_FABRIC_LIVE=1
CONNECTOR_FABRIC_API_KEY=fab_your-api-key
CONNECTOR_FABRIC_WORKSPACE_ID=your-workspace-id
CONNECTOR_FABRIC_API_BASE=https://api.fabric.so/v1
```

### 5.4 Verify

```bash
curl -s "$CONNECTOR_FABRIC_API_BASE/notes?limit=1" \
  -H "X-Api-Key: $CONNECTOR_FABRIC_API_KEY" \
  -H "X-Fabric-Workspace-Id: $CONNECTOR_FABRIC_WORKSPACE_ID" \
  -H "Accept: application/json"
```

Expected: `{"notes":[{"id":"...","title":"...","tags":[...],...}],"next_cursor":null}`

If `403 invalid_workspace`: workspace ID doesn't match the API key — both must come from the same workspace.

### 5.5 Record

```bash
set -a; source .env.live; set +a
php vendor/bin/phpunit tests/Live/Connectors/FabricLiveTest.php --testdox
```

### 5.6 Common errors

- `401 invalid_api_key` — Key revoked or copied with stray whitespace. Regenerate.
- `429 rate_limited` — Default plan is 60 req/min; the test only makes ~5 calls but if you re-run rapidly you can hit the cap. Wait 1 minute.

---

## Provider 6 — OneDrive (Microsoft Graph)

### 6.1 Register the Azure app

1. Open https://portal.azure.com/ and sign in (free MSA account works).
2. Top search bar: type `App registrations`, click the result.
3. Click **"+ New registration"** (top of page).
4. **Name**: `askmydocs-live-test`.
5. **Supported account types**: select **"Personal Microsoft accounts only"** (simpler; pick "Multitenant" if you need org accounts).
6. **Redirect URI**: select **"Public client/native (mobile & desktop)"** from the dropdown → put `http://localhost`.
7. Click **"Register"**. The app overview page opens.

### 6.2 Configure API permissions

1. In the app overview, left submenu → **"API permissions"** → **"+ Add a permission"** → **"Microsoft Graph"** → **"Delegated permissions"**.
2. Search and tick:
    - `Files.Read` — read user files (only scope the test needs).
    - `User.Read` — read basic profile (required by the Graph baseline).
3. Click **"Add permissions"**.
4. If you see a yellow warning **"Grant admin consent for ..."** — click it and approve (personal accounts don't actually need this but the prompt persists).

### 6.3 Mint an access token via device-code flow

The cleanest non-interactive path on a dev machine:

1. Note your **Application (client) ID** from the app overview page (top section).
2. Run:
    ```bash
    curl -sX POST "https://login.microsoftonline.com/consumers/oauth2/v2.0/devicecode" \
      -H "Content-Type: application/x-www-form-urlencoded" \
      -d "client_id=YOUR_CLIENT_ID" \
      -d "scope=Files.Read User.Read offline_access"
    ```
3. The response contains a `user_code` and `verification_uri`. Open https://microsoft.com/devicelogin in a browser, paste the user_code, sign in with your Microsoft account, approve.
4. Run (within 15 minutes, replacing `DEVICE_CODE` with `device_code` from step 2):
    ```bash
    curl -sX POST "https://login.microsoftonline.com/consumers/oauth2/v2.0/token" \
      -H "Content-Type: application/x-www-form-urlencoded" \
      -d "client_id=YOUR_CLIENT_ID" \
      -d "grant_type=urn:ietf:params:oauth:grant-type:device_code" \
      -d "device_code=DEVICE_CODE"
    ```
5. Response contains `access_token`. Copy it (valid ~1 hour).

### 6.4 Upload one file

In the OneDrive web UI (https://onedrive.live.com), upload a small `.docx` to the root or a `Test` folder. This gives the live test something to read.

### 6.5 Write credentials

`.env.live`:
```
CONNECTOR_ONEDRIVE_LIVE=1
CONNECTOR_ONEDRIVE_TOKEN=eyJ0eXAiOiJKV1Qi...your-access-token
```

### 6.6 Verify

```bash
curl -s "https://graph.microsoft.com/v1.0/me/drive/root/children?\$top=1" \
  -H "Authorization: Bearer $CONNECTOR_ONEDRIVE_TOKEN"
```

Expected: `{"@odata.context":"...","value":[{"id":"...","name":"...","file":{"mimeType":"..."},...}]}`

If `401 InvalidAuthenticationToken — Access token is empty`: the token expired (~1 hour life). Repeat step 6.3.

### 6.7 Record

```bash
set -a; source .env.live; set +a
php vendor/bin/phpunit tests/Live/Connectors/OneDriveLiveTest.php --testdox
```

### 6.8 Common errors

- `401 InvalidAuthenticationToken — Access token validation failure` — Token doesn't match the scopes; re-do step 6.3 making sure `scope=Files.Read User.Read offline_access` is intact.
- `403 accessDenied` — Trying to read a file your account doesn't own — only the test-user's own drive is accessible via `/me/drive`.

---

## After recording — verification + commit

For **each** provider you recorded:

1. **List the new fixtures**:
   ```bash
   ls tests/Fixtures/connectors/<provider>/recorded/
   ```

2. **Verify no real data leaked** (≥ 30 seconds per file — this is the most important step):
   ```bash
   cat tests/Fixtures/connectors/<provider>/recorded/<file>.json | less
   ```
   Look for: real names, real emails not under example.com, real IBANs/phone numbers, real file titles that identify a customer. The scrubber catches IDs but NOT free-text. Hand-edit if anything personal remains.

3. **Stage + commit**:
   ```bash
   git add tests/Fixtures/connectors/<provider>/recorded/
   git commit -m "test(v4.5/W5.5): record real <provider> response fixtures"
   ```

4. **Wipe `.env.live` before pushing**:
   ```bash
   # Linux (GNU coreutils — shred is NOT native on macOS):
   shred -uvz .env.live

   # macOS (BSD rm with -P overwrites 3× before unlink):
   rm -P .env.live

   # Windows (PowerShell):
   Remove-Item .env.live -Force
   ```
   `.gitignore` already excludes it, but defense-in-depth.

---

## Refreshing fixtures when a vendor changes its API shape

Same flow as the first-time recording — re-run the relevant `tests/Live/Connectors/<Provider>LiveTest.php` after rotating credentials. Recorder OVERWRITES existing fixtures with the same endpoint hash, so you don't need to delete the old ones first. Git diff will show the shape changes.

---

## Maintainer checklist for adding a new connector to v4.6+

1. Add a new `LiveConnectorTestCase` subclass under `tests/Live/Connectors/<NewProvider>LiveTest.php`.
2. Add the gate env-var to `.github/workflows/live-recording-nightly.yml`.
3. Add a hand-crafted baseline under `tests/Fixtures/connectors/<new>/hand-crafted/*.sample.json`.
4. Add a NEW section to this runbook with the same precision standard (7-9 numbered steps + verify one-liner + common errors).
5. Pin the section in `tests/Feature/Live/LiveScaffoldingTest::every_provider_ships_at_least_one_hand_crafted_sample`.
