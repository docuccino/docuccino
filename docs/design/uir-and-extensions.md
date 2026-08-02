# Design: UIR & Extension Architecture

Status: approved (2026-08-01). Reconciled with `docs/design/inference-embedding.md` —
where the two conflict on the TypeEngine boundary, the inference doc wins.
See `docs/plan.md` for scope/roadmap; this doc carries implementation-level detail.

## 1. UIR document

OAS 3.2-shaped JSON with one reserved key `x-docuccino` allowed on every node. Schemas are
JSON Schema 2020-12 (`jsonSchemaDialect: https://spec.openapis.org/oas/3.2/dialect/base`).

Top level:

```json
{
  "$schema": "https://spec.docuccino.app/uir/1.0/schema.json",
  "uir": "1.0.0",
  "openapi": "3.2.0",
  "jsonSchemaDialect": "https://spec.openapis.org/oas/3.2/dialect/base",
  "info": {}, "servers": [], "security": [], "tags": [],
  "paths": {}, "webhooks": {},
  "components": { "schemas": {}, "responses": {}, "parameters": {}, "securitySchemes": {}, "examples": {}, "headers": {} },
  "x-docuccino": {
    "document": { "id": "doc:default", "configHash": "…", "contentHash": "…" },
    "generator": { "name": "docuccino/laravel", "version": "…", "specVersion": "1.0.0" },
    "content": { "pages": [] },
    "diagnostics": []
  }
}
```

- `contentHash` = SHA-256 over canonical serialization EXCLUDING `x-docuccino.generator` and
  `x-docuccino.diagnostics` (tool upgrades don't dirty CI diffs).
- No timestamps anywhere — banned by the UIR schema itself.
- `x-docuccino.diagnostics` embedded only with `--embed-diagnostics` (CLI is the primary channel).
- UIR spec semver is independent of PHP packages; `$schema` URL embeds major.minor.
  Consumers MUST ignore unknown `x-docuccino` members (additive = minor; shape/identity change =
  major + new `$schema` URL).

## 2. Identity model

Every operation, parameter, named schema, response, security scheme carries
`x-docuccino.id` = `<kind>:<algoVersion>:<hash>`, where `<hash>` is the first 16 base32
characters of the full SHA-256 of the identity tuple (~80 bits) — matching the impl and the
schema's `nodeId` pattern.

| Kind | Identity inputs (hashed canonical tuple) | Survives | Breaks on |
|---|---|---|---|
| `op:` | doc id + upper method + path template with params positionally normalized (`/forms/{p0}/fields/{p1}`) | file moves, controller/method renames, path-param renames, param reorder | URI or method change |
| `par:` | parent op id + `in` + name | reorder, description/schema edits | rename (a real contract change for query/header) |
| `sch:` (named) | source FQCN (+ generic args); pinnable via `#[SchemaId('…')]` | file moves | class rename without pin |
| `sch:` (inline) | structural hash of canonical schema with descriptions/examples/x-docuccino stripped | prose edits | shape change (correct) |
| `res:` | parent op id + status + media type | — | status change (correct) |
| `doc:` | config key | everything | doc renamed in config |
| `page:` | content page slug | file moves within content dir | slug change |

Never file paths, line numbers, or array positions as identity inputs (those are
provenance). `operationId` (human-readable OAS field) is separate: route name by default,
configurable strategy. Identical tuples (two routes claiming `GET /x`) = error diagnostic.

## 3. Canonicalization (normative in the spec)

1. Fixed member order per object type (published as `x-canonicalOrder` in the meta-schema);
   map-like objects (`paths`, `components.*`, `responses`) sort keys by Unicode code point.
2. `paths` sorted by template; operations in fixed method order
   `get, put, post, delete, options, head, patch, trace, query`.
3. `parameters` sorted by (`in` rank: path, query, header, cookie; then name).
4. `tags`/`security`/`enum`: declaration order preserved, deduplicated (first wins).
5. Serialization: UTF-8, LF, 2-space indent, trailing newline, minimal escaping,
   shortest-round-trip floats.

## 4. Provenance

`x-docuccino.provenance` = list of contribution records:

```json
{
  "producer": "integration:query-builder",
  "layer": "integration",
  "fields": ["schema", "description"],
  "source": { "file": "modules/Form/Queries/FormIndexQuery.php", "line": 28, "symbol": "…::query" },
  "confidence": 0.9,
  "overrode": [ { "field": "description", "value": "…", "producer": "inference" } ]
}
```

Producers: `inference`, `attribute`, `docblock`, `integration:<name>`, `overlay`,
`config`, `fallback`. `source.file` is project-root-relative. Emit levels:

**Integration producer-name convention (frozen at v1).** `<name>` is the integration's
directory name (`packages/laravel/src/Integrations/<Dir>/`) kebab-cased — the one canonical
string, used identically whether the contribution is built via `Contribution::integration('<name>')`
or an `ExceptionToResponse::producer()` returning `'integration:<name>'`. The full set:

| Directory          | Producer `<name>`   |
|--------------------|---------------------|
| `SpatieData`       | `spatie-data`       |
| `ApiResources`     | `api-resources`     |
| `Eloquent`         | `eloquent`          |
| `Enum`             | `enum`              |
| `QueryBuilder`     | `query-builder`     |
| `RateLimit`        | `rate-limit`        |
| `Sanctum`          | `sanctum`           |
| `Passport`         | `passport`          |
| `Permission`       | `permission`        |
| `ProblemDetails`   | `problem-details`   |
| `FrameworkErrors`  | `framework-errors`  |
| `InferredHandler`  | `inferred-handler`  |
| `Validation`       | `validation`        |
| `FormRequest`      | `form-request`      |

`Validation` is the always-on shared rule chain (it owns the rule vocabulary); `FormRequest`
is the FormRequest request-body recovery extension — a distinct producer, so a body recovered
from a FormRequest's rules is attributed to `integration:form-request`, not `integration:validation`.

`--provenance=none|winners|full`, default `winners` for committed artifacts.
Mock hints: `x-docuccino.mock` = `{faker, seedGroup}` on schema properties (OAS emitter → `x-faker` or drop).
All other `x-*` members pass through untouched.

`source.line` is provenance, not identity, so it never affects `contentHash` or any `id`.
Committed UIR artifacts should therefore emit with `--provenance=none` (or `winners` and
accept that source line numbers churn as code moves); the churn is cosmetic and cannot alter
identities or the content hash (architecture N5 — documented, not a hashing change).

## 5. Pipeline

```
RouteResolvers → RouteCollection
  → per route (isolated try/catch, cacheable):
      RouteContextBuilder → OperationPipeline (phased) → OperationFragment
  → Assembler (merge, hoist/dedupe components, apply overlays, DocumentTransformers)
  → Canonicalizer → Validator (bundled UIR schema) → Emitters
```

Phases: `Parameters | Request | Responses | Errors | Security | Overrides | Finalize`.
Per-route failure → skeleton operation (config `on_route_error: skeleton|omit`) + error
diagnostic. Diagnostics: `{severity, code, message, source, routeSignature, help}`,
deterministic ordering, `--fail-on=error|warning|none`.

Core value objects: `RouteContext{route, action reflection, inspection (lazy TypeEngine
handle), attributes, docblocks, document}`; `OperationDraft` whose every write goes
through the PatchGuard (below) recording `(field, value, Provenance)`.

## 6. Extension API (contracts in `Docuccino\Core\Extensions\Contracts`)

```php
interface RouteResolver { /** @return iterable<RouteDescriptor> */ public function resolve(DocumentConfig $doc): iterable; }

interface OperationExtension {
    public function phase(): OperationPhase;
    public function handle(OperationDraft $op, RouteContext $ctx): void;
}

interface TypeToSchema {
    public function supports(DType $type): bool;
    public function toSchema(DType $type, SchemaContext $ctx): ?SchemaResult; // null = defer to next in chain
}

interface ValidationRulesToSchema { /* rules aren't types; per-rule transformer sub-extensions incl. cross-field */ }

interface ExceptionToResponse {
    public function supports(DType $exceptionType, RouteContext $ctx): bool;
    public function toResponse(DType $exceptionType, RouteContext $ctx, ComponentRegistry $components): ResponseDraft;
}
// Error-response resolution chain (first supports() wins; Phase 4):
//   1. InferredHandlerExceptionToResponse — analyses the APP'S REAL exception handling:
//      render callbacks discovered by reflecting the BOOTED app's handler (catches
//      package/provider-registered ones AST parsing would miss); ReflectionFunction →
//      file/line → NodeScopeResolver over the closure body (JsonResponse<TPayload> stub
//      preserves response()->json shapes; statuses constant-folded). Catch-all
//      render(Throwable) bodies analysed once per thrown exception type with the param
//      NARROWED to that type — PHPStan's instanceof narrowing resolves the branches
//      (the Eos ProblemDetailsRenderer pattern). Exception-class render() /
//      Responsable::toResponse() analysed the same way. Too-dynamic body → defer (null)
//      + diagnostic at the exact expression. Handler files join dependencyFiles.
//   2. FrameworkDefaultsExceptionToResponse — Laravel's stock JSON shapes
//      (422 {message,errors}, 401/403/404 {message}), maintained per Laravel version.
//   3. Presets (problem-details) + user extensions; attributes/config override anything.

interface ExampleProvider { /* chain: static/@example/#[Example] (v1) → factory render / response-calls (v1.1) */ }

interface VersioningPolicy { // diff enforcement: changeset severity vs info.version delta
    public function evaluate(Changeset $changes, string $oldVersion, string $newVersion): PolicyVerdict;
    // Built-ins: SemverPolicy (breaking → major bump required), DateVersionPolicy
    // (breaking → new date version), NoVersioningPolicy (breaking → fail/warn outright).
    // Per-document config; wired into docuccino:diff --enforce (nonzero exit for CI).
    // Longitudinal governance (deprecation windows, history, cross-repo) = SaaS, not here.
}

interface DocumentTransformer { public function transform(UirDocumentDraft $doc, DocumentContext $ctx): void; }
interface Emitter { public function format(): string; public function emit(UirDocument $doc, EmitOptions $o): string; }
interface Viewer  { public function render(ViewerContext $ctx): Response; }
```

- Ordering: `#[ExtensionOrder(priority: 0, before: [...], after: [...])]` — topo sort,
  tie-break priority desc then FQCN. Built-ins publish `Priorities::*` constants.
- `TypeToSchema` / `ExceptionToResponse` are chains: first `supports()` wins; returning
  null defers — a user extension `before: [SpatieDataToSchema::class]` intercepts
  specific classes only.
- **Late-bound registration**: `ExtensionRegistry` accumulates class-strings/closures;
  nothing resolves until a build starts (post-boot by definition). `Docuccino::extend()`
  works from any provider register()/boot(); config `extensions` merges at resolve time.
  No API returns the extension list before resolve — early snapshot is impossible.
- Extensions are container-resolved (constructor DI). Core is framework-agnostic (no
  illuminate/symfony-framework deps); its only runtime dependencies are `psr/container`,
  `opis/json-schema`, `symfony/yaml` and `nikic/php-parser`.
- **Dogfooding rule (arch-test enforced)**: built-in integrations live in
  `packages/laravel/src/Integrations/*` and may import only `Docuccino\Core\Extensions\
  Contracts\*` — never `Docuccino\Core\Internal\*`.
- **Placement rule (Tom, 2026-08-02 — decides "core or adapter?" for every new piece):**
  **anything whose INPUT is the UIR document belongs in core; anything whose INPUT is
  Laravel code belongs in the adapter.** Recovery is adapter-side; representation and
  document-level analysis are core-side; framework-neutral machinery with framework-owned
  vocabulary splits accordingly. Worked examples that set the precedent:
  - Validation: normalized rule model + transformer chain + schema builder = core
    (machinery); the Laravel rule VOCABULARY + rule RECOVERY (FormRequest/inline/Data
    attributes) = adapter (`Integrations/Validation` + per-source integrations).
  - Data-leakage lint (`Core\Lint\SensitiveFieldLint`): scans the emitted document —
    core, even though some default heuristics table entries look Laravel-flavored
    (they're neutral strings); the adapter contributes only config plumbing/registration.
    `Core\Lint` is where future document-level rules (description coverage, naming)
    accumulate — reusable by the reference CLI, other-language producers, and the SaaS.
  - Corollary: pure, stable core utilities that integrations legitimately need (e.g.
    `Core\Support\Fqcn`) get allow-listed in the arch test with justification — never
    duplicated to dodge the boundary.

## 7. Precedence / patch semantics

`fallback(5) < inference(10) < integration(20) < docblock(30) < attribute(40) < overlay(45,
OpenAPI Overlay 1.0) < programmatic config(50)`. Field-level PatchGuard:

- Unset field → accepted. Higher-over-lower → accepted, loser appended to `overrode`.
- Lower/equal-over-existing → rejected (`PatchResult::Shadowed`), info diagnostic.
- Collections merge by identity key (parameters by in+name, responses by status, content
  by media type, properties by name) — never wholesale replace.
- `null` in an attribute = "not specified" (no write); explicit removal is a sentinel
  (`Remove::field()`, `#[Hidden]`, `#[IgnoreParam]`, `#[IgnoreResponse]`).
- Within a layer, more-specific target beats less-specific (method attr > class attr).

## 8. TypeEngine boundary (authoritative shape — see inference doc)

```php
interface TypeEngine {
    public function analyzeAction(ActionRef $a): ActionAnalysis;   // ReturnSite[], ThrownException[], diagnostics, dependencyFiles
    public function classMetadata(ClassRef $c): ClassMetadata;
    public function trace(ActionRef $a, TraceVisitor $v): void;
}
```

`DType` closed set: `ScalarT, LiteralT, ArrayShapeT, ListT/MapT, UnionT, IntersectionT,
ClassT(fqcn, typeArgs), EnumT(cases), CallableT, NullT/VoidT/NeverT, UnknownT(reason)`.
`NullTypeEngine` in core answers UnknownT for everything (keeps pipeline total).

## 9. Config shape (docuccino/laravel)

```php
return [
    'enabled' => env('DOCUCCINO_ENABLED', true),
    'documents' => [
        'default' => [
            'info' => ['title' => '…', 'version' => …, 'description' => ['file' => '…md']],
            'servers' => [['url' => 'https://{tenant}.example.com', 'variables' => [...]]],
            'routes' => ['include' => ['api/*'], 'exclude' => [...], 'closure' => null],
            'security' => [...full scheme set..., 'auto_detect_middleware' => 'auth*'],
            'error_responses' => 'problem-details',
            'tags' => ['mapper' => PrefixTagMapper::class, 'map' => [...]],
            'content' => ['dir' => 'resources/docs/api'],
            'overlays' => ['resources/docs/overlays/*.yaml'],
            'representation' => ['filters' => 'bracketed|deepObject', 'nullable' => …, 'enums' => …, 'operation_id' => …],
            // Per-integration document-level knobs — one bag per integration, keyed by its directory
            // name (kebab-cased); each integration reads ONLY its own bag (via DocumentConfig::integration()).
            'integrations' => [
                'api_resources' => ['wrap' => true],                      // top-level resource `data` wrapping (false | true | '<key>')
                'sanctum'       => ['modes' => ['token', 'stateful'], 'cookie' => 'myapp_session'],
                'passport'      => ['url' => 'https://auth.example.com'], // oauth2 flow base URL (default app.url)
                'query_builder' => ['pagination_terminals' => ['paginateList']], // extra paginating method names
            ],
            'export' => ['path' => '…', 'formats' => ['openapi-3.2']],
            'viewer' => ['driver' => 'scalar', 'route' => '/docs/api', 'gate' => 'viewApiDocs', 'source' => 'generate|artifact'],
        ],
    ],
    'extensions' => [],
    // Data-leakage lint: enabled, an allow-list, and `patterns` (extra token → label heuristics
    // merged over the built-in sensitive-name table).
    'lint' => ['leakage' => ['enabled' => true, 'allow' => [], 'patterns' => []]],
    'on_route_error' => 'skeleton',
    'cache' => ['enabled' => true, 'store' => null],
];
```

`error_responses` accepts `default` (framework-default JSON error shapes), `problem-details`
(the RFC 9457 preset), or `none` (no error responses). Integration config lives under one `integrations.<name>` bag
per integration — there is no back-compat read of the old flat `security.sanctum` / `passport` /
`query_builder` locations (pre-launch).

## 10. Fragment caching

Unit = OperationFragment (operation + registered components + diagnostics + provenance,
serialized as UIR JSON fragments). Key = sha256(tool ver ‖ spec ver ‖ identity-algo ver ‖
doc configHash ‖ resolved extension list (FQCNs + package versions) ‖ route signature ‖
sha256 of each file in `ActionAnalysis::$dependencyFiles`). Assembly → canonicalize →
validate always run fresh. Watch mode later = loop incremental build + SSE push.

## 11. Worked example (one operation)

```json
"paths": {
  "/api/v1/forms": {
    "get": {
      "x-docuccino": {
        "id": "op:v1:mfz3q8k2w9r7t1ua",
        "provenance": [
          { "producer": "inference", "layer": "inference", "fields": ["responses.200"],
            "source": { "file": "modules/Form/Http/Controllers/FormController.php", "line": 38, "symbol": "FormController::index" },
            "confidence": 0.95 },
          { "producer": "attribute", "layer": "attribute", "fields": ["summary"],
            "source": { "file": "modules/Form/Http/Controllers/FormController.php", "line": 34 }, "confidence": 1.0,
            "overrode": [ { "field": "summary", "value": "Index forms", "producer": "docblock" } ] }
        ]
      },
      "operationId": "forms.index",
      "summary": "List forms",
      "tags": ["Forms"],
      "parameters": [
        { "x-docuccino": { "id": "par:v1:ab12cd34ef56ab78",
            "provenance": [ { "producer": "integration:query-builder", "layer": "integration", "fields": ["*"],
              "source": { "file": "modules/Form/Queries/FormIndexQuery.php", "line": 22 }, "confidence": 0.9 } ] },
          "name": "filter[status]", "in": "query", "required": false,
          "schema": { "type": "string", "enum": ["draft", "published", "archived"],
                      "x-enumDescriptions": { "draft": "Not yet visible", "published": "Live", "archived": "Read-only" } } },
        { "x-docuccino": { "id": "par:v1:77aa88bb99cc00dd" },
          "name": "per_page", "in": "query", "required": false,
          "schema": { "type": "integer", "default": 15, "minimum": 1, "maximum": 100,
                      "x-docuccino": { "mock": { "faker": "numberBetween:1,100" } } } }
      ],
      "responses": {
        "200": { "x-docuccino": { "id": "res:v1:e1f2a3b4c5d6e7f8" }, "description": "Paginated list of forms",
                 "content": { "application/json": { "schema": { "$ref": "#/components/schemas/PaginatedFormData" } } } },
        "401": { "$ref": "#/components/responses/ProblemUnauthenticated" },
        "422": { "$ref": "#/components/responses/ProblemValidation" }
      }
    }
  }
}
```

## 12. Open questions carried forward

- Generic schema identity (`Paginated<FormData>`): FQCN+args tuple proposed; needs a
  normative cross-language rule in the spec before 1.0.
- Confidence semantics: recorded-only in v1; spec must document meaning now.
- Webhooks: in UIR shape, no producer until `#[Webhook]` (v1.1).
