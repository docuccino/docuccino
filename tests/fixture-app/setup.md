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

- `app/Http/Controllers/ThrowsController.php` — eight actions covering abort/abort_if,
  authorize, findOrFail, inline `validate()`, a 2-deep service call with and without
  `@throws`, a vendor any-throwable call, and a try/catch.
- `app/Services/OrderService.php` — `place()` / `placeDeclared()` / `reserve()`, the 2-level
  throw chain descended by the exception-flow layer.
- `app/Exceptions/OutOfStockException.php` — a custom domain exception (also reused by the
  inferred-handler sources below).

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
- `app/Data/MergedRulesData.php` — the class-level `#[MergeValidationRules]`, which flips spatie's
  resolver from `add` (replace at the key) to `merge` (append), so the property's own `#[Max(255)]` keeps
  applying alongside the override.
- `app/Data/UploadPolicyData.php` + `app/Support/MediaCollections.php` — a static `rules()` allow-listing a
  natively typed `#[StringType]` property with `Rule::in(MediaCollections::validNames())`: the values are
  not statically knowable, and the override still replaces what the property type inferred.
- `app/Http/Controllers/SsoRedirectController.php` + `app/Services/SsoGateway.php` — a `RedirectResponse`
  action and a `JsonResponse` action whose payloads are named nowhere, the two shapes the response side
  degrades on.
- `app/Http/Controllers/FileDeliveryController.php` — the rest of the framework response family, declared
  the way a real controller declares it: `BinaryFileResponse` from `response()->download()`,
  `StreamedResponse` from `response()->stream()`, and the plain `Illuminate\Http\Response` from
  `response()`. Each is proof that the class the response guard names is one the engine really recovers.
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
  the constant's own string and a guard reading only the outermost expression would fold; and the fallback
  writes every argument as a literal at the call site.
- `app/Exceptions/DataProblemDocument.php` — the Data-object counterpart of
  `ProblemResponse::fromProblem()`: a factory answering the DTO rather than a response, so the
  constructor is a call hop away from the response and every member reads off one of the factory's
  parameters — a bound `InvoiceProblem` case's accessors, a plain string, or an `?? new Optional` tail
  whose member only exists when the caller passed it.
- `app/Exceptions/RefinerEdgeCases.php` + `app/Support/TraceContext.php` — the refiner's remaining edge
  paths, including `unbindableOptionalMember()`: the same `?? new Optional` idiom with a STATIC read on
  its left (`TraceContext::id()`, the shape of an app's `Tracer::traceId()`), so no call site anywhere can
  settle whether the member is there and it must not be recorded as one this response carries.
- `app/Exceptions/RenderCallbacks.php` — a method returning a per-exception render closure
  (`fn (OutOfStockException $e) => response()->json(['error' => …], 409)`), analysed by
  file+line.
- `app/Exceptions/InvokableProblemRenderer.php` — a catch-all `__invoke(Throwable $e): JsonResponse`
  with sequential `instanceof` branches (409/401 + a 500 default) emitting a distinct
  `application/problem+json`-style body (a `type`/`title`/`status`/`instance` shape). Registered as an
  invokable object, it reaches the handler as a `Closure::fromCallable()` naming `__invoke`, so it is
  analysed as that METHOD with `$e` narrowed — the shape a by-line closure lookup would miss.

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
