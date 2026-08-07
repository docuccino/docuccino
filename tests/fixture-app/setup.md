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
| phpstan/phpstan | 2.2.7 |
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
