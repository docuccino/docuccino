---
title: Commands
description: The docuccino artisan commands — export, validate, diff, cache, clear — with real flag tables.
---


Docuccino registers five artisan commands. Shared behavior:

- **Enabled guard.** Every command except `docuccino:clear` aborts (exit 1) when
  `config('docuccino.enabled') === false`, printing a notice. `clear` runs even when disabled, so
  you can always flush the cache.
- **`{document?}` argument.** Omit it to run over *every* configured document; pass a key to run
  one. An unknown key errors and exits non-zero. Per-document results aggregate — any single
  failure fails the whole command.
- **Diagnostics.** Commands print diagnostics grouped by route signature in deterministic order.

## `docuccino:export`

Generate and export API documentation from your routes.

```
docuccino:export
    {document? : The configured document key (defaults to every document)}
    {--format= : uir | openapi-3.2 | openapi-3.1 (defaults to openapi-3.2)}
    {--out= : Output path (defaults to the document export path)}
    {--fail-on=none : none | warning | error — the severity that makes the command exit non-zero}
    {--provenance=winners : none | winners | full — UIR provenance detail}
    {--yaml : Emit YAML instead of JSON}
```

| Flag | Values / default | Effect |
| --- | --- | --- |
| `document` | any configured key / all documents | Which document(s) to export. Unknown key → exit 1. |
| `--format` | `uir` \| `openapi-3.2` \| `openapi-3.1` / `openapi-3.2` | Selects the emitter. `uir` → raw UIR; `openapi-3.1` → the downlevel emitter; anything else → OAS 3.2. An invalid value errors (no silent fallback). |
| `--out` | path / document's `export.path` | Overrides the output path (resolved absolute against `base_path()`; missing directories created). Forbidden when exporting multiple documents without a `document` argument. |
| `--fail-on` | `none` \| `warning` \| `error` / `none` | Severity that makes the exit code non-zero: `warning` fails on any warning or error; `error` fails only on errors; `none` never fails on severity. |
| `--provenance` | `none` \| `winners` \| `full` / `winners` | UIR provenance detail (only meaningful for `--format=uir`). Unrecognized values fall back to `winners`. |
| `--yaml` | flag / off | Emit YAML instead of JSON. |

**Committing the output.** Docuccino's output is deterministic — identical code produces
byte-for-byte identical output. Commit `docs/openapi.json` (or a UIR document) and diff it in CI — see
[`docuccino:diff`](#docuccinodiff). For the committed artifact, `--provenance=none` (or `winners`,
accepting that source line numbers churn as code moves — churn is cosmetic and never alters
identities or the content hash) is the recommendation.

## `docuccino:validate`

Validate the generated document(s) against the bundled UIR schema.

```
docuccino:validate
    {document? : The configured document key (defaults to every document)}
    {--fail-on=none : none | warning | error — extra diagnostic severity that also fails (a schema violation always fails)}
```

| Flag | Values / default | Effect |
| --- | --- | --- |
| `document` | configured key / all | Which document(s) to validate. Unknown → exit 1. |
| `--fail-on` | `none` \| `warning` \| `error` / `none` | *Additional* severity that also fails. Independent of the schema check. |

**A schema violation always fails**, even with the default `--fail-on=none`. `--fail-on` only adds
warning/error gating on top. Each valid document prints `<key>: valid against UIR <version>.`.

## `docuccino:diff`

Diff a committed API artifact against the current document — semantic, id-based.

```
docuccino:diff
    {old : Path to the committed UIR/OpenAPI artifact to diff against}
    {document? : The configured document key to generate as the new side (defaults to "default")}
    {--against= : Read `old` from this git ref (git show <ref>:<old>) instead of the working tree}
    {--enforce : Enforce the document's versioning policy; exit non-zero on a violation}
    {--format=terminal : terminal | json}
```

The diff is computed over stable `x-docuccino.id`s, so a path-param rename reads as "no change"
while a URI change reads as remove + add. Prefer a UIR artifact for `old` — it carries the
identities.

| Flag | Values / default | Effect |
| --- | --- | --- |
| `old` (required) | path | The "old" side. Missing/unreadable/invalid-JSON → exit 1. |
| `document` | configured key / `"default"` | Which document to generate as the "new" side. Unknown → exit 1. |
| `--against` | git ref, e.g. `HEAD` / unset | Reads `old` via `git show <ref>:<old>` (so `old` must be repo-relative) instead of from disk. Refs/paths starting with `-` are rejected; git failure → exit 1. |
| `--enforce` | flag / off | Enforce the document's [`versioning`](/reference/configuration/#versioning) policy; a violation exits non-zero. Without it, the diff is informational and exits 0 even with changes. |
| `--format` | `terminal` \| `json` / `terminal` | `terminal` renders a human changeset (+ a satisfied/violated policy line when enforced); `json` prints a machine payload. |

### `--enforce` and versioning policies

The policy is chosen from the document's **`versioning` config value** (not a CLI flag). It
compares the changeset's breaking-change severity against both documents' `info.version`:

| Policy | Fails when |
| --- | --- |
| `semver` | The actual `info.version` bump is insufficient for the change severity (e.g. a breaking change without a major bump). The verdict carries the minimum acceptable version. |
| `date` | The new version's date identifier does not advance as the changeset requires. |
| `none` | Never — `--enforce` with `none` always passes on versioning. |

Exit is non-zero when `--enforce` produces an unsatisfied verdict; otherwise the command exits 0.

## `docuccino:cache`

Build and cache the API document(s) for the runtime endpoint.

```
docuccino:cache {document? : The configured document key (defaults to every document)}
```

Builds each selected document and stores its OpenAPI 3.2 payload in the configured Laravel cache
store (`cache.store`) so the runtime viewer can answer `viewer.source: cache` without a rebuild. No
`--fail-on`; diagnostics don't affect the exit code. Fails only on a disabled install or unknown
document key.

## `docuccino:clear`

Clear the cached runtime API document(s).

```
docuccino:clear {document? : The configured document key (defaults to every document)}
```

The inverse of `docuccino:cache`: forgets each selected document's cached payload. Notably it has
**no enabled guard**, so it runs even when `docuccino.enabled` is `false`. Fails only on an unknown
document key.

## Exit-code summary

| Command | Non-zero exit triggers |
| --- | --- |
| `export` | disabled; bad `--format`; `--out` with multiple docs; unknown doc; `--fail-on` severity match |
| `validate` | disabled; unknown doc; any schema violation (always); `--fail-on` severity match |
| `diff` | disabled; unknown doc; bad/missing `old`; git/JSON failure; incomparable docs; `--enforce` + unsatisfied verdict |
| `cache` | disabled; unknown doc |
| `clear` | unknown doc only (no enabled guard) |
