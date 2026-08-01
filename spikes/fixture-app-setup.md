# Fixture app — how to recreate `spikes/fixture-app/`

`spikes/fixture-app/` is **git-ignored** (a full Laravel install; see the root
`.gitignore` entry `/spikes/fixture-app/`). The spikes (A/B/C) share this one
fixture app. Recreate it exactly like this.

## Host used

- macOS (Darwin arm64)
- PHP 8.5.9 (CLI on PATH)
- Composer 2.9.5

## Commands

Run from `spikes/`:

```bash
# 1. Laravel 12 skeleton. Installed cleanly on PHP 8.5.9 — no installer
#    resistance, no --ignore-platform-reqs needed.
composer create-project laravel/laravel fixture-app "^12.0" --no-interaction

cd fixture-app

# 2. Larastan (dev) — pulls phpstan/phpstan as a transitive dep.
composer require --dev larastan/larastan --no-interaction

# 3. Spatie packages (for Spikes B/C, which reuse this fixture).
composer require spatie/laravel-query-builder spatie/laravel-data --no-interaction
```

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

## Fixture source added on top of the skeleton (tracked via the spike, applied into the ignored app)

These two files are authored by Spike A and must be created inside the fixture
app after install (they are the analysis targets):

- `app/Http/Controllers/SpikeController.php` — four actions:
  `listUsers()`, `jsonShape()`, `resourceCollection()`, `unionAction()`.
- `app/Http/Resources/UserResource.php` — minimal `JsonResource` (`@mixin User`).

The canonical copies live next to this file at
`spikes/spike-a/fixture-src/` — copy them into the fixture app:

```bash
cp -R spikes/spike-a/fixture-src/app/. spikes/fixture-app/app/
```

The default `App\Models\User` model is used as-is.

## Fixture source authored by Spike C (exception-flow analysis)

Spike C adds an exception-heavy controller plus a two-level service layer. The
canonical copies live at `spikes/spike-c/fixture-src/`; copy them into the
ignored app the same way:

```bash
cp -R spikes/spike-c/fixture-src/app/. spikes/fixture-app/app/
```

- `app/Http/Controllers/ThrowsController.php` — eight actions, one per Spike C
  case (abort/abort_if, authorize, findOrFail, inline validate, a 2-deep service
  call with no `@throws`, the same with `@throws`, a vendor any-throwable call,
  and a try/catch).
- `app/Services/OrderService.php` — `place()` / `placeDeclared()` / `reserve()`,
  the 2-level throw chain descended by Layer 3.
- `app/Exceptions/OutOfStockException.php` — a custom domain exception.

No routes, migrations, or `composer` changes are needed — the spike drives the
files by path (it never boots HTTP), and `App\Models\User` / `UserResource`
(from Spike A) are reused as-is.
