# Fixture app — how to recreate `tests/fixture-app/app/`

The real-engine (`@group fixture`) tests run the inference engine out-of-process against a
provisioned Laravel + Larastan application. That install lives at `tests/fixture-app/app/`
and is **git-ignored** (a full Laravel install; see the root `.gitignore` entry
`/tests/fixture-app/app/`). The tracked overlay sources — the analysis targets the tests
point at — live alongside it at `tests/fixture-app/src/`. Recreate the install exactly like
this.

## Host used

- macOS (Darwin arm64)
- PHP 8.5.9 (CLI on PATH)
- Composer 2.9.5

## Commands

Run from the repository root:

```bash
# 1. Laravel 12 skeleton into the git-ignored install dir. Installed cleanly on PHP 8.5.9 —
#    no installer resistance, no --ignore-platform-reqs needed.
composer create-project laravel/laravel tests/fixture-app/app "^12.0" --no-interaction

# 2. Larastan (dev) — pulls phpstan/phpstan as a transitive dep.
composer require --working-dir=tests/fixture-app/app --dev larastan/larastan --no-interaction

# 2b. OPTIONAL, and the one knob worth knowing about. This app's own vendor is the phpstan the
#     real engine loads, so whatever lands here is the only version the fixture group proves.
#     2.2.0 and the newest 2.2.x resolve NodeScopeResolver differently — the floor hands out
#     fiber-driven scopes, the newest hands out plain ones — so CI runs both. To reproduce the
#     fiber leg locally:
#     composer require --working-dir=tests/fixture-app/app --dev -W phpstan/phpstan:2.2.0 --no-interaction

# 3. Spatie packages (Query Builder trace + Data class recovery).
composer require --working-dir=tests/fixture-app/app \
  spatie/laravel-query-builder spatie/laravel-data --no-interaction

# 4. Integration packages (JSON:API + laravel-actions real-engine tests).
composer require --working-dir=tests/fixture-app/app -W \
  "timacdonald/json-api:^1.0@beta" "spatie/laravel-json-api-paginate:^2.0" \
  "lorisleiva/laravel-actions:^2.0" --no-interaction

# 5. Overlay the tracked fixture sources onto the install's app/ directory.
cp -R tests/fixture-app/src/app/. tests/fixture-app/app/app/

# 6. Overlay the tracked MODULAR sources (a `Modules\` PSR-4 root OUTSIDE app/, so the Query-Builder
#    trace's follow-beyond hop into a Query class outside the descend scope is exercised) and register
#    the PSR-4 root so PHPStan can reflect it, then refresh the autoloader.
mkdir -p tests/fixture-app/app/modules
cp -R tests/fixture-app/src/modules/. tests/fixture-app/app/modules/
php -r '$f="tests/fixture-app/app/composer.json";$j=json_decode(file_get_contents($f),true);$j["autoload"]["psr-4"]["Modules\\"]="modules/";file_put_contents($f,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");'
composer dump-autoload --working-dir=tests/fixture-app/app
```

CI provisions the same way (`.github/workflows/ci.yml`, "Provision fixture app") — keep the
two in lockstep.

Nothing re-copies the overlay afterwards, so editing anything under `src/` leaves the install behind
and the fixture group then analyses the old code. `composer test` and `composer test:inference-fixture`
both run `tools/fixture-app-drift.php` first, which names the stale files and points back at steps 5-6
above; it is inert when there is no install.

## Resulting composer.json constraints (as resolved on this host)

```
require:
  php: ^8.2
  laravel/framework: ^12.0
  laravel/tinker: ^2.10.1
  spatie/laravel-data: ^4.23
  spatie/laravel-query-builder: ^7.3

require-dev:
  larastan/larastan: ^3.10
  ... (default Laravel 12 dev deps: phpunit, pint, pail, sail, collision, mockery, faker)
```

## Exact installed versions (composer.lock, this host)

| Package | Version |
|---|---|
| laravel/framework | v12.64.0 |
| phpstan/phpstan | 2.2.8 |
| larastan/larastan | v3.10.0 |
| nikic/php-parser | v5.8.0 |
| phpstan/phpdoc-parser | 2.3.3 |
| spatie/laravel-data | 4.23.0 |
| spatie/laravel-query-builder | 7.3.0 |

## Overlay sources (`tests/fixture-app/src/app/…`)

These files are tracked and copied into the install after step 4 (step 5 above). They are the
analysis targets — the engine only ever reflects/parses them; no routes or migrations are
needed and nothing boots HTTP. The default `App\Models\User` model is used as-is by several of
them.

### Return-type + JSON payload analysis

- `app/Http/Controllers/SpikeController.php` — four actions (`listUsers()`, `jsonShape()`,
  `resourceCollection()`, `unionAction()`) exercising Eloquent collection generics,
  `response()->json([...])` constant-array payloads, `AnonymousResourceCollection`, and a
  union return with distinct per-line types.
- `app/Http/Resources/UserResource.php` — a minimal `JsonResource` (`@mixin User`).

### QueryBuilder deep-chain trace (the Scramble-Pro-beater)

The allowed-filters chain is built inside a Query class, reached two calls deep, behind a
custom pagination terminal — the pattern out-of-the-box Scramble fails on.

- `app/Support/ListQueryBuilder.php` — `final class ListQueryBuilder extends
  Spatie\QueryBuilder\QueryBuilder` with a custom `paginateList()` terminal that internally
  calls the vendor `paginate()`.
- `app/Queries/UserIndexQuery.php` — `final readonly class`; its `query()` builds
  `ListQueryBuilder::for(User::class)->allowedFilters([...])->allowedSorts([...])
  ->defaultSort('name')` (the chain lives here, NOT in the controller).
- `app/Http/Controllers/UserListController.php` — `listUsers()` returns
  `(new UserIndexQuery())->query()->paginateList(25)`.

The enum-cast filter proof (feature 1) uses its own inline chain + a cast-target model:

- `app/Enums/ListingStatus.php` — a backed enum (`open`/`closed`/`draft`) with
  `#[CaseDescription]`s on two cases, so recovery yields backing values + `x-enumDescriptions`.
- `app/Models/Listing.php` — an Eloquent model casting its `status` column to `ListingStatus` and
  its `active` column to `boolean`, with a `scopeStatus(Builder, ListingStatus)` local scope. So
  `AllowedFilter::exact('status')` types from `$casts`, `AllowedFilter::scope('status')` types from
  the scope's value parameter, and a `callback` filtering on `active` types from the boolean cast.
- `app/Http/Controllers/ListingQueryController.php` — `index()` builds
  `QueryBuilder::for(Listing::class)->allowedFilters(['title', AllowedFilter::exact('status')])->paginate(20)`,
  the target of the `trace-qb-enrich` runner mode (subject-model recovery + enum-cast typing).
- `app/Http/Controllers/ListingFilterKindsController.php` — `index()` builds
  `QueryBuilder::for(Listing::class)->allowedFilters([AllowedFilter::scope('status'),
  AllowedFilter::callback('active', fn ($q, $v) => $q->where('active', $v))])->paginate(20)`, the
  round-2 proof that scope value-parameter typing and callback closure column recovery work through
  the real engine (`trace-qb-enrich`).

The method-built allow-list shapes (a filter whose public name is never written at the call site) live
under `modules/` too, so the same recovery has to work through the out-of-project `$query->query()` hop:

- `modules/Billing/PositionSearchQuery.php` — every entry is built by an instance method:
  `->allowedFilters($this->termFilter(), $this->facetFilter('status', 'status'))`,
  `->allowedSorts($this->titleSort())`, `->defaultSort($this->titleSort())`. `termFilter()` takes no
  arguments (its name `q` and its `where('title', …)` column exist only in its body); `facetFilter()`
  names a filter only once the call site's arguments are bound to its parameters. Recovering these means
  folding what each method RETURNS, not what the call site writes.
- `modules/Billing/PositionFacetQuery.php` — the array form: `->allowedFilters(...$this->allowedFilters())`
  and `->allowedIncludes(...$this->allowedIncludes())`, so one folded return has to expand into every
  entry it carries (with each item's own leading comment, written inside the helper). Its
  `configuredSort()` BRANCHES, pinning the fold's honest limit: one `query-builder.unresolved-entry`
  diagnostic, never a guess at one of the arms.
- `modules/Billing/PositionController.php` — the two trace entries (`index()`, `facets()`), each just
  `$query->query()->paginateList(15)`.

The shape where the query object IS the builder and configures itself in its own CONSTRUCTOR — so nothing
in the action body leads to the allow-lists at all, and the constructor has to be traced as a root of its
own (seeded from the action's parameter type):

- `modules/Billing/ChargeListQuery.php` — `final class ChargeListQuery extends QueryBuilder` whose
  `__construct()` calls `parent::__construct(Listing::query()->with([...]))` (the subject model origin a
  self-configuring subclass writes instead of `for()`) and then `allowedFilters(...)`/`allowedSorts(...)`/
  `allowedIncludes(...)`/`defaultSort(...)`, with its own `paginateList()` terminal. Its entries are
  deliberately mixed: an `InvoiceFilters::enum(...)` project factory, a non-enum factory typing off its key
  column, `AllowedFilter::callback('tag', $this->tagFilter(...))` (a first-class callable, so the column
  stays out of reach — pinned as a plain-string filter, not a guess),
  `AllowedFilter::custom('title_search', new ListingTitleSearchFilter)` (the parenless instance form, whose
  class only the typed `new` at the call site can name), `$this->stateFilter()` (name AND internal column
  live only in its body) and a BRANCHING `configuredFilter()` — one honest diagnostic.
- `modules/Billing/ChargeController.php` — `index(ChargeListQuery $query)`: the container hands the action
  its builder and the body is nothing but `$query->paginateList(25)`.
- `app/Filters/ListingTitleSearchFilter.php` — the custom `Spatie\QueryBuilder\Filters\Filter` the entry
  above instantiates.

### Exception-flow analysis

- `app/Http/Controllers/ThrowsController.php` — the actions the exception-flow layer is measured against,
  in two families. How a throw is REACHED: abort/abort_if (with the status written positionally and,
  separately, named), authorize, findOrFail, inline `validate()`, a 2-deep service call with and without
  `@throws`, a vendor any-throwable call, and a try/catch. And where an `HttpException` subclass's STATUS
  is written: pinned in the class, inherited from a base that adds no constructor, written at the `throw`
  positionally and named, taken from a constructor default the construction leaves empty, that same
  construction one hop away inside the factory the `throw` names, and chosen per factory — plus the
  degradations, where a constructor normalises what it was handed, reuses the parameter after forwarding
  it, or a factory builds two ways, and the two vendor cases nothing is said about. And where the throw
  point carries no construction at all: thrown from a trait method and declared at the caller, rethrown
  from a `catch`, and written inside a closure the action hands to a callee — as an argument, held in a
  local first, in an arrow function, which is one boundary the hop stops at, and nested past the descent
  budget, which is the other — the last of those writing its counted throw ahead of the closure it
  measures against, because `transaction()` is generic over its callback from Laravel 13 on and a closure
  that only throws makes everything after the call dead code.
- `app/Services/OrderService.php` — `place()` / `placeDeclared()` / `reserve()`, the 2-level
  throw chain descended by the exception-flow layer.
- `app/Exceptions/OutOfStockException.php` — a custom domain exception (also reused by the
  inferred-handler sources below).
- `app/Exceptions/ExportRejectedException.php` — the static-factory idiom: a private constructor with the
  status defaulted and no factory writing the slot, so the default is what EVERY instance carries.
- `app/Exceptions/ExportBlockedException.php` — the same default behind a PUBLIC constructor, which pins
  nothing for the class. Both spellings of a construction that leaves the slot empty are thrown — a bare
  `new`, and a factory doing the same — so the two cannot be answered differently.
- `app/Exceptions/ExportLockedException.php` — no constructor of its own, so the framework's runs and the
  status is the argument each `throw` writes, positionally in one action and named in another.
- `app/Exceptions/ExportUnsupportedException.php` — the factory idiom with no constructor of its own: the
  named factory builds with its status before decorating the exception with what the caller passed.
- `app/Exceptions/ExportConflictException.php` — a status PER factory (the constructor default, one
  factory overriding it, and one choosing by argument), so neither the class nor every factory names one.
- `app/Exceptions/ExportPartialException.php` — a constructor that NORMALISES the status it was handed, so
  neither the default nor what a caller puts in the slot is what the instance carries; the honest answer
  is no status at all.
- `app/Exceptions/ExportSupersededException.php` — the status parameter reused after it was forwarded, so
  a read taken in the body's END scope would answer a status nothing was ever built with.
- `app/Exceptions/ProbeStaleException.php` + `app/Support/Concerns/GuardsProbeState.php` — no constructor
  of its own and exactly ONE factory, so the class states its status once and nothing at a `throw` repeats
  it. Reached from a trait's guard clause, which surfaces at the action as a declared exception rather than
  a construction: the population where the class has to answer for itself.

### Data + Eloquent model reflection

- `app/Data/ArticleData.php` — a `spatie/laravel-data` Data class with typed public promoted
  properties (`id: int`, `title: string`, `subtitle: ?string`), so the engine recovers precise
  property types by reflection.
- `app/Data/ListingSummaryData.php` + `app/Data/BaseListingData.php` + `app/Data/Concerns/HasRevision.php`
  — a Data class whose shape is written across four files: it declares `title`, inherits `id`/`status`
  from the base class, picks up `revision` from a trait the BASE class uses (PHP reports it as the
  base's, so only a trait walk names the file), and copies `ListingStatus`'s cases into `status`. The
  fragment-cache proof that `dependencyFiles` names every file the shape came from, not just the class
  the question was asked about
  (`php/inference-phpstan/tests/Integration/ClassMetadataDependencyTest.php`).
- `app/Data/ProblemDocumentData.php` — a Data class that is both the runtime carrier and the documented
  schema of an error body, rendered through `withoutWrapping()->toResponse()` with the media type
  re-labelled by a header set on the returned response. Neither half is visible to a naive read:
  `toResponse()` declares a bare `JsonResponse`, and the `Content-Type` is a mutation rather than a
  constructor argument. Its `instance`/`errors` members are `Optional`, so only the arguments a branch
  passed can say whether that branch's response carries them. It also carries
  `toNegotiatedResponse()`, whose two branches build into the same `$response` variable with only the
  second labelling its media type — the shape that catches a header write being attributed to the wrong
  branch's body.
- `app/Data/OwnResponseProblemData.php` — the same idea one step further: it OVERRIDES `toResponse()`
  and builds `new JsonResponse($this->transform(…WrapExecutionType::Disabled), $status, [headers])`
  itself. The engine must decline to model spatie's own `toResponse()` here and let the constructor
  fold win, or the app's real status and media type are thrown away.
- `app/Data/SnapshotData.php` + `app/Data/SnapshotFormData.php` — a response Data class typed the way a
  real one is: every array member's generic lives in the PROMOTED PARAMETER's own `@var`, beside the prose
  describing it, except `context`, whose generic is written once in the constructor's `@param` block. Only
  `context` survives today; the rest pin the degraded output (see
  `php/inference-phpstan/tests/Integration/PromotedPropertyDocblockTest.php`). The members cover a map, a
  nested map, a `list<SnapshotFormData>`, an `array<int, string>` and a `@phpstan-var` tag.
  `SnapshotFormData` carries a NATIVE backed-enum property, the working half of the enum contrast against
  `Listing`'s docblock-only `@property ListingStatus $status`.
- `app/Data/MfaChallengeData.php` — a `DataCollection` with no `#[DataCollectionOf]` whose item class is
  named only by the constructor `@param` generic: a bare `DataCollection` reflects as a precise class, so
  that generic is never consulted.
- `app/Data/SaveAnswersData.php` — a request DTO whose map/list generics ARE recovered (they are in the
  `@param` block) and are then collapsed to a bare `array` by the validation-rule vocabulary.
- `app/Data/UpdateNodeData.php` — a static `rules()` naming `label`, a field the class has no property
  for, only to `prohibit` it, plus a dotted `metadata.retention.mode` key constraining one member of the
  metadata blob, plus a POSITIONAL `array{float, float} $position` tuple: the docblock grammar has only
  the keys to tell it that is a JSON array, and a shape it read as an object would document `"0"`/`"1"`
  property names.
- `app/Data/ActionPreviewData.php` + `app/Rules/MaxJsonByteSize.php` — the commonest override there is: a
  static `rules()` that only restates `array` over properties whose generics the constructor `@param`
  block already recovered (a map, a nullable map alongside a size-only custom rule, and a list). `array`
  is the one word the vocabulary has for every array shape, so restating it must not collapse a recovered
  map to `{"type": "array"}` — a schema the JSON object the API accepts would fail.
- `app/Data/ConditionalThingData.php` — a `calculateResponseStatus()` override choosing between two class
  constants on `$request->routeIs(...)`. The engine folds the return type to `200|201`; the adapter's
  resolver then reads the ternary off the AST and narrows it to the one status each route takes, which is
  what stops a GET being documented with a 201 the server can never send.
- `app/Data/GuardedThingData.php` + `app/Data/FlaggedThingData.php` — the same decision carrying a SECOND
  `return`: a guard clause, and an unreachable branch above the ternary. Both keep the whole union on
  every route, which is what pins the narrowing's return count against the analyser's own walk rather
  than against a plain parse of the file — a `return` PHPStan omitted would narrow a body it must not.
- `app/Data/MergedRulesData.php` — the class-level `#[MergeValidationRules]`, which flips spatie's
  resolver from `add` (replace at the key) to `merge` (append), so the property's own `#[Max(255)]` keeps
  applying alongside the override.
- `app/Data/UploadPolicyData.php` + `app/Support/MediaCollections.php` — a static `rules()` allow-listing a
  natively typed `#[StringType]` property with `Rule::in(MediaCollections::validNames())`: the values are
  not statically knowable, and the override still replaces what the property type inferred.
- `app/Http/Controllers/SsoRedirectController.php` + `app/Services/SsoGateway.php` +
  `app/Contracts/IdentityProvider.php` — a `RedirectResponse` action and two `JsonResponse` actions whose
  payloads are named nowhere, the shapes the response side degrades on. The action typed on the CONTRACT is
  the genuinely bare one: an interface has no body to follow, so nothing is recovered at all. The one typed
  on the concrete gateway stamps its status fluently, so a status is recovered and the payload still is not
  — the case that must keep saying its body is unrecovered rather than passing for described.
- `app/Http/Controllers/WebhookReceiptController.php` — responses whose status (and sometimes media type)
  is stamped on through Symfony's fluent setters after the body was built: `->setStatusCode()` over
  `response()->json()` and over `new JsonResponse(…)`, reached across `->header()`/`->withHeaders()` links,
  plus the three degradations — a status that will not fold, a header name that will not, and a
  `->setData()` that may have replaced the body and so refuses the whole chain.
- `app/Http/Controllers/FileDeliveryController.php` — the rest of the framework response family, declared
  the way a real controller declares it: `BinaryFileResponse` from `response()->download()` and
  `response()->file()`, `StreamedResponse` from `response()->stream()`, `streamDownload()`,
  `eventStream()` and `Storage::download()`, and the plain `Illuminate\Http\Response` from `response()`.
  Each is proof that the class the response guard names is one the engine really recovers — and, since
  the download and the inline file are the SAME class, that the call site is where the media type and the
  disposition really come from. `download()` wraps its path in `storage_path(...)`, which PHPStan does not
  fold, so the extension has to survive that; `eventStream()` is not on the response-factory contract the
  `response()` helper is typed as, so its recovery is a fact about the analyser rather than an assumption.
- `app/Http/Controllers/DashboardPageController.php` — a Blade page: `view('…')` behind a declared
  `: View` (the contract) and behind no return type at all. Proof that the engine hands back the CONCRETE
  `Illuminate\View\View` either way, so recognising only the contract would miss every real app.
- `app/Models/Product.php` — an idiomatic Eloquent model declaring NO public column properties
  (magic attributes) and documenting its columns the ide-helper way, via class-level
  `@property`/`@property-read` docblock tags (`id: int`, `sku: string`, `description: ?string`,
  `name: string`), so `classMetadata()` recovers the column universe and its types from the
  docblocks — the shape a real model has (Finding 0). Its `$casts` (a `ListingStatus` enum + a
  `boolean` + a `hashed` column) and `$hidden` drive the adapter-side floor + visibility union
  (proven in-process by the Eloquent mapper test). Only ever reflected — never queried.

### Inferred exception-handler tier

- `app/Exceptions/ProblemRenderer.php` — a `render(Throwable $e): JsonResponse` with sequential
  `if ($e instanceof …)` branches returning `response()->json($problem, $status)` per exception
  type (422/401 + a 500 default), so narrowed analysis recovers one branch per thrown type. It
  also carries `renderAmbiguous(Throwable $e)` — a NEGATED guard
  (`if (! ($e instanceof OutOfStockException))`) putting the broad default ahead of the specific
  branch, so narrowing to `OutOfStockException` raises the `inference.ambiguous-narrowing` info
  diagnostic.
- `app/Exceptions/DataProblemRenderer.php` — a renderer that documents its errors through a spatie Data
  object rather than an array literal, so every problem response shares one component. Its arms differ in
  how far the `new` sits from the response: the validation arm goes through
  `DataProblemDocument::make()` (a factory hop) and supplies both optional members; the `HttpException`
  arm reaches the Data through two hops and supplies neither; one arm goes to the class that writes its
  own response (`OwnResponseProblemData`); the `ArithmeticError` arm passes a class constant named like a
  credential (`self::SUPPORT_API_KEY`) and renders through `toNegotiatedResponse()`, pinning both refusals
  — no folded secret in a published example, and no media-type label borrowed from the helper's other
  branch; the `JsonException` arm writes the same credential behind a `??` default, which PHPStan types as
  the constant's own string and a guard reading only the outermost expression would fold; the
  `DomainException` arm titles its problem with `__('errors.forbidden')`, whose answer is the ANALYSING
  process's locale rather than the contract, so it pins that such a member stays UNREAD — a folded
  translation would publish one machine's words as the contract; and the fallback writes every argument
  as a literal at the call site.
- `app/Exceptions/DataProblemDocument.php` — the Data-object counterpart of
  `ProblemResponse::fromProblem()`: a factory answering the DTO rather than a response, so the
  constructor is a call hop away from the response and every member reads off one of the factory's
  parameters — a bound `InvoiceProblem` case's accessors, a plain string, or an `?? new Optional` tail
  whose member only exists when the caller passed it. `traced()` writes that tail over a READ of a
  parameter (`$trace->currentId() ?? new Optional`) rather than over the parameter itself.
- `app/Exceptions/RefinerEdgeCases.php` + `app/Support/TraceContext.php` — the refiner's remaining edge
  paths, including every way spatie's "omit this key" idiom reaches a call site that cannot settle it:
  `unbindableOptionalMember()` writes `?? new Optional` with a STATIC read on its left
  (`TraceContext::id()`, the shape of an app's `Tracer::traceId()`), which no call site anywhere can
  settle; `nullableOptionalMember()` reaches the same idiom a factory hop away with a value that may be
  null; `methodOptionalMember()` hands over a receiver that certainly exists while the tail waits on what
  a method on it answers; and `forwardedOptionalMember()` passes a value that is never null and may
  already be the marker. All four must be recorded as members the body may omit, never as ones it carries
  — only an argument that IS the awaited value can settle one.
- `app/Exceptions/PortalProblemRenderer.php` + `app/Exceptions/RendersProblems.php` + the
  `PortalException` family (`HasProblemFields`, `HasRetryWindow`, `PortalRejectedException`,
  `PortalThrottledException`, `PortalUnavailableException`) — the shape a class-level `#[ErrorComponent]`
  cannot separate: one annotated base, three arms dispatching on it plus a marker interface, three
  different bodies, all built through one `problem()` helper inherited from another file. Two arms carry
  their own `#[ErrorComponent]` and win over the helper's (the OUTERMOST declaring hop on a render path
  wins); the third carries none, so the helper's house name stands for it and the helper's file has to
  reach the route's dependency set. The bodies deliberately reach the helper as a whole `array` argument,
  so the payload honestly widens to `array<string, mixed>` while the names still tell the arms apart.
- `app/Exceptions/SubmissionLockedException.php` — a renderable exception whose `render()` carries the
  attribute: the analysed method is the outermost hop on its own path.
- `app/Exceptions/GroupedProblemRenderer.php` + `app/Exceptions/RendersGroupedProblems.php` — the
  `match (true)` renderer read off the AST rather than off PHPStan's per-return narrowing, in every shape
  the grammar has: one arm listing SEVERAL exception types (an arm fires when ANY of its conditions holds,
  so both types have to reach that arm's body), an arm whose `||` other side says nothing about the
  parameter (so anything reaches it), an arm whose `&&` still requires both, and two arms building through
  a helper the class gets from a TRAIT — one carrying `#[ErrorComponent]` and one carrying none, since a
  trait-imported method is reported as the using class's own and both what it declares and what it does not
  are only reachable through the trait's own file.
- `app/Exceptions/RenderCallbacks.php` — a method returning a per-exception render closure
  (`fn (OutOfStockException $e) => response()->json(['error' => …], 409)`), analysed by
  file+line.
- `app/Exceptions/InvokableProblemRenderer.php` — a catch-all `__invoke(Throwable $e): JsonResponse`
  with sequential `instanceof` branches (409/401 + a 500 default) emitting a distinct
  `application/problem+json`-style body (a `type`/`title`/`status`/`instance` shape). Registered as an
  invokable object, it reaches the handler as a `Closure::fromCallable()` naming `__invoke`, so it is
  analysed as that METHOD with `$e` narrowed — the shape a by-line closure lookup would miss.
- `app/Exceptions/HeaderPreservingRenderer.php` — the renderer that NAMES its response in a local before
  returning it (what copying an exception's protocol headers onto the body forces), in three arms: the call
  returned straight out, one assignment then a mutation, and two branches writing one local (a refusal).
- `app/Exceptions/RebuiltProblemRenderer.php` — the same local REBUILT after its first assignment, by a
  `list()` destructuring, a `foreach` binding and a callee taking it by reference. None of the three is an
  `=`, and all three must retire the first expression rather than publish its body.
- `app/Exceptions/DecoratedProblemRenderer.php` — a `render()` that returns the response it was handed,
  beside an inline renderer with its own `render()` building a 418 into a local of the same name. One file,
  two bodies: the harvest has to carry the class or each answers for the other.

### Arguments the call site does not write out

Every reader that wants argument N reads a POSITION, and a spread fills its own and every later one from a
sequence — so the slot a reader indexes is not the value the call receives. An argument that is written but
does not fold loses the same value without moving anything, which is why both shapes are here. Each file
below writes one such call in the shape an app writes it, beside the same call written out, so the pair
pins what widens and what does not.

- `app/Http/Requests/SpreadChoicesRequest.php` — a `Rule::in('any', ...$this->statuses())` and a
  `Rule::enum(...)->only(Open, ...$this->alsoAllowed())`, plus a `visibility` field stating every value at
  the rule. The written half is the hazard: published on its own it is a SHORTER list of legal values than
  the endpoint accepts, so the constraint is dropped and `validation.rule-values-unread` says so.
- `app/Http/Requests/UnreadChoicesRequest.php` — the same truncation with no spread in it:
  `Rule::in('any', $this->fallbackStatus())` and `->only([ListingStatus::Open, $this->alsoAllowed()])`
  each name one value the fold cannot read. A reader watching only for spreads walked straight past
  both and published a list one value short.
- `app/Http/Controllers/SpreadResponseController.php` — `response()->json(...)`, `new JsonResponse(...)`
  and `response()->noContent(...)` all handed an argument list built by a private helper, with `index()`
  writing the same envelope at the call site as the control. Read positionally, the argument LIST is
  documented as the response body and the framework's default status is published for a call that states
  its own.
- `app/Exceptions/SpreadProblemRenderer.php` — a renderer spreading its arguments into
  `DataProblemDocument::make()`, whose every body member reads one of those parameters. Read as "the call
  site passed nothing", each member is deleted from a body that always carries it.
- `app/Http/Controllers/TrashedFilterController.php` — `AllowedFilter::trashed($this->trashedFilterKey())`.
  Spatie's documented `trashed` default is true of a call that passed NO name; this one passes one, so
  publishing `trashed` names a query parameter the endpoint does not have.

### Page-size recovery

The size argument of a paginating terminal, followed back to the request key it came from — and, just as
importantly, NOT followed where the key only chose between sizes.

- `app/Support/ListPageSize.php` — the shared clamp (`clamp()`, reading `per_page` with the caller's own
  `$default`) plus `limit()`, which names its read in a local first and carries a literal fallback.
- `app/Support/Concerns/ClampsPageSize.php` + `app/Support/TeamPageSize.php` — the same clamp arriving from
  a TRAIT, with the trait's read deliberately at a line the using class's own `summarySize()` spans: a
  file+line pair only means something when both halves came from one source.
- `app/Support/PresetPageSize.php` — two helpers that read the request and answer with a literal of their
  own (a `match` subject, an `if` condition), so neither key is a page size.
- `app/Http/Controllers/PageSizeEvidenceController.php` — one list endpoint per helper, all paging the same
  model, so the only difference between a documented key and none is what the helper's value was built from.
- `app/Http/Controllers/RequestPagedListController.php` +
  `app/Http/Controllers/RequestPagedCollectionController.php` — the three-frame shapes: a custom
  Query-Builder terminal handing the request to the clamp, and a resource collection doing the same with no
  Query Builder anywhere.

### JSON:API + laravel-actions recovery

- `app/Http/Resources/ArticleJsonApiResource.php` — a real `timacdonald/json-api` resource; the
  engine analyses `toAttributes()` into `{title: string, body: string}`, the shape the shared
  JSON:API document builder consumes.
- `app/Http/Controllers/JsonApiPaginateController.php` — paginates through spatie's
  `jsonPaginate()` macro inside a helper on a `where`-narrowed Eloquent builder, with two literal
  overrides (`jsonPaginate(100, 25)`), so the real `JsonApiPaginateTraceVisitor` proves terminal
  recognition, builder-receiver matching, and argument folding one call deep.
- `app/Actions/PublishArticleAction.php` — a `lorisleiva/laravel-actions` action whose literal
  `rules()` array the engine recovers into a constant shape, turned into a `RuleSet` by
  `ShapeToRuleSet`.
- `app/Providers/AppServiceProvider.php` — named rate limiters in their idiomatic shapes: the
  Laravel-11 skeleton default `api` arrow limiter (`fn ($r) => Limit::perMinute(60)->by(…)`), a
  full-closure `uploads` limiter on a per-hour window, and a conditional `dynamic` one. The engine's
  closure trace (via `trace-rate-limiter`, located by line) folds the first two to concrete numbers
  and leaves the conditional one numberless (small-integrations §1, Wave D item 4).
- `app/Http/Requests/StoreListingRequest.php` — a FormRequest whose `rules()` mixes a pipe-string
  rule, a `Rule::enum(ListingStatus::class)` factory descriptor, and a closure rule, so the real
  `RulesMethodVisitor` proves descriptor folding inside a FormRequest (enum backing values + FQCN)
  and the `validation.rule-unrecoverable` diagnostic for the closure field (validation §1).
- `app/Rules/SortCode.php`, `app/Rules/OpaqueSignature.php` and
  `app/Http/Requests/StorePaymentRequest.php` — a custom rule class carrying `#[RuleSchema]`, an
  unannotated sibling, and a FormRequest validating with `new` instances of both: the real fold proves an
  annotated rule OBJECT documents from its class attribute while the unannotated one stays diagnosed.

### API version changes

- `app/Versioning/SnapshotsRenamedTheirCandidate.php` and
  `app/Versioning/SnapshotsStoppedPublishingTheirLegacyForm.php` — two `#[ApiVersionChange]` classes
  where a real application keeps them: under `app/`, discovered by scanning the directory the document
  names in `api_version.changes.dir`. They declare a rename, a required-ness change and a removal over
  `App\Data\SnapshotData`, whose members the analyser recovers out of `@var`, `@phpstan-var` and a
  constructor `@param` block — so `RealEngineVersioningTest` derives a version from what the engine
  really found rather than from a schema written to suit the assertion. The removal's `type:` names
  `App\Data\SnapshotFormData`, which is a component only because the chain hoisted it, so the
  re-added field's `$ref` is a pointer at the real document's own naming.

  Nothing dispatches them and nothing loads the fixture app's autoloader in the Pest process; the
  suite registers a two-line PSR-4 loader for `App\Versioning\` alone (`loadFixtureAppVersionChanges()`
  in `tests/Pest.php`), because a version change is read by reflection and so has to be loadable, while
  every `::class` in its arguments is a compile-time string that never is.
