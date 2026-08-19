---
title: Commands
description: The docuccino artisan commands — install, export, validate, diff, cache, clear, watch and explain — with every flag, default and exit code.
---


Docuccino registers the artisan commands below. Every one exits `0` on success and `1` on failure, so
each is safe to gate a CI job on. [`docuccino:explain`](#docuccinoexplain) adds one more code — `2`,
for a query that named several operations — so a script can tell "not found" from "be more specific".

Shared behavior:

- **Enabled guard.** Every command except `docuccino:clear` aborts with exit `1` when
  `config('docuccino.enabled')` is `false`, printing
  `Docuccino is disabled (set docuccino.enabled = true to run this command).` `clear` has no guard,
  so you can always flush the cache.
- **`{document?}` argument.** Omit it to run over *every* configured document; pass a key to run
  one. **`docuccino:diff` is the exception** — with no `{document}` it diffs the `default`
  document only, never all of them. `docuccino:install` takes no `{document}` at all: it reports on
  every configured document. An unknown key errors and exits `1`. Per-document results
  aggregate: any single document failing fails the whole command.
- **Diagnostics.** `export`, `validate` and `cache` print diagnostics grouped by route signature in
  deterministic order; `diff`, `clear` and `explain` print none. `watch` and `install` print whatever
  the export they run prints, and nothing of their own — what they report is about your setup rather
  than about the document, which is a console message.
- **`--memory-limit`.** Accepted by every command that builds a document — `export`, `validate`,
  `diff`, `cache`, `watch`, `explain`, `install` — since inference runs a static analyzer inside the
  artisan process.
  Raise-only: a process already running with a higher limit is left alone, and `-1` is rejected. Same
  lever as [`engine.memory_limit`](/laravel/reference/configuration/#engine), and the flag wins.
  `clear` builds nothing, so it doesn't take it.
- **Long-running.** Every command runs once and exits, except `docuccino:watch`, which stays in the
  foreground until you stop it.

## `docuccino:install`

Set Docuccino up in this application and generate a first document.

```
docuccino:install
    {--force : Replace an existing config/docuccino.php with the shipped defaults}
    {--no-export : Set up without generating a first document}
    {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}
```

| Flag | Values / default | Effect |
| --- | --- | --- |
| `--force` | flag / off | Replaces an existing `config/docuccino.php` with the shipped defaults. Without it an existing file is never touched — the command says it left it alone and names this flag. |
| `--no-export` | flag / off | Finishes the setup without generating a document. Otherwise the command offers one, and `--no-interaction` takes the prompt's default, which is yes. |
| `--memory-limit` | php.ini value, e.g. `2G` / unset | Raises the process memory limit before the first export runs — see the shared-behavior note above. |

The one command you run once rather than on every change, and the only one that writes anything
outside an export path. Four steps, in order:

1. **Config.** Publishes `config/docuccino.php` — the same file, byte for byte, that
   `vendor:publish --tag=docuccino-config` writes. An existing file is left exactly as it is unless
   you pass `--force`, so a second run changes nothing.
2. **Routes.** Reads your router and reports how many routes each configured document really matches.
   The count comes from the same resolver a build uses — attribute exclusions, closure filters and
   vendor package routes already subtracted — so it is the number your next export will document.
3. **Engine.** Says whether the analysis engine is installed and, when it isn't, prints the one
   command that fixes it alongside what the document loses meanwhile. Nothing here needs the engine:
   the command runs, and reports, either way.
4. **First document.** Offers to run [`docuccino:export`](#docuccinoexport), then prints the viewer
   URL for each document and the commands worth knowing next.

**When nothing matches.** The shipped [`routes.include`](/laravel/reference/configuration/#routes) is
`api/*`, and plenty of applications version their API somewhere else. Rather than leaving you to
guess, the routes step lists the prefixes your routes actually sit under, busiest first, and names the
value that would pick one up:

```text
Routes
──────
"default" documents 0 of the 42 routes this application publishes (include: api/*).

"default" matched nothing. Your routes sit under:

  Prefix   Routes
  ───────  ──────
  v1/*     31
  admin/*  11

Set documents.default.routes.include in config/docuccino.php — e.g. ['v1/*'].
```

An application with no routes to document yet gets a sentence saying so, not a failure.

Exits `1` on a disabled install, a `config/docuccino.php` it could not write, or a failed first
export — setup succeeding while the export fails is still a failure.

## `docuccino:export`

Generate and export API documentation from your routes.

```
docuccino:export
    {document? : The configured document key (defaults to every document)}
    {--format= : uir | openapi-3.2 | openapi-3.1 | openapi-3.0 | postman — writes this one format instead of the configured targets}
    {--out= : Output path (defaults to the matching target, else the document export path)}
    {--fail-on=none : none | error | warning | info | hint — the quietest severity that still makes the command exit non-zero}
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
| `--fail-on` | `none` \| `error` \| `warning` \| `info` \| `hint` / `none` | The quietest severity that still fails the run: anything reported at that severity **or louder** makes the exit code non-zero, and `none` never fails on severity. `error` catches errors only, `warning` adds warnings, `info` adds the recovery reports — an unrecoverable payload, a model with no readable columns, a validation rule that could not be read — and `hint` catches everything the build said. An invalid value errors (no silent fallback) — a typo must not quietly remove the gate. Codes listed under [`diagnostics.accept`](/laravel/reference/configuration/#diagnostics) still print but never fail the run; errors are never accepted. |
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
- **Your own examples win.** Where a request body, response or parameter publishes an
  [`#[Example]`](/laravel/documenting/responses/#example-payloads), the collection sends that payload
  rather than one derived from the shape; a map of several is read by its lowest key, the same rule
  every other reader of the document uses.

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
    {--fail-on=none : none | error | warning | info | hint — quietest extra severity that also fails (a schema violation always fails)}
    {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}
```

| Flag | Values / default | Effect |
| --- | --- | --- |
| `document` | configured key / all | Which document(s) to validate. Unknown → exit 1. |
| `--fail-on` | `none` \| `error` \| `warning` \| `info` \| `hint` / `none` | *Additional* severity floor that also fails, read exactly as it is on [`export`](#docuccinoexport). Independent of the schema check. An invalid value errors, as it does on `export`. |
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

## `docuccino:watch`

Rebuild API documentation as your code changes, and refresh an open viewer.

```
docuccino:watch
    {document? : The configured document key (defaults to every document)}
    {--interval=1 : Seconds between polls of the watched files}
    {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}
```

| Flag | Values / default | Effect |
| --- | --- | --- |
| `document` | any configured key / all documents | Which document(s) to rebuild. Unknown key → exit 1. |
| `--interval` | seconds, `0.25` and `2` are both fine / `1` | How often the watched files are re-read. A value that isn't a positive number errors (no silent fallback). |
| `--memory-limit` | php.ini value, e.g. `2G` / unset | Passed through to each rebuild — see the shared-behavior note above. |

Start it beside `php artisan serve` and leave it running:

```bash
php artisan docuccino:watch
```

It builds once, then rebuilds whenever a file the build depends on changes, and prints which file
triggered it. `Ctrl+C` stops it.

### What it watches

Not a pattern you have to keep in sync with your project — the same files the build itself recorded
as its inputs:

- **Everything behind an operation.** Each cached operation stores the files it was recovered from:
  the controller, everything a parent class or trait answered for it, every file a traced helper
  walked, and any file an attribute read. Editing one controller rebuilds one operation.
- **Everything that decides all of them.** `config/`, `routes/`, `composer.json` and `composer.lock`,
  each document's [`content.dir`](/laravel/reference/configuration/#content) tree, its
  [`webhooks.dir`](/laravel/reference/configuration/#webhooks) tree, its
  [overlay](/laravel/reference/configuration/#overlays) files, and the
  [`engine.neon`](/laravel/reference/configuration/#engine) file if you name one. These are watched
  as directories, so a route file, a content page or a webhook class you add mid-session counts too.

The artifacts a build writes are deliberately excluded — watching its own output would rebuild
forever.

Watch mode turns the [fragment cache](/laravel/guides/speeding-up-builds/) on for the builds it runs
(via `DOCUCCINO_FRAGMENT_CACHE`), which is what makes a rebuild incremental and what gives it the
list above.

If you have run `php artisan config:cache`, that env value was read and baked in when you cached, so
the override reaches nothing: every rebuild re-analyzes your whole application and stores none of it,
and editing a controller stops triggering one. Watch says so on startup, before the first build, so
you can stop and fix it. Run `php artisan config:clear`, or set `DOCUCCINO_FRAGMENT_CACHE=true` and
cache again.

### Live viewer refresh

While `docuccino:watch` is running, the [viewer](/laravel/guides/viewer/) subscribes to a reload
channel at `<viewer.route>/reload` and refreshes itself when a rebuild changes the document. A
rebuild that changes no byte leaves the page alone.

The channel sits behind exactly the same
[`viewer.gate`](/laravel/reference/configuration/#viewer) and `viewer.middleware` as the rest of the
viewer, and it answers only while a watch session is running — with no session, it is a `404` and the
page carries no subscriber at all. There is nothing to switch off in production.

### Why each rebuild is a new process

Watch runs `docuccino:export` in a fresh PHP process rather than rebuilding in place. PHP never
un-loads a class, so a long-lived process would keep documenting your controllers as they were when
it started, and would never see a route you added. Spawning is cheap next to analysis, and the
fragment cache is on disk — so the new process picks up every operation the last one built and
re-analyzes only what actually changed.

Nothing goes through a shell on the way, so a project path with a space or an `&` in it is spawned
correctly on every platform. A rebuild that hasn't finished in 15 minutes is stopped and reported as
a failed build, so an analysis that wedges costs you one rebuild rather than the session.

## `docuccino:explain`

Explain why one endpoint is documented the way it is, layer by layer.

```
docuccino:explain
    {route : The operation — "POST /api/invoices", a URI, a route name, an operation id, or part of any of them}
    {document? : The configured document key (defaults to every document)}
    {--method= : Narrow a URI several verbs answer (get, post, put, patch, delete, …)}
    {--field= : Explain one field, printing every value in full (e.g. requestBody, responses.201.description)}
    {--json : Print the trail as JSON instead of the report}
    {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}
```

Every value in the document carries a record of who put it there —
[provenance](/laravel/guides/how-it-works/#3-uir) — and this reads it back. Point it at an endpoint
that looks wrong and it prints, field by field, which
[precedence layer](/laravel/guides/how-it-works/#3-uir) won, what that value displaced, the `file:line`
to open next, and **what to change to override it**.

It reads and prints only: nothing is written, and no cache is touched.

```bash
php artisan docuccino:explain "POST /api/invoices"
```

```
POST /api/invoices
──────────────────
InvoiceController@store  ·  document "default"  ·  route invoices.store

Precedence, low to high — the highest rung that writes a field wins it:
fallback › inference › integration › docblock › attribute › overlay › config
✓ published    ✗ shadowed

operation
  requestBody
    ✓ integration {"required":true,"content":{"application/json":{"schema…
        integration:form-request · app/Http/Controllers/InvoiceController.php:38
    → set it with #[BodyParameter(name: 'total')]
  summary
    ✓ attribute   "Raise an invoice"
        app/Http/Controllers/InvoiceController.php:36
    ✗ docblock    "Store a new invoice."
    → edit the attribute above, or outrank it with an overlay

responses.201
  description
    ✓ attribute   "The invoice as stored."
        app/Http/Controllers/InvoiceController.php:36
    ✗ fallback    "Created"
    → edit the attribute above, or outrank it with an overlay

responses.422  → #/components/responses/UnprocessableEntity
  from integration:implicit-response · app/Http/Controllers/InvoiceController.php:38 · implicit:validated-request
  component
    ✓ integration "UnprocessableEntity"
    → set it with #[ErrorComponent('InvoiceNotFound')] on the exception or its render method
  description
    ✓ integration "Unprocessable Entity"
    → set it with #[Response(status: 422, description: '…')]

5 fields · 7 contributions · 2 shadowed
A shadowed value is recorded by producer only — the trail keeps what lost, not where it came from.
1 value shortened to fit — `--field=<name>` prints one in full.
```

**Reading it.** Each block is one node of the document, named the way you would point at it — the
operation itself, then its parameters, request body and responses, then anything they `$ref`. Under
each field is the stack of layers that reached it: `✓` is the value the document publishes, and every
`✗` under it is a value a lower rung wrote and lost with. A `→` after a node name is the component it
points at; a `from` line means every field on that node came from the same place, and a `→` line under
it means they all take the same override.

The rung is always spelled out beside its color, so the report reads the same piped to a file, under
`--no-ansi`, and in a CI log. A confidence only appears when it is low enough to act on — a mapper
that converted a type cleanly reports `0.9`, so printing it everywhere would just teach you to skip
it. The precedence ladder prints **only when something was actually shadowed**; on an endpoint where
nothing competed it would be explaining a competition that never happened.

### The `→` line: what to change

Knowing which layer won is only half an answer. The other half is derivable from it, so each field
gets one line saying how to take it:

| The winning rung | What the line says |
| --- | --- |
| `fallback`, `inference`, `integration`, `docblock`, **and an attribute writes that field** | The attribute, spelled with this endpoint's own values — `set it with #[QueryParameter(name: 'filter[status]')]` |
| `fallback`, `inference`, `integration`, `docblock`, and **no** attribute writes it | The generic truth — `no attribute writes this — an overlay outranks docblock` |
| `attribute` | `edit the attribute above, or outrank it with an overlay` — the `file:line` above it is the attribute |
| `overlay` | `edit the overlay that set it; only config outranks an overlay` |
| `config` | `config is the top rung — edit config/docuccino.php` |

An attribute is named **only where it genuinely writes that field on that node**: `#[Group]` really is
what sets `tags`, and `#[ErrorComponent]` — not `#[Response]` — is what names a shared error body. A
lever that would do nothing is worse than no lever, so everywhere else the answer is the generic one,
which is still actionable: an [overlay](/laravel/guides/customizing-output/) can write any field at
all, and it outranks everything except `config`.

### `--field`: one field, whole

The scannable report shortens a long value to keep the columns readable — and the value you are
debugging is exactly the one likely to be long. `--field` prints one field's whole stack with every
value in full:

```bash
php artisan docuccino:explain "POST /api/invoices" --field=requestBody
```

```
POST /api/invoices
──────────────────
InvoiceController@store  ·  document "default"

operation
  requestBody
    ✓ integration
        integration:form-request · app/Http/Controllers/InvoiceController.php:38
        {
            "required": true,
            "content": {
                "application/json": {
                    "schema": {
                        "$ref": "#/components/schemas/StoreInvoiceRequest"
                    }
                }
            }
        }

  → set it with #[BodyParameter(name: 'total')]
```

It is narrowed exactly as the route argument is — an exact `node.field` path, then an exact field
name, then a fragment — and answers on the same three exit codes. A name several nodes carry
(`description` is the usual one) lists them with the rung that won each, and exits `2`:

```
2 fields match "description".
─────────────────────────────

  Field                      Rung
  ─────────────────────────  ───────────
  responses.201.description  attribute
  responses.422.description  integration

php artisan docuccino:explain "POST /api/invoices" --field=responses.201.description
```

A field the trail names but the document does not carry reads `(removed by this layer)` — that layer
wrote a deletion, which is a decision about the field rather than a missing value.

### Naming the endpoint

You'll usually run this straight after looking at the [viewer](/laravel/guides/viewer/) or your
exported `openapi.json`, and both show `POST /api/invoices` — so that is the primary spelling. A
route *name* works too but is never required: closure and unnamed routes are common, and they are
disproportionately the ones that document badly.

The argument is tried as, in order:

1. an exact **route name** — `invoices.store`;
2. an exact **operation id** — `storeInvoice`, which is what an SDK user would quote at you;
3. a **URI**, with or without a leading method and with or without a leading slash —
   `POST /api/invoices`, `post api/invoices`, `/api/invoices`, `api/invoices`. A base path your
   document shares is added or taken away as needed, so `invoices` finds `/api/invoices`.

Nothing matches exactly? The argument is matched as a fragment of any of the three instead, which
turns a failed lookup into a menu:

```bash
php artisan docuccino:explain invoices
```

```
3 operations match "invoices".
──────────────────────────────

  Method  URI                      Document  Route           Operation id
  ──────  ───────────────────────  ────────  ──────────────  ────────────
  GET     /api/invoices            default   invoices.index  listInvoices
  POST    /api/invoices            default   invoices.store  storeInvoice
  GET     /api/invoices/{invoice}  default   invoices.show   showInvoice

php artisan docuccino:explain "GET /api/invoices"
```

`--method` narrows a URI several verbs answer, so `docuccino:explain api/invoices --method=post` and
`docuccino:explain "POST /api/invoices"` are the same request. It is only needed to disambiguate —
a URI one verb answers never asks for it.

Several matches is an **answer**, not an error: the command lists them and exits `2` rather than
picking one for you. Nothing matching at all exits `1` and prints the spellings it accepts, filled in
with an operation your document really has.

| Flag | Values / default | Effect |
| --- | --- | --- |
| `route` (required) | route name \| operation id \| URI \| fragment | Which operation to explain. No match → exit 1; several → exit 2. |
| `document` | configured key / all documents | Which document(s) to search. Every configured document is built and searched when omitted, and the answer always names the one it is about. Unknown key → exit 1. |
| `--method` | `get` \| `post` \| `put` \| `patch` \| `delete` \| … / unset | Narrows a URI several verbs answer. An invalid value errors (no silent fallback). |
| `--field` | field name \| `node.field` path \| fragment / unset | Explains one field with every value printed in full. No match → exit 1; several → exit 2. |
| `--json` | flag / off | Prints the whole trail as JSON — the same `status` / exit-code pair, plus `nodes[]` carrying each field's contributions with their layer, rank, value, source and confidence. |
| `--memory-limit` | php.ini value, e.g. `2G` / unset | Raises the process memory limit; the document is generated first, so it needs `export`'s headroom. |

### Why it never needs `--provenance`

[`--provenance`](#docuccinoexport) is an **export** setting: it decides how much of the trail survives
into a committed artifact, and `winners` — the default — drops the `overrode` records that say what
was shadowed. `docuccino:explain` never reads an artifact. It builds the document in memory, where the
trail is always complete, so the shadowed half is there whatever your export settings are.

That also means an operation the report finds nothing for really did record nothing, rather than
having had it stripped: it is a skeleton, for an action Docuccino could not reflect. The command says
so instead of printing an empty report.

### What it deliberately doesn't do

The trail describes **this build**, not its history. Docuccino keeps git metadata out of the document
on purpose — a commit SHA in the output would break byte-identical builds — so there is nothing here
about when a value changed, or who changed it. For "what changed", commit the artifact and use
[`docuccino:diff`](#docuccinodiff).

A shadowed contribution is also recorded by producer alone: `overrode` keeps the field, the value that
lost and the producer that wrote it, and has nowhere to record the file it came from. The report says
so once at the bottom rather than leaving an empty column on every `✗` row.

## Exit codes

Every command returns `0` on success and `1` on failure, and `docuccino:explain` also returns `2`.
What counts as failure:

| Command | Exits `1` when |
| --- | --- |
| `install` | disabled; `config/docuccino.php` could not be written; the first export failed |
| `export` | disabled; unknown `--format`, `--fail-on` or `--provenance` value; `--out` given while exporting multiple documents, or without `--format` against a multi-target document; unknown document key; an unaccepted diagnostic matches `--fail-on` |
| `validate` | disabled; unknown `--fail-on` value; unknown document key; **any** schema violation (regardless of `--fail-on`, and never acceptable — it's an error); an unaccepted diagnostic matches `--fail-on` |
| `diff` | disabled; unknown document key; `old` missing, unreadable or not valid JSON; `git show` fails; a ref or path starting with `-`; the two documents are incomparable; `--enforce` with an unsatisfied verdict |
| `cache` | disabled; unknown document key |
| `clear` | unknown document key (no enabled guard) |
| `watch` | disabled; unknown document key; `--interval` that isn't a positive number; no documents configured. A failing rebuild does **not** stop the session — it prints and waits for the next change |
| `explain` | disabled; unknown document key; unknown `--method` value; no operation matches the query; no field matches `--field`. Exits **`2`** — not `1` — when several operations or several fields match, so a script can tell "not found" from "be more specific" |
