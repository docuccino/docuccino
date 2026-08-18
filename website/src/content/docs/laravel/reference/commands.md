---
title: Commands
description: The five docuccino artisan commands — export, validate, diff, cache and clear — with every flag, default and exit code.
---


Docuccino registers five artisan commands. Every one exits `0` on success and `1` on failure, so
each is safe to gate a CI job on.

Shared behavior:

- **Enabled guard.** Every command except `docuccino:clear` aborts with exit `1` when
  `config('docuccino.enabled')` is `false`, printing
  `Docuccino is disabled (set docuccino.enabled = true to run this command).` `clear` has no guard,
  so you can always flush the cache.
- **`{document?}` argument.** Omit it to run over *every* configured document; pass a key to run
  one. **`docuccino:diff` is the exception** — with no `{document}` it diffs the `default`
  document only, never all of them. An unknown key errors and exits `1`. Per-document results
  aggregate: any single document failing fails the whole command.
- **Diagnostics.** `export`, `validate` and `cache` print diagnostics grouped by route signature in
  deterministic order; `diff` and `clear` print none.
- **`--memory-limit`.** Accepted by every command that builds a document — `export`, `validate`,
  `diff`, `cache` — since inference runs a static analyzer inside the artisan process. Raise-only: a
  process already running with a higher limit is left alone, and `-1` is rejected. Same lever as
  [`engine.memory_limit`](/laravel/reference/configuration/#engine), and the flag wins. `clear` builds
  nothing, so it doesn't take it.

## `docuccino:export`

Generate and export API documentation from your routes.

```
docuccino:export
    {document? : The configured document key (defaults to every document)}
    {--format= : uir | openapi-3.2 | openapi-3.1 | openapi-3.0 | postman — writes this one format instead of the configured targets}
    {--out= : Output path (defaults to the matching target, else the document export path)}
    {--fail-on=none : none | warning | error — the severity that makes the command exit non-zero}
    {--provenance=winners : none | winners | full — UIR provenance detail}
    {--drop-ids : Omit the flat x-docuccino-id member OpenAPI output carries by default (the artifact then diffs by method + path)}
    {--yaml : Emit YAML instead of JSON}
    {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}
```

| Flag | Values / default | Effect |
| --- | --- | --- |
| `document` | any configured key / all documents | Which document(s) to export. Unknown key → exit 1. |
| `--format` | `uir` \| `openapi-3.2` \| `openapi-3.1` \| `openapi-3.0` \| `postman` / all configured targets | Writes **only** this format, replacing the document's [`export.targets`](/laravel/reference/configuration/#export) for that run. `uir` → raw UIR; `openapi-3.1` and `openapi-3.0` → the downlevel emitters; `postman` → a [Postman Collection v2.1.0](#postman-collections). An invalid value errors (no silent fallback). |
| `--out` | path / the matching target, else [`export.path`](/laravel/reference/configuration/#export) | Overrides the output path — resolved against `base_path()` unless already absolute, and missing directories are created. Rejected when it would have to hold several artifacts at once: more than one document configured and no `document` argument, or a document with several [`export.targets`](/laravel/reference/configuration/#export) and no `--format` — in both cases each write would clobber the last. Name a document, pass `--format`, or configure per-document targets. |
| `--fail-on` | `none` \| `warning` \| `error` / `none` | Severity that makes the exit code non-zero: `warning` fails on any warning or error; `error` fails only on errors; `none` never fails on severity. An invalid value errors (no silent fallback) — a typo must not quietly remove the gate. |
| `--provenance` | `none` \| `winners` \| `full` / `winners` | UIR provenance detail. `full` keeps every record including its `overrode` trail, `winners` keeps the records but drops the trails, `none` strips provenance entirely. An invalid value errors (no silent fallback). Only `--format=uir` carries provenance — the OpenAPI emitters always drop it. |
| `--drop-ids` | flag / off | Omits the flat `x-docuccino-id` member. OpenAPI exports carry it **by default**: `x-docuccino` itself never survives emission (it holds provenance — source file, line, symbol — which has no business in a published spec), but the id is an opaque hash of members the document already publishes, and it is what lets [`docuccino:diff`](#docuccinodiff) pair a committed artifact by identity instead of by method + path. Drop it if you want bytes indistinguishable from a hand-written spec, accepting the weaker diff. No effect on `--format=uir`, which carries identities natively. |
| `--yaml` | flag / off | Emit YAML instead of JSON, for the single-target `--format` override. Configured targets state it in their own path instead (`.yaml`/`.yml`). Rejected with `--format=uir` and `--format=postman`, which have no YAML form. |
| `--memory-limit` | php.ini value, e.g. `2G` / unset | Raises the process memory limit before inference runs — see the shared-behavior note above. |

**One build, many artifacts.** With no `--format`, the command writes every target the document
configures — a single analysis feeding each emitter in turn. It prints `Wrote <path> (<format>).` per
target, in configured order, then any diagnostics.

`--format` and `--out` **replace** that list for the run rather than filtering it, so
`--format=openapi-3.0` gives you a 3.0 file whether or not a 3.0 target is configured. When one is,
that target's path is used — looked up by format, so which file you get never depends on how the list
happens to be ordered.

A target list the command cannot honor — an unknown format, two targets writing one file, a `.yaml`
path on `uir` — fails with a `config.export-*` error **before** the build starts, so a wrong filename
never costs you an analysis. A write that fails prints `Could not write <path>.` instead of `Wrote`,
and the command exits non-zero.

**Downlevel notes.** OpenAPI 3.1 and 3.0 are older, smaller specs, so a downlevel sometimes has to
convert or drop something the UIR carries. Every one of those steps prints a `downlevel.*` diagnostic
naming the construct and the JSON pointer it sat at, right after that target's `Wrote` line — so the
artifact never quietly ships a weaker contract than your code describes. These are reported, not
enforced: `--fail-on` reads the **build's** diagnostics, so adding a 3.0 target never turns a green
pipeline red on its own. The table under
[OpenAPI 3.0 export](/laravel/getting-started/first-export/#openapi-30-export) lists what 3.0 changes.

### Postman collections

`--format=postman` (or a `postman` export target) writes a **Postman Collection v2.1.0** from the same
build as your OpenAPI file:

- **Folders follow your tags**, nested the way `tags.definitions` nests them. A tag with no operations
  is left out.
- **`{{baseUrl}}`** comes from your first configured server, and every server variable becomes a
  collection variable of its own — so switching tenant or version is one edit.
- **Auth** maps onto Postman's own block (`bearer`, `basic`, `digest`, `apikey`, `oauth2`), with the
  credentials as `{{variable}}` references named after your security schemes.
- **Request bodies** are generated from each schema, so a request is runnable rather than empty, and
  every documented response is saved as an example.

Postman cannot hold a JSON Schema, so a collection is a weaker contract than the OpenAPI file — keep
emitting both. Where something has no Postman equivalent at all (webhooks, callbacks, `mutualTLS` and
`openIdConnect` schemes) a `postman.*` diagnostic names it rather than letting the file go quiet.

**Committing the output.** Docuccino's output is deterministic — identical code produces
byte-for-byte identical output. Commit `docs/openapi.json` (or a UIR document) and diff it in CI — see
[`docuccino:diff`](#docuccinodiff). For the committed artifact, `--provenance=none` (or `winners`,
accepting that source line numbers churn as code moves — churn is cosmetic and never alters
identities or the content hash) is the recommendation.

An OpenAPI artifact carries its node identities by default, which is what keeps that diff semantic —
without them it pairs nodes by method + path like any other OpenAPI differ, so renaming a path
parameter reads as a removal plus an addition rather than the no-op it is. `--drop-ids` opts out. The
diff says so when it has to fall back, and never guesses: it will not pair one side's identities
against the other side's paths.

## `docuccino:validate`

Validate the generated document(s) against the bundled UIR schema.

```
docuccino:validate
    {document? : The configured document key (defaults to every document)}
    {--fail-on=none : none | warning | error — extra diagnostic severity that also fails (a schema violation always fails)}
    {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}
```

| Flag | Values / default | Effect |
| --- | --- | --- |
| `document` | configured key / all | Which document(s) to validate. Unknown → exit 1. |
| `--fail-on` | `none` \| `warning` \| `error` / `none` | *Additional* severity that also fails. Independent of the schema check. An invalid value errors, as it does on `export`. |
| `--memory-limit` | php.ini value, e.g. `2G` / unset | Raises the process memory limit; validation generates the document first, so it needs `export`'s headroom. |

**A schema violation always fails**, even with the default `--fail-on=none` — `--fail-on` only adds
warning/error gating on top. A valid document prints `<key>: valid against UIR <version>.`; an
invalid one prints `<key>: N schema violation(s).` and lists them as `document.schema-invalid` error
diagnostics grouped by route.

## `docuccino:diff`

Diff a committed API artifact against the current document — semantic, id-based.

```
docuccino:diff
    {old : Path to the committed UIR/OpenAPI artifact to diff against}
    {document? : The configured document key to generate as the new side (defaults to "default")}
    {--against= : Read `old` from this git ref (git show <ref>:<old>) instead of the working tree}
    {--enforce : Enforce the document's versioning policy; exit non-zero on a violation}
    {--format=terminal : terminal | json}
    {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}
```

The diff is computed over stable `x-docuccino.id`s, so a path-param rename reads as "no change"
while a URI change reads as remove + add. Prefer a UIR artifact for `old` — it carries the
identities natively, and an OpenAPI artifact carries them unless it was exported with
[`--drop-ids`](#docuccinoexport).

When either side has no identities the diff pairs nodes by method + path on **both** sides, like any
other OpenAPI differ, and says so in its output (`pairing: "structural"` in the JSON payload). What
you lose is rename detection: a renamed path parameter reads as a removal plus an addition. What you
never get is a guess — the differ will not pair one side's identities against the other side's paths,
because the two key spaces don't overlap and every operation would read as removed *and* re-added.
Note that content pages live under the document `x-docuccino` and so cannot survive OpenAPI emission
at all; diffing an OpenAPI artifact against a document that has them reports each as added.

When both sides do carry several identities for a kind of node — operations, parameters, component
schemas — and share none of them, every one of those reads as removed *and* re-added. That is what a
pairing failure looks like, so the diff warns and names the kinds (`disjointIdentities` in the JSON
payload): check whether the artifact belongs to another document or predates a change to how ids are
minted, and re-export it if it does. The warning is advisory — `--enforce` never reads it — and stays
quiet when either side carries a single identity of that kind, where one node replaced by another says
the same thing and is far likelier.

Responses and parameters are read through `components` on **both** sides, so one that moved between
inline and [shared](/laravel/documenting/errors/#repeated-bodies-become-shared-components) is not itself
a change, while an edit to a shared one is reported against every operation that `$ref`s it. A parameter
written as a bare `{"$ref": …}` states no `name` and no `in` — the pair that tells one parameter from
another — so resolving it is also what lets the diff tell an operation's `$ref`ed parameters apart at
all. Where a pointer resolves to nothing, the pointer itself does that job.

A `components.schemas` entry that nothing in either document references is a schema no operation can reach,
so no edit to it can change a request or a response. Its changes are still reported — a component name
becomes a type in a generated client — but never as breaking, and the diff names each component it stood
down (`unreferencedComponents` in the JSON payload) so the downgrade is never silent. Reachability is
transitive from the operations: a schema reached only through another schema counts, while one reached only
from a schema that is itself unreferenced does not. Docuccino publishes no unreferenced component, so this
comes up only when `old` is a hand-written or third-party artifact — where a shelf of unused schemas is
ordinary. Deleting a schema the new document still points at is the other side of the same coin: the
pointer is left naming nothing, so that is breaking (`schema.removed-still-referenced`) rather than the
tidying-up a plain `schema.removed` describes.

A schema published under a component name carries an id minted from the bytes it publishes, so editing
the body mints a new one — a [shared error shape](/laravel/documenting/errors/#repeated-bodies-become-shared-components)
that loses a required property is the common case. On ids alone that would read as one schema removed and
another added under the same name, with nothing comparing the two bodies, so a schema whose id pairs with
nothing on the other side is paired by the component name instead. Ids still come first, because they
answer what a name cannot: a schema whose body did not move but whose name did keeps its id, and that is a
rename rather than a removal plus an addition.

`components.securitySchemes` is diffed the same way, keyed by the name a `security` requirement uses. A
scheme some requirement still names is one every client has to satisfy, so changing how — its `type`, `in`,
`name`, `flows`, or any other member that isn't prose — is breaking, and so is deleting it while a
requirement still asks for it. Dropping a scheme along with the requirements naming it is not: the API
stopped asking. A scheme no requirement anywhere names is stood down exactly like an unreferenced schema,
and named in the same list. OpenAPI writes `security` as a list of requirements, and an artifact that wrote
one bare — `{"bearerAuth": []}` where `[{"bearerAuth": []}]` belongs — is read as the requirement it plainly
states, wherever it sits: dropped instead, the scheme would look like one nothing asks for.

Webhooks are diffed as the operations they are, under `webhooks.<name>` in place of a path. A webhook is a
call the API promises to make, and a consumer writes an endpoint against it, so removing one or narrowing
what it sends is breaking on the same terms as an operation under `paths`.

A parameter or response written as a `$ref` is compared as the component it points at. The contract comes
from the component and never from the pointer's neighbours: a parameter takes its `name`, `in`, `required`,
`deprecated` and `schema` from there, and a response its `headers` and `content` — so a `required: false`
next to a pointer at a component that says `required: true` changes nothing, and the diff reports nothing.
A `description` beside the pointer still wins, and anything else stated there — `style`, `explode`,
`example`, an `x-` extension — is read as written.

An operation's parameters are its own plus the ones its path item declares for every operation under it,
minus any the operation restates for the same `name` and `in` — the override OpenAPI specifies. Docuccino
writes parameters on the operation, so this only comes up when `old` is a hand-written or third-party
artifact, which is exactly where a parameter would otherwise go uncompared.

| Flag | Values / default | Effect |
| --- | --- | --- |
| `old` (required) | path | The "old" side. Missing/unreadable/invalid-JSON → exit 1. |
| `document` | configured key / `"default"` | Which document to generate as the "new" side. Unknown → exit 1. |
| `--against` | git ref, e.g. `HEAD` / unset | Reads `old` via `git show <ref>:<old>` (so `old` must be repo-relative) instead of from disk. Refs/paths starting with `-` are rejected; git failure → exit 1. |
| `--enforce` | flag / off | Enforce the document's [`versioning`](/laravel/reference/configuration/#versioning) policy; a violation exits non-zero. Without it, the diff is informational and exits 0 even with changes. |
| `--format` | `terminal` \| `json` / `terminal` | `terminal` renders a human changeset (+ a satisfied/violated policy line when enforced); `json` prints a machine payload. |
| `--memory-limit` | php.ini value, e.g. `2G` / unset | Raises the process memory limit; the "new" side is generated, so the diff needs `export`'s headroom. |

### Output

`terminal` prints a one-line summary (`4 changes (1 breaking)`, or `No API changes.`), then a
`BREAKING` block ahead of a `NON-BREAKING` block, each line marked `+` added, `-` removed, `~`
changed. No color, no timestamps — safe to paste into a PR comment.

`json` prints one object:

```json
{
  "document": "default",
  "breaking": true,
  "counts": { "total": 4, "breaking": 1 },
  "changes": [
    {
      "kind": "removed",
      "target": "parameter",
      "id": "par:v1:k4v2mzq7tn3xrs6b",
      "path": "GET /api/invoices parameters query:status",
      "breaking": true,
      "code": "parameter.removed"
    }
  ],
  "policy": {
    "satisfied": false,
    "policy": "semver",
    "code": "major-bump-required",
    "message": "Breaking changes require a major bump (1.4.0 → 1.5.0).",
    "requiredVersion": "2.0.0"
  }
}
```

`kind` is `added` \| `removed` \| `changed`; `target` is `operation` \| `parameter` \| `response` \|
`schema` \| `securityScheme` \| `page`; `code` is a stable classification such as `parameter.removed`,
`parameter.became-required`, `response.content-removed`, `schema.type-narrowed` or
`securityScheme.changed`. A change carrying field-level detail adds a `fields` array. The `policy`
member appears only with `--enforce`, and `requiredVersion` only on a violation.

### `--enforce` and versioning policies

The policy comes from the document's [**`versioning` config value**](/laravel/reference/configuration/#versioning),
not a CLI flag. It weighs the changeset's breaking changes against both documents' `info.version`.
The three policies differ mostly in what a *breaking* changeset demands:

| `versioning` | A breaking changeset passes when… | Verdict codes |
| --- | --- | --- |
| `none` (default) | **Never.** No version bump rescues it — the contract is declared unbreakable. Versions are never inspected. | `breaking-forbidden` |
| `semver` | The major version went up (`1.4.2` → `2.0.0`). While still at `0.y.z` a minor bump is enough (`0.3.1` → `0.4.0`), per semver §4. | `major-bump-required`, `minor-bump-required`, `invalid-version` |
| `date` | The new `YYYY-MM-DD` version is strictly later than the old one. A trailing suffix is ignored for the comparison, so `2026-08-01` and `2026-08-01-rc1` compare equal. | `new-date-required`, `invalid-date-version` |

A non-breaking changeset passes under all three. Note that `semver` and `date` parse both versions
first, so an unparseable `info.version` on either side is a violation even when nothing about the API
changed — CI never green-lights a malformed version.

An unrecognized `versioning` keyword resolves to `none` — a typo fails closed rather than quietly
waving breaking changes through.

Because `none` is the **default**, a first `--enforce` run rejects every breaking change outright.
That's usually what you want on an internal API; set `versioning` to `semver` or `date` when you're
ready to ship breaking changes behind a version bump.

On a violation the verdict carries the lowest version that would satisfy the policy, printed as
`(require ≥ 2.0.0)` and surfaced as `requiredVersion` in the JSON payload. Only an unsatisfied
verdict makes `--enforce` exit non-zero; without `--enforce` the diff is informational and exits `0`
however large the changeset.

### In CI

Commit the artifact, then fail the build when it drifts from the code or breaks the contract without
a version bump:

```bash
# 1. The committed spec must match the code.
php artisan docuccino:export --provenance=none
git diff --exit-code docs/openapi.json

# 2. The change must be structurally valid…
php artisan docuccino:validate --fail-on=warning

# 3. …and must not break the contract without the version bump the policy demands.
php artisan docuccino:diff docs/openapi.json --against=origin/main --enforce
```

Step 3 reads the artifact as it exists on `main` (`git show origin/main:docs/openapi.json`) and
diffs it against the document generated from the branch, so the check reports exactly what the pull
request changes about your API.

## `docuccino:cache`

Build and cache the API document(s) for the runtime endpoint.

```
docuccino:cache
    {document? : The configured document key (defaults to every document)}
    {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}
```

Builds each selected document and stores its OpenAPI 3.2 payload — JSON, default emit options — under
`docuccino:document:<key>` in the [`cache.store`](/laravel/reference/configuration/#cache) Laravel
cache store (`null` uses your default store), so the runtime viewer can answer
[`viewer.source: cache`](/laravel/reference/configuration/#viewer) without a rebuild. Stored
forever: re-run the command to refresh it, typically as a deploy step.

Prints `Cached document "<key>".` per document, then any diagnostics. There is no `--fail-on` here —
diagnostics never affect the exit code. Fails only on a disabled install or an unknown document key.
`--memory-limit` applies here too — worth knowing, since warming the cache is usually a deploy step.

## `docuccino:clear`

Clear the cached runtime API document(s).

```
docuccino:clear
    {document? : The configured document key (defaults to every document)}
    {--fragments : Also empty the per-operation fragment cache}
```

The inverse of `docuccino:cache`: forgets each selected document's cached payload and prints
`Cleared cached document "<key>".` It is the one command with **no enabled guard**, so it runs even
when `docuccino.enabled` is `false` — you can always flush a stale payload out of an installation
you've just switched off. Fails only on an unknown document key.

`--fragments` additionally empties the [fragment cache](/laravel/reference/configuration/#cache) —
the per-operation store behind `cache.enabled` — and prints
`Cleared N cached operation fragment(s).` That store is shared by every document, so naming one
document still empties all of it (an unknown key fails the command before anything is cleared), and it
is emptied whether or not the fragment cache is currently enabled — it is the supported way to recover
from a fragment store you no longer trust, instead of deleting `storage/docuccino/fragments` by hand.

## Exit codes

Every command returns `0` on success and `1` on failure. What counts as failure:

| Command | Exits `1` when |
| --- | --- |
| `export` | disabled; unknown `--format`, `--fail-on` or `--provenance` value; `--out` given while exporting multiple documents, or without `--format` against a multi-target document; unknown document key; a diagnostic matches `--fail-on` |
| `validate` | disabled; unknown `--fail-on` value; unknown document key; **any** schema violation (regardless of `--fail-on`); a diagnostic matches `--fail-on` |
| `diff` | disabled; unknown document key; `old` missing, unreadable or not valid JSON; `git show` fails; a ref or path starting with `-`; the two documents are incomparable; `--enforce` with an unsatisfied verdict |
| `cache` | disabled; unknown document key |
| `clear` | unknown document key (no enabled guard) |
