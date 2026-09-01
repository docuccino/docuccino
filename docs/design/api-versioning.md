# Date-based API versioning

> **Status: phase 1 is built. Phases 2 and 3 are design.** The evidence behind these decisions —
> Stripe, Cadwyn, Intercom, Airflow, and the Laravel ecosystem survey — is in the research issue
> [#316](https://github.com/docuccino/docuccino/issues/316). This document records what was decided,
> what building the first slice established, and what the later slices look like; it is not a roadmap
> and carries no dates.

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
| Contract assertions against a named document | `ApiContract::forDocument()` |
| Stable identities and lookup by node id | `x-docuccino.id`, `ContractIndex` |
| Date version policy, and a differ that classifies breaking vs non-breaking | `DateVersionPolicy`, `DocumentDiffer` |
| Reading a document as it was at any git ref | `docuccino:diff --against=<ref>` |
| Byte-determinism, per-node provenance, per-operation coverage | pipeline-wide |

## Decisions

### The code is the newest version; older documents are derived backwards

This is the direction the whole model runs in, and it is worth getting straight before anything else.

A version change declares a shape that is **no longer in the code**. So the head of the repository is
always the newest version, and an older version's document is the head document with every later change
undone, newest first, each handing the shape of the version below it to the next. Nothing is enacted,
nothing is deleted, and no branch has to keep an old shape alive: a change class describes the past, and
the build reads the present.

The obvious-looking alternative — enact the breaking change in the codebase and have the tooling recover
what used to be there — is not merely unnecessary, it is harmful. It makes the newest document, the one
thing the build can read directly, into a derived artifact; it forces an application to keep N shapes
compiling to document N versions; and for the versions that matter it is impossible anyway, since the
whole premise is that the old shape is gone.

The cost is a vocabulary that reads backwards at the point of writing, and that is the one mistake it
invites: `to:` is the field name in the code today, `from:` is the name older versions publish. The pair
written the other way round renames the wrong end and produces a version document describing a shape
nobody ever served.

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

The parameter is declared ONCE, in `components.parameters`, and every operation `$ref`s it. Inline it
is the document's largest repetition by a distance — four parallel arrays one member long per version
plus a fixed sentence, on every operation — so the document grows by operations × versions where
nothing about the declaration varies with either: measured, a 400-operation API publishes 435 KB at one
version and 3.8 MB at fifty, against a flat 254–263 KB shared. The component is named for the HEADER,
never for the route table, which is why it may be named at all where a scoped change's fork may not
(§"the fork rule"). Each use site keeps its own parameter id beside the `$ref`, the way a hoisted error
response keeps its use site's, so `ContractIndex`, per-operation coverage and provenance address the
same nodes they did inline; `x-docuccino` never survives an OpenAPI emit, so the artifact a consumer
reads is a bare pointer either way. No `representation` keyword switches it off: unlike the three that
do, this parameter is minted whole from document config rather than recovered from application code, it
is byte-identical on every operation by construction, and the inline form has no property the shared
one lacks.

There is no OpenAPI convention for "this field exists from version X" — the registry carries only a
boolean `deprecated`. Minting an extension for it is a decision to take deliberately and say out loud.

`info.version` **is** the version, and `api_version` says only that the document is one. A second
config key naming the version again would be two sources for one fact, and the only thing two sources
ever do is disagree. A document left at the shipped `1.0.0` placeholder therefore states no version at
all: it is not derived, and the build says so (`versioning.version-unstated`) rather than putting a
version nobody serves into every operation's enum and making it the value a client falls back to.

### Which of two versions is older is asked once

Deriving an older document is a walk down an ordered change list, so ordering is load-bearing rather
than incidental. It is the SAME question the diff policies ask when they gate a version bump, and it is
answered in one place for both — `date` compares the `YYYY-MM-DD` prefix, `semver` compares three
integers. `strcmp` is not that answer for either: bytewise, `1.10.0` comes before `1.9.0`, so a
semver-versioned application's changes would apply backwards, deterministically and silently.

The order comes from the document's `versioning` keyword, and where the keyword names none it is
derived from the shape of the versions themselves — all dates, or all semver. That is a default rather
than a knob: an application spelling its versions plainly never has to spell them out again in config.
Versions that are neither, or a mixture, order under nothing and no change is applied.

### Scope is a change's property, not a verb's

A change applies wherever the schema it names appears, which is the common case and the only one that
needs no fork. `#[AppliesTo]` narrows it, and sits beside `#[ApiVersionChange]` rather than being an
argument of `#[RenamedResponseField]`: scope is a property of the change, so declaring it once covers
however many fields the change renames and every verb added later inherits it for free.

Its selectors are the vocabulary a config entry naming an operation already uses — an operation
signature, an operationId, either with a `*` — read through `Glob`, the product's one wildcard grammar
and the one `routes.include`/`routes.exclude` speak, so a `*` never means one thing to the author and
another to the build.

**The fork rule.** A narrowed change means the operations in scope genuinely have a different type from
the rest in that version's document.

1. Compute the operations this document publishes the schema for, following `$ref`s.
2. If the scope covers all of them, rename the shared component **in place**. No fork — otherwise
   scoping to every operation would emit something different from scoping to none, which is the same
   fact said twice in two shapes.
3. Otherwise the operations in scope get the older shape **inlined**, and the rest keep the shared
   component untouched.

Inlining rather than minting `FormDataV2` is the point. A published component name becomes a type name
in a generated client, and `ComponentNames`' invariant is that a name is a function of the things
contesting it — a name appearing or vanishing with how many operations happened to share a body would
be a function of the route table, so an unrelated new endpoint would rename somebody's type. An inline
schema registers no name, so it cannot. The one shape this cannot produce is a schema containing
itself: its private copy would point at the shared component one level down, so the operation is left
at the shape the code publishes and the build says why.

## The correctness model

This is the differentiator. Three checks, none of which requires Docuccino to transform anything at
runtime:

1. **Per-version contract test.** Replay the suite with the version pinned; every response must
   validate against *that version's* document.
2. **No silent drift in a live version.** Regenerate a version's document and require every difference
   from the published one to be non-breaking, **or** attributable to a declared version change.
3. **Coverage per version** — which operations of version X the suite never exercised.

**Check 1 is load-bearing, and it is what shipped first.** Separating the declarative half (ours) from the
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

### Phase 1 — a walking skeleton, end to end — built

One real version change, in the vocabulary, producing two documents, with a contract test that passes
for both **and fails when the transformation is wrong**. Deliberately thin and deliberately vertical: it
proves the claim before any layer is built out. What it came to:

- **Two attributes and one modifier.** `#[ApiVersionChange]` carries the version and the sentence a
  consumer reads; `#[RenamedResponseField]` is the first verb; `#[AppliesTo]` is the modifier, written
  beside the change rather than inside the verb. Every argument is a string or a `::class` constant and
  every one is read by reflection, so nothing parses, folds or executes a line of the application — a
  change class's body is never read at all.
- **A document per version.** `api_version` says the document IS a version, `info.version` says which
  one, and every declared change that shipped after it is applied in reverse. A document declaring no
  `api_version` is not a version, and not a byte of it moves.
- **The version header, with its enum.** Every operation publishes the header a client pins with:
  optional, defaulting to this document's version, enumerating every version the application configures,
  and decorated with SDK member names and each version's own sentence — a date is not an identifier. One
  declaration in `components.parameters` that every operation `$ref`s, named for the header. An
  application that documents the header itself keeps its own wording.
- **Scoping, and the fork rule** above.
- **The per-version contract test.** Replay a real request with a version pinned and require the
  response to validate against *that* version's document — including the half that has to be able to
  fail, where a head-shaped response is checked against the older document and refused, naming the
  field.

The vocabulary is designed here, once, for both readers — statically foldable for Docuccino, ergonomic
for the runtime package that will emit it in phase 2.

### The required-ness verbs, and the order verbs apply in — built

The second slice: `#[MadeResponseFieldRequired]`, `#[MadeResponseFieldOptional]` and
`#[MadeRequestFieldOptional]`, plus the ordering rule a change carrying two kinds of verb needs.

- **Required-ness is modelled directly.** JSON Schema's `required` is a first-class array, so the
  vocabulary moves it rather than importing Pydantic's conflation of required-ness with a missing
  default. `properties` is never touched by any of the three.
- **Three verbs rather than two with a flag, and no fourth.** `SchemaPolarity::memberPresence()`
  already records the asymmetry: a `required` entry arriving NARROWS a request and moves nothing on a
  response. So "made required" on the way in and on the way out are different sentences to a consumer,
  and `#[MadeRequestFieldRequired]` is the combination there is no honest verb for.
- **Two of the three are falsifiable and one is not, and that is said out loud.** Dropping a
  `required` entry widens what the older document accepts, and a widening of a true statement is
  true — so the per-version contract test cannot fail on `#[MadeResponseFieldRequired]`. It is safe by
  construction, not by being checked, and the reference page says so rather than implying an oracle it
  does not have. The two `Optional` verbs make the older document stricter, and the contract test can
  and does refuse a runtime that does not keep the promise.
- **The verb order: renames last.** `AttributeSet::all()` answers per attribute type, so once a change
  carries two kinds of verb the author's written order is gone and something has to decide. Every verb
  but the rename names its field the way the code spells it TODAY, and a rename is the one verb that
  changes what a field is called — so a rename run first leaves every other verb looking for a
  property that has already moved, editing nothing and reporting a correct declaration as rotted.
  `VerbOrder` states it once; `VersionChangeOrderTest` is the executed guard.
- **`required` order is a function of the schema.** An added entry goes in at the index equal to the
  number of entries already standing that `properties` puts before it. Appending would make the list
  depend on the order the verbs were written in; counting is commutative, so two verbs adding two
  fields land the same way round whichever ran first, and no entry already standing has to move.
- **One identity mint for both facets.** A class's request shape and its response shape are different
  nodes, and `SchemaIdentity::publishedId()` is now the single place the qualifier is spelled — read
  by the recovery chain that writes the ids and by the verbs that resolve them. Before it, the rename
  verb minted from the raw FQCN and so could not reach a class that pinned its own identity with
  `#[SchemaId]`: the change was skipped with `versioning.schema-unresolved`, of a schema the document
  was plainly publishing.
- **A weaker diagnostic, stated as weaker.** A rename has a distinguishable before and after, so it
  can tell `renamed` from `taken` from `absent`. "Remove from `required`" applied twice is a no-op, so
  nothing can report that the edit already ran. What can be reported is the code disagreeing with the
  declaration — the change says a promise moved and the schema already says what the older version
  would — which is `versioning.change-target-unchanged`, and it fires only on declarations that are
  wrong.
- **No diagnostic for a required-only change under a partial scope.** Such a change inlines a whole
  schema, and everything between the operation and it, to publish a one-entry difference — a real cost,
  and it is recorded under "Limits the first slice found" below. It is not a diagnostic: nothing in the
  corpus has fired one, an info-severity note about a trade the author made deliberately is the noise
  that trains a reader to stop reading the channel, and the fork's cost is already reported where it
  actually fails (`versioning.scope-unforkable`).

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

**Settled: the hybrid.** Its own landing page for discovery, with its own comparison pages against the
existing Laravel packages, and its reference on the main site — so the two audiences each get a front
door while the reference, where a reader moves between versioning and the rest of Docuccino constantly,
stays in one place and one search index.

The linking runs both ways or neither: the Docuccino documentation has to name versioning where a reader
would look for it, and the package's own pages have to say what generating a document buys, or the tight
integration is invisible to exactly the people it is for.

Naming follows the existing rule: a split repository whose name would be language-generic carries a
language prefix, and one already naming its framework does not.

Writing the pages is phase 2 work — a production package ships with documentation or it does not ship —
but settling the shape early is what makes the phase-1 material free of it. That material is the
generator's own feature rather than the package's runtime, so it sits with the rest of the generator's
guides and never has to move; the package's reference becomes a new top-level sidebar group when the
package ships.

## Out of scope

**Data versioning** — migrating stored records to a new shape. Cadwyn is unambiguous that this is not
an API-versioning problem and cannot be solved by one; its worked example ends in four social remedies
rather than a technical one. Anything shipped here should say the same rather than imply otherwise.

## Limits the first slice found

Consequences of the decisions above, each one a diagnostic rather than a silent degradation.

- **A schema that contains itself cannot be forked under a partial scope.** The fork gives the
  operations in scope a private copy, and a copy of a self-referential schema still points at the shared
  component one level down — so the operation would publish the older name at the top and today's name
  one level in. The operation is left at the shape the code publishes and the build says why. An
  unscoped change has no such limit: it renames the component in place, and the self-reference goes on
  pointing at it.
- **A required-only change under a partial scope buys the least of any verb.** The fork gives each
  matched operation a private copy of the schema — and of everything between the operation and it,
  since every `$ref` on the way down is expanded — to publish two schemas differing by one entry in one
  list. The trade is the same one every scoped change makes and the arithmetic is much worse, so the
  guide says to widen the scope and take the no-fork branch where the promise really did move
  everywhere.
- **An inlined fork repeats a body rather than minting a name.** Under a partial scope each matched
  operation carries its own copy of the older shape, so a generated client gets an anonymous type per
  operation instead of one it can name. That is the price of the naming invariant, and it is worth
  paying: `FormDataV2` would be a name that appeared, vanished and moved with how many operations
  happened to share a body — a function of the route table rather than of the schema, so an unrelated
  new endpoint would rename somebody's type. Scoping to *every* operation that publishes the schema
  costs nothing, because that is the branch with no fork in it.

  The names on the way DOWN go the same way, and that half is easy to miss: the copy is made by
  expanding every `$ref` between the operation and the schema, so an operation whose body was
  `Envelope` → `Page` → `FormData` publishes one anonymous object where a client would have had three
  named types. It is the same price for the same reason — a `$ref` left in the copy would point back at
  the shared component — and only the operations in scope pay it.
- **There is no removal verb, and not building one was deliberate.** "This version also published
  `subtotal`" has to declare the removed field's TYPE, because the type is exactly what is no longer in
  the code to read — so the verb carries a schema, and a schema written as an attribute argument is a
  second type grammar beside the one the rest of the product recovers out of PHP. Worth building when an
  application asks for it, and worth not guessing at before then.

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

- Whether the version can also travel in the URL path. A header is what is built, following Intercom,
  Stripe and GitHub; path versioning is not refused, only not built.
- What an application does with a pin it does not recognise. Whether an unknown version waterfalls to
  the closest earlier one is a runtime decision the application owns — Docuccino describes the versions
  that exist and holds no opinion about the rest, and neither the document nor the generator is where
  that answer could live.
- Whether to follow Stripe's move from monthly dates to named release trains, which caps breaking
  versions at roughly two a year and is the single biggest lever on the cost of everything above.
- Whether N documents × M operations is affordable in one build, and whether the fact that most
  versions touch a handful of operations can be exploited.
- Whether a failed per-version assertion is a build diagnostic or a test failure. Building check 1
  answered it for check 1 — a test failure, because the check is only worth anything if the real router
  and the application's own middleware ran to produce the response. Checks 2 and 3 are still open.
