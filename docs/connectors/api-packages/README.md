# Portable API connector packages

Contract: **configuration v1**, format `askmydocs.api-connector-config`.
The existing JSON v1 format remains supported. ZIP is a transport envelope for
that same configuration, not a separate plugin or executable extension.

- [Canonical JSON Schema, Draft 2020-12](v1/connector.schema.json)
- [Complete example](v1/connector.example.json): two read-only tools and a list/detail relation
- [External agent handoff](../../handoff/api-connector-packages.md)

The schema describes the canonical shape. The rules below add reference,
credential, runtime and ZIP constraints checked by the application. Both apply.
Do not change the meaning of v1 fields. A future incompatible contract requires
a new integer version, schema and explicit migration. Unknown versions fail
with HTTP 422 before writes; they are never guessed or silently downgraded.

## Configuration contract

UTF-8 JSON, one root object, at most 5 MiB (5,242,880 bytes), nesting depth at
most 64. Use integer `1` for `_meta.version` and the exact format string above.
Do not generate YAML, OpenAPI documents or endpoint-workspace `config` envelopes.
Translate the user's endpoint specifications into this portable envelope.

| Root field | Meaning |
| --- | --- |
| `_meta` | Required `{ "format": "askmydocs.api-connector-config", "version": 1 }` |
| `connector` | Required container; `name` required, max 128 characters. Optional `description`, `project_key` (max 100), `base_url` (max 2048), `headers`, `secret_headers`, `is_active` (default true). |
| `default_auth_profile_ref` | Optional nullable reference to a profile in this file; no default means unauthenticated unless a route selects one. |
| `auth_profiles` | Required list, including when empty; at most 100 profiles. |
| `routes` | Required list, including when empty; at most 1000 endpoints, 500 parameters per endpoint, 10000 parameters in total. |
| `relations` | Required list, including when empty; at most 1000 relations. |

References and slugs are unique within the file. Profile references match
`^auth-[a-zA-Z0-9_-]+$` (max 128); route references are arbitrary nonempty strings
(max 128); use `orders-list`, for example. These are portable file references,
not local row IDs. Slugs match `^[a-zA-Z0-9_]+$` (max 96).

Unknown structural fields fail validation, including code, scripts and unsupported
transform options. For compatibility with the former v1 importer, local metadata
`id`, `tenant_id`, `created_at`, `updated_at`, `api_connector_id`, `auth_profile_id`
on connector/profile/route/parameter/relation objects is discarded before
validation. Canonical generators must omit it. Numeric relation IDs, history,
test payloads and other unknown fields are not accepted.

The canonical schema uses JSON objects for maps. PHP's historical empty maps
may be exported as `[]`; the importer also accepts that empty representation.
Use `{}` when generating a map. Lists must be sequential arrays, never objects
keyed by IDs. Application validation also accepts Laravel's usual boolean/integer
form representations; generators must use the JSON types in the schema.

### Authentication and secrets

Every profile requires `ref` and `type`. Optional fields are `config`,
`public_credentials`, `secret_fields` and `secret_headers`. The latter two are
lists of names, **never values**, at most 100 each. Credential keys other than the reserved `headers` key match
`^[a-zA-Z0-9_-]+$` (max 128); header names must be valid HTTP tokens.

| Type | Public fields allowed | Credentials requested at import | Supported nonsecret config |
| --- | --- | --- | --- |
| `none` | none | only declared secret parameters/fields | none needed |
| `api_key` | none | `api_key`, or legacy `key` when declared in `secret_fields` | `in`: `header` (default) or `query`; `name`: defaults to `X-API-Key` |
| `bearer` | none | `token`, or legacy `access_token` when declared | none needed; uses `Authorization: Bearer …` |
| `basic` | `username` | `password`, plus `username` when not supplied publicly | none needed |
| `custom` | none | each `secret_headers` entry; if no fields/headers declared, `X-Custom-Auth` or configured header name | legacy `header_name` or `name` |
| `oauth2_cc` | `client_id` | `client_secret`, plus `client_id` when not supplied publicly | required `token_url`; optional `scope`, `auth_style`: `body` (default) or `basic`, `header_name`: defaults to `Authorization` |

`config` accepts only `in`, `name`, `header_name`, `token_url`, `scope`,
`auth_style`; the type determines which values the runtime consumes.
`scope` is a string (max 4096), not a list. Interactive OAuth grants and refresh
token flows are unsupported. Unknown configuration such as `config.client_secret`
is rejected. Public credentials are allowed only for the corresponding type.

Shared `headers` may contain `Accept`, `Accept-Language`, `Content-Type` and
`User-Agent` (case-insensitive). Every other shared header is exported by name in
`connector.secret_headers` and requested again. The importer rejects inline private
header values. This conservative rule also protects custom header names without
obvious words such as "token". Header values and separately supplied credentials
cannot contain CR, LF or NUL.

`source=secret` parameter values are cleared in previews/imports and exports.
Secret parameters require `secret_ref` and an effective profile (route override,
otherwise connector default). Their keys are added to the import requirements.
An explicit `none` profile can carry such credentials without adding auth headers.
Fixed parameters with authentication names (`authorization`, API key, token,
secret, password, cookie, signature) or private header names are exported as
secret parameters. If needed, export creates a portable `none` profile for them.
The importer rejects these parameters when still marked `fixed`.

Exports omit profile credential values, private shared headers, test responses
and call history. If a known stored credential also occurs in public configuration
(for example in a description), export fails with a remediation message instead of
creating a leaking package. Do not put secrets in descriptions, schemas, URLs,
public constants or sample values: arbitrary unlabeled secret text cannot be
identified reliably. Export does not turn unmarked free text into a secret vault.

### Endpoints and runtime support

Required endpoint fields: `ref`, `name`, `slug`, `http_method`, `url`, `mode`,
`status`, `endpoint_type`, `endpoint_type_locked`, `parameters`.

- Methods: `GET`, `POST`, `PUT`, `PATCH`, `DELETE`. Body parameters require
  `POST`, `PUT` or `PATCH`.
- URLs are absolute HTTP(S), max 2048 characters, with no userinfo or fragments.
  Sensitive query keys must move to auth profiles or secret parameters. A
  connector `base_url` is descriptive; route URLs must still be complete.
  Each `{id}`-style placeholder requires a matching `path` parameter.
  Import performs no network or DNS calls. The existing SSRF policy still checks
  real requests, including OAuth token exchange; HTTP may be blocked there.
- `mode`: `tool`, `ingest`, `both`. Only `tool` and `both` expose live chat tools.
  The data model preserves `ingest`; this package feature adds no bulk ingestion.
- `status`: `draft`, `tested`, `active`, `disabled`. Preserved on import, along
  with connector activation. Importing an active configuration makes its tools
  eligible for later chat calls. Review URLs, methods and tool descriptions.
- `endpoint_type`: `unknown`, `list`, `detail`. Use an explicit classification
  and `endpoint_type_locked: true` when known; `auto` is not a portable type.
- Parameters require `name`, `location` (`path/query/header/body`), `source`
  (`llm/fixed/secret`), `type` (`string/integer/number/boolean/array/object`),
  `required` (boolean), `sort_order` (nonnegative integer). Names must be unique
  per endpoint. `value` is a nullable **string**, only used by `fixed`; `secret_ref`
  is only used by `secret`. Optional `description` is text. Parameter names max
  128 characters; secret keys max 128. Body names are literal top-level JSON keys;
  dotted names do not create nested request objects.
- `input_schema` / `output_schema`: optional nullable JSON objects describing the
  contract. `tool_definition`: nullable `{name, description, input_schema}`;
  active endpoints need a complete definition to be usable as chat tools (legacy
  active rows without one still round-trip). Use the same slug and input
  schema for the route and tool. Include only `llm` parameters in the tool's
  input. Schema data is inert: no code or remote `$ref` resolution is performed.
- `param_mapping` is optional legacy metadata. Import and export rebuild it from
  `parameters`; it cannot override the runtime wiring or smuggle secret values.
- Operational overrides: `timeout_ms` positive integer, `cache_ttl_s` and
  `rate_limit` nonnegative integers; `null` uses existing runtime defaults.
- Optional `items_path` is a dot path (max 255). `output_transform` accepts only
  `include` and `exclude`, lists of at most 500 dot paths (max 255 each), with `*`
  support. Arbitrary code, templates and expressions are unsupported.
- `pagination` is nullable object metadata preserved from the route workspace.
  Existing detection describes `page` (`page_param`, `size_param`, `start_page`,
  `items_path`) or `cursor` (`cursor_param`, `next_cursor_path` or `next_url_path`,
  `items_path`). Legacy/custom metadata is preserved without execution. This
  feature does not implement automatic multi-page requests; generate ordinary
  endpoint parameters and let the existing caller supply them.

### List/detail relations

Each relation requires `list_route_ref`, `detail_route_ref`, and a nonempty
`field_map` list (max 500). The pair must be unique; routes must exist in this
file and have `list`/`detail` types. Optional `name` (max 128), `description`,
`sort_order` (nonnegative integer) are preserved.

Each mapping is `{ "from": "id", "to_param": "id", "to_location": "path" }`:
`from` is a field path within one list item (max 255); `to_param` must name an
LLM parameter of the detail route (max 128); optional `to_location` must match
that parameter's location. No scripts or mapping expressions run.

## ZIP v1 envelope

The JSON's `_meta.version` versions the package contents. ZIP has no independent
manifest version in v1. The archive contains exactly:

```text
connector.json       required; configuration described above
README.md            optional; inert handoff notes, never rendered or executed
```

Names are exact and case-sensitive, at the root. No directories, alternate path
separators, absolute paths, `..`, drive letters, symlinks, devices, extra entries,
duplicate names or nested archives. Maximum two entries. Upload size at most
5 MiB; `connector.json` at most 5 MiB expanded; README at most 64 KiB expanded.
Per-entry compression ratio at most 100:1. Only STORE and DEFLATE, no encryption.
Use STORE for predictable compliance (exports do). Every entry is read with a
bounded stream and its size and CRC checked; files are never extracted.
ZIP operations require PHP's `ext-zip`; JSON remains usable without it.

## HTTP and UI

Existing base path: `/api/admin/api-connectors` (subject to host route config).
All routes use Sanctum authentication, tenant authorization and `manageConnectors`
(admin/super-admin). Use the active authorized tenant context; a file cannot
select its destination tenant or reference local profiles/routes by ID.

| Request | Result |
| --- | --- |
| `GET /{id}/export` or `?format=json` | `{ "data": <configuration> }`; save only `data` as the file |
| `GET /{id}/export?format=zip` | Downloadable ZIP bytes |
| `POST /import/validate` | `{ "data": {config, name, endpoint_count, relation_count, required_credentials} }`; no writes |
| `POST /import` | 201 with a new connector resource; secrets hidden |

POST bodies may be JSON `{ "config": <configuration>, "credentials": {...} }`
or multipart with `file` (`.json` / `.zip`) and, on import, `credentials`.
Exactly one of `config` or `file` is accepted. MIME is not trusted; extension
chooses the parser and the contents must pass it. Preview is optional; final
import always validates again. No persistent preview token or temporary extracted
directory is used. Validation errors are HTTP 422 with `message` and `errors`.
Database write conflicts/failures return 409 with a retry message; other
persistence failures return 500 with a safe message after rollback, without
echoing SQL, bindings or internal diagnostics.
Unauthenticated/forbidden requests receive 401/403; another tenant's export is 404.

Supply credentials separately, keyed exactly as returned by preview:

```json
{"credentials":{"auth-orders":{"token":"SUPPLY_LOCALLY_AT_IMPORT"}}}
```

Custom auth header keys are `header:<header-name>` within their profile; shared
headers use profile `shared-headers` and the header name itself as key. Secret
values are nonempty strings, max 16384 characters via HTTP. They do not belong in
the portable configuration, README or ZIP.

The API section and API management page reuse the same **Import API** modal and
**Export API** ZIP action. Import shows counts, a reviewable configuration and
only missing credential inputs. Original JSON exports remain supported through
the HTTP endpoint. Files containing active tools should be reviewed before import.

Import always creates a copy. It never overwrites a connector or deletes data.
The destination is the current tenant; `project_key` is preserved as a string,
following the existing soft project registry (it does not create a project or
copy project membership). Existing names and slugs get numeric suffixes;
tool names/descriptions and list/detail references are remapped consistently.
All writes, including profiles, parameters and relations, share one database
transaction. Missing secrets, invalid references and persistence failures leave
no partial connector behind. No API tests, AI generation, HTTP calls, shell
commands, scripts or plugin installation run during preview/import.

## Validation

```sh
php vendor/bin/phpunit tests/Feature/Connectors/ApiConnectorConfigTransferTest.php tests/Feature/Connectors/ApiConnectorPackageTest.php
npm test -- frontend/src/features/admin/api-connectors frontend/src/features/admin/connectors/ApiConnectionTile.test.tsx --no-file-parallelism
npm run typecheck
```

The package tests import the documented example, verify a JSON/ZIP round-trip,
credentials and reference remapping, and reject invalid formats and unsafe ZIPs.
The existing transfer suite covers tenancy, authorization and transactional
rollback on a failing/cancelled save.
