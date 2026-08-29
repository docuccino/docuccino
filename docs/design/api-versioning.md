# Date-based API versioning

> **Status: design. Nothing here is built.** The evidence behind these decisions — Stripe, Cadwyn,
> Intercom, Airflow, and the Laravel ecosystem survey — is in the research issue
> [#316](https://github.com/docuccino/docuccino/issues/316). This document records what was decided
> and what a first slice looks like; it is not a roadmap and carries no dates.

## The frame

Docuccino's thesis is documentation from real code rather than annotations. **Versioning is the one
place that thesis cannot reach**: the old shape is not in the code any more, because it was deleted.
A version delta is therefore irreducibly declarative.

That is a constraint, not a defeat. It says: keep the declaration minimal and ordered, and then
**verify it** rather than trusting it — which is the ground nobody else occupies.

## What the product already has

A version is a **named document**, and most of that already exists.

| Piece | Where |
| --- | --- |
| Multiple named documents, each with its own `info.version`, routes, servers, overlays | `config/docuccino.php` → `documents` |
| Contract assertions against a named document | `ApiContract::against()` |
| Stable identities and lookup by node id | `x-docuccino.id`, `ContractIndex` |
| Date version policy, and a differ that classifies breaking vs non-breaking | `DateVersionPolicy`, `DocumentDiffer` |
| Reading a document as it was at any git ref | `docuccino:diff --against=<ref>` |
| Byte-determinism, per-node provenance, per-operation coverage | pipeline-wide |

## Decisions

### Re-derive each version; do not patch a canonical document

Every system that publishes per-version specs ships **standalone documents** — Intercom's are eleven
independent files, GitHub's are date-suffixed. Cadwyn regenerates its models per version and emits
each document through the ordinary path.

OpenAPI Overlay is a poor fit and no project uses it for this: `update` is a recursive merge that can
only widen, so narrowing needs a `remove` first and the intermediate document is invalid; rename only
became expressible in Overlay 1.1.0, as a copy-then-remove sequence. Overlay also carries no data
semantics at all — it rewrites the description, never the payload.

Re-derivation keeps determinism and locality intact, because each document stays a pure function of
(code, version).

### One object carries description, target and transformation

Stripe rewrote a working versioning system for documentation reasons. Their account is that engineers
*"would need to specify what their changes were independent of where they made the change"*, so the
API reference *"was missing changes"*; the rewrite let them *"enforce that the change being made is
properly documented, since we know that it's encapsulated inside of the change class itself."*
Intercom and Cadwyn arrived independently at the same triple.

So the unit is one registered change carrying all three, ordered within a version.

### The declarative half must be statically foldable

**Docuccino executes nothing.** The declarative half therefore has to be readable without running it.
A closure over an array is not — and that is the shape every existing Laravel migrations package uses,
which is why there is nothing in the ecosystem to compile today.

Foldability is requirement one on the vocabulary, not an ergonomic detail discovered later. The Query
Builder integration already constant-folds through helper methods several calls deep, so this is known
ground.

### Attributes describe what exists; version changes describe what is gone

- **Attributes** carry facts about the current shape — a description, a deprecation, an example.
- **Version changes** carry the shapes no longer in the code, which attributes cannot reach.

An attribute must not carry a version fact the chain already knows. Two sources for one fact drift.

### The document declares its own version in stock OpenAPI

Follow Intercom, not GitHub. GitHub's per-version documents are silent about their own version — it
lives only in the filename, which no generated client can see. Intercom sets `info.version` to the API
version and declares the version header parameter on every operation, with `default` = this document's
version and `enum` = every supported version. No extension minted, and the enum is the
closed-set-owes-an-enum case.

There is no OpenAPI convention for "this field exists from version X" — the registry carries only a
boolean `deprecated`. Minting an extension for it is a decision to take deliberately and say out loud.

## The correctness model

This is the differentiator. Three checks, none of which requires Docuccino to transform anything at
runtime:

1. **Per-version contract test.** Replay the suite with the version pinned; every response must
   validate against *that version's* document.
2. **No silent drift in a live version.** Regenerate a version's document and require every difference
   from the published one to be non-breaking, **or** attributable to a declared version change.
3. **Coverage per version** — which operations of version X the suite never exercised.

**Check 1 is load-bearing and ships first.** Separating the declarative half (ours) from the
imperative half (the application's runtime) re-creates exactly the split Stripe eliminated. The
difference is that Stripe had no oracle for the drift and this product would: check 1 *is* that
oracle. Without it, the separation is the anti-pattern and nothing else here rescues it.

It also catches the failure Airflow records in production — the declarative instruction alters the
class, the converter alters the dict, and without reconciliation *"the dropped field would still
appear on the wire."* A per-version document is a lie unless something forces that check.

### Two invariants that are NOT available

- **Do not golden-freeze a past version's document.** Live versions legitimately change: one Intercom
  commit applies an identical fix to all eleven version files, because non-breaking corrections are
  backported to every supported version. A byte-lock fails on every honest fix. Check 2 is the
  survivable form.
- **`forward(backward(x)) == x` does not hold.** The internal representation is deliberately lossy
  old→new — a list collapsing to a single value cannot round-trip. The honest property is one-sided: a
  migrated response validates against the target version's schema; a migrated request validates at the
  current one.

## Phases

### Phase 1 — a walking skeleton, end to end

One real version change, in the vocabulary, producing two documents, with a contract test that passes
for both **and fails when the transformation is wrong**. Deliberately thin and deliberately vertical:
it proves the claim before any layer is built out.

The vocabulary is designed here, once, for both readers — statically foldable for Docuccino,
ergonomic for the runtime package that will emit it in phase 2.

### Phase 2 — the production package

A Laravel package owning the imperative half, with change objects that co-locate description, target
and transformation, so the declarative half is **derived** rather than hand-written. Tightly
integrated with Docuccino by design rather than by adapter.

It is a production dependency. Docuccino remains dev-only; the two packages have different postures
and that is fine, but it is a new posture for the org and should be taken on knowingly.

### Phase 3 — the surfaces that become possible

A generated per-version changelog (Stripe's is programmatically generated from the change objects);
`/.well-known/api-catalog` per RFC 9727 relating the version documents; retirement signalling with
RFC 9745 `Deprecation` and RFC 8594 `Sunset`.

## Where the package is documented

A production package with its own runtime failure modes needs real documentation, and that is a fork
worth taking deliberately rather than by default.

**It turns on one question: is the package usable without Docuccino?** If it is — and a Laravel
versioning runtime plainly is, since that is what every existing package in the ecosystem does — then
it has an audience who will never generate a document, and who arrive searching for API versioning
rather than for API documentation.

Two shapes:

- **A section on `docs.docuccino.app`.** Keeps one domain's authority, one search index, one sidebar,
  and one deploy. The site's information architecture already anticipates a second axis: framework
  pages are path-scoped under `laravel/` and the topic switcher is explicitly deferred until a second
  framework ships. A second *product* is a third axis again, so the sidebar decision cannot be
  deferred twice.
- **Its own site.** A clean front door for the audience that wants versioning and not documentation,
  with its own comparison pages against the existing Laravel packages. Costs a split of domain
  authority, a second deploy target beside the existing website workflow, and a hop for the reader who
  wants both.

A hybrid is available and probably right: **its own landing page for discovery, its reference on the
main site**, so the two audiences each get a front door while the reference — where a reader moves
between versioning and the rest of Docuccino constantly — stays in one place and one search.

Whichever is chosen, the linking runs both ways or neither: the Docuccino documentation has to name
versioning where a reader would look for it, and the package's own pages have to say what generating a
document buys, or the tight integration is invisible to exactly the people it is for.

Naming follows the existing rule: a split repository whose name would be language-generic carries a
language prefix, and one already naming its framework does not.

This is phase 2 work — a production package ships with documentation or it does not ship — but the
sidebar and domain decision is worth settling in phase 1, because it is cheap to decide now and
expensive to move once pages exist and are indexed.

## Out of scope

**Data versioning** — migrating stored records to a new shape. Cadwyn is unambiguous that this is not
an API-versioning problem and cannot be solved by one; its worked example ends in four social remedies
rather than a technical one. Anything shipped here should say the same rather than imply otherwise.

## Limits inherited with the model

These are consequences of the design, not gaps to engineer around. Cadwyn documents each:

- **Side effects do not compose.** A change that alters behavior rather than shape needs an
  `isApplied()` escape hatch in the handler, and they accumulate linearly.
- **Declarative defaults do not work alone** — a declared default needs a matching converter, or the
  document promises a value the wire never carries.
- **Incompatible type changes are refused.** `int` → `string` is data versioning.
- **Enum expansion is breaking for responses**, because a wider union is not a subtype of a narrower
  one.

The runtime failure modes are worse than documentation ones, and the Keygen write-up is the list to
read before phase 2: migrations bleeding into webhook payloads, migrations applied to error responses
and to `204 No Content`, and a migration silently ceasing to apply when a route is renamed.

## Open questions

- Header or URL path for the version, and whether an unknown version waterfalls to the closest earlier
  one.
- Whether to follow Stripe's move from monthly dates to named release trains, which caps breaking
  versions at roughly two a year and is the single biggest lever on the cost of everything above.
- Whether N documents × M operations is affordable in one build, and whether the fact that most
  versions touch a handful of operations can be exploited.
- Whether a failed per-version assertion is a build diagnostic or a test failure.
- Whether the package documents on `docs.docuccino.app`, on its own site, or splits landing page from
  reference — settle in phase 1, since moving indexed pages later is the expensive half.
