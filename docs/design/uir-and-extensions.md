# Design: UIR & Extension Architecture

Status: approved (2026-08-01). Reconciled with `docs/design/inference-embedding.md` —
where the two conflict on the TypeEngine boundary, the inference doc wins.
See the README for scope; this doc carries implementation-level detail.

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
| `sch:` (request body) | the source class identity (pinned id or FQCN) with a `#request` discriminator appended | same as the class identity above — file moves, and rename **with** a pin | class rename without pin |
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
directory name (`php/laravel/src/Integrations/<Dir>/`) kebab-cased — the one canonical
string, used identically whether the contribution is built via `Contribution::integration('<name>')`
or an `ExceptionToResponse::producer()` returning `'integration:<name>'`. The full set:

| Directory            | Producer `<name>`      |
|----------------------|------------------------|
| `SpatieData`         | `spatie-data`          |
| `ApiResources`       | `api-resources`        |
| `TimacdonaldJsonApi` | `timacdonald-json-api` |
| `JsonApiPaginate`    | `json-api-paginate`    |
| `Eloquent`           | `eloquent`             |
| `QueryBuilder`       | `query-builder`        |
| `RateLimit`          | `rate-limit`           |
| `Sanctum`            | `sanctum`              |
| `Passport`           | `passport`             |
| `Permission`         | `permission`           |
| `ProblemDetails`     | `problem-details`      |
| `FrameworkErrors`    | `framework-errors`     |
| `InferredHandler`    | `inferred-handler`     |
| `Validation`         | `validation`           |
| `FormRequest`        | `form-request`         |
| `LaravelActions`     | `laravel-actions`      |

`Validation` is the always-on shared rule chain (it owns the rule vocabulary); `FormRequest`
is the FormRequest request-body recovery extension — a distinct producer, so a body recovered
from a FormRequest's rules is attributed to `integration:form-request`, not `integration:validation`.

**Custom rule objects (`#[RuleSchema]`).** A `new X(...)` in a rule position folds to a `ConstValue`
INSTANCE kind (class FQCN + folded ctor args — a small sibling of the factory descriptor, since PHPStan
collapses the expression to a bare object and the class is the only documentable fact). The adapter reads
the class's `#[RuleSchema]` (`Integrations/Validation/CustomRuleReader`) and maps its fields onto
SYNTHETIC NAMED RULES rather than writing schema keywords: `type` → a type rule (unrecognised values pass
through as a rule name, so a typo diagnoses), `enum` → `in:`, `pattern` → `regex:`, `min`/`max` → the size
rules. `format`/`description`/`example` have no Laravel rule, so the vocabulary gained one transformer
(`AnnotationRuleTransformer`, effect-ordered last) — keeping ONE schema-writing path through the chain.
The attribute is the contract, not the `ValidationRule` interface (a vendor rule documents the moment its
author adds it) and ctor args are ignored. Every recovery path shares the fold or the reader (FormRequest
/laravel-actions `rules()`, inline `validate()`/`Validator::make`, spatie `#[Rule(new X)]`); each records
the rule class file as a fragment dependency, whether or not it carried the attribute, so adding the
attribute invalidates. No attribute → the unchanged `validation.rule-unrecoverable` path; closures stay
opaque by nature.

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
// The ResponseDraft an ExceptionToResponse returns carries INTENDED-PUBLIC write methods:
// setDescription / setRef / set / content(mediaType) and setExample(mediaType, example) — the last
// attaches an OAS media-type `example` beside the schema, FIRST-WRITER-WINS so the result is
// order-stable regardless of extension evaluation order. It is deliberately not `@internal`: the
// built-in inferred-handler tier attaches its literal-carrying error examples through exactly this
// method, and the "no privileged back door" promise means a third-party ExceptionToResponse must be
// able to do the same. (`guard()`, by contrast, IS `@internal`.)
// Public READ side: isBodyless() — HTTP forbids content on 1xx/204/205/304 (RFC 9110), so content()
// under one of those hands back a DETACHED SchemaDraft and the response freezes body-less whichever
// producer aimed one at it (inference folding `response()->json(null, 204)`, an attribute, an
// integration). Enforced once at the write so no producer can bypass it, and asked BEFORE building a
// body — InferredResponsesExtension skips payload conversion, which is what keeps a dropped body's
// schema out of components. An overlay (layer 45, applied post-freeze) can still write content there.
// Error-response resolution chain (first supports() wins; Phase 4):
//   1. InferredHandlerExceptionToResponse — analyses the APP'S REAL exception handling:
//      render callbacks discovered by reflecting the BOOTED app's handler (catches
//      package/provider-registered ones AST parsing would miss); ReflectionFunction →
//      file/line → NodeScopeResolver over the closure body (JsonResponse<TPayload> stub
//      preserves response()->json shapes; statuses constant-folded). Catch-all
//      render(Throwable) bodies analysed once per thrown exception type with the param
//      NARROWED to that type — PHPStan's instanceof narrowing resolves the branches
//      (the common Problem-Details renderer pattern: a catch-all render() with
//      per-type instanceof branches). Exception-class render() /
//      Responsable::toResponse() analysed the same way. Too-dynamic body → defer (null)
//      + diagnostic at the exact expression. Handler files join dependencyFiles.
//   2. FrameworkDefaultsExceptionToResponse — Laravel's stock JSON shapes
//      (422 {message,errors}, 401/403/404 {message}), maintained per Laravel version.
//   3. Presets (problem-details) + user extensions; attributes/config override anything.

interface ExampleProvider { /* chain: static/@example/#[Example] (v1) → factory render / response-calls (v1.1) */ }
```

**Implicit responses (pre-dogfood wave).** `ThrowAnalyzer` only sees exceptions the action BODY raises;
the framework also produces error responses from MIDDLEWARE and binding-time machinery the body never
throws. `ImplicitResponsesExtension` (adapter, Errors phase, `Priorities::LATE`) synthesizes those from
statically-visible signals and runs each through the SAME resolved exception→response chain, so the
body matches the document's error style:

| Status | Signal | Synthesized exception |
|---|---|---|
| 401 | auth middleware matches `security.auto_detect_middleware`, and the route is not `#[Unauthenticated]` | `AuthenticationException` |
| 422 | a request extension recovered a validated body (its integration producer owns `requestBody`) | `ValidationException` |

> **Deliberate gap:** the 422 signal is body-verb only. A validated GET/HEAD applies its rules as query
> parameters (never a `requestBody`), so a validated read endpoint is NOT documented with an implicit
> 422 even though it can 422 at runtime. Left as-is to avoid a 422 on every validated read (review B6).

| 404 | the route has ≥1 model-bound path parameter — ONE 404 per operation, not per param | `ModelNotFoundException` |
| 403 | `can:` / `signed` / `verified` middleware, or a FormRequest `authorize()` the engine proves is not a literal `return true` | `AuthorizationException` |

Precedence & dedup: it writes at the integration layer and runs LATE, so a status the action ALSO
throws explicitly (already applied by `ErrorResponsesExtension`) owns its status-keyed response and the
synthesis is shadowed by PatchGuard — never a double response. Overridable by docblock/attribute/overlay;
each status honours `#[IgnoreResponse]`; skipped under `error_responses => 'none'`. Provenance names the
signal (producer `integration:implicit-response`, source symbol `implicit:<signal>`). **Placement:** the
input is Laravel middleware/bindings/recovered-request, so the synthesis is adapter-side; the body still
comes from the framework-neutral chain. **Deliberate non-goals:** CSRF 419, maintenance 503, and
arbitrary custom-middleware throw-analysis are not synthesized (a middleware name carries no reliable
status contract); 429 stays the rate-limit integration's.

```php

interface VersioningPolicy { // diff enforcement: changeset severity vs info.version delta
    public function evaluate(Changeset $changes, string $oldVersion, string $newVersion): PolicyVerdict;
    // Built-ins: SemverPolicy (breaking → major bump required), DateVersionPolicy
    // (breaking → new date version), NoVersioningPolicy (breaking → fail/warn outright).
    // Per-document config; wired into docuccino:diff --enforce (nonzero exit for CI).
    // Longitudinal governance (deprecation windows, history, cross-repo) is out of scope
    // for the open-source packages.
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
  illuminate/symfony-framework deps); its runtime dependencies are `psr/container`,
  `opis/json-schema`, `symfony/yaml`, `nikic/php-parser`, `phpstan/phpdoc-parser` (the standalone
  parsing library behind `Core\TypeGrammar`, NOT the analyser) and — since core now reads Docuccino
  attributes off reflected classes/enums (`SchemaIdentity`, `EnumReflection`, the attribute
  overrides extension) — the dependency-free, lockstep-versioned `docuccino/attributes`. That tiny
  attribute package is the one runtime dep core added to absorb the attribute-aware placement moves;
  it is deliberately NOT the framework or the analysis engine.
- **Dependency direction (Tom, 2026-08-09 — the production-safety wave).** The engine and the adapter
  are SIBLINGS over core, not a chain:

  ```
                    docuccino/attributes
                             ↑
                       docuccino/core ────────────┐
                        ↑            ↑            │
        docuccino/laravel      docuccino/inference-phpstan
       (production: + illuminate)   (dev-only: + phpstan, larastan)
  ```

  `docuccino/laravel` used to REQUIRE `docuccino/inference-phpstan`, which hard-requires
  phpstan/phpstan — so a production `composer install` pulled a static analyser into `vendor/`.
  The engine is now a `suggest` (and a require-dev for the adapter's own tests); the adapter reaches
  it through core's `Inference\TypeEngineBuilder` seam, probing `Laravel\Engine\EnginePackage::BUILDER`
  by string. Arch tests freeze both directions: the adapter imports nothing from
  `Docuccino\Inference\PhpStan` (and no `PHPStan\`/`Larastan\` namespace at all), and the engine
  imports nothing from `Docuccino\Laravel` or `Illuminate`. Absent engine → `NullTypeEngine` plus one
  `engine.not-installed` warning per document.
- **Dogfooding rule (arch-test enforced)**: built-in integrations live in
  `php/laravel/src/Integrations/*` and may import only `Docuccino\Core\Extensions\
  Contracts\*` — never `Docuccino\Core\Internal\*`.
- **Per-document enable/disable (`integrations.<name>.enabled`)**: each integration bag carries an
  `enabled` bool, resolved at **per-document extension-resolution time** (the late-bound registry
  seam — `IntegrationToggles` gates `DefaultExtensions::all($document)`), orthogonal to `installed()`:
  `installed()` stays "is the package present", `enabled` is "does THIS document want it"; an
  integration contributes only when **installed AND enabled-for-this-document**. Because gating drops
  the extension from the resolved set, its FQCN leaves the fragment-cache signature — flipping `enabled`
  invalidates cached fragments for free. Defaults are per-integration: **on when installed, except
  `permission` (default off)** — documenting permission names leaks the app's internal authorization
  taxonomy into the public spec, so it is the first member of the **"sensitive-by-activation
  integrations default off"** principle (Passport stays on: OAuth scopes are the public contract). An
  installed-but-disabled integration emits one `integration.disabled` info diagnostic per document
  (discoverability), never fired when the package is absent.
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
    accumulate — reusable by the reference CLI, other-language producers, and any
    downstream consumer of the UIR.
  - Pipeline engine = core (`Core\Pipeline\{Assembler, FragmentCache, OperationPipeline,
    OperationFragment, GenerationResult, AssemblyResult}` + `Core\Extensions\ResolvedExtensions`):
    a second adapter inherits the whole assemble→overlay→transform→hash→validate spine and
    its fragment caching for free. `DocumentGenerator`/`DocumentBuilder` stay in the adapter —
    the framework seam: route discovery, per-route context building, the booted-app environment
    digest, and `config('docuccino.*')` loading are Laravel-code inputs, deliberately NOT moved.
    The single framework-owned datum the engine emitted, the generator `name`, became an
    `Assembler` constructor param the adapter binds to `docuccino/laravel` (byte-identical here;
    a future adapter labels itself).
  - Content subsystem = core (`Core\Content\{ContentCompiler, Frontmatter}` beside the resolver
    and model already there): markdown-with-frontmatter is not Laravel code, and the reference
    CLI / a second adapter compiles the identical tree. This retired the earlier "filesystem IO
    belongs to the adapter" split — the placement rule keys on FRAMEWORK coupling, not IO-vs-pure,
    and that split did not survive `FragmentCache` (also file IO) moving to core. The adapter keeps
    only the `content.dir` config read + compiler invocation. `Core\Support\ConfinedPath` moved on
    the same reasoning (a pure path-confinement utility, the strongest `Fqcn`-precedent candidate);
    the framework-grammar readers `TypeStringParser` + the summary/description docblock split moved
    laterally to `docuccino/inference-phpstan` first, because they import `PHPStan\PhpDocParser`, which
    core banned at the time — superseded by the type-grammar move below.
  - Placement re-review follow-up (Tom, 2026-08-03 — after `docuccino/attributes` became a core
    runtime dep, which lifted the gate on attribute-aware moves). Byte-neutral relocations, goldens
    unchanged:
    - Provenance: `Core\Provenance\RootRelativeSourcePathResolver` (was the adapter's
      `LaravelSourcePathResolver`) — a pure composer.json-ancestor-walk implementing core's
      `SourcePathResolver`, zero framework imports; any adapter constructs it with its own base path
      (the Laravel provider still binds `base_path()`).
    - Component hoisting: `Core\Extensions\Schema\ComponentHoist` (was
      `Integrations\Support\ComponentHoist`) is now the single reserve→build→reference +
      cycle-break skeleton — core's built-in `ClassTypeToSchema` was de-duplicated onto it (its
      docblock had long admitted it mirrored ComponentHoist), and the integration mappers (spatie
      Data, Eloquent, resources) keep calling it unchanged. `Core\Extensions\Schema\SchemaIdentity`
      + `Core\Extensions\Schema\EnumReflection` moved with it (reflecting Docuccino attributes /
      enum cases is framework-neutral); the enum mapper `Core\Extensions\BuiltIn\EnumSchema`
      (reads `#[CaseDescription]` + the representation naming policy) moved to core beside the
      plainer `EnumTypeToSchema` it supersedes — its registration/order is unchanged, so it is not
      an installable integration (the docs matrix already listed it "built in — always on").
    - Request assembly: `Core\Extensions\Validation\RecoveredRequest` (was
      `Integrations\Support\RecoveredRequest`) applies a core `ValidationSchema` to an operation —
      body for write verbs, query params for GET/HEAD — which is generic OAS assembly, not recovery;
      the three adapter recovery extensions (FormRequest/inline, spatie-Data, laravel-actions) still
      recover their rule sets adapter-side, then converge on this core applier.
    - Overrides: `Core\Extensions\BuiltIn\AttributeOverridesExtension` (was
      `Laravel\Extensions\AttributeOverridesExtension`) reads only Docuccino attributes + core
      `ConfinedPath` (for `#[DescriptionFromFile]`); the provider keeps binding its `$basePath`.
  - Corollary: pure, stable core utilities that integrations legitimately need (e.g.
    `Core\Support\Fqcn`) get allow-listed in the arch test with justification — never
    duplicated to dodge the boundary. Because these moves landed in already-allow-listed core
    namespaces (`Extensions\Schema`, `Extensions\Validation`), no arch-test allow-list needed
    widening.
  - Deliberately NOT moved (placement classification recorded; each stays adapter-side for a
    concrete reason):
    - `Laravel\Engine\TypeEngineMode` stays — it is adapter *config vocabulary* (how the Laravel
      adapter selects/tunes its inference engine), not framework-neutral machinery.
    - `Laravel\Exceptions\DefaultExceptionToResponse` stays — a placeholder terminal in the
      error-response chain; its placement is classified but the action is deferred until it grows a
      real (framework-neutral) body worth relocating.
    - The parameter/request-body/response attribute extensions
      (`AttributeParametersExtension`, `AttributeRequestBodyExtension`, `AttributeResponsesExtension`)
      stay — they read Laravel-code attributes, which is adapter input; and `Routing\AttributeCollector`
      stays because it consumes the adapter's route-reflection `ReflectedAction`. What DID move is the
      grammar underneath them — see the type-grammar entry below.
  - **Type grammar = core (Tom, 2026-08-09).** `Core\TypeGrammar\{PhpDocParserStack, TypeStringParser,
    ImportContext, DocBlockReader}` (was `Inference\PhpStan\{Support, Types, Metadata}`). Rationale:
    every future adapter — Symfony, a reference CLI — needs the same grammar to read a
    `#[Response(type: '…')]` string and a docblock's summary/description/`@property` tags, which is
    exactly what core is for. It is framework-neutral AND engine-neutral machinery: the input is a
    type string or a docblock, not Laravel code and not an analysis scope. The blocker was core's
    ban on `PHPStan\`; that ban now carves out `PHPStan\PhpDocParser\`, the standalone parsing
    library (production-safe, shipped by every generator in this space), while phpstan/phpstan the
    analyser stays banned. `ImportContext` needs nikic/php-parser, which core already required, so the
    move added exactly one dependency. Byte-neutral: goldens unchanged.
    The adapter's attribute extensions and the engine's `ClassMetadataFactory` now share one grammar
    from core instead of the adapter reaching sideways into the engine — which is what made the
    engine droppable at all.
  - Query Builder recovery vs representation (Tom, 2026-08-05 — the enum-cast filter wave). Recovery
    is adapter-side: `QueryBuilderTraceVisitor` folds the subject model, allow-lists (with internal
    column names + constant `->default()`/`->nullable()` modifiers + a leading `//` or `/** */`
    comment above the entry), and pagination into policy-independent `QueryBuilderFacts`.
    Representation is `QueryBuilderParameters`, the only place the OAS *expression* is chosen under
    the document's `representation.filters` policy. Two decisions recorded:
    - **Enum-cast exact filters model as an array, not a scalar enum.** Spatie's exact filter treats
      a comma-joined value as a `whereIn` list, so a strict scalar `enum` would reject a legal
      `filter[status]=draft,sent`. The shipped shape is `type: array, items: {type: string,
      enum:[…], x-enumDescriptions}` with `style: form, explode: false` (the comma serialization) in
      the bracketed policy, an array property under `deepObject`; a constant `->default()` sits on
      the array schema as the single value, `->nullable()` adds a description note (never a null enum
      case). Non-enum casts keep their plain scalar shape (churn control); unresolved subject/column
      degrades every filter to `string`, as before. The array modelling held up against Scalar
      rendering + validators, so the `type: string, enum:[…]` fallback the wave left open was not
      needed.
    - **A leading comment is an integration-layer description (precedence 20)** — below docblock (30)
      and `#[QueryParameter]` (40), so authored descriptions still win; recovered purely from the
      array-item node's attached comment, first sentence verbatim, no tag parsing.
  - Query Builder filter-kind breadth (Tom, 2026-08-05 — round 2). Type recovery generalised beyond
    `exact` to every kind, on the principle **the semantic fact is policy-independent, and now the
    kind-specific fact is too**: `scope` reflects the model scope method's value parameter (native or
    backed enum) via `ScopeParameterResolver`; `callback`/`custom __invoke` recover the column of a
    single `$q->where(COL, $value)` via the shared `WhereColumnAnalyzer` (AST-only — the closure-by-
    line engine trace feeds *return* expressions, so an inline callback's expression-statement body is
    read from its node directly, and a custom class's body is parsed like `AccessorReader`); a static
    (`EQUAL`/`DYNAMIC`) `operator` types off the internal column, a non-static one stays a string;
    `trashed` is a fixed `with`/`only` enum. Only `exact` uses the `whereIn` array; every other kind is
    a single-value comparison, so its enum is one scalar value. A `partial`/bare-string filter over an
    enum column is never enum-typed (a substring match isn't an enum member) — it earns an info
    `query-builder.partial-on-enum` nudge toward `exact`. A `#[QueryParameter]` on a custom filter
    **class** overrides its body inference at the integration layer (its `name` ignored — the parameter
    name is the `AllowedFilter` name), so a route-level attribute (layer 40) still wins.
  - deepObject / bracketed attribute parity (same wave). `#[QueryParameter('filter[status]')]` patches
    the flat `filter[status]` parameter under the bracketed policy and the `status` property of the
    `filter` deepObject container under the deepObject policy — same attribute, mirrored behaviour
    (patch an existing member, create a missing one). Enabled by emitting deepObject `properties` as
    nested schema drafts (per-property PatchGuard provenance, so the override is recorded with the
    integration value kept in `overrode`). The QB integration runs at `Priorities::EARLY` so its
    container exists before the attribute layer patches into it.
  - Single paginating-terminal table + custom-terminal resource envelope (D3, 2026-08-06). The
    length/simple/cursor terminal→kind table lives once on `Support\PaginationTerminalVisitor`
    (`PAGINATOR_TERMINALS`); the QB parameters visitor, the API-resources envelope, and json-api-paginate
    all read it (the standalone `JsonApiPaginateTraceVisitor` is deleted — the shared visitor now also
    exposes the outermost terminal call's folded int args, which is all json-api-paginate needed extra).
    **Decision:** a custom QB terminal configured under `integrations.query_builder.pagination_terminals`
    (e.g. a `paginateList` helper) now ALSO triggers the API-resources `{data, links, meta}` envelope
    (length-aware), not only the QB page parameters — the terminal set is config-shared, so a resource
    collection paginated by a custom terminal is documented consistently with its page params instead of
    getting parameters but a bare-array body.
  - Paginating-terminal scope boundary (2026-08-14). The terminal table names METHODS, but the real
    predicate is the RECEIVER: `PaginationTerminalVisitor::receiverIsBuilder()` (Eloquent/Query builder,
    relation, Spatie `QueryBuilder`, subclasses included) plus `classIsModel()` for the magic
    `Model::paginate()` static. **Decision: the receiver gate stays**, and
    `integrations.query_builder.pagination_terminals` is scoped as "extra method names ON those
    receivers", never a general "my app paginates here" hook. A paginator over an in-memory collection
    (`$catalogQuery->paginate($request, $entries, InvoiceData::class)` on a plain query object) carries
    no static evidence of WHICH request keys it reads; `per_page` in particular is app wiring —
    Laravel's `paginate($perPage)` reads no request key on its own — so inferring page params from a
    paginator-shaped return type would document a parameter that may do nothing, which is worse than
    documenting none. Supported answers: `#[QueryParameter]` (layer 40) or an app-registered
    `OperationExtension` (layer 20). Response side is unaffected and stays type-driven where it can be:
    a spatie `PaginatedDataCollection`/`CursorPaginatedDataCollection` return documents its envelope
    from the type; only the Laravel resource-collection envelope needs the trace (its
    `AnonymousResourceCollection<T>` return type is identical paginated or not).
  - Enum + request-body component hoisting (Tom, 2026-08-07 — the last engine delta from the
    dogfood run, closing the named-component gap vs Scramble). Both are representation moves — the semantic
    facts (an enum's cases/descriptions; a request's rule set) are unchanged; only their OAS *location*
    moved from inline to a `$ref`'d `components.schemas` entry.
    - **Enums hoist by default** (`representation.enums.components`, default `true`). `EnumSchema` (core)
      resolves the component name via `SchemaIdentity` (`#[SchemaName]` else short name) pinned to the
      FQCN identity and calls `SchemaContext::reference()`, so one enum is a single deduped component
      that properties, query-parameter item schemas (the QB enum filters — which already route through
      the converter, so they inherit the `$ref` for free), and enum-cast columns all reference. Only a
      **reflectable** enum hoists — an un-autoloadable one has no honest name/identity to pin, so it
      stays inline; `false` restores the byte-identical inline expression. A **nullable** enum can't
      carry `type: [x, null]` on a `$ref`, so `UnionTypeToSchema` composes `anyOf: [{$ref}, {type:
      null}]` under BOTH nullable policies (the existing not-a-simple-type branch already did this).
    - **Request bodies hoist when recovered from a single source class** (spatie Data / FormRequest /
      action `rules()` class) — `RecoveredRequest` (core) references the class-derived body as a
      component named after the class, deduped so the same class across N ops is one component. An
      inline `validate()`/`Validator::make()` body has no source class to name honestly → stays inline.
    - **Deviation rule.** An operation carrying a `#[BodyParameter]` keeps its body **inline** for that
      op. That attribute patches individual body properties at the attribute layer by reading
      `schema.properties` (`AttributeRequestBodyExtension`); a `$ref` has none, so hoisting would let the
      patch silently discard the whole shared component (or mutate it for every other op). Dereferencing
      keeps the `$ref` honest. Call-site partials (`include`/`exclude`/`only`/`except`) shape the
      *response* via query parameters, not the request body, so they never force the body inline.
    - **Per-response property visibility is OUT OF SCOPE (2026-08-14).** `#[Hidden]` is document-wide and
      stays that way: the property contribution happens during type→schema conversion, where no operation
      and no status are in scope, and a class is ONE component because component identity is pinned to the
      FQCN (`IdentityGenerator::namedSchemaId`). Making visibility status-aware would make component
      identity status-dependent, breaking both the FQCN pin and the registry's structural dedupe — one
      class would fan out into a component per status it appears under. The capability itself already
      exists where it can be stated honestly: the problem-details preset emits `allOf: [{$ref ProblemDetails},
      {properties: {errors}}]` for validation entries (`ProblemDetailsSchema::response()`), because there
      the 422 shape is the preset's own fact, not a property of the app's class. App-side answers, in order:
      the preset; a per-status type + `#[Response(status:, type:)]`; `array|Optional` (in `properties`, out of
      `required`); an overlay on the one operation's response; a `DocumentTransformer` for many at once.
      **If ever revisited**, the only sound shape is the existing DEVIATION rule — dereference the component
      inline for THAT response and patch the inline copy (precedent: `#[BodyParameter]` forcing a hoisted
      request body inline, above) — never a status-aware converter.
    - **Both-sides naming.** Request- and response-side components of the same class carry DISTINCT diff
      identities (the request body's is `<FQCN>#request`), so a request rules-shape never dedupes into a
      response property-shape by identity. Structurally-identical shapes still collapse to ONE component
      via the registry's structural dedupe; genuinely different shapes (the input/output asymmetry — e.g.
      a `HiddenFromRequest` field, or validation-shape vs property-shape) yield two components. Because
      the Request phase precedes Responses, the request keeps the base name and the response is
      deterministically suffixed (`Name_2`) with the existing `components.name-collision` info — proven
      live by the workbench `ArticleData` (`#[SchemaName('Article')]`) fixture, used on both sides with
      divergent shapes.

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

**`#[Hidden]` stays OUTPUT-only; request-hiding is the separate `#[HiddenFromRequest]` (pre-dogfood
decision).** `#[Hidden]` was NOT conflated to also hide a Data property from the request body. A
property hidden from output but still accepted in the request is a real, intentional shape — and
exactly the accidental exposure the built-in data-leakage lint is designed to surface (the workbench
`ArticleData.secret` scenario proves it) — so folding request-hiding into `#[Hidden]` would silently
suppress that signal, which the brief flagged as "wrong for output-DTO users". Per the brief's
"separate attribute" path, request-body hiding is the explicit opt-in `#[HiddenFromRequest]` (a Scramble
request-`#[Hidden]` field migrates to it); `#[FromRouteParameter]` (spatie) is likewise excluded — its
value is bound from the route, never sent. `#[Hidden]`'s meaning is unchanged (output only), so the
leakage lint keeps catching a genuinely-leaking hidden field.

## 8. TypeEngine boundary (authoritative shape — see inference doc)

```php
interface TypeEngine {
    public function analyzeAction(ActionRef $a): ActionAnalysis;   // ReturnSite[], ThrownException[], diagnostics, dependencyFiles
    public function classMetadata(ClassRef $c): ClassMetadata;
    public function trace(ActionRef $a, TraceVisitor $v): TraceReport;
}
// Optional trace capabilities (Docuccino\Core\Inference), opt-in per visitor, each degrading by declining:
interface FollowsReturnType { public function followsReturnType(DType $t): bool; }                       // on the VISITOR: descend past project paths (never vendor)
interface FoldsCallReturns  { public function deferReturnFold(Node\Expr $c, callable $onFolded): bool; } // on the SCOPE: fold what a call RETURNS, not what it is written with
```

`FoldsCallReturns` is what recovers an allow-list entry whose public name lives in the callee's BODY
(`$this->termFilter()`, `ListFilters::status()`, `...$this->allowedFilters()`) rather than in the
arguments at the call site: a DEFERRED fold of a single unconditional `return`, answered after the walk.
Opt-in because `constantValueOf` is shared and a fabricated descriptor is worse than an unrecovered one —
mechanics, limits and the FiberScope reason for deferring are in the inference doc §4.

`DType` closed set: `ScalarT, LiteralT, ArrayShapeT, ListT/MapT, UnionT, IntersectionT,
ClassT(fqcn, typeArgs), EnumT(cases), CallableT, NullT/VoidT/NeverT, StatusMarkerT, UnknownT(reason)`.
`NullTypeEngine` in core answers UnknownT for everything (keeps pipeline total).

`StatusMarkerT` is the one member that is not a language type: it is a pipeline-resolution SIGNAL
meaning "this body member echoes the response's own HTTP status", synthesised by the engine's response
refinement (never translated from a PHPStan type) and resolved to a `LiteralT` at the response-building
seam before schema conversion. It lives in the closed DType set rather than in a transient side-channel
because it must survive the CACHE and WORKER boundaries: the marker sits inside the `ArrayShapeT`
payload of a `JsonResponse<…>` `ClassT` that is cached in an `ActionAnalysis`, while resolution happens
later, in the adapter — a side-channel could not cross that boundary. DType consumers are `supports()`
chains rather than exhaustive `match`es over kind, so adding it broke no totality; the fallback mapper
maps it honestly to a bare `integer` (no fabricated `const`/example) for the case where nothing resolves
it.

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
            'error_responses' => ['preset' => 'problem-details', 'errors_shape' => 'pointer-list'],
            'tags' => ['mapper' => PrefixTagMapper::class, 'map' => [...], 'default_strategy' => 'controller'],
            'content' => ['dir' => 'resources/docs/api'],
            'overlays' => ['resources/docs/overlays/*.yaml'],
            'representation' => ['filters' => 'bracketed|deepObject', 'nullable' => …, 'enums' => …, 'operation_id' => …],
            // Per-integration document-level knobs — one bag per integration, keyed by its config
            // name (snake_case); each integration reads ONLY its own bag (via DocumentConfig::integration()).
            // Every bag also accepts `enabled` (bool), resolved per-document at extension-resolution
            // time: an integration contributes only when its package is installed AND the document
            // enables it. Default on when installed, EXCEPT `permission` (default OFF — opt-in; see below).
            'integrations' => [
                'api_resources' => ['wrap' => true],                      // top-level resource `data` wrapping (false | true | '<key>')
                'sanctum'       => ['modes' => ['token', 'stateful'], 'cookie' => 'myapp_session'],
                'passport'      => ['url' => 'https://auth.example.com'], // oauth2 flow base URL (default app.url)
                'query_builder' => ['pagination_terminals' => ['paginateList']], // extra paginating method names
                'permission'    => ['enabled' => true],                   // opt in (default OFF): document role/permission requirements
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

`error_responses` accepts either a string preset — `default` (framework-default JSON error shapes),
`problem-details` (the RFC 9457 preset), or `none` (no error responses) — **or** a bag
`['preset' => <string>, 'errors_shape' => 'map'|'pointer-list']`, where `errors_shape` chooses how a
422 body models its `errors`: `map` (field → messages, the default) or `pointer-list` (a list of
`{detail, pointer}` objects, JSON-Pointer style). `tags.default_strategy` chooses how an operation
with no `#[Group]` gets its default tag: `controller` (the controller's short name → `tags.map`, the
default) or `none` (no default tag); an unknown value coerces to `controller` and emits a
`config.unknown-tag-strategy` info diagnostic. `tags.definitions` entries are full OAS 3.2 Tag
Objects (`name` + optional `summary`/`description`/`parent`/`kind`) plus Docuccino's own `weight`,
which orders the emitted array (weight, then name) and is not emitted. Parents are resolved AFTER
that sort, so the result never depends on definition order: a `parent` naming no defined tag emits
`config.unknown-tag-parent`, one that would close a cycle emits `config.tag-parent-cycle`, and in
both cases the offending LINK alone is dropped (the tag stays) so the hierarchy is always a forest.
`summary`/`parent`/`kind` are 3.2-only — the 3.1 downlevel drops each with its own
`downlevel.tag-*` warning. Setting `enabled` on one of the always-on producers
(validation / form_request / framework_errors / problem_details / inferred_handler) has no effect and
emits a `config.enabled-ignored` info diagnostic — only the toggleable integrations honour `enabled`.
Integration config lives under one `integrations.<name>` bag per integration — there is no
back-compat read of the old flat `security.sanctum` / `passport` / `query_builder` locations
(pre-launch).

**Determinism — paths are stored base-path-relative.** `DocumentConfig::hash()` digests the whole raw
config bag and that digest is EMITTED as `document.configHash`, so an absolute path anywhere in a
document's config would fold the generating machine's filesystem layout into a committed artifact: two
checkouts of the same app at different paths could never emit the same bytes. `ConfigPaths` — the
single owner of the path-key table (`content.dir`, `export.path`, `info.description.file`, `overlays`)
— therefore rewrites every path-like value that sits INSIDE the app base path to its base-relative
form at config-read time, in `DocumentConfigFactory::make()`, the one choke point every command, the
viewer and the pipeline share. The hash then follows a path's MEANING, not its spelling:
`base_path('resources/docs/api')` and `'resources/docs/api'` hash identically. Resolution is unchanged
(a relative value is joined back against the base path by `Paths::absolute()` /
`ConfinedPath::resolve()`), and containment is decided lexically — no `realpath()` — so a glob obeys
exactly the same rule as a file. A path genuinely OUTSIDE the app is kept verbatim (rewriting it would
break the read) and emits a `config.machine-dependent-path` info diagnostic naming the key and the
path, so the machine dependence is stated rather than silently baked into the output. `cache.path` and
`engine.project_paths` are app-level, never part of a document bag, so they reach no emitted byte.

## 10. Fragment caching

Unit = OperationFragment (operation + registered components + diagnostics + provenance,
serialized as UIR JSON fragments). Key = sha256(tool ver ‖ spec ver ‖ identity-algo ver ‖
doc configHash ‖ environment digest ‖ build fingerprint ‖ resolved extension list (FQCNs +
package versions) ‖ route cache-signature ‖ sha256 of each file in
`ActionAnalysis::$dependencyFiles`). Assembly → canonicalize → validate always run fresh.
Watch mode later = loop incremental build + SSE push.

The route cache-signature (`RouteDescriptor::cacheSignature()`) is method + URI + NAME + resolved
action + normalised middleware + any scalar `cacheInputs` a resolver folds in — the name is in
because it is the default `operationId` and a rename touches no file the fragment depends on. The
build fingerprint (`Laravel\Pipeline\BuildFingerprint`) is the environment the engine runs in:
which engine resolved, whether the engine package is installed, the output-shaping half of
the `engine` config (everything but `memory_limit`, a process ceiling that reaches no emitted byte),
and the app's `composer.lock` hash — installing the engine or upgrading the analyser changes what
inference recovers without touching one analysed file. Tool ver additionally carries this package's
own installed source reference where Composer can answer for it, so a `path`/dev checkout edited in
place — the maintainer's loop, invisible to the app's lock file — doesn't share fragments with the
release it was checked out from. The store itself is emptied by `docuccino:clear --fragments`.

Two consequences of the cache being the fast path. A build resolves its `TypeEngine` before it
starts, but a fully warm one asks it nothing, so the adapter hands out an `Engine\LazyTypeEngine`
that builds the real engine on the first question — the analyser boots exactly where it did, ahead
of any analysis, and not at all for a build that recovers nothing. That is why the fingerprint names
the engine (`TypeEngineFactory::engineIdentity()`) instead of reading the class off an instance: the
key is computed before the first route, and asking would cost the boot it exists to avoid. And
freshness hashing goes through `Core\Pipeline\FileDigests`: one build hashes each dependency file
once — the same file sits in many routes' lists — and sees one view of it, and the memo dies with
the build.

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
          "description": "Exact-match filter. Accepts a comma-separated list of values (matched as `whereIn`).",
          "style": "form", "explode": false,
          "schema": { "type": "array", "items": { "type": "string", "enum": ["draft", "published", "archived"],
                      "x-enumDescriptions": { "draft": "Not yet visible", "published": "Live", "archived": "Read-only" } } } },
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
