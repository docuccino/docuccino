# Tool-owned config

Status: **built, with three refinements to the plan below.** The record of the decision stands; where it
and this section disagree, this section is what shipped.

- **`config/docuccino.php` does not disappear.** It keeps what the framework reads while it BOOTS and on
  a viewer REQUEST: `enabled`, each document's whole `viewer` bag, and `cache.store`.
  `packageBooted()` registers viewer routes on every application boot, so wiring read from a project
  file would mean every boot parsing a file somebody may be halfway through editing — and `gate`,
  `driver`, `cdn`, `configuration` and `source` are read per request for the same reason.
- **There is no override and no merge.** A build key left in `config/docuccino.php` is DETECTED and
  reported, never read. That deletes the per-leaf recursive merge, the precedence rule and the
  duplicate-key report this document proposed, along with the failure mode it named as the worst one —
  a document that quietly stops matching the file somebody edited. `ConfigSplit` owns the reporting:
  an unmigrated application is an ERROR and a finished migration with leftovers is a WARNING, one
  diagnostic each naming the keys with the list capped and the rest counted.
- **The three `env()` toggles landed as a closed allow-list**, not as `${VAR}` interpolation.
  `BuildConfig::ENV_OVERRIDES` names two — `DOCUCCINO_ENGINE` over `engine.mode` and
  `DOCUCCINO_FRAGMENT_CACHE` over `cache.enabled`, both of which a RUN has an opinion about — and
  `enabled` never left the framework config, so it keeps its own `env()` there. `--memory-limit`
  reaches `engine.memory_limit` through the container, beside the console marker.

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

### Shape: one document, and no framework section

The plan was that app-shaped keys live in the same file under a framework key, the way PHPStan holds
Larastan's config:

```yaml
documents: …
lint: …
laravel:
  on_route_error: skeleton
  project_paths: [app, modules]
```

**Not built, and deferred rather than pending.** Neither of the two keys illustrating it shipped there:
`on_route_error` is at the root and `project_paths` is under `engine`. A key-by-key audit disqualified
both, because both concepts exist in every framework — every generator has to decide what to do with a
route it cannot read, and every analyser has to be told which directories are the project — and only
their *default values* are Laravel-shaped. A proposed namespace whose only two illustrations do not
qualify has not found its members.

That audit left exactly one candidate, the auth-middleware wildcard, and it was rejected on the test
that matters: the section is for a key whose **concept** has no counterpart in another framework. "Which
requests count as authenticated" has one — Symfony has firewalls. What differs is only the value's
grammar, which is the case the audit's own principle already covers: **an adapter contributes the value
space, not a new key.** `engine.project_paths: ['app']` is the same shape and wants no prefix either.

So a section built now would be seeded with the one key a second adapter would most likely want back
out, and every key that moves in or out of it costs a golden regeneration — the config hash is over the
document's whole bag, so the price is paid twice for a namespace nothing yet needs.

The section stays deferred until a key turns up whose concept a second adapter cannot honour at all. If
one does, it is still one file and not a second: a framework adapter contributes *discovery*, not a
separate configuration surface.

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
