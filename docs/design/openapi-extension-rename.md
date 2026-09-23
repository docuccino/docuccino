# Design: from "the UIR" to an OpenAPI extension

Status: **approved (2026-09-22)**. Ships in **v0.20.0**, alongside the UIR 1.1 / workflows work whose
release PR (#515) is still open and untagged.

**Two version axes, and they answer differently.** The package is v0.20.0: one breaking minor below
1.0 (`RELEASING.md`), and folding this in costs nothing because the release has not been cut.

The UIR spec is **2.0**, and it has to be. `uir-and-extensions.md` §1 states the rule — additive is a
minor, a shape or identity change is a major with a new `$schema` URL — and removing two required root
members is a shape change against the **published** 1.0 (`spec.docuccino.app/uir/1.0/schema.json`
serves today). That 1.0 is live, so the rule binds regardless of what is convenient.

**1.1 stays in the family even though it never shipped** — untagged, and its URL still 404s. Folding
workflows and this move into a single 2.0 would be tidier by one directory and would make the
v0.20.0 changelog contradict itself: the merged commit "carry declared workflows in the document, as
UIR 1.1" cannot be reworded, and a released changelog that claims 1.1 for an artifact that never
declared it spends exactly the trust this repo's rules exist to protect. So `spec/` carries 1.0, 1.1
and 2.0, the artifact declares 2.0, and the record stays true.

Reconciled against two commits that landed after the first draft and make this change cheaper (§5):
**"carry declared workflows in the document, as UIR 1.1"** and **"publish declared workflows as an
Arazzo description"**. They are cited by subject throughout rather than by hash — the stack they are
in has already been re-chained once, and a hash cited here would have gone stale silently.

Companion to [`uir-and-extensions.md`](./uir-and-extensions.md), which stays the normative
description of the extension's contents. This doc changes what the artifact is *called* and two keys
of where it puts things; it changes nothing about identity, provenance, precedence or the content
layer's shape.

## 1. The decision, in one paragraph

Stop publishing a format called the UIR and start publishing an OpenAPI 3.2 document that carries a
documented extension. Concretely: move the two root members that are not OpenAPI members
(`$schema`, `uir`) under `x-docuccino.generator`, rename the `uir` emit format, split the published
schema so the extension can be validated on its own, and rewrite the public vocabulary so the
headline noun is OpenAPI and the second noun is a capability rather than an acronym.

The artifact's bytes change in two places. Everything the artifact *means* is unchanged.

## 2. Why

**It is what we already ship.** `Emit\OpenApi32Emitter` strips every `x-docuccino` member plus
`$schema` and `uir`, and its docblock states the result "round-trips losslessly against the
x-docuccino-stripped UIR". The delta between "our own IR" and "OpenAPI plus a vendor extension" is
two root keys. The name promises a larger difference than exists, and a reader who looks under the
hood and finds an `x-` member has been oversold.

**Those two keys are the only reason the artifact is not valid OpenAPI.** `spec/uir/1.0/schema.json`
declares `required: ["uir", "openapi", "info", "paths"]` with `additionalProperties: false` and an
`^x-` passthrough. The OpenAPI Object is closed the same way and admits neither member, so a strict
validator rejects our primary artifact over two keys whose content has a home already —
`x-docuccino.generator.specVersion` exists today and carries the same version.

**Three costs we pay for the name.** A reader must learn a TLA before reaching a benefit — the README
spends its opening paragraph defining one. "We compile your app into our own intermediate
representation" is the sentence that makes a technical lead ask what happens when they leave, where
"standard OpenAPI 3.2 plus an extension you can strip with a flag" answers that before it is asked.
And the docs site spends its `description` frontmatter — one string, the most valuable one it has —
on "A UIR-based API documentation generator", which nobody searches for.

**The argument for keeping it, answered.** A named, versioned, schema'd artifact is how a format
becomes infrastructure instead of a feature, and the repo anticipates exactly that: the split-repo
naming rule reserves a flat namespace for "every language we may add", and §12 of the UIR design doc
carries an open question about a normative cross-language rule before 1.0. That case is real and it
survives this change intact. A standard with one producer and zero third-party consumers is not yet a
standard; the moat is hypothetical and the explanation tax is charged today. And the cross-language
story reads *better* as "every adapter emits OpenAPI 3.2 carrying `x-docuccino`" than as "every
adapter emits our IR" — neutrality never required the grand name.

**And the name survives more adapters, not fewer.** "The Docuccino extension" names the *product*,
which every adapter is, rather than a format, which every adapter would otherwise have to be taught
about separately. A Rails or Django adapter emitting "OpenAPI 3.2 plus the Docuccino extension" needs
no new vocabulary in either community; one emitting "a UIR" has to sell an unfamiliar format to a
second audience before it can sell the tool. The extension name gets *easier* as the number of
adapters grows, which is the opposite of how the UIR name behaves.

**We are already an OpenAPI Initiative citizen in more than one format.** The Arazzo emitter, and Arazzo is an OAI specification. Docuccino now publishes OpenAPI 3.2/3.1/3.0 *and* Arazzo
— four documents in specifications we did not invent, and one we did. The odd one out is the one we
put our name on, and it is the one users are told is the native format.

**Where it pays most.** The hosted product's pitch is *bring your OpenAPI, get more when you build it
with us*. A customer who arrives holding a spec and is told the native format is something called a
UIR correctly infers they are second-class. One format, one import path, one mental model makes the
difference between tiers "how much `x-docuccino` is in your document" — a gradient a customer climbs
rather than a wall they are on the wrong side of.

## 3. The document change

| Today (root) | UIR 2.0 | Why there |
|---|---|---|
| `$schema` | `x-docuccino.generator.schema` | The schema URL is a statement about the generator's output contract, not about the API |
| `uir` | *deleted* — `x-docuccino.generator.specVersion` already carries it | It is already there; two spellings of one fact is the defect |

`x-docuccino.generator` is the right home for both, and not merely a free slot. `Identity\ContentHasher`
excludes `x-docuccino.generator` and `x-docuccino.diagnostics` from `contentHash` precisely so tool
upgrades never dirty a committed diff. A schema URL embeds major.minor, so parked anywhere else a UIR
1.1 release would churn every committed artifact on every user's next build. Under `generator` it
cannot.

Net effect: the document becomes a valid OpenAPI 3.2 document with no asterisk, and **`contentHash`
does not move at all** — the UIR 1.1 commit already excludes both members (§5).

One tidy-up falls out. `ContentHasher::hash` currently opens with
`unset($document['$schema'], $document['uir'])`; once both live under `generator`, which the next line
already unsets, that statement is dead for any document this version builds. Before deleting it,
check whether anything re-hashes a document *read back* from an older artifact — if so it is a
compatibility shim rather than dead code, and it stays with a comment saying which.

The extension key itself stays `x-docuccino`. Vendor extensions conventionally carry the vendor's
name, the key is referenced in every reader and every fixture, and changing it is maximum churn for
no gain. The *marketing* names the capability; the *key* names the vendor. Nobody should read this
doc and go renaming the member.

## 4. What does not change — the de-risking

This matters more than the change itself, because it is what makes the release safe to ship.

- **Node identities are untouched.** `Identity\IdentityGenerator` states that identity inputs never
  include file paths, line numbers or array positions, and nothing in it reads a document-level
  member. Ids are computed from node content, so moving root keys cannot move an id.
- **`ALGO_VERSION` stays `v1`.** Therefore `Diff\IncomparableDocumentsException` never fires across
  the upgrade, and a v0.19 artifact still pairs by `Pairing::Identity` against a v0.20 one. **A user
  upgrading does not lose their diff history.** If the implementation finds otherwise, stop — that is
  a different and much larger change.
- Precedence, PatchGuard, provenance records, the diagnostics channel, the content layer's shape,
  the fragment cache's keying, every emitter but the two touched: unchanged.
- The OpenAPI 3.2/3.1/3.0 exports are unchanged byte for byte. They already stripped both keys.

## 5. What churns, and what no longer does

**This got cheaper while the plan was being written.** The first draft said every document's
`contentHash` would move once. That is no longer true: the UIR 1.1 commit excludes `$schema` and `uir` from
the hash, for exactly the reason this plan puts them under `generator` — its message says the first
spec minor ever shipped "would have moved every document's hash and told every consumer their API had
changed. The spec version is a fact about the tool." Two routes, same conclusion, and the cheaper half
is already paid.

So the rename is **hash-neutral**. No user's committed `contentHash` moves. No consumer sees a diff
that says the API changed. What moves is bytes: the two members relocate, so any artifact holding them
at the root is rewritten.

Goldens, measured across the 65 files: 62 are JSON, **40 carry a root `uir` member**, 39 record a
`document.contentHash`. The expectation is therefore precise and worth asserting before regenerating
anything:

- **40 files move** — the root members relocate.
- **0 `contentHash` values move.** If one does, stop: something outside `generator` entered the hash
  and the change is not what this plan describes.
- The three non-JSON goldens — all `openapi32.yaml` — must not move at all, because that emitter
  already stripped both keys.

That second bullet is the real guard here, and it is stronger than a golden count: it is a property,
checkable by re-hashing before and after, and it fails loudly if the move is wider than intended.

Regeneration is still an **isolated commit** under the absolute rules, and per the memory rule it also
rewrites `generator.version` across unrelated fixtures — restore those so the commit stays isolated.

## 6. The emit format id

`Emit\Formats::TABLE` is the one place that knows which formats exist; its three readers are the CLI's
`--format`, a document's configured export targets, and the viewer's artifact selection. It now holds
six rows — the Arazzo emitter added `arazzo` — of which one changes:

```
'uir' => [UirEmitter::class, false, true, false],
```

Post-rename the two artifacts differ only by whether `x-docuccino` is retained, so the honest id names
that: **`openapi-3.2-full`** against `openapi-3.2`. Three further touch points move with it —
`CONTRACT_PREFERENCE` (where `uir` leads, correctly, because provenance survives nowhere else),
`viewerPreference`, and the comment in `php/laravel/config/docuccino.yaml` that lists the valid ids
inside the `export.targets` block, which `tools/config-reference-sync.php` holds against the website's
configuration reference in both directions.

The fourth column — "held to a published schema" — currently reads `false` for `uir`. After §8 it can
read `true`. That is a real quality gain, not bookkeeping: see §9.

**Decided (2026-09-22): no alias.** `uir` does not survive as a deprecated format id. A table alias is
a few lines, but it is also a knob, and the project's bar for a knob is high; the release is already
breaking and pre-1.0, so a clean break is in-contract. What the break owes instead is a **message that
names the replacement**: `Formats::emit` already throws "expected one of", and an unknown-format
error reading *"`uir` is now `openapi-3.2-full`"* costs nothing and beats an alias that must later be
removed. That sentence is part of the change, not a nicety — `--format=uir` is baked into users' CI
pipelines and the failure is the first thing they meet on upgrade. It owes a test that executes it.

## 7. Pages and the content layer

Mechanically, nothing here changes: pages live at `x-docuccino.content` and the OpenAPI emitter drops
them because, as its docblock says, OAS has nowhere to put them. Under the new framing that reads
*better* than it did — the content layer is the clearest example of why the extension needs a name
and a schema at all. It is the part of the document OpenAPI genuinely cannot hold.

Three things to settle.

**7.1 Resolution stays in the build.** `Content\ContentResolver` assigns each page its stable `page:`
id, resolves `::operation`/`::schema` directives, and *validates operation and tag nav refs, turning
every broken reference into a diagnostic rather than a silent drop*. And `ContentExtension`'s docblock
states that content participates in `contentHash` by design, "so a prose edit or a nav move is a
visible (non-breaking) changelog entry". Publishing markdown independently — via a GitHub Action or
anything else — forfeits both: a page that links to a renamed operation breaks silently, and prose
leaves the changelog. That is the drift problem the product exists to eliminate, reintroduced in the
layer the consumer actually reads.

**7.2 The fast-authoring loop is a real pull, and it has a clean answer.** The objection to 7.1 is
that a content edit today needs a full rebuild, and that non-PHP authors should not need one.
`ContentCompiler` is the filesystem-input half and `ContentResolver` is the document-input half —
`resolve(CompiledContent $content, array $document)` takes the assembled document as a plain array and
depends only on `IdentityGenerator` and `DirectiveResolver`. It is already framework-neutral and
already server-runnable. So the Action (or the SaaS) uploads a markdown bundle *plus* a document, and
the **same resolver** runs at publish time. One implementation, two hosts, nav-ref validation
preserved. Do not fork the content layer; relocate where its first half runs.

**7.3 The constraint that falls out of 7.2, and it is load-bearing.** If content can be resolved in
two places, then the same document can hash two ways depending on where its pages were attached —
which breaks the determinism guarantee directly. So: **content resolved anywhere other than the build
does not enter `contentHash`.** Publish-time content is an overlay applied after the hash is computed,
and the document must record which of the two happened so a consumer is never guessing. Settle this
before writing any server-side path; getting it wrong is silent and shows up as a warm/cold mismatch
much later.

## 8. The published schema, split in two

**Which model this is, since Arazzo invites the wrong one.** Arazzo is not an OpenAPI extension — it
is a standalone sibling specification with its own root (`arazzo`, `info`, `sourceDescriptions`,
`workflows`), its own `$id` under `spec.openapis.org`, and its own document. `ArazzoEmitter`'s own
docblock says so: "this is not an OAS-shaped document". It *references* OpenAPI descriptions rather
than living inside one. So Arazzo is the precedent for a **sidecar**, not for what `x-docuccino` is.

The precedent class we are actually in is the documented vendor extension with a published schema —
`x-ms-*`, `x-amazon-apigateway-*`, `x-codeSamples` — where the extension travels inside the OpenAPI
document and the schema is what a third party implements against. The good ones get adopted by tools
that did not write them, which is the only route by which this becomes infrastructure rather than a
feature (§2).

**The sidecar alternative, considered and rejected.** Emitting `x-docuccino` as its own file beside a
clean OpenAPI document is a real design, and it has one genuine advantage: the trust boundary becomes
a *file* boundary, which is far easier to enforce than a strip-on-emit filter — provenance carries
`source.file` and `source.line`, so an artifact reaching a public docs site is a disclosure. Rejected
anyway, for two reasons. The linkage would have to be by JSON Pointer, since the node ids would no
longer be inline — and a pointer breaks on any reordering, which is precisely the fragility the
identity model exists to remove; keeping the ids inline to avoid that forfeits most of the
"clean file" benefit. And an inline `x-` member rides free through every pipeline that already reads
OpenAPI, where a sidecar must be taught to each one. The two-audience need is already met better than
a sidecar would meet it: one document, two emissions, regenerated from one source, so they cannot
drift.



Today one schema describes the whole document. Split it:

- **the extension schema** — `x-docuccino` alone, strict, applicable on top of *any* OpenAPI document.
  This is the marketing asset: it is what makes the thing an extension rather than a format, and it is
  what a third party would implement against.
- **the composite document schema** — retained, now expressed as "a valid OpenAPI 3.2 document that
  additionally satisfies the extension schema".

**The split is version-aware, and it mints a new major.** `spec/` carries `uir/1.0/` (live),
`uir/1.1/` (never published) and gains `uir/2.0/` — the shape this plan defines. The split is
therefore over a *family*, not a file, and the same discipline applies: `composer sync-schema` walks every published
version, and per the UIR 1.1 commit the vendored-schema guard matches the family by `$id` prefix rather than by
an exact version, precisely so a second version does not read as somebody else's. Both properties must
survive the path change; the guard is the one to check first, because its failure mode — a schema
landing in a bucket asserted to be empty — has already happened once.

`spec.docuccino.app` stays, and so does the versioning discipline. The `$id` URLs change, which is
itself a breaking change for anyone who pinned one — acceptable pre-1.0, and it is the same release.

## 9. The acceptance test

The whole project has one binary, provable pass condition:

> The full artifact validates against the **official OpenAPI 3.2 meta-schema**, and its `x-docuccino`
> member validates against the standalone extension schema.

That is exactly the claim the rename makes in public, so it is the claim the suite must hold. It also
flips `Formats::TABLE`'s fourth column for the full format from `false` to `true` — the column that
tells a caller asking "is this artifact sound" whether anybody can say. Today, for our own primary
artifact, nobody can.

Per the coverage standards, write the guard so it **executes** the refusal: feed it a document with a
stray root member and confirm the meta-schema rejects it. A claimed guard is asserted; a real one is
run.

## 10. Surface inventory

Measured, not estimated. Re-verified at the head of `feat/a-workflow-is-declared-on-its-operations`:
65 goldens / 62 JSON / 40 root-`uir` / 39 recording a `contentHash`; `ALGO_VERSION` still `v1`;
`Formats::TABLE` at six rows; `x-docuccino` carrying exactly `content`, `diagnostics`, `document`,
`generator`, `workflows`; and `$schema` + `uir` still the only non-OAS, non-`x-` root members across
every `*.uir*.json` golden.

### 10.1 In the repository

| Surface | Extent |
|---|---|
| Core document + emitters | `UirDocument::fromArray`/`toArray`, `UirEmitter`, `OpenApi32Emitter`, `Generator`, `ContentHasher` (dead-code check, §3) |
| Golden fixtures | 65 files, 40 carrying a root `uir` member |
| Published schema | `spec/uir/1.0/` **and** `spec/uir/1.1/` → split per §8; `$id` and `required` change in both; `composer sync-schema` and the prefix-matching vendored-schema guard follow |
| Emit format id | `Formats::TABLE` (6 rows, incl. `arazzo`), `CONTRACT_PREFERENCE`, `viewerPreference`, CLI `--format`, `export.targets` |
| Config surface | `php/laravel/config/docuccino.yaml` export comment + website configuration reference, synced by `tools/config-reference-sync.php` |
| Website | `/uir/index.mdx`, `/uir/hosting.md`, plus **81** acronym mentions. An earlier draft of this row said "~190 … attributes (57)"; that was a naive substring grep for `uir`, which also matches *require*, *required* and *acquire*. `attributes.md` contains ONE. The real concentration is `commands.md` (13), `narrative-content.mdx` (9), `uir/hosting.md` (8), `how-it-works.md` (8), `uir/index.mdx` (7), `contract-testing.mdx` (6). Measure with `\bUIR\b` and pair it with a positive control — the bad figure in this very plan is what the denominator rule exists to prevent |
| Positioning | README opening paragraph; `index.mdx` `description` frontmatter; hero already leads with OpenAPI and needs no change |
| Missing today | No `UPGRADING.md`; no redirect config in `website/astro.config.*` — both are deliverables of this release |

### 10.2 Where the name is published, including outside this repository

The rename is a naming change, so the places the old name is *indexed* are part of the work and not a
follow-up. There are five, and only two are in this tree.

| Property | Where it lives | What it says today |
|---|---|---|
| `docs.docuccino.app` | `website/` (this repo) | §10.1 — the ~190 mentions and the `description` frontmatter |
| `spec.docuccino.app` | `spec/` (this repo) | The schema family, per §8 |
| **`docuccino.app`** | **not in this repo** | The apex, named as `homepage` in all five `composer.json` files |
| **Packagist** | five `composer.json` files (this repo) | See below — the highest-leverage miss |
| **GitHub** | repo description and topics | Indexed, and carries the same sentence |

**Packagist is the one to fix first, and it was missing from this plan.** It is where PHP developers
actually search, and the metadata is in this tree, so it costs a line each:

- The root package description reads *"UIR-based API documentation generator for Laravel (monorepo)"* —
  the same defect as the website's `description` frontmatter, in the field Packagist renders as the
  package's one-line summary.
- **`uir` occupies a keyword slot in all five packages.** Nobody searches Packagist for it. The slot is
  worth an actual query — `openapi-extension`, `openapi-3.2`, `api-changelog` — and the existing
  `scramble-alternative` keyword shows the competitive-search instinct is already there.
- `docuccino/laravel`'s description is already right and needs no change: *"Generate OpenAPI 3.2 and
  3.1 documentation for a Laravel API automatically, from the real types in your code."* It is the
  model for the other four.
- `composer validate --strict` gates all five and must stay green.

**The apex/SaaS site is outside this repository** — `docuccino-saas`, a Laravel app whose marketing
copy lives in `app/Support/Marketing/SiteContent.php` by its own convention. Its copy has been brought
onto the §13.2 convention on the branch `docs/name-the-extension` (Pint clean, 31/31 tests): the
"format you can build on" capability is now "an extension you can build on", pipeline beat 02 reads
"It compiles to OpenAPI", and no shipped string on that site says UIR.

Two boundaries were held deliberately and matter to whoever reviews it. **Every statement there is
true today**, before this plan lands — `x-docuccino` already *is* an OpenAPI extension member, and
the only claim the rename newly unlocks is "a valid OpenAPI document, full stop", which is therefore
the one claim the copy does not make. And the docs link still targets `/uir/`, because that page still
exists; it changes in step 4 with its redirect. The site's hero and `description` meta already led
with OpenAPI and were left alone.

**`homepage` stays `https://docuccino.app`** in all five packages — that is a URL, not a name, and
nothing about it changes.

## 11. Sequencing

Stacked, bottom-up, one writer per branch. Re-chain by content, never by SHA.

1. **`feat(core)!: carry the spec version and schema URL in the extension`** — the two key moves, the
   schema split, the meta-schema guard of §9. Breaking.
2. **`chore(repo): regenerate the goldens for the moved root members`** — isolated, nothing else in it,
   `generator.version` churn restored.
3. **`feat(core)!: name the full artifact for the OpenAPI it is`** — the format id, its three readers,
   the unknown-format message of §6, the config comment and the reference sync.
4. **`docs(website): lead with OpenAPI`** — the ~190-mention sweep, README paragraph one, the
   `description` frontmatter, and the three positioning strings it does not reach (`starlight.description`,
   which the 404 serves; `starlightLlmsTxt.description`, which is what AI assistants ingest via the live
   `/llms.txt`; and the home page's own "under the hood" paragraph). Per-page `<title>`s too: Starlight
   promotes the sidebar label into the title tag, so the home page currently serves `Docuccino | Docuccino`
   and `/laravel/documenting/requests/` — the natural landing page for "openapi from form requests" —
   names none of those words.

   **The `/uir/` URL does NOT move, and this reverses an earlier instruction in this plan.** The draft
   said "redirects for every `/uir/` URL that may have been linked", which named an intent without
   checking the mechanism, and the mechanism does not exist on this host. Astro's static redirect emits
   `<meta name="robots" content="noindex">` (`astro/dist/core/routing/3xx.js`), and the site deploys to
   GitHub Pages via `withastro/action` with no adapter, so a real 301 is not available. The default
   mechanism would therefore have told Google to DROP the old URLs rather than pass their equity on —
   taking with them the `/uir/` links in `README.md` and `php/core/README.md` that render on GitHub and
   Packagist, two fragment links, and the `.md` twin at `/uir.md`.

   Keeping the slug costs nothing that can be measured: URL keywords are a negligible ranking input and
   nobody searches for the word — which is this plan's own argument for the rename. The rename's object
   is what the page SAYS. So the page's title, heading, sidebar label and prose move to the §13.2
   vocabulary, the path stays, and no redirect config is added. If the docs site ever moves to a host
   that serves real 301s, the URL can follow then; it is recorded here so the question is not
   rediscovered.
5. **`chore(repo): describe the packages by what they emit`** — the five `composer.json` descriptions
   and keywords of §10.2, `composer validate --strict` green. Small, independent, and the highest SEO
   return per line in the whole plan; it could land ahead of the rename without waiting for it.
7. **`docs(repo): upgrading to v0.20`** — the user-facing migration of §12.

Outside the stack, and not blocking it: the apex site (§10.2) and the GitHub repo description/topics.
Both need the §13.2 convention and nothing else from here.

Content §7.2/7.3 is **not** in this release. It is a separate design once the rename has landed, and
7.3 must be settled before any of it is written.

## 12. Migration for users

Short, because the blast radius genuinely is:

- `--format=uir` → `--format=openapi-3.2-full`, and the same id in `export.targets`.
- Anything reading `$schema` or `uir` at the document root reads `x-docuccino.generator.schema` and
  `x-docuccino.generator.specVersion`.
- **`contentHash` does not move.** An earlier draft of this section said every hash moved once; that
  was already false when it was written, and it contradicted §5 two pages earlier. The UIR 1.1 release
  took `$schema` and `uir` out of the hash, and both land under `x-docuccino.generator`, which was
  never in it — so a committed artifact's hash is byte-for-byte what it was. What changes is the
  artifact's bytes, where those two members sit.
- **Diff history is preserved** — identities and the algorithm version are untouched (§4).

## 13. Open decisions

1. ~~The deprecated alias (§6).~~ **Decided 2026-09-22: no alias**, and the unknown-format error names
   the replacement. Folded into §6.
2. ~~The public name for the extension's capability.~~ **Decided 2026-09-22: "the Docuccino
   extension"**, and no coined noun for the container. Reasoning: the members are heterogeneous —
   identity, provenance, content, workflows, build metadata — so any single noun is either vague
   ("metadata") or wrong ("provenance" covers one of five). More to the point, inventing a new
   proprietary noun to replace UIR repeats the mistake this plan exists to undo. "Extension" is what
   the code has always called it (`DocumentExtension`, `NodeExtension`), so the public name costs a
   reader nothing. The **capabilities** are named individually in the marketing, never the container:
   *semantic diff* (from `id`), *provenance* (from `provenance`), *guides that cannot go stale* (from
   `content`), *publishable workflows* (from `workflows`). One-line through-line: **"Docuccino compiles
   your application into OpenAPI 3.2 — and records how it knew."** Constraint for the step-4 sweep:
   nothing annotation-adjacent, since the site's own H2 is "Documentation from your code, not from
   annotations".
3. **Whether the composite document schema survives the split at all**, or the extension schema plus
   the official meta-schema is the whole story. Leaning toward the latter, since §9 already validates
   against both and a third schema that restates them can only drift.
4. **§7.3's recording mechanism** — how a document states where its content was resolved. Deferred with
   the rest of §7, but noted here so it is not rediscovered.
