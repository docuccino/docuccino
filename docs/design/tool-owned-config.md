# Tool-owned config

Status: **decided, not built.** This records the decision and what it commits us to, so the work can be
picked up without re-arguing it.

## The decision

Docuccino's configuration moves out of `php/laravel/config/docuccino.php` and into a tool-owned file at
the project root. The Laravel PHP config becomes a deprecated optional override, then goes at 1.0.

## Why, given we argued the other way first

The case against was that a Laravel package's config belongs in `config/`, that `env()` and
`config:cache` are DX we would lose, and that cross-framework consistency is better served by a config
*contract* in core than by a shared file. Three of those four do not survive contact with what Docuccino
actually is.

**Docuccino is a dev tool, not a runtime package.** It installs under `require-dev`, runs as a CLI
command, and produces an artifact. Every comparable tool carries its own config: `phpstan.neon`,
`phpunit.xml`, `rector.php`, `psalm.xml`, `infection.json`. Decisively, **Pint** — a *first-party
Laravel* dev tool — uses `pint.json`, not `config/pint.php`. That is Laravel itself saying dev tooling
does not belong in `config/`.

**`config:cache` was never a real cost.** It is a production optimisation, and a dev-only tool never
runs under cached config in the context that matters.

**`env()` is three toggles.** `enabled`, `engine.mode`, `cache.enabled`. No secrets, no URLs. Those are
CLI flags or a small named allow-list, which is how PHPStan solves the same problem.

**"The adapter owns config" was false.** Of the top-level sections, `documents`, `extensions`, `lint`,
`diagnostics`, `engine` and `cache` are framework-neutral; only `on_route_error` and a handful of
path/filter keys are app-shaped. The file living under `php/laravel/config/` was an accident of history,
not a split anyone designed — so the earlier "core owns the shape, adapters map into it" argument was
describing that accident as though it were the design.

**And the timing argument is what makes this urgent rather than eventual.** We are at 0.14.x. Doing it
now costs one deprecation cycle. Doing it after a second framework adapter exists means paying it twice.
Doing it after 1.0 means paying it against a frozen surface.

## What this commits us to

### Format: YAML, for one specific reason

Not because YAML is better than neon or json, but because **we already read YAML for overlays** and
`symfony/yaml` is already a core dependency. A second format in the same product would be the real cost.

### Determinism is the part most likely to be underestimated

`configHash` is a **fragment-cache key input**. So YAML's parse ambiguities are not cosmetic here:

- YAML 1.1 reads `no`, `off`, `yes`, `on` as booleans — the Norway problem
- an unquoted `1.10` becomes a float, and `0.14.0` stays a string
- an unquoted `null`, `~` and an empty value are three spellings of one thing

Any of those silently changing a parsed value changes the hash, which invalidates or — worse — *fails to
invalidate* the right fragments. This needs explicit parse flags, and a guard that round-trips the
shipped config and fails when a value's type changes. That guard is part of the work, not a follow-up.

### Shape: one document, with the adapter under its own key

App-shaped keys live in the same file under a framework key, the way PHPStan holds Larastan's config:

```yaml
documents: …
lint: …
laravel:
  on_route_error: skeleton
  project_paths: [app, modules]
```

Not a second file. A framework adapter contributes *discovery*, not a separate configuration surface.

### Migration: not a flag day

1. The YAML becomes the source of truth.
2. `config/docuccino.php` stays a recognised override for a release or two, with a diagnostic naming the
   migration and the key that moved. A silent precedence change here would be the worst possible
   outcome, because the symptom is a document that quietly stops matching the config someone edited.
3. The install command writes the YAML.
4. 1.0 drops the PHP file.

### Two guards need rewriting, and they are the reason the config stays honest

- `ConfigReferenceSyncTest` holds the website's configuration reference to the config key-for-key, in
  both directions, commented options included.
- `ShippedConfigTest` holds the file to being **pure data**, so a dev-only install survives a `--no-dev`
  production boot.

Both are written against a PHP file. The second one changes character entirely: purity stops being a
property we assert about PHP source and becomes free, since YAML cannot contain a call. But a new
failure mode replaces it — a YAML file is *parsed at a different time*, so "does this load without the
framework" becomes the thing to guard.

### One benefit that falls out

A root config file is read **before the framework boots**, so the tool becomes runnable without Laravel
at all — which is how PHPStan works, and which makes a second framework adapter a smaller change rather
than a parallel one.

## Fold in: the key audit

58 keys is a lot for a product whose stated position is that an option is an admission we could not work
it out. A restructuring is the moment to ask which of them should not exist — before they are re-spelled
into a new format and become expensive to remove. Each key gets one of: keep, keep-but-rename to
framework-neutral vocabulary, or delete with the default that replaces it.

## Open questions

- **Filename.** `docuccino.yaml` or `.docuccino.yaml`? Dotfile hides it; the tools we are copying mostly
  do not (`phpstan.neon`, `pint.json`, `rector.php`).
- **Does the overlay list stay a config key, or does the config file grow an `overlays:` section that
  can carry actions inline?** Overlays are already YAML; merging them would be tidy and would also blur
  a boundary that currently works.
- **Schema.** PHPStan ships one for its neon. A JSON Schema for the config would give editors
  completion and would let the config reference be *generated* rather than sync-tested.
- **Where the three `env()` toggles land** — CLI flags, a named allow-list, or `${VAR}` interpolation
  (which we would then own the precedence bugs of).

## Not doing

- A `.spectral.yaml` reader, or a Spectral-compatible rule engine. Unmeasured demand.
- A rule *vocabulary* for org lint rules. The ruleset audit found the seven org-config candidates
  collapse to one table of required members keyed by document position, plus a min/max pair on operation
  tag count — a handful of scalars and one list, for which the precedent is the existing
  `lint.leakage.patterns` map. No expression language is needed, so none should be built.
