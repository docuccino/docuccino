# Design: UIR & Extension Architecture

Status: approved (2026-08-01). Reconciled with `docs/design/inference-embedding.md` —
where the two conflict on the TypeEngine boundary, the inference doc wins.
See the README for scope; this doc carries implementation-level detail.

## 1. UIR document

OAS 3.2-shaped JSON with one reserved key `x-docuccino` allowed on every node. Schemas are
JSON Schema 2020-12 (`jsonSchemaDialect: https://spec.openapis.org/oas/3.2/dialect/base`).

Top level:

```json
{
  "$schema": "https://spec.docuccino.app/uir/1.0/schema.json",
  "uir": "1.0.0",
  "openapi": "3.2.0",
  "jsonSchemaDialect": "https://spec.openapis.org/oas/3.2/dialect/base",
  "info": {}, "servers": [], "security": [], "tags": [],
  "paths": {}, "webhooks": {},
  "components": { "schemas": {}, "responses": {}, "parameters": {}, "securitySchemes": {}, "examples": {}, "headers": {} },
  "x-docuccino": {
    "document": { "id": "doc:default", "configHash": "…", "contentHash": "…" },
    "generator": { "name": "docuccino/laravel", "version": "…", "specVersion": "1.0.0" },
    "content": { "pages": [] },
    "diagnostics": []
  }
}
```

- `contentHash` = SHA-256 over canonical serialization EXCLUDING `x-docuccino.generator` and
  `x-docuccino.diagnostics` (tool upgrades don't dirty CI diffs).
- No timestamps anywhere — banned by the UIR schema itself.
- `x-docuccino.diagnostics` embedded only with `--embed-diagnostics` (CLI is the primary channel).
- UIR spec semver is independent of PHP packages; `$schema` URL embeds major.minor.
  Consumers MUST ignore unknown `x-docuccino` members (additive = minor; shape/identity change =
  major + new `$schema` URL).

### The empty-object invariant: the JSON values a PHP array cannot spell

A document is assembled, canonicalised, cached, compared and emitted as PHP arrays, and a PHP array
cannot spell every JSON object. Exactly two shapes are out of reach: `{}`, and an object whose member
names re-key to a `0..n-1` run (`{"0":"a","1":"b"}`) — PHP re-reads `"0"` as the integer 0, and an
array keyed `0,1,2…` writes back as a list. Nothing else is out of reach, and it matters that the set
stops there: `{"1":"a","2":"b"}` and `{"201":{}}` are plain arrays that write back as the objects they
were, so carrying THOSE as objects too would buy nothing and cost every caller legitimately holding a
numerically-keyed MAP — a `responses` keyed by status code, most of all.

Those two stay `stdClass`, the codebase's standing spelling for a JSON object no array can hold, and
nothing downstream can put the distinction back: a JSON Schema validator takes `{}` for `type: object`
and refuses `[]`, and one PHP array is both. `additionalProperties: []` is not a schema at that
pointer, `paths: []` is not a Map, and an `example: []` beside a `type: object` is a body that lies
about its shape.

**One reader, because two readers disagree.** `Core\Support\JsonValue` is the only thing that pulls
JSON INTO a document — an authored `@example` literal, an `#[Example(file:)]`, a recorded response
body, a shared recording session, a cached operation fragment, a committed artifact re-read for a diff
or for the viewer. `array_is_list()` over the cast members is the entire rule, which is why it is
position-independent and needs no list of which keywords hold data. `JsonReaderArchTest` enforces it
rather than asking: an associative `json_decode` under any package's `src/` is either in the
allow-list with the reason its value never reaches a document, or the suite fails. Entries are
justified by where the value GOES, never by the call looking harmless, and a stale entry fails too,
because a line whose call has moved guards nothing.

Five readers had to be converted to get there, and two of them are why "remember to" was never the
mechanism. The harness's own `loadFixture()` decides what every downstream assertion is looking at, so
its loss was invisible from inside — load a fixture holding `example: {}`, re-emit it, and every
round-trip, golden and self-comparison agrees on `[]` because both sides went through the same loss.
And `SharedRecordingLedger`'s session file is how a recorded body reaches the worker that did not
record it; it decoded associatively while the committed sidecar holding the same values did not, so
**which worker won a slot decided whether a published example kept its shape.**

**A shared instance is impossible, so equality is asked by value.** Every such object is minted fresh,
and `===` on an object is instance identity — so two producers writing the same `{}` read as a
disagreement, and the patch guard records a phantom `overrode` against a value nobody displaced.
Interning one instance per process was tried and retired, for two reasons. It is not sufficient: only
the EMPTY object can be interned, and the index-keyed shape is minted per site regardless. And it is
not safe, because a `stdClass` subclass cannot be made immutable in PHP. `__set` catches a direct
assignment and nothing else — PHP resolves a by-reference property ACQUISITION through
`get_property_ptr_ptr`, which creates the property on the instance without consulting `__set` at all,
and `&$o->x`, `$o->p[] = 1`, `$o->n++`, `preg_match(…, $o->m)` and `parse_str(…, $o->p)` are every one
of them that. A shared instance is handed to `DocumentTransformer`, which is code we do not own, so a
single such write would reach every `{}` in the document — structural positions included — and persist
to the fragment cache on disk.

So `JsonValue::same()` is the comparison: `===` in every respect EXCEPT that two `stdClass` standing
for the same JSON object are one value. Nothing else relaxes, deliberately, and `Support\Json::stable`
cannot serve as the helper here — JSON has no spelling for an integer-valued float, so it answers `1`
for both `1.0` and `1`, which is precisely the distinction a warm-versus-cold assertion exists to see.
`graphDifferences()` is the same rule over whole documents, reporting the position rather than a
boolean.

**Free-form data needs the distinction; a structural position does not.** `JsonValue` keeps the two
spellings apart because an `example: {}` read back as `[]` publishes a lie. A schema, a `properties`
entry, an `items` is a map whichever way it was written, so `Support\Hydrate::mapOrNull()` accepts
both — and a reader at such a position that tests `is_array()` alone does not degrade, it DROPS the
node, after which a comparison that never sees a member reports it added.

**Where a value cannot report what it was, the POSITION answers.** Once a document is PHP arrays the
value is no longer able to say which JSON it came from, so the answer has to come from the keyword's
own contract: `Draft\SchemaKeywords::SUBSCHEMA_POSITIONS` states, per keyword, what its value is. A
keyword named there needs no further entry anywhere — the canonicalizer derives how to canonicalise
it, the structural hash where to recurse, `Contract\SchemaCheck` what to repair, the example audit
where to descend, and the 3.0 downlevel where to convert. The one thing still stated by hand is the
ORDER members publish in, which is a normative choice rather than a fact about the keyword, so it is
stated where it is a decision and held against the table by a guard; going stale therefore costs a
member its place and never its shape. Draft-07's `dependencies` has no row and cannot have one — each
of its members is EITHER a subschema or a list of property names, decided by the member's own value, so
one position cannot describe it and a reader picking either answer would be wrong half the time. It is
left unpositioned, which every reader treats as data and none rewrites.

The same reasoning covers the other value only a position can vouch for. A boolean IS a schema at
every subschema position, and there it is the most load-bearing value in the language:
`properties: {a: false}` says the property must never appear, and rewritten to `{}` it says the
property may be anything; `allOf: [false]` is satisfied by nothing and becomes satisfied by
everything. The identity half is worse than the bytes — three semantically distinct schemas minted ONE
`sch:` id, so content-dedupe merged them, deduping by a key that had already thrown away the
difference the name was supposed to carry. So one subschema reader answers for every arm, and for the
four slots where a Schema Object hangs off something that is NOT one: a media type's `schema`, a
parameter's, a header's, and every member of `components.schemas`. A value that is no schema at all
still widens to `{}` at every slot, which is the vague-but-true answer rather than a document no
validator accepts.

**That widening is deliberately SILENT, which is the one place the "say so with a diagnostic" rule does
not apply.** Widening normally owes a diagnostic, and the boolean arm at this very site raises
`downlevel.boolean-subschema` when 3.0 cannot carry one — so the channel exists and only one arm uses
it. Three things settle it. The population is zero and cannot be otherwise from inside: every producer
mints a typed schema array, so nothing the product writes can put a non-schema at a subschema slot, and
the one authoring surface that could — an overlay's `mixed` update — has no instance anywhere in the
corpus. The site has nothing useful to say: it is a pure function with no pointer state, reached from
identity hashing, content hashing, spec validation and every emitter, two of which run per fragment
many times a build with nowhere to put a report, so a diagnostic here would fire on a 3.0 export and
not on a UIR emit — one policy per producer, which is the anti-pattern. And the boolean arm is not the
precedent it resembles: it reports a LOSS an author can act on, because the `false` they wrote is being
weakened, whereas a value that was never a schema is nobody's claim.

`document.schema-invalid` cannot stand in either, and moving it earlier is not the fix it looks like.
`SpecValidation\Validator` canonicalises BEFORE validating, so the coercion runs first and launders the
problem — but that hop is what turns a PHP array into JSON at all, and by the invariant above an array
cannot spell `{}`. Validate the bytes as handed over and a legitimate `properties: {}` is rejected: the
validator is a post-condition on what the emitter writes, never a pre-condition on the array it was
given. `CanonicalizerTest` pins all three facts, laundering included, so the silence is a decision on
the record rather than an omission.

**Two readers stand at those outer slots.** The canonicaliser answers on the way OUT; the document model
answers on the way IN, and a slot it drops never reaches the canonicaliser to be published correctly at
all. The model's loss is the worse of the two, because it does not merely restate the value — it removes
the member. An omitted `schema` inverts the parameter exactly as `{}` would AND fails the spec's own
`parameter.anyOf`, and a vanished `components.schemas` member leaves every `$ref` naming it dangling: a
document every validator accepts and every client generator breaks on at load time. So a schema slot is
hydrated through `Hydrate::schemaOrNull()` and `Hydrate::schemaMap()` rather than the plain object
helpers, which answer `null` for a boolean and for the `stdClass` a `{}` arrives as. The two differ on one
point, and it is the pointer that decides it: a slot on an OBJECT may be absent, since nothing can name
it, while a member of a MAP is only ever widened, because a `$ref` can.

**Twenty sites, which is why the rule is stated here once.** The class is the same defect throughout —
something asked the VALUE what it was, where only the position or the reader knows — and every
instance shipped green, because a suite whose two sides go through one loss agrees with itself.

| Object mistaken for array | What it published |
|---|---|
| an untyped parameter's draft | coerced its empty schema to `null`, so the parameter published no schema at all |
| the docblock `@example` reader | refused `{}`, the one literal it would not publish, with `docblock.example-untypable` — 34 unactionable warnings on a 221-operation application, and the natural example on a free-form map lost |
| the inline and `#[Example(file:)]` readers | each read the same bytes their own way, so one example published differently depending on where the author put it |
| the YAML writer | cast every `stdClass` away, so `paths: []` and `additionalProperties: []` shipped spec-invalid while the JSON of the same build was correct |
| the fragment cache | wrote with `json_encode` and read back associatively, so the warm build published `"example": []` where the cold one published `{}`, with `publishedSchemaId` and `contentHash` moving with it |
| the `source=artifact` viewer | re-emitted a committed document associatively — one document, two answers, on the copy a reader actually looks at |
| `SharedRecordingLedger` | read its session file associatively while the committed sidecar beside it did not |
| `Diff\SchemaComparator` | gated its property walk and `items` descent on `is_array()`, so a property whose schema is `{}` read as ADDED against a document that had it all along |
| the harness's `loadFixture()` | lost `{}` in the one place that decides what every downstream assertion is looking at |
| the patch guard, and three document comparisons | asked instance identity where they meant value |
| `Lint\ExampleSchemaLint` | audited a pre-canonicalisation draft and handed `additionalProperties: []` to a validator that correctly refuses it: an uncaught exception, no document at all, and a release reverted |
| the canonicalizer's per-keyword handlers | never learned `unevaluatedProperties`, `unevaluatedItems` or `additionalItems`, so an overlay writing any of the three published `[]` |
| the same table | was short `contentSchema`, `definitions` and `dependencies` |

| Boolean mistaken for the empty schema | What it published |
|---|---|
| the single-subschema arm | flattened a boolean, so `not: false` published as `not: {}` — the exact opposite — and `items: null` became a confident `items: {}` |
| the map and list arms | did the same at 12 of the 20 positioned keywords, so `properties: {a: false}` and a boolean branch of `allOf`/`anyOf`/`oneOf` all inverted |
| the four outer slots | did the same where a Schema Object hangs off something that is not one, so `content: {application/json: {schema: false}}` published `{}` |
| the document model, at two of the four | DROPPED rather than flattened, one layer before the canonicaliser could answer: a parameter's `schema: false` republished with no `schema` at all, and a `components.schemas` member written as `false` vanished from the bucket while every `$ref` kept pointing at it. The differ then read the loss rather than the edit, reporting the tightest narrowing in the language as a non-breaking `schema.type-removed` |
| the 3.0 downlevel | passed a raw boolean through at six positions 3.0's own closed member set rejects, so the artifact failed the repo's vendored meta-schema with zero diagnostics |
| `Diff\SchemaComparator` | read no boolean subschema, so `items: {type: string}` → `items: false` — the tightest narrowing an element contract has — reported NO change while `contentHash` moved |
| `Contract\Examples\ExampleAudit` | kept three hand copies of the position table, short by five keywords, so an `#[Example]` under `if`/`then`/`else`/`unevaluatedItems`/`unevaluatedProperties` was never checked against the schema beside it |

Nine of those are one narrower shape worth naming, because it is the one a guard can catch: a stale
SECOND copy of the subschema table — three in the example audit, two in the 3.0 downlevel, three in
the canonicalizer, one in the structural hash. `DeclaredShapeTest` therefore fails on any `const array`
anywhere in the packages naming three or more positioned keywords unless it is one of the four
sanctioned lists, each stated for a reason that is not "which keywords carry subschemas".

### Diff polarity: what a change under a subschema position is worth

The position table says where a subschema hangs. It does not say what an edit down there means, and it
cannot: `items` and `not` sit at the same position and point opposite ways. So `Diff\SchemaPolarity`
records one decision per positioned keyword, on three axes, and `Diff\SchemaComparator` is its only
reader.

**Polarity** governs a change UNDER the position. `DIRECT` — narrowing the subschema narrows the parent,
so the child's classification carries up unchanged; every position constraining the value's own members
reads this way. `INVERSE` — narrowing the subschema WIDENS the parent, which is `not` and only `not`.
`CONDITIONAL` — no polarity can be computed: `if` moves instances between the `then` and `else` branches,
so narrowing it widens where there is no `else` and is indeterminate where there is, while
`$defs`/`definitions` are a STORE rather than an assertion, so a member's polarity is whatever the `$ref`s
naming it are worth, which the differ does not resolve.

`then` and `else` are DIRECT rather than conditional, which is a correction to the obvious reading:
`{if: A, then: B}` accepts `(A ∧ B) ∨ ¬A` and `{if: A, else: C}` accepts `A ∨ (¬A ∧ C)`, and narrowing B
or C narrows either set. Only `if` itself is non-monotone.

The direction an INVERSE or CONDITIONAL child moves the parent in is exactly what cannot be computed, so
nothing tries: the child's own code and path are published unchanged — each a true statement about the
subschema the path names — and the VERDICT is forced to breaking. For a release gate a false alarm costs
the author one look and a false "safe" costs the consumer a broken client, so the indeterminate case is
breaking by decision rather than by accident. An annotation-only edit is the one exception, because it
moves no contract at any position.

**Member** is what PRESENCE means at the position, which is a separate question from polarity because at
most positions an absent subschema and the empty schema mean the same thing and at four they do not. It
is asked twice, and the two questions have different answers: the KEYWORD arriving or leaving, where the
side without it carried no constraint of that kind at all, and ONE MEMBER of a position both sides
carried. `Diff\SchemaMember` is the closed set of kinds — an enum rather than string constants, because a
kind is minted in the differ and never read off a document, so every `match` over it is exhaustive with no
default and a kind added with no verdict is a PHPStan failure rather than a conservative runtime guess.

- `EmptySchema` — absent IS the empty schema (no `items` constrains no element), so a member arriving or
  leaving falls out of the ordinary keyword comparison and needs no code of its own.
- `Constraint` — absent is not the empty schema, and arriving narrows: `not: {}` rejects every value while
  no `not` rejects none. `allOf` reads the same way at both questions, an intersection arriving being an
  intersection's branch arriving writ large.
- `Bounded` — `contains`, whose arrival narrows only while it asserts anything: `minContains: 0` drops the
  demand for a matching element, but a `maxContains` beside it still caps how many may match, so both
  bounds decide it (`Draft\SchemaKeywords::containsAsserts()`).
- `Union` — the one kind whose two answers differ, and the reason the questions are asked apart. A BRANCH
  of an existing `anyOf`/`oneOf` removed narrows the union and is breaking either way, while one added
  widens what a request accepts and is safe there — a response can now carry a shape no existing reader
  has a case for, the `schema.enum-value-added` argument exactly, so it is breaking on a response. The
  KEYWORD arriving is not that: the old side was not an empty union, it was unconstrained, so the union
  landing narrows both sides, and it leaving mirrors `schema.enum-removed` — a request widens while a
  response reader loses the closed set of shapes it typed against.
- `Store` — a `$defs` member. Arriving is nothing (nothing can name a definition that did not also change);
  leaving may dangle a `$ref` the differ does not resolve, so it is breaking.
- `Property` and `Required` — the two positions with a comparison of their own (`properties`, and
  `dependentRequired`'s per-property lists, whose entries narrow a request exactly as `required` does).

**Pairing** is how two sides' members are matched, and only one fact about it is a decision the keyword
owns: `pairsByIndex`, true for `prefixItems` alone, because a tuple index IS the slot it constrains. A map
pairs by key and a composition list pairs by what a member IS — identity, then the component it names, then
its content, never its position, `ComponentNames`' rule applied to branches — and the position already says
which of those two applies.

**Refinement** is the same defect one level down and a different set of questions. A refinement carries
no subschema, so there is no polarity to invert, no `if` to make a direction incomputable and no member
list to pair; what a `maxLength: 3` is worth beside a `maxLength: 100` is a direction in the keyword's
own VALUE space. `Diff\SchemaRefinement` records one decision per refinement keyword and
`Diff\RefinementKind` is the closed set of readings, an enum for the same reason `SchemaMember` is one:

- `UpperBound` / `LowerBound` — a ceiling or a floor, lower and higher being narrower respectively.
  `maximum` and `minimum` sit at the same place in a schema and point opposite ways, which is why the
  direction is the keyword's own rather than the family's. Each row also carries what the keyword's
  ABSENCE means, so `minLength: 0` written out is a restatement of the default floor while `minimum: 0`
  is a floor arriving where there was none.
- `Divisor` — `multipleOf`, where narrower is "a multiple of what it was": the multiples of 4 are a
  subset of the multiples of 2, and 2 against 3 orders neither way. Read with a relative tolerance,
  because a decimal step does not divide exactly in binary (`0.1 / 0.05` is 2.0000000000000004) and a
  reader with none calls an ordinary relaxation a change nothing can order.
- `Flag` — `uniqueItems`, off where nobody wrote it, so turning it on narrows.
- `Opaque` — `pattern`, `const`, `contentEncoding`, `contentMediaType`: two values with no order between
  them at all. Presence still orders (one arriving narrows, one leaving widens); a value CHANGED is
  reported as the change it is and classed breaking. A regex containment argument is a real decision
  procedure and nobody should improvise one at a release gate.
- `Elsewhere` — `enum`, `format` and `contentSchema`, each already a member of the diff's own vocabulary,
  plus `contains`' own `minContains`/`maxContains`. Those two are read beside the keyword they bound,
  because they are inert without it and because whether they assert anything is what decides what that
  keyword's PRESENCE is worth; a second reading here would report a bound that constrains nothing.
- `Undecided` — a refinement the draft model knows and nobody has decided, read as a change nothing can
  order rather than as silence.

The verdict is the one the enum comparison beside it already makes. A bound TIGHTENED is breaking on both
sides — a request rejects a body a writer used to send, and a schema's `request` flag can under-state its
audience — while one RELAXED is safe for a writer and hands a response reader a value it has no case for,
the `schema.enum-value-added` argument exactly. A change nothing can order is breaking for the reason
every indeterminate answer here is. Three codes carry all of it — `schema.refinement-narrowed`,
`-widened`, `-changed` — each naming the keyword in its `fields`, because a code per keyword per
direction is forty-odd classifications where the keyword is already the field.

`exclusiveMinimum`/`exclusiveMaximum` changed MEANING between drafts, and a reader that assumes one
dialect silently mis-answers the other — which is what a diff is handed whenever `old` is an artifact
written before a migration. draft-04 spells exclusivity as a boolean modifier on the `minimum`/`maximum`
beside it; 2020-12 spells it as the bound itself. Both are read: two numbers compare as the bound, two
booleans as the flag they are (absent is "not exclusive", and turning it on narrows at either end). A
boolean against a NUMBER is the one case nothing here can order, because telling `exclusiveMinimum: true`
from `exclusiveMinimum: 5` means folding the sibling keyword this comparison does not read — so it is
reported and classed breaking rather than guessed at.

**Reading** is the third set, and the one the two guards above could not see between them: a keyword that
carries no subschema and refines no value is invisible to a scan keyed to the positions AND to one keyed
to the refinements. Five sat in that gap and were read by nothing — `discriminator`, `nullable`, `$id`,
`$anchor`, `$schema` — so a `discriminator` whose `mapping` was repointed passed `--enforce` as safe while
every generated client's polymorphic deserialisation broke. That failure is silent on both sides: the
payload still validates, the client still compiles, and it fails at run time in the consumer's application
as a mis-typed object. `Diff\SchemaReading` records one decision per keyword in that remainder — the whole
remainder, not the five, because "read elsewhere" and "read by nothing, for this reason" are the rows that
stop a sixth member hiding in the same gap — and `Diff\ReadingKind` is the closed set of readings:

- `Discriminator` — the Discriminator Object. The KEYWORD arriving narrows (a client that could send any
  branch must now tag it) and leaving is `schema.enum-removed`'s argument: the schema widens while a
  response reader loses the tag it was switching on. Inside one both sides carry, a `mapping` entry
  removed narrows, one added widens (a branch the client has no case for, so breaking on a response), and
  one REPOINTED is neither — nor is a `propertyName` rewritten, which has no widening reading at all. A
  `mapping` is a MAP, so its entries pair by key and a reordered mapping is not a change; every other
  member compares as a value, so a member OpenAPI adds later is read the day it appears.
- `Nullability` — `nullable`, 3.0's spelling of a type union's `null` branch, read BESIDE that union
  because the two are one statement in two dialects: `{type: string, nullable: true}` becoming
  `{type: [string, null]}` moves no contract, and a reading of the keyword alone calls that migration a
  narrowing and fails the gate on it. Absent is not nullable, so the keyword arriving with `true` widens
  and a null withdrawn narrows. This is the `exclusiveMinimum` split answered the other way round: there
  the sibling keyword could not be folded, here it can and exactly.
- `Identity` — `$id`/`$anchor`. A `$ref` may name either and the differ resolves none, so a name CHANGED
  or gone may leave a pointer naming nothing, which is the reading a `$defs` member leaving already gets;
  one arriving is safe, nothing having been able to point at it before.
- `Dialect` — `$schema`, which names the dialect every keyword beside it is read in. A comparison spanning
  a change to it compared two languages, so the direction is what cannot be computed: reported and
  breaking, an explicit dialect arriving included, because nothing here can tell one restating the dialect
  already in force from a migration to another.
- `Elsewhere` — `type`, `$ref` and `required`, each already a member of the diff's own vocabulary and each
  naming where it is read, so no edit is reported twice.
- `Annotation` — the annotation-only keywords, read as the non-events they are. Held against
  `SchemaKeywords::annotationOnly()` itself rather than listed twice.
- `Unread` — read by nothing, deliberately. `x-docuccino` carries the identity the diff pairs nodes BY, so
  comparing it would report the pairing rather than the contract; `default`, `readOnly`, `writeOnly` and
  `deprecated` are contract claims this diff does not read yet, recorded as the gap they are rather than
  left where no guard can see them.
- `Undecided` — a keyword the draft model knows and nobody has decided, reported under
  `schema.keyword-changed` and classed breaking rather than passing as safe.

Every keyword the draft model gives a subschema position needs a row, and a keyword with no row is read
CONDITIONALLY rather than skipped: a keyword the model learns before anyone decides its polarity is
reported conservatively instead of passing as safe. That is a degradation and not a plan —
`SchemaCompositionDiffTest` derives the set from `Draft\SchemaKeywords` and fails, in both directions,
until the row is written. Every refinement owes a row on exactly the same terms, and owes its own guard:
a refinement occupies no position, so the composition guard is blind to it by construction and nothing in
the suite failed while sixteen keywords stayed unread. `SchemaRefinementDiffTest` derives that set from
`Draft\SchemaKeywords::refinements()` instead.

Two derived guards did not add up to coverage, though, because each was keyed to its own subset — which is
the lesson the five unread keywords taught and the reason there is now a third assertion above the three.
`SchemaReadingDiffTest` holds the UNION of all three tables against every keyword the draft model
classifies (`SchemaKeywords::knows()`), in both directions and with an anti-vacuity floor: a keyword the
model learns that no table decides fails the suite by name, and a decision for a keyword the model does not
know fails it as the dead row it is. The three sets are also asserted to be a partition, so nothing is
answered twice by tables that could disagree about it. The model is in turn held against the canonicaliser's
own member order (`DeclaredShapeTest`), so the chain runs whole: a keyword the document can carry is ordered,
a keyword that is ordered is classified, and a keyword that is classified is decided.

## 2. Identity model

Every operation, parameter, named schema, response, security scheme carries
`x-docuccino.id` = `<kind>:<algoVersion>:<hash>`, where `<hash>` is the first 16 base32
characters of the full SHA-256 of the identity tuple (~80 bits) — matching the impl and the
schema's `nodeId` pattern.

| Kind | Identity inputs (hashed canonical tuple) | Survives | Breaks on |
|---|---|---|---|
| `op:` | doc id + upper method + path template with params positionally normalized (`/forms/{p0}/fields/{p1}`) | file moves, controller/method renames, path-param renames, param reorder | URI or method change |
| `op:` (webhook) | doc id + a `webhook` discriminator + upper method + the webhook NAME verbatim | file moves, class renames | name or method change |
| `par:` | parent op id + `in` + name | reorder, description/schema edits | rename (a real contract change for query/header) |
| `sch:` (named) | source FQCN (+ generic args); pinnable via `#[SchemaId('…')]` | file moves | class rename without pin |
| `sch:` (request body) | the source class identity (pinned id or FQCN) with a `#request` discriminator appended | same as the class identity above — file moves, and rename **with** a pin | class rename without pin |
| `sch:` (inline) | structural hash of canonical schema with descriptions/examples/x-docuccino stripped | prose edits | shape change (correct) |
| `res:` | parent op id + status + media type | — | status change (correct) |
| `doc:` | config key | everything | doc renamed in config |
| `page:` | content page slug | file moves within content dir | slug change |

A webhook is an operation and carries an `op:` id, because that is what the differ pairs on — but it
is keyed by a name rather than a path, so nothing is normalised away (a webhook has no parameters, and
every byte of its name is contract) and the discriminator keeps a webhook called `/forms` out of the
identity the path `/forms` holds.

Never file paths, line numbers, or array positions as identity inputs (those are
provenance). `operationId` (human-readable OAS field) is separate: route name by default,
configurable strategy. Identical tuples (two routes claiming `GET /x`) = error diagnostic.

**An id travels in two forms, and every reader owes both.** UIR carries it nested, under the
`x-docuccino` object that also carries provenance. An OpenAPI export has nowhere to put that object,
so under `keepIds` it projects the id alone as a flat `x-docuccino-id` at the same node — which is
the only identity an emitted artifact can carry, at every node. `Core\Document\NodeIdentity` owns
reading both, and the emitter writes its constant rather than a literal, so a writer and a reader
cannot drift apart. This matters most where the two meet: `docuccino:diff` pairs a freshly built
document against an artifact read back off disk, so a reader that knows only the nested form puts the
two sides in disjoint key spaces and reports every node it cannot pair as removed AND re-added — a
wall of phantom breaking changes on a document nobody touched.

### Component naming: a minted name is a function of the thing

A component's storage SLOT is handed out first-come — `Foo`, then `Foo_2` — and first-come is route
order. Published as-is, the plain name goes to whichever route sorts first, so adding an unrelated
route can swap what two components mean without changing a byte of either shape: deterministic per
build, and still a silent breaking change for every generated client. So the PUBLISHED name is
derived from the set of things contesting it and never from the order they were met.

`Core\Extensions\Schema\ComponentNames` owns that rule and every path that mints a name goes through
it. Each registration states a CLAIM — the name it asked for, the identity behind it, and the bytes
it publishes — and proposes names off a ladder:

1. the name it asked for plus the facet of its identity, so a class's request shape and its response
   shape propose different names always, contested or not (`App\Data\Article#request` →
   `ArticleRequest`, the class's own shape → `Article`);
2. then the innermost namespace segments of its identity, one at a time —
   `AuthenticationSSOConnectionData` beside `SSOSSOConnectionData`;
3. then a prefix of the hash of its identity (of its published bytes, for a claim that names no
   identity) — for two classes in one namespace, or a `#[SchemaId]` pin with no namespace to walk.

While two claims propose the same name they both take their next rung, so nobody keeps a name two
claims asked for. A claim that climbs ONTO a name someone else asked for plainly keeps climbing
alone: the incumbent asked without contest, and renaming it would let one part of an application
move an unrelated one. A contested name is also reported (`components.name-collision`) — the
automatic answer is stable but nameless, and the warning's job is to offer the better one.

The same claims settle `components.responses` and `components.securitySchemes`. A registrar-chosen
literal like `passport` looks exempt and is not: an app that never called `Passport::tokensCan()`
builds a different `passport` definition per distinct scope set.

**Rung 1 is an ASK, and a producer may make it.** For a class-identified schema the ask is the class's
short name and there is nothing to decide. A shared error body has no class to be named after, so its
ask would default to `Error<status>` — which serves neither of the two readers this project has. The
developer running the generator has no way to improve it, and whoever catches the type in a generated
client learns a number rather than what went wrong. `Draft\ResponseDraft::claimComponentName()` lets the
producer that built the body supply the ask instead (`NotFound`), and nothing else about the ladder
changes: an error claim carries no identity either way, so rungs 2 and 3 collapse into the hash of its
published bytes and the ladder is exactly base-then-hash whether the base was declared or defaulted.

Falling to that hash is still the right answer for a name someone chose on purpose. Two DIFFERENT bodies
claiming `NotFound` are two contracts asking to be called one thing, and awarding the plain name to
either would make what a client's `NotFound` means depend on which routes the application happens to
have — the exact defect this section exists to prevent. So both climb to `NotFound_kzvq2m4a`, and the
`components.name-collision` warning names every claimant and the name it got, because the author is the
only one who can tell the two errors apart and give them a name each.

### Shared error components

`Extensions\BuiltIn\SharedErrorResponses` collapses a repeated 4xx/5xx body, in two passes whose
order is load-bearing.

**Shapes first** (`components.schemas`): a body SHAPE two or more operations state identically is
hoisted and each `content[<media type>].schema` becomes a `$ref`. This is the pass that decides what
a generated client gets — one error type instead of one per operation — and it must not care how an
operation DESCRIBES or ILLUSTRATES the error, so `description`, `headers` and the media type's own
examples all stay outside it. Which of those the RESPONSE pass then keys on is a separate question,
answered below: `headers` yes, the words and the illustrations no.

**A declared name describes a response that states ONE representation, and there it names the shape under
it too.** The two buckets hold different kinds of thing, so publishing an anonymous body as
`components.schemas.ValidationError` because the response around it claimed that name asserts the body IS
that error. At a single-representation response the assertion is true, and the two buckets publishing one
concept under one name is the point of the claim — a client catches `NotFound`, and the shape underneath
is `NotFound` too. Where the response states SEVERAL it is a guess, and it was wrong in the case that
found this: a 422 answered with an RFC 9457 problem body under `application/problem+json` and a union of
challenge shapes under `application/json` had the union published as the named validation error, beside
the response component that correctly held the problem body.

**A multi-representation response is not the error its claim names either**, which the same document
proved one level up. A renderer's `#[ErrorComponent('ValidationError')]` reached seventy-eight 422s:
seventy-five stating the problem body alone, and three stating it beside an authentication challenge. Two
responses, one claim, and the ladder retired the name for both — `ValidationError_5lwwjnmg` and
`ValidationError_m2hyrf57`, neither of them a type anyone catches, and the rename fell on the
seventy-five that had held the plain name for a release. Offering a representation the claim says nothing
about is the same defect as being one: the claim describes the response only where the response is the
one thing it describes. So a response stating several asks for **what it carries** — the components its
representations reference, one per distinct shape, joined with `_` in the order the media types sort — and
that name is a function of its own bytes, so neither body is named after the other's existence and the
claim stays with the body that is genuinely it. The three above publish as
`AuthenticationChallenge_ProblemDetailsData`; the seventy-five keep `ValidationError`.

The separator is what makes the join **injective**, and it is load-bearing rather than decorative: run
together, `{Foo, BarBaz}` and `{FooBar, Baz}` both spell `FooBarBaz`, so two responses with no shape in
common contended for one name and each took a content-derived rung neither needed — a pair of hashes
nobody can catch by name, produced by an ambiguity that had no reason to exist. `.` and `-` are the only
other characters a component key may hold and neither survives as an identifier in a generated client, so
`_` is the only candidate. A single distinct shape joins nothing and keeps its own name, so the common
case is byte-for-byte what it was before there was a separator at all.

**The one claim that does describe a response stating several is one that says so.** A producer names the
error it rendered and cannot see what another put beside it — that is the whole argument above — but
something naming the response AT the operation can, and a name an author may override is the point of
having one at all. So the claim carries the statement: `claimComponentName()` takes a `$namesResponse`
flag, frozen beside the name as `x-docuccino.facts.componentNamesResponse`, and only a claim carrying it
reaches a multi-representation response. Nothing a reader can compute off the finished response tells the
two apart, and the precedence LAYER least of all: `#[ErrorComponent]` on an exception class writes at
`attribute` and still speaks for the one error that class is, so ranking on the layer would hand the
reported topology straight back. Only the claimer knows, which is the same reason `$isStatusDefault`
travels with the write.

Every representation has to name a shape, or the response takes its status. A name assembled from the
shapes that happened to be readable would speak for part of the body and say nothing about the rest,
which is the assertion this whole area refuses; `Error422` is vague and true. Names the hoist itself
MINTED are not the document's own either — they move when a shape's contest does, and a response named
after one would move with it — so a body one of whose representations is a shape this pass just named
falls to its status as well. And a shape whose OWN name carries a `_` falls there too, however many shapes
it stands with: `_` splits the join back apart only if no part contains one, so a lone `Auth_Challenge`
reads as a join of `Auth` and `Challenge` and cannot be told from it. Exempting the one-shape case would
put both in one codomain and hand the collision straight back, so the refusal is unconditional — a
vague-but-true `Error422` over a name that misdescribes what it was built from.

The claim is out of a shape's and a multi-representation response's dedupe SCOPE for the same reason: a
name that cannot describe a body must not tell two of them apart either. Scoping by it hoisted one union
twice — once under the claim, once under the status, identical members and two ids — which hands a client
two names for one type, and keeping it in the response's scope would only send two identically-carried
bodies up the ladder together. The claim still scopes what it does name: two producers naming two
different errors that happen to spell one single-representation body get a component each, and an
undeclared body's own representation never moves because someone elsewhere learned to name theirs.

**Responses second**, over the rewritten document: a whole response — description, headers, and by now
a schema `$ref` — that two or more operations state identically is hoisted too. Second so the response
it hoists points at the shared shape instead of carrying its own anonymous copy; a code generator names
an inline schema after whatever encloses it, so the other order hands back exactly the per-response
types the first pass exists to prevent. The passes are independent, never alternatives.

**A response's `summary` and `description` are prose about a body, and stay out of the key too.** Words
do not change what a response IS — the shape pass has said so from the start, since it collapses the
types a client is generated from whatever the responses around them say — and keying the response pass on
them cost exactly what keying on examples cost, one field over. Six of eleven response components in the
application that found this carried content-hash suffixes, and the widest of them, referenced by 145
operations, was suffixed by a contest that was prose-only: two 404s with the same `$ref` and the same
headers, differing in `description` and `example`. One taxonomy endpoint's own wording renamed the 404
type 145 unrelated operations handed a generated client.

A Response Object states ONE description, so the merge cannot carry every wording the way `examples`
carries every illustration, and no choice among them would be true of the others. The rest travel to the
referring node instead: from 3.1 a Reference Object states `summary` and `description` of its own, which
override the ones it points at, so an arm keeps its words exactly where it wrote them and the body is
stated once. Both are FIXED FIELDS of that object (OAS 3.2 §4.23) rather than members standing beside a
`$ref`; what a Reference Object may not carry is a Specification Extension. The 3.0 downlevel drops them
with a `downlevel.ref-siblings` note, because 3.0's Reference Object defines neither and ignores what
stands beside a `$ref`, and prose about a response has no `allOf` to move into.

Which wording the component publishes is the one the most arms state, ties settled by the wording itself:
a function of the set, never of the walk. An arm arriving with words of its own therefore leaves every
other arm's bytes alone; what it can do, at the count where it takes the plurality, is move which arms
carry an override — the same ranked trade `MIN_OCCURRENCES` makes, and it moves no NAME. An arm that
stated nothing carries nothing and takes the shared words, the way one that illustrated nothing takes the
shared examples. That is the one place a plurality moving changes what an arm RESOLVES to, and it is a
place with nothing to fix: an arm with no wording has none of its own to keep, and writing it the words
the plurality happens to state would put someone else's sentence in its mouth. The blast radius is a
response that stated no `description`, which is not a valid one. The empty wording never wins for the
same reason: `description` is a required member.

**A media type's `example` is illustration, and stays out of the key.** Two renderer arms that answer
one status with one schema and one description, differing only in the words they fill in, are one
contract shown twice — and keying on the example made them two components. Both then asked for the same
name, neither could keep it, and an SDK consumer was handed `BadRequest_uvscdete` and
`BadRequest_zpjxajqg`: two structurally identical types for one concept, neither named after anything.
So the response pass strips `content[<media type>].example` before it groups, and republishes every
arm's body on the one shared component — as the media type's `example` where the arms agreed on one,
and as an `examples` MAP where they did not. Both members sit outside the schema and both are defined
in 3.0, 3.1 and 3.2, so they are Media Type Object members beside the `schema` rather than anything the
schema `$ref` has to carry, and nothing downlevels — where the 2020-12 alternative, `examples` INSIDE
the schema, is a sibling of that `$ref`, which costs 3.0 an `allOf` wrapper and is then flattened back to
a single `example`, silently dropping all but one. A single illustration therefore keeps the singular
member: a one-entry map would mint a key nobody asked for, and the bytes an unmerged document already
published are the simplest thing that says it.

**An authored `examples` map is illustration too, and its NAMES survive the merge.** Keeping such a map
in the key looked like the careful answer — those keys were chosen by whoever wrote them and a document
has published them — and it was the wrong one, because it made a response's identity a function of who
had annotated it. Two operations answering 403 identically shared one `Error403`; naming an example on
ONE of them split the group, pushed the other's body back inline and retired the component both were
referencing. A `#[Example(name:)]` on one route rewrote an unrelated route's bytes, and deleted a name a
generated client had been written against. It bit at exactly two arms — three or more left a pair still
agreeing — which is the common case, not the exotic one.

So the map comes off the key and the names travel with it. The shared component publishes the author's
own keys, plus a minted one per illustration nobody named; minting is handed the authored names as
taken, so the two kinds of key can never displace one another and no illustration is lost to a
collision. An author's key is already a function of their declaration, so it disturbs nothing that
`ComponentNames` would not — and it reads far better than a hash.

The one case nothing here can settle is two arms giving one name to two different examples — and what it
must not cost is the component. Publishing either body under that name would put one arm's example behind
the other's label, and dropping one loses an illustration, so the name goes to NEITHER: each is published
under that name plus a hash of its own content, which is the ladder every contested name in this document
climbs, and the build reports `components.example-name-conflict` naming the key that went nowhere. The
alternative — not merging the arms — is what keeping the wording in the key made dangerous: one bucket
holds every wording of a contract, so two pairs that each agreed with themselves now contest across a
wording neither shares, and dropping the group over it would delete a component all four operations were
pointing at. An example key is not a type a client is written against; a component name is.

Two limits, both about not claiming more than is true. An example only illustrates something when a
schema is there to be illustrated, so a media type stating an example and NO shape keeps it in the key
— that example is the only claim the media type makes. And a media type stating BOTH `example` and
`examples` is left whole: OpenAPI already calls that document wrong, and this pass has no business
tidying it by merging half of it away.

Nothing merged this way can become false. The key still holds every media type and every `schema` in
it, so each example goes on sitting beside precisely the schema it was written against — the merge
widens no contract and there is nothing to re-validate. What it does cost is that one operation's
illustration is now offered on every operation sharing the contract, including one that illustrated
nothing. That is the honest reading of responses the document already stated identically, and it is the
price of one type instead of one per arm.

**Example keys are minted by `ComponentNames`, from the example's own content.** A key is
`example_<hash>` — or plainly `example` where it is the only one asking, or `<name>_<hash>` where it is
one of the two examples an author gave one name — opaque on purpose. For a COMPONENT name opacity is a
real cost — a generated client is written against it — but no code generator turns an example key into a
type, so this is the one place the naming invariant can be paid for in readability rather than in
meaning. In exchange the
locality is near-absolute: every key is a function of its own body alone, so an arm arriving or leaving
adds or removes its own key and renames none of the others. (Two arrivals do change a key: going from one
arm to two swaps the singular `example` for a map, and an arm contesting a name moves both examples off
it. Both are the ranked trade `MIN_OCCURRENCES` makes when a second occurrence moves the first from
inline to `$ref` — the shared component's NAME never moves, and the name is what a client is written
against.)

**The name is declared by whoever built the body.** Both passes publish under a name a code generator
turns into a type, so `Error404` — or `Error404_2obip4vj` where a status carries two bodies — is what an
SDK consumer ends up writing in a `catch`, having never seen the codebase that produced it.
`ResponseDraft::claimComponentName()` lets the producer that assembled the body say what kind of error
it is, and the adapter's own tiers do (`NotFound`, `Unauthorized`, `TooManyRequests`), so the default
output already reads. The claim is guarded like every other field — two producers naming one response
settle by precedence — and freezes into `x-docuccino.facts.component`, which is the only channel there
is: the hoist runs over the finished document, so a claim that lived anywhere else would be lost on a
warm fragment-cache hit and a warm build would publish different names from a cold one.

**The declaration is part of what the body IS**, not a label applied afterwards, so it joins the status
in the PUBLICATION key and in the published component's id. Keyed on the bytes alone, two kinds of error
that happen to render identically would collapse into one component under one of the two names, and
which name that is would depend on which routes the application happens to have — a name silently coming
to mean something else, which is the one failure this whole area is built to prevent. Keyed on the
declaration as well they are two components, each named for its own declarer and neither able to move
the other. An undeclared body keys on the status alone, exactly as it always did, so its key, its hash
rung and its component id are unchanged — and so does one whose claim does not describe it, since the
name it asks for is a function of the representations it carries and those are already in the key.

**What repeats decides WHETHER a body is hoisted; what its producer declared decides only what the
component is CALLED.** The two questions are counted separately and this is load-bearing: occurrences
are grouped by status and body with every declaration erased — the grouping a document with no
declarations in it would have had — and each publication in a group that repeats is hoisted under its
own name. Count per publication instead and a route learning to name its error takes its occurrence out
of the group behind an unrelated route's body, drops that body below the threshold and puts it back
inline: one part of an application changing the emitted representation of another, which is the defect
locality forbids and which a mixed document (a first tier recovering a body without naming it, a later
tier naming an identical one) reaches in stock Laravel. So a declaration can ADD a component and never
take one away, and an undeclared body publishes exactly what it would have published in a document
where nobody declared anything at all. The price is that a body declared by one route and undeclared
by another is published twice, once under each name — the same bytes, two types in a generated client,
and the honest reading of two producers that said different things about the same body.

The second pass counts the same way, which takes one more step: by the time it runs the two responses
differ in the schema `$ref` the first pass just wrote into them. So the grouping resolves a reference to
a shape THIS run published back to that shape's group, and two responses spelling one shape under two
names are one body again.

Naming an error after the EXCEPTION would have been the obvious design and is wrong: the relation is
not 1:1 in either direction. Three exception types routinely render one body (their `detail`s are
runtime translations that fold to nothing distinct), so picking one of the three names lets deleting an
unrelated route rename the survivor; and one exception can render two bodies (a literal problem-type
folds, a computed one does not), so it contests its own name. The producer knows what kind of error it
speaks for; the throw site does not.

An application overrides a built-in name the way it overrides anything: register an
`ExceptionToResponse` (unannotated, so `Priorities::DEFAULT` puts it ahead of the framework-errors tier
at `LATE` and the fallback at `LAST`) and the chain takes it, body and name together; or claim over the
built-in's name from an `OperationExtension`, where the guard's ladder decides — the built-in tiers
claim at `integration`, so an application's own integration breaks the tie on `specificity`. An OVERLAY
cannot: overlays are applied before the transformers, so `components.responses.*` does not exist yet
when one runs.

**`#[ErrorComponent]` is the short way in, and it is not a second naming path.** The paragraph above
says the throw site cannot name an error, and that still holds: what the attribute declares is not
"call this exception X" but "the error this class stands for is X", and the adapter turns that into the
same `claimComponentName()` on the same response — `Extensions\ErrorResponsesExtension` is the one
place that reads the CLASS anchor, and `Exceptions\DeclaredErrorComponent` the one place that resolves
it. The ladder is then the ordinary one, with one rule the guard's layers cannot express. It contributes
at `attribute`, above the `integration`/`fallback` the tiers claim their status-derived default at, so
the declaration wins there. It must LOSE to a registered mapper, though, and a mapper contributes at
`integration` — below `attribute` — so precedence alone would invert the answer. The rule that settles
it is stated once, on `DeclaredErrorComponent::mayReplace()`: a declaration replaces the status DEFAULT
and nothing a producer named itself. That is the honest statement of why, too — the mapper saw this
body and the class did not, and one exception class can render several.

Inheritance is deliberate: `ReflectionClass::getAttributes()` does not walk parents, but an application
base (`ApiException`) naming its errors once is the shape worth serving, so the reader walks and the
nearest declaration wins. That makes the answer depend on a file the throwing route never mentions, so
every signalled throw records `DeclarationFiles::of()` for its exception hierarchy on the route's
fragment dependencies — otherwise an attribute added to a base would leave warm builds publishing the
old name. Two exceptions declaring DIFFERENT names for one operation's one status is the case layers
cannot settle either: the response carries one name, and awarding it to whichever throw the engine
reported first would make a published type name a function of encounter order. Neither takes it, the
status default stands, and `attribute.error-component-contested` names both classes — unless a producer
had already named the body, in which case neither declaration was ever in the running and there is
nothing for the reader to reconcile, so nothing is reported.

**The action is not a third anchor, and `#[Response(errorComponent:)]` is what stands in its place.** `TARGET_METHOD`
lets PHP accept `#[ErrorComponent]` on a controller method and `AttributeCollector` materialises it, so an
author naming the error they want renamed on the action answering it got their old names back and nothing
said why — the reported case, and the placement is now `attribute.error-component-unread`, raised for the
ACTION's own declaration only. `AttributeCollector` walks the controller's parents, so reporting off the
route's whole set says one mistake once per route of every child — six routes for one attribute on one
base, measured, and linear in the API from there. A route-scoped pass has nowhere to collapse that: a
per-build "already said" set makes what the document reports a function of which routes came from the
fragment cache, and a warm build reporting less than a cold one is a silent degradation. So the placement
that is one report per declaration stays, and the one that would be N does not; nothing about the emitted
names differs either way. It is raised from `Finalize` rather than from the error chain, so
`error_responses => 'none'` — the value an application with no config key resolves to — does not take it
with it. Making it READ
there is the wrong fix, for a reason the attribute's own shape gives: it carries a name and nothing else,
and its two anchors are per-BODY by construction — an exception class raises one error, a render method
answers with one body. An action is neither. It answers at every error status its validation, its
authorisation and its throws produce, ordinarily four or five, and one name over all of them sends every one
of them up the ladder to a content hash. That is the same trade `#[IgnoreResponse]` refuses: a visible
no-op swapped for an invisible wrong answer, here a wrong PUBLISHED NAME, which is the one thing this
section exists to prevent. Reaching it would also mean a third producer consulting the attribute —
`AttributeResponsesExtension` beside `ErrorResponsesExtension` and `HandlerResponseBuilder` — with a rule
for choosing among an operation's statuses that the attribute gives it no way to write.

`#[Response]` gives it one. That attribute already states a status and a media type, so `errorComponent:` on it
is unambiguous, and it is the only anchor that reaches a body **nothing threw**: `#[ErrorComponent]` is read
off the exception classes a route signals and the render methods on their path, and a `4xx` an operation
declares for itself is on neither. It claims through the same `claimComponentName()` at `attribute`, so
there is still one naming path. It goes to the guard exactly as every other argument of the attribute does,
so two of them at one status naming different components settle the way `description:` does: first writer
over a most-specific-first `AttributeSet`, which is a child action overriding its base controller's default
rather than the two cancelling out, and the name that lost travels on the provenance trail like every other
shadowed value. A bespoke "use neither and warn" here bought nothing the guard does not, and cost the
inherited case its documented answer. Against the class anchor the `#[Response]` claim carries
`specificity: 1`, so the documented precedence — the declaration nearest the operation wins — is in the
tuple `outranks()` compares rather than in `Responses` happening to run before `Errors`; `mayReplace()`
still holds `ErrorResponsesExtension` off a status something already named, which is what keeps it from
reporting a contest nobody can act on. It
does NOT force componentization: what repeats decides that, here as everywhere, so a name on a body one
operation states waits inline for a second stater exactly as `#[ErrorComponent]` does. Hoisting on a name
would put one entry per one-off error in the bucket, which this section already rejected on its own terms.

**The same attribute on a RENDER METHOD is the other anchor, and it is the same naming path again.**
The class anchor says one thing per class, which is not enough for the shape the base above invites: a
renderer dispatching on `ApiException` plus a marker interface turns one family into several different
bodies, all inheriting one declared name, and the contest retires it into a hash. Only a per-arm anchor
separates them. It cannot be read where the class one is, because the arm is only visible on the CALL
PATH, so the engine reads it — `Analysis\ComponentDeclarations` off a `ReflectionMethod` — and carries
it out on `ReturnSite::$component`, beside the recovered type rather than inside it: it names the body
rather than describing it, and a serialised member of the analysis is what survives the fragment cache.
`InferredHandler\HandlerResponseBuilder` then claims it as the producer's own name, so the ladder needs
no new rung: `mayReplace()` already says the class anchor takes the status default and nothing a
producer named itself, which makes the ordering method, then class, then default, with a mapper ordered
ahead of the tier still winning outright.

The rule for several declaring methods on one path is that the OUTERMOST wins — the method nearest the
answer, not the one nearest the body. The method that constructs the payload is almost always a shared
`problem()` helper, so attributing to it would give every arm one name, which is the collapse this
anchor exists to prevent; making the outermost win instead means a shared helper can only ever speak for
arms that declared nothing themselves. Stated once on `RefinedResponse::withComponent()`, and stamped
per callee in `ResponseShapeRefiner::declared()` so the refiner's memo stays a function of the symbol
alone. Cache soundness follows the same rule as the class anchor: the declaring method's own file is
recorded, because an unoverridden method belongs to the parent that declared it and a house helper on a
base is the ordinary case. A path that reaches no declaration — vendor code, dynamic dispatch, a spent
descent budget — carries none, which is exactly the behaviour before the anchor existed; a declaration
shadowed by an outer one is deliberate rather than lost, so neither raises a diagnostic of its own.

Which arm a narrowed analysis picks had to be fixed to make any of it true. `Analysis\NarrowingGuard`
reads a return site's guard as ALTERNATIVES OF REQUIRED classes — a union alternates, an intersection
requires — because `$e instanceof ApiException && $e instanceof HasRetryWindow` admits a type only if it
is both, and a flat list of names cannot tell that from `ApiException|HasRetryWindow`, which admits
either. Read as "either", every arm naming the shared base answers for every member of the family: the
throttle gets the rejection arm's body under the rejection arm's name.

Contests route through the machinery that already exists: two DIFFERENT bodies claiming one name climb
`ComponentNames`' ladder and are reported as `components.name-collision`.

Illegal names are refused at the WRITE. `ComponentNames::isLegal()` owns the character class — one
copy, in the class that also `sanitize()`s by force — and `claimComponentName()` reads a name that
fails it as no declaration at all, so nothing a `$ref` could not point at ever reaches
`x-docuccino.facts.component`, whether or not the hoist that would have refused it later is switched
on. The body keeps whatever else named it — the status default the built-in tiers claim, or
`Error<status>` where the status has no phrase of its own: a degraded name, never an invalid document,
and never a question of which knobs are set.

Refusing at the write costs the refusal its voice, though, because a draft has nowhere to say anything,
and a silent refusal is the wrong answer for the one declarer who is not an extension author. So the
refusal is stated twice more, by whoever CAN state it, and each of the three is the only one that could:

- **The adapter, for `#[ErrorComponent]`.** An application author who typos one has no other way to
  learn the attribute did nothing, so `ErrorResponsesExtension` asks `ComponentNames::isLegal()` — the
  same character class the write refuses on — where it READS the declaration and warns
  `attribute.error-component-invalid`, naming the class, the
  file the attribute is on and the name it read. A refused name is not a declaration, so it also does not
  contest a legal one for the same status — a typo on one exception cannot strip a correctly named
  response back to its default. The method anchor is refused by the tier that read it, under the same
  code and the same wording, naming the declaring method instead of the class. That tier is an
  integration, and an integration may not import `ComponentNames`, so the predicate is asked through
  `ComponentRegistry::isLegalName()` — a delegation on the object it is already handed for exactly this,
  and the extension author's view of a character class that still lives in one place.
- **The hoist, for a document that already states the fact.** `components.name-invalid` covers the one
  source a draft cannot police, which is now only an overlay, since overlays are applied before the
  transformers run. It is raised only for bodies that were actually published, since it says the body
  "was named after its status instead" and that is untrue of a body nothing hoisted.
- **A registered mapper, for itself.** `ExceptionToResponse::toResponse()` is handed the
  `ComponentRegistry`, so an extension author who wants to be told has the channel already; the adapter
  does not police a producer's string, and core has deliberately dropped it by the time either could.

Both diagnostics quote the name with control characters escaped, because a diagnostic is read on a
terminal and nothing validated the string it came from.

**The occurrence threshold is deliberately not local.** Adding a second identical occurrence promotes
the FIRST from inline to `$ref`, so an operation nobody edited emits different bytes. What it does not
do is change what anything MEANS: same body, same generated type, same contract. That is the whole
distinction, and it is why names here are derived from content and declaration alone while the
inline/`$ref` boundary
is allowed to move — the defect worth preventing is a NAME that quietly comes to mean a different
shape, which a client keeps compiling against and silently gets wrong. Hoisting singletons would make
the boundary local at the cost of a `components` bucket holding one entry per one-off error body:
more indirection, more names to collide, a worse document for reader and generator both.

## 3. Canonicalization (normative in the spec)

1. Fixed member order per object type (published as `x-canonicalOrder` in the meta-schema);
   map-like objects (`paths`, `components.*`, `responses`) sort keys by Unicode code point.
2. `paths` sorted by template; operations in fixed method order
   `get, put, post, delete, options, head, patch, trace, query`.
3. `parameters` sorted by (`in` rank: path, query, header, cookie; then name).
4. `tags`/`security`/`enum`: declaration order preserved, deduplicated (first wins).
5. Serialization: UTF-8, LF, 2-space indent, trailing newline, minimal escaping,
   shortest-round-trip floats.

## 4. Provenance

`x-docuccino.provenance` = list of contribution records:

```json
{
  "producer": "integration:query-builder",
  "layer": "integration",
  "fields": ["schema", "description"],
  "source": { "file": "modules/Form/Queries/FormIndexQuery.php", "line": 28, "symbol": "…::query" },
  "confidence": 0.9,
  "overrode": [ { "field": "description", "value": "…", "producer": "inference" } ]
}
```

Producers: `inference`, `attribute`, `docblock`, `integration:<name>`, `overlay`,
`config`, `fallback`. `source.file` is project-root-relative. Emit levels:

**Integration producer-name convention (frozen at v1).** `<name>` is the integration's
directory name (`php/laravel/src/Integrations/<Dir>/`) kebab-cased — the one canonical
string, used identically whether the contribution is built via `Contribution::integration('<name>')`
or an `ExceptionToResponse::producer()` returning `'integration:<name>'`. The full set:

| Directory            | Producer `<name>`      |
|----------------------|------------------------|
| `SpatieData`         | `spatie-data`          |
| `ApiResources`       | `api-resources`        |
| `TimacdonaldJsonApi` | `timacdonald-json-api` |
| `JsonApiPaginate`    | `json-api-paginate`    |
| `Eloquent`           | `eloquent`             |
| `QueryBuilder`       | `query-builder`        |
| `RateLimit`          | `rate-limit`           |
| `Sanctum`            | `sanctum`              |
| `Passport`           | `passport`             |
| `Permission`         | `permission`           |
| `ProblemDetails`     | `problem-details`      |
| `FrameworkErrors`    | `framework-errors`     |
| `InferredHandler`    | `inferred-handler`     |
| `Validation`         | `validation`           |
| `FormRequest`        | `form-request`         |
| `LaravelActions`     | `laravel-actions`      |

`Validation` is the always-on shared rule chain (it owns the rule vocabulary); `FormRequest`
is the FormRequest request-body recovery extension — a distinct producer, so a body recovered
from a FormRequest's rules is attributed to `integration:form-request`, not `integration:validation`.

**Custom rule objects (`#[RuleSchema]`).** A `new X(...)` in a rule position folds to a `ConstValue`
INSTANCE kind (class FQCN + folded ctor args — a small sibling of the factory descriptor, since PHPStan
collapses the expression to a bare object and the class is the only documentable fact). The adapter reads
the class's `#[RuleSchema]` (`Integrations/Validation/CustomRuleReader`) and maps its fields onto
SYNTHETIC NAMED RULES rather than writing schema keywords: `type` → a type rule (unrecognised values pass
through as a rule name, so a typo diagnoses), `enum` → `in:`, `pattern` → `regex:`, `min`/`max` → the size
rules. `format`/`description`/`example` have no Laravel rule, so the vocabulary gained one transformer
(`AnnotationRuleTransformer`, effect-ordered last) — keeping ONE schema-writing path through the chain.
The attribute is the contract, not the `ValidationRule` interface (a vendor rule documents the moment its
author adds it) and ctor args are ignored. Every recovery path shares the fold or the reader (FormRequest
/laravel-actions `rules()`, inline `validate()`/`Validator::make`, spatie `#[Rule(new X)]`); each records
the rule class file as a fragment dependency, whether or not it carried the attribute, so adding the
attribute invalidates. No attribute → the unchanged `validation.rule-unrecoverable` path; closures stay
opaque by nature.

`--provenance=none|winners|full`, default `winners` for committed artifacts.
Mock hints: `x-docuccino.mock` = `{faker, seedGroup}` on schema properties, written by `#[Mock]` through
core's `Extensions\Schema\MockHints` — the one reader, called by every class-hoisting mapper (core's DTO
mapper, spatie Data, API Resource, Eloquent) and by `RecoveredRequest` for a request whose fields are named
by rules rather than properties. On a property the attribute applies to it and follows a `#[MapName]` to the
key it publishes under; on a class it names the member, which is the only form a magic column, a `toArray`
key or a validated field can reach. The property's own attribute beats a class-level one naming it. Nothing
is evaluated and the expression is opaque — only an empty one is refused (`attribute.mock-invalid`), and a
name the schema does not publish is dropped (`attribute.mock-unknown-property`). The OAS emitters project
the `faker` half onto `export.mock_faker_key` (conventionally `x-faker`) or drop it; that key lives under
`export` so it stays out of `configHash`.
All other `x-*` members pass through untouched.

`source.line` is provenance, not identity, so it never affects `contentHash` or any `id`.
Committed UIR artifacts should therefore emit with `--provenance=none` (or `winners` and
accept that source line numbers churn as code moves); the churn is cosmetic and cannot alter
identities or the content hash (architecture N5 — documented, not a hashing change).

## 5. Pipeline

```
RouteResolvers → RouteCollection
  → per route (isolated try/catch, cacheable):
      RouteContextBuilder → OperationPipeline (phased) → OperationFragment
  → Assembler (merge, hoist/dedupe components, apply overlays, DocumentTransformers)
  → Canonicalizer → Validator (bundled UIR schema) → Emitters
```

Phases: `Parameters | Request | Responses | Errors | Security | Overrides | Finalize`.
Per-route failure → skeleton operation (config `on_route_error: skeleton|omit`) + error
diagnostic. Diagnostics: `{severity, code, message, source, routeSignature, help}`,
deterministic ordering, `--fail-on=none|error|warning|info|hint` (a severity floor).

Core value objects: `RouteContext{route, action reflection, inspection (lazy TypeEngine
handle), attributes, docblocks, document}`; `OperationDraft` whose every write goes
through the PatchGuard (below) recording `(field, value, Provenance)`.

## 6. Extension API (contracts in `Docuccino\Core\Extensions\Contracts`)

```php
interface RouteResolver { /** @return iterable<RouteDescriptor> */ public function resolve(DocumentConfig $doc): iterable; }

interface OperationExtension {
    public function phase(): OperationPhase;
    public function handle(OperationDraft $op, RouteContext $ctx): void;
}

interface TypeToSchema {
    public function supports(DType $type): bool;
    public function toSchema(DType $type, SchemaContext $ctx): ?SchemaResult; // null = defer to next in chain
}

// What RouteContext::converter() hands an extension: the top-level entry into that chain, and the
// SchemaContext mappers themselves receive (so it passes straight to ValidationRulesToSchema::convert).
// The implementation behind it (Extensions\Schema\SchemaConverter) is @internal — a method declared only
// there is not surface. toSchema() starts a fresh confidence run and depth count; SchemaContext::convert()
// recurses inside one.
interface TypeSchemaConverter extends SchemaContext {
    public function toSchema(DType $type): SchemaResult;
}

interface ValidationRulesToSchema { /* rules aren't types; per-rule transformer sub-extensions incl. cross-field */ }

interface ExceptionToResponse {
    public function supports(DType $exceptionType, RouteContext $ctx): bool;
    public function toResponse(DType $exceptionType, RouteContext $ctx, ComponentRegistry $components): ResponseDraft;
}
// The ResponseDraft an ExceptionToResponse returns carries INTENDED-PUBLIC write methods:
// setDescription / setRef / set / content(mediaType) and setExample(mediaType, example) — the last
// attaches an OAS media-type `example` beside the schema, FIRST-WRITER-WINS so the result is
// order-stable regardless of extension evaluation order. It is deliberately not `@internal`: the
// built-in inferred-handler tier attaches its literal-carrying error examples through exactly this
// method, and the "no privileged back door" promise means a third-party ExceptionToResponse must be
// able to do the same. supersedeMediaRange(mediaType, by) joins them: a producer that NAMES the media
// type retires the any-media-type range a vaguer producer documented the same body under, for the same
// reason — a built-in does it from the attribute layer, so a third party must be able to.
// (`guard()`, `absorb()` and `isSupersededBy()` are all `@internal`: they move drafts about or arbitrate
// whether a whole node survives, which is the pipeline's job rather than an extension's.)
// Public READ side: isBodyless() — HTTP forbids content on 1xx/204/205/304 (RFC 9110), so content()
// under one of those hands back a DETACHED SchemaDraft and the response freezes body-less whichever
// producer aimed one at it (inference folding `response()->json(null, 204)`, an attribute, an
// integration). Enforced once at the write so no producer can bypass it, and asked BEFORE building a
// body — InferredResponsesExtension skips payload conversion, which is what keeps a dropped body's
// schema out of components. An overlay (layer 45, applied post-freeze) can still write content there.
// Error-response resolution chain (first supports() wins; Phase 4):
//   1. InferredHandlerExceptionToResponse — analyses the APP'S REAL exception handling:
//      render callbacks discovered by reflecting the BOOTED app's handler (catches
//      package/provider-registered ones AST parsing would miss); ReflectionFunction →
//      file/line → NodeScopeResolver over the closure body (JsonResponse<TPayload> stub
//      preserves response()->json shapes; statuses constant-folded). Catch-all
//      render(Throwable) bodies analysed once per thrown exception type with the param
//      NARROWED to that type — PHPStan's instanceof narrowing resolves the branches
//      (the common Problem-Details renderer pattern: a catch-all render() with
//      per-type instanceof branches). Exception-class render() /
//      Responsable::toResponse() analysed the same way. Too-dynamic body → defer (null)
//      + diagnostic at the exact expression — including a shape that came back EMPTY,
//      since an error response with no `content` says the error returns nothing, and
//      answering with one would end the chain before a tier that can state a body is
//      asked. It answers anyway when it holds something they do not: a status it folded
//      itself, or one HTTP forbids a body on. Handler files join dependencyFiles.
//   2. FrameworkDefaultsExceptionToResponse — Laravel's stock JSON shapes
//      (422 {message,errors}, 401/403/404 {message}), maintained per Laravel version.
//   3. Presets (problem-details) + user extensions; attributes/config override anything.
```

**No `ExampleProvider`.** Examples were sketched as a contract of their own and never needed one:
`#[Example]` and `@example` are read by the attribute/docblock extensions like any other override, and
an extension that wants to attach one writes it through `ResponseDraft::setExample()` or the schema
draft. Nothing implements or names an `ExampleProvider` — it is not part of the v1 surface.

Two bags, not one. `setExample()` is a producer ILLUSTRATING what it worked out (first-writer-wins);
`declareExamples()` on `ResponseDraft` / `ParameterDraft` / `OperationDraft` is an author STATING what
the payload looks like, which is why a declaration displaces an illustration at freeze. Declared maps
are keyed by the author's own names and kept name-sorted, and OAS makes `example` and `examples`
mutually exclusive, so a non-empty map wins over either singular. `Core\Extensions\BuiltIn\AttributeExamplesExtension`
is the one producer of declarations: it runs in Finalize (every response, parameter and request body a
declaration could name already exists), confines an `#[Example(file:)]` path through the same
`ConfinedPath` `#[Description(file: …)]` uses, and registers the resolved path as a route dependency
whether or not the read worked — so creating a file that wasn't there rebuilds the route.

**Recorded examples.** A test suite already exercises real endpoints with real data, and the
contract-testing observer seam (`Laravel\Testing\Contracts\ContractObserver`) already holds the
request, the response and the matched operation. `Laravel\Testing\ExampleRecorder` writes each
observed exchange to a committed file per operation, and `Laravel\Extensions\RecordedExamplesExtension`
reads it back at build time. Reading a committed file is ALL the build does — no test runs, no route is
dispatched, no database is opened — so "Docuccino never executes your application code" is unchanged.
The file format, the store, the redaction and the whole-document audit are core
(`Core\Examples\*`): the input is a data file rather than Laravel code, exactly as for the content
subsystem. Five decisions carry the feature:

- **Recording is OPT-IN per assertion, and `recordAs:` is the asking.** Checking an exchange and
  publishing it as documentation have opposite ideal coverage — check as many exchanges as the suite can
  produce, publish one deliberately chosen response per operation — so tying them together hands the
  documentation decision to whichever test happened to answer with the best-ranking body. An assertion
  that names no scenario therefore checks and records nothing. An UNNAMED body is a read-only shape:
  files holding one are still read and published (an upgrade takes no example out of a document) and
  `examples.recording-unnamed` says so, since no run will refresh one.
- **Curation is the author's, and it happens in the diff.** Among the responses a suite records under one
  name the recorder keeps one, and the human reviews the committed file. The narrowing is a function of
  the bodies and never of the run: only an exchange whose RESPONSE half was checked and passed is
  recorded (so a body cannot illustrate a schema it contradicts), and the winner is the most POPULATED
  body, then the shorter, then the smaller by the bytes it would PUBLISH — `RecordedExample::outranks()`.
  That last term is the published encoding rather than `Json::stable()`, which sorts an object's members
  and so ties two bodies holding the same members in a different order while the files they write differ;
  a tie there would hand the choice back to arrival. A first-come rule would let reordering a test file
  change a published example, which is the same defect `ComponentNames` exists to prevent, one node
  down.
- **A committed body is rewritten only when its SHAPE changes** (`ResponseShape`, keyed into
  `ExampleRecording::with()`). A `created_at`, a UUID or an autoincrement key moves the body on every
  run and the structure on none of them, so re-recording an unchanged suite is a no-op on the file and
  therefore on the artifact. That, plus the file being committed, is the whole determinism answer:
  nothing is normalised away and nothing is guessed at.
- **Credentials are replaced on the way out, and re-checked on the way in.**
  `Core\Examples\ExampleRedaction` reuses `Lint\SensitiveFieldLintOptions` and `Lint\CredentialShapes` rather than
  re-deriving them — one table, so an application that taught the lint its own names has taught the
  recorder too. Only STRINGS are replaced, so a `token_count` keeps its type and the example goes on
  satisfying its schema; a sensitive member name taints everything beneath it. A number is REPORTED
  rather than replaced — `{"cvv": 123}` is a secret and `[redacted]` where the schema says integer is
  not a fix — and reporting is enough, because publication is refused on any finding at all. Nothing but
  the name can speak for a number, so the name has to BE a heuristic rather than contain one: `token`
  counts, `token_count` does not. The build refuses to publish a committed body that still matches (a
  hand edit, or a heuristic added since) and `examples.recording-unsafe` names the pointer, never the
  value.
- **The example is the MEDIA TYPE's, not the schema's.** `SharedErrorResponses` strips
  `content[<media type>].example` before it groups, so a recording written into the schema would key the
  hoist and could drop an unrelated route's 404 out of its shared component and back inline.
  `setExample()` is first-writer-wins and carries no provenance record — that is the cost, and it is the
  same one every media-type example already pays — so the `integration` rung is settled by the DRAFT
  rather than by the extension: `setExample()`/`illustrateExamples()` fill the illustration bags,
  `declareExamples()` fills the declaration bags, and `ResponseDraft::freeze()` publishes a declaration
  over an illustration whichever ran first, which is why nothing here depends on the recorded extension
  running before or after `AttributeExamplesExtension`.
- **A recording's NAME decides which member it publishes into.** A test names the scenario it set up
  (`assertValidResponse(recordAs: 'empty-cart')`); named recordings publish together as the media type's
  `examples` map, a legacy unnamed body as the singular `example`, and naming one scenario for a
  (status, media type) drops the unnamed candidate for it — OAS carries `example` or `examples` and never
  both, so a file keeping one would keep something the document cannot publish
  (`ExampleRecording::normalised()`), and that is also the upgrade path off an unnamed file. A name is never derived from the test's name: renaming a test would
  then rename a published example. `ResponseDraft::freeze()` resolves the rest — a declared map takes
  named illustrations into it (a name passed at a call site is a name somebody CHOSE, unlike a key of our
  own) and wins every key it also spells, while a declared singular publishes alone, since filing a
  recording beside it would mean inventing a name for the AUTHOR'S example. The exception is the hoist:
  `SharedErrorResponses` leaves a media type already carrying an `examples` map whole, so on a status it
  groups (`SharedErrorResponses::shares()` — the same grammar, read once) the names are not published at
  all, the best body goes out as the singular `example`, and `examples.recording-name-unpublished` says
  so — at INFO, because with naming now the only way to record, every recorded error body reaches it and
  the one remaining remedy is a document-wide setting.

Two supporting notes. `RouteContext::$operationId` carries the already-minted id into the draft phase,
because a recording is filed under identity (so it survives a route rename) and deriving the id a second
time is how two answers to "which operation is this" start disagreeing; the recording file joins
`RouteContext::dependencies()` whether or not it exists, so creating one invalidates exactly as editing
one does. And every recording DIAGNOSTIC comes from `Core\Examples\RecordedExampleAudit`, a
`DocumentTransformer`: only a whole-document pass can tell a recording nobody claimed from one that is
simply another operation's, and a transformer runs on every build, so warm reports what cold reports
without any of it having to ride a cached fragment.

Recording and COVERAGE both survive a parallel runner, and they survive it differently, because they
are different questions. A recording is per-operation, and `outranks()` is a total order on the bodies
themselves, so the best of a set does not depend on which worker met which member of it — workers only
have to take turns.
`Core\Examples\SharedRecordingLedger` gives them one: an exclusive `flock` on a lock file of its own
(the recording is replaced by a rename, so a lock held ON it is a lock on a discarded inode), around a
read-compare-write against a scratch SESSION holding the file as it stood before the run plus the run's
best per key. The session is what makes the answer equal the single-process one: the shape rule compares
against what was COMMITTED, which a worker cannot recover from a file another worker has already
rewritten. Both live under the system temp directory keyed by the recordings directory and by the run
(`ParallelRun::runKey()`, the worker's parent process — the one thing every worker of a run agrees on
and no later run repeats), so a second run starts from the file as it stands and neither ever appears in
the tree an author commits. Where the platform cannot name the run, or the lock cannot be taken,
recording refuses and writes nothing: a half-merged recording is worse than none.

Coverage needs none of that, and gets none of it. It is a whole-suite AGGREGATE, so no worker can answer
it at all — not because the data is split but because none of them knows when the others have finished,
and a shard does not even share a machine with the ones it would have to wait for. So it is not asked
inside the run. `CoverageLog` has each process append what it reached to a file of its own, with no lock,
because a union has nothing to reconcile; `CoverageMerge` unions N directories of those afterwards, and
`docuccino:coverage` reports and gates.

What is counted is a documented RESPONSE, not an operation. A `422` is a promise of its own — the type a
consumer writes a `catch` against — so a suite asserting only happy paths reaches every operation and
proves none of them, and a number counting operations calls that full coverage: the too-generous reading
a gate exists to prevent. So a log entry is `op:…@422`, resolved to a documented response key through
`ContractOperation::responseKeyFor()` — the same grammar the checker picks a response with, so a coverage
row and a failure message can never disagree about where a status belonged. A `default`, and a response
documented with no content, are each one promise; an operation documenting no response at all carries one
row asking only whether it was reached.

A documented WEBHOOK is counted beside them, which is the same argument pointing outward: a document
whose outbound half nothing asserts would otherwise read as fully covered. It carries ONE row, keyed
`delivery` and lit by a passing delivery check, rather than one row per documented response — a
webhook's responses are what the RECEIVER answers, and nothing in the sending application's suite can
exercise one, so counting them would publish a floor nobody could ever meet. So the numbers the report
prints are operations exercised beside responses and deliveries exercised, and only the second meets
`--min`. The second entry form is the bare `op:…`: an
operation reached with no response proved, which is what a request-only assertion, an assertion that
FAILED, and a log an older release wrote, can honestly claim — so a stale log reads LOWER than the truth
and never higher. A pass carrying a NOTE — a `text/csv` body, a media type the contract gives no schema
— counts as exercised: the gap is in the document, and no assertion could close it, so refusing the
credit would leave the endpoint permanently uncoverable.

`responseKeys()` is the same grammar read the other way round, and lists exactly what `responseKeyFor()`
can select. A documented key outside it — `4xx` where OAS spells `4XX`, a word like `ok` — is valid JSON,
passes the meta-schema, and can never be resolved to, so it is in neither count: a denominator carrying
one could never be filled and a 100% floor would be out of reach forever. `CoverageReport` names each one
under the numbers rather than dropping it silently. And only a three-digit status has a range at all —
reading the first digit of 1000 as a family would answer `1XX`, which no log entry can carry.

Three properties carry it:

- **A name is unique per writing process, never per worker.** It carries the runner's worker token where
  there is one — a directory of `w3.…` reads better than one of hashes — but the pid and four random
  bytes BESIDE it, because `--shard=1/4` and `--shard=2/4` on one machine both have a worker `1` and one
  overwriting the other is exactly the false gap the feature exists to stop. Nothing is detected: a
  runner that sets no token is the ordinary single-process case, and a runner nobody has heard of
  participates by writing a file like everybody else. The price is that runs accumulate rather than
  replace, which `docuccino:coverage --reset` is for — and a forgotten reset reads exactly like one run,
  only more generous, which for a gate is the worse direction. There is no sound structural fix (stamping
  the parent pid would refuse two shard invocations sharing a machine, which the design sanctions), so
  the report says how far apart the logs it merged were written where that is longer than a run.
- **The merged answer is a function of the run and of nothing else.** Sets have no first writer, so the
  same entries come back whatever the worker count, whichever file each was seen in, and whatever order
  the directories were named — the parallel report equals the single-process one exactly.
- **An incomplete merge is never averaged.** A directory that cannot be read — absent, or there and
  refusing to open, at the top of a named path or nested anywhere under one — a directory holding no log,
  and a file that does not read back as entries each take the whole merge out of gating and are named. A gate
  that quietly measured three of four shards is worse than no gate. Two things make that guarantee hold
  rather than nearly hold. The walk propagates a subdirectory it could not open BY NAME instead of
  merging what it could reach, because one `--path` over a downloaded artifact tree is the recommended
  shape and a shard nobody could read is not a shard that ran clean. And a log line is held to the entry
  SHAPE, not merely to being printable: a worker killed part way through a write leaves an ASCII prefix
  of one, which would otherwise merge as an entry, match no operation, and undercount in silence. What is
  left is a directory nobody NAMED, which nothing in the merge can see — so the documented CI recipe
  names each shard's directory as a `--path` of its own, and the count is a gate over exactly the shards
  it was told to expect.

**Implicit responses (pre-dogfood wave).** `ThrowAnalyzer` only sees exceptions the action BODY raises;
the framework also produces error responses from MIDDLEWARE and binding-time machinery the body never
throws. `ImplicitResponsesExtension` (adapter, Errors phase, `Priorities::LATE`) synthesizes those from
statically-visible signals and runs each through the SAME resolved exception→response chain, so the
body matches the document's error style:

| Status | Signal | Synthesized exception |
|---|---|---|
| 401 | auth middleware matches `security.auto_detect_middleware`, and the route is not `#[Unauthenticated]` | `AuthenticationException` |
| 422 | a request extension recovered a validated body (its integration producer owns `requestBody`) | `ValidationException` |

> **Deliberate gap:** the 422 signal is body-verb only. A validated GET/HEAD applies its rules as query
> parameters (never a `requestBody`), so a validated read endpoint is NOT documented with an implicit
> 422 even though it can 422 at runtime. Left as-is to avoid a 422 on every validated read (review B6).

| 404 | the route has ≥1 model-bound path parameter — ONE 404 per operation, not per param | `ModelNotFoundException` |
| 403 | `can:` / `signed` / `verified` middleware, or a FormRequest `authorize()` the engine proves is not a literal `return true` | `AuthorizationException` |

Precedence & dedup: it writes at the integration layer and runs LATE, so a status the action ALSO
throws explicitly (already applied by `ErrorResponsesExtension`) owns its status-keyed response and the
synthesis is shadowed by PatchGuard — never a double response. Overridable by docblock/attribute/overlay;
each status honours `#[IgnoreResponse]`; skipped under `error_responses => 'none'`. Provenance names the
signal (producer `integration:implicit-response`, source symbol `implicit:<signal>`). **Placement:** the
input is Laravel middleware/bindings/recovered-request, so the synthesis is adapter-side; the body still
comes from the framework-neutral chain. **Deliberate non-goals:** CSRF 419, maintenance 503, and
arbitrary custom-middleware throw-analysis are not synthesized (a middleware name carries no reliable
status contract); 429 stays the rate-limit integration's.

```php

// NOT in Extensions\Contracts: this one lives in `Docuccino\Core\Diff\Policy`, beside the differ and
// the Changeset/PolicyVerdict types it reads. A diff policy is asked at diff time, never during a
// build, so it belongs with the machinery it judges rather than with the build-time contracts.
interface VersioningPolicy { // diff enforcement: changeset severity vs info.version delta
    public function name(): string; // the stable policy id, e.g. `semver`
    public function evaluate(Changeset $changes, string $oldVersion, string $newVersion): PolicyVerdict;
    // Built-ins: SemverPolicy (breaking → major bump required), DateVersionPolicy
    // (breaking → new date version), NoVersioningPolicy (breaking → fail/warn outright).
    // Per-document config; wired into docuccino:diff --enforce (nonzero exit for CI).
    // Longitudinal governance (deprecation windows, history, cross-repo) is out of scope
    // for the open-source packages.
}

interface DocumentTransformer { public function transform(UirDocumentDraft $doc, DocumentContext $ctx): void; }
interface Viewer  { // selected by `viewer.driver`; built-ins: scalar (default), redoc
    public function name(): string;                     // the stable driver id, e.g. `scalar`
    public function render(ViewerContext $ctx): mixed;  // HTML, or the adapter's own response type
}
interface ViewerAssets { public function assets(): array; } // asset name => file; IS the allow-list
```

**Emitters are not extensible yet.** `Docuccino\Core\Emit\Emitter` names a format and nothing else, and
its `emit()` half lives on the `@internal` `ReportingEmitter` because `EmitOptions` has moved with every
format added. More to the point, `Emit\Formats` is a closed table — the CLI, a document's export targets
and the viewer all resolve formats through it, and no registrar accepts an emitter from outside core. So
`Emitter` is `@internal` too: implementing it registers nothing. It gets promoted to the public surface
when a registration path exists and the `emit()` signature settles.

- Ordering: `#[ExtensionOrder(priority: 0, before: [...], after: [...])]` — topo sort,
  tie-break priority desc then FQCN. Built-ins publish `Priorities::*` constants.
- `TypeToSchema` / `ExceptionToResponse` are chains: first `supports()` wins; returning
  null defers — a user extension `before: [SpatieDataToSchema::class]` intercepts
  specific classes only.
- **Late-bound registration**: `ExtensionRegistry` accumulates class-strings/closures;
  nothing resolves until a build starts (post-boot by definition). `Docuccino::extend()`
  works from any provider register()/boot(); config `extensions` merges at resolve time.
  No API returns the extension list before resolve — early snapshot is impossible.
- Extensions are container-resolved (constructor DI). Core is framework-agnostic (no
  illuminate/symfony-framework deps); its runtime dependencies are `psr/container`,
  `opis/json-schema`, `symfony/yaml`, `nikic/php-parser`, `phpstan/phpdoc-parser` (the standalone
  parsing library behind `Core\TypeGrammar`, NOT the analyser) and — since core now reads Docuccino
  attributes off reflected classes/enums (`SchemaIdentity`, `EnumReflection`, the attribute
  overrides extension) — the dependency-free, lockstep-versioned `docuccino/attributes`. That tiny
  attribute package is the one runtime dep core added to absorb the attribute-aware placement moves;
  it is deliberately NOT the framework or the analysis engine.
- **Dependency direction (Tom, 2026-08-09 — the production-safety wave).** The engine and the adapter
  are SIBLINGS over core, not a chain:

  ```
                    docuccino/attributes
                             ↑
                       docuccino/core ────────────┐
                        ↑            ↑            │
        docuccino/laravel      docuccino/inference-phpstan
       (production: + illuminate)   (dev-only: + phpstan, larastan)
  ```

  `docuccino/laravel` used to REQUIRE `docuccino/inference-phpstan`, which hard-requires
  phpstan/phpstan — so a production `composer install` pulled a static analyser into `vendor/`.
  The engine is now a `suggest` (and a require-dev for the adapter's own tests); the adapter reaches
  it through core's `Inference\TypeEngineBuilder` seam, probing `Laravel\Engine\EnginePackage::BUILDER`
  by string. Arch tests freeze both directions: the adapter imports nothing from
  `Docuccino\Inference\PhpStan` (and no `PHPStan\`/`Larastan\` namespace at all), and the engine
  imports nothing from `Docuccino\Laravel` or `Illuminate`. Absent engine → `NullTypeEngine` plus one
  `engine.not-installed` warning per document.
- **Dogfooding rule (arch-test enforced)**: built-in integrations live in
  `php/laravel/src/Integrations/*` and may import only `Docuccino\Core\Extensions\
  Contracts\*` — never `Docuccino\Core\Internal\*`.
- **Per-document enable/disable (`integrations.<name>.enabled`)**: each integration bag carries an
  `enabled` bool, resolved at **per-document extension-resolution time** (the late-bound registry
  seam — `IntegrationToggles` gates `DefaultExtensions::all($document)`), orthogonal to `installed()`:
  `installed()` stays "is the package present", `enabled` is "does THIS document want it"; an
  integration contributes only when **installed AND enabled-for-this-document**. Because gating drops
  the extension from the resolved set, its FQCN leaves the fragment-cache signature — flipping `enabled`
  invalidates cached fragments for free. Defaults are per-integration: **on when installed, except
  `permission` (default off)** — documenting permission names leaks the app's internal authorization
  taxonomy into the public spec, so it is the first member of the **"sensitive-by-activation
  integrations default off"** principle (Passport stays on: OAuth scopes are the public contract). An
  installed-but-disabled integration emits one `integration.disabled` info diagnostic per document
  (discoverability), never fired when the package is absent.
- **Placement rule (Tom, 2026-08-02 — decides "core or adapter?" for every new piece):**
  **anything whose INPUT is the UIR document belongs in core; anything whose INPUT is
  Laravel code belongs in the adapter.** Recovery is adapter-side; representation and
  document-level analysis are core-side; framework-neutral machinery with framework-owned
  vocabulary splits accordingly. Worked examples that set the precedent:
  - Validation: normalized rule model + transformer chain + schema builder = core
    (machinery); the Laravel rule VOCABULARY + rule RECOVERY (FormRequest/inline/Data
    attributes) = adapter (`Integrations/Validation` + per-source integrations).
  - Document lints (`Core\Lint\*`): they scan the emitted document, so core, even though some
    default heuristics table entries look Laravel-flavored (they're neutral strings); the
    adapter contributes only config plumbing/registration, so the reference CLI, other-language
    producers and any downstream consumer of the UIR run the identical rules.
    `SensitiveFieldLint` is the leakage pass; `MissingDescriptionLint`, `OperationIdStyleLint`
    and `UndocumentedTagLint` are the completeness ones, sharing `LintRuleOptions` (off-switch +
    safelist) and `LintOperation` (the signature-ordered walk, so a finding's place never depends
    on the order its route was met).
    `LintOperation` walks BOTH headings: a webhook is an operation too, and its operationId, its
    prose and its tags are all author-typed. It is named `METHOD webhooks.<name>` — the differ's
    vocabulary, and what a webhook's own diagnostics already say — which keeps it out of the space a
    path template occupies, since a path always begins with `/`. Where the lever differs the help
    does: `#[OperationId]` never reaches a webhook, so the rename it names is the `#[Webhook]`.
    All four carry `#[ExtensionOrder(priority: Priorities::LAST)]`. A lint reads the document as it
    will be EMITTED, so it must run after anything that can still change it — today only
    `SharedErrorResponses`, whose hoist rewrites an inline error body into a `$ref` and would leave
    the leakage pass publishing pointers the output does not have. `LAST` rather than an edge onto
    that one class, so a third-party transformer lands ahead of the lints too; the FQCN tie-break
    that happened to produce the right answer is not an ordering contract.
    **Every one of them owes a firing population before it ships**, per the diagnostics rule:
    measured against the workbench, the prose rule fires on 2 of 23 operations (both actionable) and
    the other two fire zero times; only the operationId rule is on by default, because it is the only
    one whose worst case is bounded — it cannot fire on anything Docuccino mints. The webhook half of
    that population is 3 operations across the goldens and 0 hits on all three rules, so extending the
    walk added no noise to anything shipped — and every hit it CAN produce names a `#[Webhook]`
    argument or a class docblock, which is the most actionable population any of the three has. The
    rules considered and rejected on the same measurement are worth keeping in mind before adding
    another: parameter descriptions 35/88, schema-property descriptions 224/229, missing examples
    110/117, missing operationId 110/117, no-error-responses 67/117.
  - Pipeline engine = core (`Core\Pipeline\{Assembler, FragmentCache, OperationPipeline,
    OperationFragment, GenerationResult, AssemblyResult}` + `Core\Extensions\ResolvedExtensions`):
    a second adapter inherits the whole assemble→overlay→transform→hash→validate spine and
    its fragment caching for free. `DocumentGenerator`/`DocumentBuilder` stay in the adapter —
    the framework seam: route discovery, per-route context building, the booted-app environment
    digest, and `config('docuccino.*')` loading are Laravel-code inputs, deliberately NOT moved.
    The single framework-owned datum the engine emitted, the generator `name`, became an
    `Assembler` constructor param the adapter binds to `docuccino/laravel` (byte-identical here;
    a future adapter labels itself).
  - Content subsystem = core (`Core\Content\{ContentCompiler, Frontmatter}` beside the resolver
    and model already there): markdown-with-frontmatter is not Laravel code, and the reference
    CLI / a second adapter compiles the identical tree. This retired the earlier "filesystem IO
    belongs to the adapter" split — the placement rule keys on FRAMEWORK coupling, not IO-vs-pure,
    and that split did not survive `FragmentCache` (also file IO) moving to core. The adapter keeps
    only the `content.dir` config read + compiler invocation. `Core\Support\ConfinedPath` moved on
    the same reasoning (a pure path-confinement utility, the strongest `Fqcn`-precedent candidate);
    the framework-grammar readers `TypeStringParser` + the summary/description docblock split moved
    laterally to `docuccino/inference-phpstan` first, because they import `PHPStan\PhpDocParser`, which
    core banned at the time — superseded by the type-grammar move below.
  - Placement re-review follow-up (Tom, 2026-08-03 — after `docuccino/attributes` became a core
    runtime dep, which lifted the gate on attribute-aware moves). Byte-neutral relocations, goldens
    unchanged:
    - Provenance: `Core\Provenance\RootRelativeSourcePathResolver` (was the adapter's
      `LaravelSourcePathResolver`) — a pure composer.json-ancestor-walk implementing core's
      `SourcePathResolver`, zero framework imports; any adapter constructs it with its own base path
      (the Laravel provider still binds `base_path()`).
    - Component hoisting: `Core\Extensions\Schema\ComponentHoist` (was
      `Integrations\Support\ComponentHoist`) is now the single reserve→build→reference +
      cycle-break skeleton — core's built-in `ClassTypeToSchema` was de-duplicated onto it (its
      docblock had long admitted it mirrored ComponentHoist), and the integration mappers (spatie
      Data, Eloquent, resources) keep calling it unchanged. `Core\Extensions\Schema\SchemaIdentity`
      + `Core\Extensions\Schema\EnumReflection` moved with it (reflecting Docuccino attributes /
      enum cases is framework-neutral); the enum mapper `Core\Extensions\BuiltIn\EnumSchema`
      (reads `#[CaseDescription]` + the representation naming policy) moved to core beside the
      plainer `EnumTypeToSchema` it supersedes — its registration/order is unchanged, so it is not
      an installable integration (the docs matrix already listed it "built in — always on").
    - Request assembly: `Core\Extensions\Validation\RecoveredRequest` (was
      `Integrations\Support\RecoveredRequest`) applies a core `ValidationSchema` to an operation —
      body for write verbs, query params for GET/HEAD — which is generic OAS assembly, not recovery;
      the three adapter recovery extensions (FormRequest/inline, spatie-Data, laravel-actions) still
      recover their rule sets adapter-side, then converge on this core applier.
    - Overrides: `Core\Extensions\BuiltIn\AttributeOverridesExtension` (was
      `Laravel\Extensions\AttributeOverridesExtension`) reads only Docuccino attributes + core
      `ConfinedPath` (for `#[Description(file: …)]`); the provider keeps binding its `$basePath`, and
      binds the same for `AttributeExamplesExtension`, which reads `#[Example(file:)]` the same way.
  - Corollary: pure, stable core utilities that integrations legitimately need (e.g.
    `Core\Support\Fqcn`) get allow-listed in the arch test with justification — never
    duplicated to dodge the boundary. Because these moves landed in already-allow-listed core
    namespaces (`Extensions\Schema`, `Extensions\Validation`), no arch-test allow-list needed
    widening.
  - Deliberately NOT moved (placement classification recorded; each stays adapter-side for a
    concrete reason):
    - `Laravel\Engine\TypeEngineMode` stays — it is adapter *config vocabulary* (how the Laravel
      adapter selects/tunes its inference engine), not framework-neutral machinery.
    - `Laravel\Exceptions\DefaultExceptionToResponse` stays — a placeholder terminal in the
      error-response chain; its placement is classified but the action is deferred until it grows a
      real (framework-neutral) body worth relocating.
    - The parameter/request-body/response attribute extensions
      (`AttributeParametersExtension`, `AttributeRequestBodyExtension`, `AttributeResponsesExtension`)
      stay — they read Laravel-code attributes, which is adapter input; and `Routing\AttributeCollector`
      stays because it consumes the adapter's route-reflection `ReflectedAction`. What DID move is the
      grammar underneath them — see the type-grammar entry below.
  - **Type grammar = core (Tom, 2026-08-09).** `Core\TypeGrammar\{PhpDocParserStack, TypeStringParser,
    ImportContext, DocBlockReader}` (was `Inference\PhpStan\{Support, Types, Metadata}`). Rationale:
    every future adapter — Symfony, a reference CLI — needs the same grammar to read a
    `#[Response(type: '…')]` string and a docblock's summary/description/`@property` tags, which is
    exactly what core is for. It is framework-neutral AND engine-neutral machinery: the input is a
    type string or a docblock, not Laravel code and not an analysis scope. The blocker was core's
    ban on `PHPStan\`; that ban now carves out `PHPStan\PhpDocParser\`, the standalone parsing
    library (production-safe, shipped by every generator in this space), while phpstan/phpstan the
    analyser stays banned. `ImportContext` needs nikic/php-parser, which core already required, so the
    move added exactly one dependency. Byte-neutral: goldens unchanged.
    The adapter's attribute extensions and the engine's `ClassMetadataFactory` now share one grammar
    from core instead of the adapter reaching sideways into the engine — which is what made the
    engine droppable at all.
  - Query Builder recovery vs representation (Tom, 2026-08-05 — the enum-cast filter wave). Recovery
    is adapter-side: `QueryBuilderTraceVisitor` folds the subject model, allow-lists (with internal
    column names + constant `->default()`/`->nullable()` modifiers + a leading `//` or `/** */`
    comment above the entry), and pagination into policy-independent `QueryBuilderFacts`.
    Representation is `QueryBuilderParameters`, the only place the OAS *expression* is chosen under
    the document's `representation.filters` policy. Two decisions recorded:
    - **Enum-cast exact filters model as an array, not a scalar enum.** Spatie's exact filter treats
      a comma-joined value as a `whereIn` list, so a strict scalar `enum` would reject a legal
      `filter[status]=draft,sent`. The shipped shape is `type: array, items: {type: string,
      enum:[…], x-enumDescriptions}` with `style: form, explode: false` (the comma serialization) in
      the bracketed policy, an array property under `deepObject`; a constant `->default()` sits on
      the array schema as the single value, `->nullable()` adds a description note (never a null enum
      case). Non-enum casts keep their plain scalar shape (churn control); unresolved subject/column
      degrades every filter to `string`, as before. The array modelling held up against Scalar
      rendering + validators, so the `type: string, enum:[…]` fallback the wave left open was not
      needed.
    - **A leading comment is an integration-layer description (precedence 20)** — below docblock (30)
      and `#[QueryParameter]` (40), so authored descriptions still win; recovered purely from the
      array-item node's attached comment, first sentence verbatim, no tag parsing.
  - Query Builder filter-kind breadth (Tom, 2026-08-05 — round 2). Type recovery generalised beyond
    `exact` to every kind, on the principle **the semantic fact is policy-independent, and now the
    kind-specific fact is too**: `scope` reflects the model scope method's value parameter (native or
    backed enum) via `ScopeParameterResolver`; `callback`/`custom __invoke` recover the column of a
    single `$q->where(COL, $value)` via the shared `WhereColumnAnalyzer` (AST-only — the closure-by-
    line engine trace feeds *return* expressions, so an inline callback's expression-statement body is
    read from its node directly, and a custom class's body is parsed like `AccessorReader`); a static
    (`EQUAL`/`DYNAMIC`) `operator` types off the internal column, a non-static one stays a string;
    `trashed` is a fixed `with`/`only` enum. Only `exact` uses the `whereIn` array; every other kind is
    a single-value comparison, so its enum is one scalar value. A `partial`/bare-string filter over an
    enum column is never enum-typed (a substring match isn't an enum member) — it earns an info
    `query-builder.partial-on-enum` nudge toward `exact`. A `#[QueryParameter]` on a custom filter
    **class** overrides its body inference at the integration layer (its `name` ignored — the parameter
    name is the `AllowedFilter` name), so a route-level attribute (layer 40) still wins.
  - deepObject / bracketed attribute parity (same wave). `#[QueryParameter('filter[status]')]` patches
    the flat `filter[status]` parameter under the bracketed policy and the `status` property of the
    `filter` deepObject container under the deepObject policy — same attribute, mirrored behaviour
    (patch an existing member, create a missing one). Enabled by emitting deepObject `properties` as
    nested schema drafts (per-property PatchGuard provenance, so the override is recorded with the
    integration value kept in `overrode`). The QB integration runs at `Priorities::EARLY` so its
    container exists before the attribute layer patches into it.
  - Single paginating-terminal table + custom-terminal resource envelope (D3, 2026-08-06). The
    length/simple/cursor terminal→kind table lives once on `Support\PaginationTerminalVisitor`
    (`PAGINATOR_TERMINALS`); the QB parameters visitor, the API-resources envelope, and json-api-paginate
    all read it (the standalone `JsonApiPaginateTraceVisitor` is deleted — the shared visitor now also
    exposes the outermost terminal call's folded int args, which is all json-api-paginate needed extra).
    **Decision:** a custom QB terminal configured under `integrations.query_builder.pagination_terminals`
    (e.g. a `paginateList` helper) now ALSO triggers the API-resources `{data, links, meta}` envelope
    (length-aware), not only the QB page parameters — the terminal set is config-shared, so a resource
    collection paginated by a custom terminal is documented consistently with its page params instead of
    getting parameters but a bare-array body.
  - Paginating-terminal scope boundary (2026-08-14). The terminal table names METHODS, but the real
    predicate is the RECEIVER: `PaginationTerminalVisitor::receiverIsBuilder()` (Eloquent/Query builder,
    relation, Spatie `QueryBuilder`, subclasses included) plus `classIsModel()` for the magic
    `Model::paginate()` static. **Decision: the receiver gate stays**, and
    `integrations.query_builder.pagination_terminals` is scoped as "extra method names ON those
    receivers", never a general "my app paginates here" hook. A paginator over an in-memory collection
    (`$catalogQuery->paginate($request, $entries, InvoiceData::class)` on a plain query object) carries
    no static evidence of WHICH request keys it reads; `per_page` in particular is app wiring —
    Laravel's `paginate($perPage)` reads no request key on its own — so inferring page params from a
    paginator-shaped return type would document a parameter that may do nothing, which is worse than
    documenting none. Supported answers: `#[QueryParameter]` (layer 40) or an app-registered
    `OperationExtension` (layer 20). Response side is unaffected and stays type-driven where it can be:
    a spatie `PaginatedDataCollection`/`CursorPaginatedDataCollection` return documents its envelope
    from the type; only the Laravel resource-collection envelope needs the trace (its
    `AnonymousResourceCollection<T>` return type is identical paginated or not).
  - Page size: proven, not assumed (2026-08-17). The entry above is right that a page-size key is app
    wiring and that a paginator-shaped return type is no evidence for one — but "no evidence" was read as
    "unknowable", and it is not. An application that honours a size key writes the read somewhere, and the
    paginator's own SIZE ARGUMENT points at it: `Support\RequestPageSizeReader` follows that argument back
    through one local variable and into the callee that produced it, and documents the key only when it
    lands on one of the request accessors that names a single key (`integer`, `input`, `query`, `get`,
    `post`). The evidence is the argument, never the name — an app whose key is `limit` documents `limit` —
    so the previous rule survives intact wherever the size really is a call-site literal or the model's
    `$perPage`: no read, no parameter. The accessor set is deliberately not narrowed to the casting
    `integer()`: plenty of applications write `input('per_page')` into an int-returning helper, and
    requiring a cast would make recovery a property of an app's house style rather than of what its code
    does. It is the value-flow rule below that proves a read IS the size, so the accessor does not have to.
    What makes a read the SIZE is value flow, never proximity: the read has to reach the value the callee
    RETURNS. `sourcesOf()` is the whole grammar — a read, a local, a `min`/`max`/`intval` argument, an
    `(int)` cast, either side of `??`, a ternary's or `match`'s arms, or a callee's returns — and an
    expression it does not name is refused rather than guessed at. The parts of those forms that are read
    to DECIDE something (a ternary condition, a `match` subject and its conditions) are deliberately not
    sources: `match ($request->input('preset')) { 'small' => 10, … }` reads a key and answers with a
    literal, and counting keys near a return would publish a mode selector as an integer page size.
    Arithmetic over a read (`$perPage * 2`) is refused for the same reason — the key would no longer
    describe the size the endpoint uses. Both bounds decline rather than guess: one variable hop per body,
    and one callee deep.
    Correlation across the descent boundary is by SOURCE RANGE, because a `TraceVisitor` is never told
    which call site the body it is walking belongs to: the size argument names a callee, reflection says
    which lines that callee spans, and a `return` inside them is that callee's. A file+line pair is only
    meaningful when both halves came from ONE source, which is why `TypeScopeImpl::location()` reports the
    TRAIT's file for a node written in a trait body — PHP analyses that body as part of every using class,
    so its nodes carry the trait's lines, and a line compared against the using class's own methods reads a
    trait's code as one of theirs. With the pair coherent, a shared clamp in a trait (the common shape) is
    read like any other, and a `return` written inside a closure the body never calls is excluded by the
    nested spans the reader records. The reader is shared by the Query-Builder visitor and
    `PaginationTerminalVisitor`, so the two producers cannot name different size keys for one chain, and
    `PaginatorPageParameter::size()` mints it once for both.
    The schema states `type: integer` and a `default` only where the read's own fallback was a literal. It
    deliberately carries no `minimum`/`maximum`: an application clamps an out-of-range size to the nearest
    bound far more often than it rejects one, so a bound recovered from a `min`/`max` would tell a consumer
    their value is invalid when it is merely adjusted. No diagnostic rides with this — a silent recovery
    that succeeds needs none, and an endpoint whose size is a literal has nothing for a reader to act on.
    Still NOT resolved by this, and still the accepted limitation the entry above describes: a paginator
    over an in-memory collection (`AbstractCatalogQuery::paginate($request, $entries, …)` building a
    `new LengthAwarePaginator`) reaches no paginating terminal at all, so there is no size argument to
    follow and no `page` either. The receiver gate is what declines it, exactly as designed.
  - Enum + request-body component hoisting (Tom, 2026-08-07 — the last engine delta from the
    dogfood run, closing the named-component gap vs Scramble). Both are representation moves — the semantic
    facts (an enum's cases/descriptions; a request's rule set) are unchanged; only their OAS *location*
    moved from inline to a `$ref`'d `components.schemas` entry.
    - **Enums hoist by default** (`representation.enums.components`, default `true`). `EnumSchema` (core)
      resolves the component name via `SchemaIdentity` (`#[SchemaName]` else short name) pinned to the
      FQCN identity and calls `SchemaContext::reference()`, so one enum is a single deduped component
      that properties, query-parameter item schemas (the QB enum filters — which already route through
      the converter, so they inherit the `$ref` for free), and enum-cast columns all reference. Only a
      **reflectable** enum hoists — an un-autoloadable one has no honest name/identity to pin, so it
      stays inline; `false` restores the byte-identical inline expression. A **nullable** enum can't
      carry `type: [x, null]` on a `$ref`, so `UnionTypeToSchema` composes `anyOf: [{$ref}, {type:
      null}]` under BOTH nullable policies (the existing not-a-simple-type branch already did this).
    - **Request bodies hoist when recovered from a single source class** (spatie Data / FormRequest /
      action `rules()` class) — `RecoveredRequest` (core) references the class-derived body as a
      component named after the class, deduped so the same class across N ops is one component. An
      inline `validate()`/`Validator::make()` body has no source class to name honestly → stays inline.
    - **Deviation rule.** An operation carrying a `#[BodyParameter]` keeps its body **inline** for that
      op. That attribute patches individual body properties at the attribute layer by reading
      `schema.properties` (`AttributeRequestBodyExtension`); a `$ref` has none, so hoisting would let the
      patch silently discard the whole shared component (or mutate it for every other op). Dereferencing
      keeps the `$ref` honest. Call-site partials (`include`/`exclude`/`only`/`except`) shape the
      *response* via query parameters, not the request body, so they never force the body inline.
      **Reading `schema.properties` is also what fixes the attribute extension's position**: `requestBody`
      is ONE guarded field every producer writes whole, so the patch keeps only the siblings it can
      already see. `AttributeRequestBodyExtension` therefore runs at `Priorities::LATE`, behind every
      recoverer at the default priority. Ahead of them it is not a lost merge but a lost body — the
      one-property body wins the field at layer 40 and the recovered one is shadowed — and a lost 422
      with it, since the implicit 422 asks which producer built the body. It asks the whole producer
      trail rather than the winner, so a patched body still names the recoverer underneath it.
    - **Per-response property visibility is OUT OF SCOPE (2026-08-14).** `#[Hidden]` is document-wide and
      stays that way: the property contribution happens during type→schema conversion, where no operation
      and no status are in scope, and a class is ONE component because component identity is pinned to the
      FQCN (`IdentityGenerator::namedSchemaId`). Making visibility status-aware would make component
      identity status-dependent, breaking both the FQCN pin and the registry's structural dedupe — one
      class would fan out into a component per status it appears under. The capability itself already
      exists where it can be stated honestly: the problem-details preset emits `allOf: [{$ref ProblemDetails},
      {properties: {errors}}]` for validation entries (`ProblemDetailsSchema::response()`), because there
      the 422 shape is the preset's own fact, not a property of the app's class. App-side answers, in order:
      the preset; a per-status type + `#[Response(status:, type:)]`; `array|Optional` (in `properties`, out of
      `required`); an overlay on the one operation's response; a `DocumentTransformer` for many at once.
      **If ever revisited**, the only sound shape is the existing DEVIATION rule — dereference the component
      inline for THAT response and patch the inline copy (precedent: `#[BodyParameter]` forcing a hoisted
      request body inline, above) — never a status-aware converter.
    - **Both-sides naming.** Request- and response-side components of the same class carry DISTINCT diff
      identities (the request body's is `<FQCN>#request`), so a request rules-shape never dedupes into a
      response property-shape by identity. Structurally-identical shapes still collapse to ONE component
      via the registry's structural dedupe; genuinely different shapes (the input/output asymmetry — e.g.
      a `HiddenFromRequest` field, or validation-shape vs property-shape) yield two components. Because
      the Request phase precedes Responses, the request keeps the base name and the response is
      deterministically suffixed (`Name_2`) with the existing `components.name-collision` warning — proven
      live by the workbench `ArticleData` (`#[SchemaName('Article')]`) fixture, used on both sides with
      divergent shapes.
    - **Collisions stay Warning, and stay actionable.** Nothing is lost to a collision — both shapes are
      published, and each reference site keeps its own class's `$ref` — so it is not the Error tier, which
      is for a document that is wrong or unbuildable (`route.build-failed`, `document.schema-invalid`,
      `route.duplicate-operation`). Warning is also the tier CI can already enforce with
      `--fail-on=warning`, so a team that wants a collision to break the build has the lever without one
      being forced on a team whose collision lives in a vendor package. What the tier does demand is that
      the message be actionable: it names BOTH FQCNs and the contested name, because the short name in it
      identifies neither claimant. Because the escape hatch is an attribute, a collision is settled per
      class rather than per document — and two classes claiming the same `#[SchemaName]` is still a
      collision. Registry diagnostics ride the route's `OperationFragment` (`takeDiagnosticsSince()`), so a
      warm fragment-cache build — which restores components without re-registering them — reports the
      collision its bytes still carry.

## 7. Precedence / patch semantics

`fallback(5) < inference(10) < integration(20) < docblock(30) < attribute(40) < overlay(45,
OpenAPI Overlay 1.0) < programmatic config(50)`. Field-level PatchGuard:

- Unset field → accepted. Higher-over-lower → accepted, loser appended to `overrode`.
- Lower/equal-over-existing → rejected (`PatchResult::Shadowed`), loser also appended to `overrode`.
  **Never a diagnostic.** A higher layer winning is the ladder working, and the overwhelming
  majority of shadows discard the value that won anyway — two producers agreeing — so an info
  diagnostic here would fire dozens of times per build to report nothing. Those are not recorded
  either; only a shadow whose value DIFFERS from the winner's leaves a trail entry, which is what
  `--provenance=full` and `docuccino explain` read back. No caller reacts to the return value.
- Collections merge by identity key (parameters by in+name, responses by status, content
  by media type, properties by name) — never wholesale replace.
- A whole schema written as ONE declared shape (`SchemaDraft::declareShape()`, for a converted type)
  retracts the keywords it replaced but did not restate. Schema keywords compose as a conjunction, so a
  superseded one left standing publishes what nobody declared: a map inference's `additionalProperties`
  beside a declared closed shape says extra keys are allowed, and an inferred `type`/`items` beside a
  declared `$ref` says the body must satisfy both — legal in a Schema Object, applied by 3.1/3.2, and
  hoisted into an `allOf` by the 3.0 downlevel. `Draft\SchemaKeywords` classifies every keyword once:
  shape keywords go unless restated, a refinement goes once the declared type no longer admits it
  (`minLength` under a declared object; a `format` or an `enum` survives a restated type), annotations
  never go, and a keyword it does not know is never retracted. Each retraction is an ordinary guarded
  `Remove`, so it is bounded by precedence like every other write.
- `null` in an attribute = "not specified" (no write); explicit removal is a sentinel
  (`Remove::field()`, `#[Hidden]`, `#[IgnoreParam]`, `#[IgnoreResponse]`).
- **A subtraction has no layer, only a position.** The guard settles which of two WRITES to a field
  wins; removing a collection member is not a write, so nothing shadows it — and nothing stops a
  producer that runs later from creating the member again, which is a removal that silently did
  nothing. So a pass that removes runs AFTER every pass that could produce: `#[IgnoreParam]` is
  `Laravel\Extensions\IgnoredParametersExtension` in Finalize (the parameter phase's own producers, the
  request phase's validation recovery, and the parameter attributes have all contributed by then),
  ahead of `AttributeExamplesExtension` so an `#[Example]` naming something it dropped reports a
  missing target. The alternative — recording the suppression so the creating `??=` refuses — was
  rejected: it puts a new invariant into `OperationDraft` for one caller's sake, and `removeParameter()`
  would stop meaning what its name says. Note the residue: a member whose schema hoisted before it was
  removed leaves the component behind, because nothing prunes `components.schemas` by reachability —
  `ResponseDraft::isBodyless()` is the same hazard, avoided there by asking BEFORE converting a payload.
  That residue is why `#[IgnoreResponse]` is not the same fix, and it is the fix it got instead: a
  response carries a body that hoists, so every producer CONSULTS `Laravel\Support\IgnoredResponses`
  before it converts anything, rather than being reordered behind a removal. Declining to build the
  response is therefore also declining to hoist its body, which is the whole difference between
  consulting and removing afterwards. Eleven producers owe it — inference and the response attributes,
  the rate-limit 429, the created-resource re-home, the two paginated rewraps (both through
  `PaginatedResponseBody::wrap()`), the laravel-actions html and authorize pair, Query Builder's
  strict-mode 400, the throw-driven `ErrorResponsesExtension`, and `ImplicitResponsesExtension`, which
  was the one that already did. `AttributeResponsesExtension`'s `removeResponse()` sweep stays as the
  BACKSTOP for a producer this package does not own, where an orphan is the lesser defect.
  A throw-driven producer asks `IgnoredResponses::mapThrow()` rather than deciding on the throw's own
  status: a mapper answers at the status its tier proves — a table entry, a code folded out of a
  `render()` — so deciding beforehand would drop a status the author never named whenever the two
  differ. Mapping is a read there, and the registry rolls back, so nothing hoists and no diagnostic
  about an unpublished body is reported.
  Three rules bound it. A declaration drops EXACTLY the status it names: the created-resource re-home
  finding its 201 dropped declines the whole re-home, retraction included, rather than taking the 200
  away as well. It cannot name a range key (`3XX`, `4XX` — the attribute takes an `int`), which answers
  both directions of the range question: an ignore establishes nothing, so unlike a `#[Response]` it
  neither retires the range a member sits in nor narrows one. And a status matching nothing is SILENT,
  for the same measured reason `#[IgnoreParam]`'s unmatched name is: over the adapter's ignore route set
  a would-be diagnostic fires 13 times against 11 real drops, and all 13 are unactionable — 12 are one
  correct class-level declaration seen once per sibling action, and the last names a member of a
  published range the attribute has no way to spell.
- Within a layer, more-specific target beats less-specific (method attr > class attr).
- **Within a layer, a declaration on the TYPE is the least specific target of all.** Several attributes
  are legal on an action, on the controller class that owns it, and on a class that is a request or
  response type; those are not three spellings of one thing. A declaration on the type is true of every
  operation that accepts it, so it belongs to the COMPONENT, and the operation keeps its `$ref`; a
  declaration on the action is one operation's, so it applies to that operation's own body and
  dereferences it. Both are layer 40 and the ladder's existing rule settles them without a mechanism
  beside it: same layer, more-specific target lands second and wins the fields it names, with the
  type's declaration underneath it.
  `#[BodyParameter]` is where this is implemented — `Extensions\Validation\RecoveredRequest` reads the
  type's, `Laravel\Extensions\AttributeRequestBodyExtension` the action's, and one
  `Extensions\Validation\DeclaredBodyFields` does the writing for both, so the field-path grammar and
  the shallowest-first ordering cannot fork. Which is a CONSUMER's rule before it is an author's: the
  named component is what a client generator turns into a named type, and restating a fact about a type
  per action cost them that type on every action that said it, plus any evidence that two operations
  accept the same shape.
  One class in both roles is the exception, and it is a carve-out rather than a rule: where the request
  source class IS the route's action (laravel-actions), one declaration site serves both, the route
  attribute bag already reads it, and the operation-level meaning it has is the one that exists.
- **`TARGET_CLASS` is two roles, and only one of them is a schema.** An action class goes through the
  route attribute bag, which reads every attribute the package ships; a TYPE is read by reflection, for
  a handful. `Extensions\Schema\SchemaClassAttributes` is the exhaustive table of which — every
  class-target attribute is honoured on a type or named with where it IS read — and a declaration in the
  second half raises `attribute.schema-class-unread` instead of being dropped in silence. The table is
  checked against the attributes package, so adding an attribute is a decision about types rather than a
  silent drop nobody made.
  Measured over the corpus's 48 request-source classes: 2 firings, both actionable, and both on the
  fixture written to provoke it — nothing an application had already written fires it, because every
  class-level declaration out there is one a type IS read for. The carve-out is what earns that: firing
  on a source class that is also the action would add 2 hits, and BOTH are unactionable — the attribute
  is read there, through the route bag — which is half the channel saying "your correct declaration did
  nothing".
- Within the docblock layer, `@summary`/`@description` beat the free-prose split, and declaring
  EITHER hands both fields to the tags — the prose above them was written for whoever maintains
  the action, and half of that note is worse in the document than none of it. `DocBlockReader::read()`
  resolves this before any patch, so the two never contest a field.

**`#[Hidden]` stays OUTPUT-only; request-hiding is the separate `#[HiddenFromRequest]` (pre-dogfood
decision).** `#[Hidden]` was NOT conflated to also hide a Data property from the request body. A
property hidden from output but still accepted in the request is a real, intentional shape — and
exactly the accidental exposure the built-in data-leakage lint is designed to surface (the workbench
`ArticleData.secret` scenario proves it) — so folding request-hiding into `#[Hidden]` would silently
suppress that signal, which the brief flagged as "wrong for output-DTO users". Per the brief's
"separate attribute" path, request-body hiding is the explicit opt-in `#[HiddenFromRequest]` (a Scramble
request-`#[Hidden]` field migrates to it); `#[FromRouteParameter]` (spatie) is likewise excluded — its
value is bound from the route, never sent. `#[Hidden]`'s meaning is unchanged (output only), so the
leakage lint keeps catching a genuinely-leaking hidden field.

## 8. TypeEngine boundary (authoritative shape — see inference doc)

```php
interface TypeEngine {
    public function analyzeAction(ActionRef $a): ActionAnalysis;   // ReturnSite[], ThrownException[], diagnostics, dependencyFiles
    public function classMetadata(ClassRef $c): ClassMetadata;
    public function trace(ActionRef $a, TraceVisitor $v): TraceReport;
}
// Optional trace capabilities (Docuccino\Core\Inference), opt-in per visitor, each degrading by declining:
interface FollowsReturnType { public function followsReturnType(DType $t): bool; }                       // on the VISITOR: descend past project paths (never vendor)
interface FoldsCallReturns  { public function deferReturnFold(Node\Expr $c, callable $onFolded): bool; } // on the SCOPE: fold what a call RETURNS, not what it is written with
```

`FoldsCallReturns` is what recovers an allow-list entry whose public name lives in the callee's BODY
(`$this->termFilter()`, `ListFilters::status()`, `...$this->allowedFilters()`) rather than in the
arguments at the call site: a DEFERRED fold of a single unconditional `return`, answered after the walk.
Opt-in because `constantValueOf` is shared and a fabricated descriptor is worse than an unrecovered one —
mechanics, limits and the FiberScope reason for deferring are in the inference doc §4.

`DType` closed set: `ScalarT, LiteralT, ArrayShapeT, ListT/MapT, UnionT, IntersectionT,
ClassT(fqcn, typeArgs), EnumT(cases), CallableT, NullT/VoidT/NeverT, StatusMarkerT, UnknownT(reason)`.
`NullTypeEngine` in core answers UnknownT for everything (keeps pipeline total).

### The `array<K, V>` key rule (`ListT` vs `MapT`)

PHP renders an array as a JSON **array** only while its keys are the `0..n` int sequence, and as a JSON
**object** otherwise. `Docuccino\Core\Inference\DType\ArrayKey` owns the one rule both recovery paths
apply — the docblock grammar (`TypeStringParser`) and the engine's `TypeTranslator` — because both reach
it with the key already mapped to a `DType`, so a second copy could only ever drift:

- an **int-capable** key (`int`, an int literal, `array-key`, `int|string`) → `ListT`
- anything else, including a key we can't reason about → `MapT`, which carries the key type

The uncomfortable half is deliberate and worth stating. `array-key` and `int|string` are exactly the keys
that *may* be strings, so an `array<array-key, V>` may well arrive as a JSON object, and `{"type":
"array", "items": V}` is then a positive false claim. Three things settle it the way it is settled:

1. **PHPStan cannot distinguish the spellings.** `V[]`, `array<V>`, `array<array-key, V>` and (once the
   list accessory is absent) `array<int, V>` all reach the translator as the same `Type` with an
   `int|string` key. One rule must cover all four, so calling the ambiguous key an object would document
   every `string[]` property in every codebase as an object — a far larger and more certain error than
   the one it avoids.
2. **The author has a precise way to say either.** `array<string, V>` yields a `MapT` and documents as an
   object with `additionalProperties`; `list<V>` yields a `ListT` unambiguously. The rule respects both.
   The ambiguity only survives where the annotation itself was ambiguous.
3. **The honest third answer costs more than it buys.** `anyOf: [array of V, object of V]` is true for
   every case, but it would land on the single most common annotation in PHP and turn a precise
   document into a union everywhere — including for the overwhelming majority of properties that really
   are lists.

So this is one place the project accepts a precise claim over a vague one, against its usual bias, and
the reason is that the vague answer here is not cheap: it is paid on almost every array-typed property in
the document. A `MapT` whose key type is itself int-capable is the recorded escape hatch if that ever
needs revisiting — the ambiguity survives in the DType, it is only the schema mapper that resolves it.

A constant shape decides the same question from its keys instead: `ArrayShapeT::$isList` is true when the
keys are the `0..n` sequence, derived in the constructor as well as taken from PHPStan's list accessory,
so a docblock tuple (`array{string, int}`) can never be documented as an object with `"0"`/`"1"` property
names — a shape no JSON document has.

`StatusMarkerT` is the one member that is not a language type: it is a pipeline-resolution SIGNAL
meaning "this body member echoes the response's own HTTP status", synthesised by the engine's response
refinement (never translated from a PHPStan type) and resolved to a `LiteralT` at the response-building
seam before schema conversion. It lives in the closed DType set rather than in a transient side-channel
because it must survive SERIALIZATION: the marker sits inside the `ArrayShapeT` payload of a
`JsonResponse<…>` `ClassT` carried in an `ActionAnalysis`, while resolution happens later, in the
adapter — a side-channel could not cross that boundary. DType consumers are `supports()`
chains rather than exhaustive `match`es over kind, so adding it broke no totality; the fallback mapper
maps it honestly to a bare `integer` (no fabricated `const`/example) for the case where nothing resolves
it.

## 9. Config shape (docuccino/laravel)

```php
return [
    'enabled' => env('DOCUCCINO_ENABLED', true),
    'documents' => [
        'default' => [
            'info' => ['title' => '…', 'version' => …, 'description' => ['file' => '…md']],
            'servers' => [['url' => 'https://{tenant}.example.com', 'variables' => [...]]],
            'routes' => ['include' => ['api/*'], 'exclude' => [...], 'closure' => null],
            'security' => [...full scheme set..., 'auto_detect_middleware' => 'auth*'],
            'error_responses' => ['preset' => 'problem-details', 'errors_shape' => 'pointer-list'],
            'tags' => ['mapper' => PrefixTagMapper::class, 'map' => [...], 'default_strategy' => 'controller'],
            'content' => ['dir' => 'resources/docs/api'],
            'overlays' => ['resources/docs/overlays/*.yaml'],
            'representation' => ['filters' => 'bracketed|deepObject', 'nullable' => …, 'enums' => …, 'operation_id' => …],
            // Per-integration document-level knobs — one bag per integration, keyed by its config
            // name (snake_case); each integration reads ONLY its own bag (via DocumentConfig::integration()).
            // Every bag also accepts `enabled` (bool), resolved per-document at extension-resolution
            // time: an integration contributes only when its package is installed AND the document
            // enables it. Default on when installed, EXCEPT `permission` (default OFF — opt-in; see below).
            'integrations' => [
                'api_resources' => ['wrap' => true],                      // top-level resource `data` wrapping (false | true | '<key>')
                'sanctum'       => ['modes' => ['token', 'stateful'], 'cookie' => 'myapp_session'],
                'passport'      => ['url' => 'https://auth.example.com'], // oauth2 flow base URL (default app.url)
                'query_builder' => ['pagination_terminals' => ['paginateList']], // extra paginating method names
                'permission'    => ['enabled' => true],                   // opt in (default OFF): document role/permission requirements
            ],
            'export' => ['path' => '…', 'formats' => ['openapi-3.2']],
            'viewer' => ['driver' => 'scalar', 'route' => '/docs/api', 'gate' => 'viewApiDocs', 'source' => 'generate|artifact'],
        ],
    ],
    'extensions' => [],
    // Document lints, all diagnostics-only. `leakage` also takes `patterns` (extra token → label
    // heuristics merged over the built-in sensitive-name table); the rest take enabled + allow.
    'lint' => [
        'leakage' => ['enabled' => true, 'allow' => [], 'patterns' => []],
        'descriptions' => ['enabled' => false, 'allow' => []],
        'operation_ids' => ['enabled' => true, 'allow' => []],
        'tags' => ['enabled' => false, 'allow' => []],
    ],
    'on_route_error' => 'skeleton',
    'cache' => ['enabled' => true, 'store' => null],
];
```

`error_responses` accepts either a string preset — `default` (framework-default JSON error shapes),
`problem-details` (the RFC 9457 preset), or `none` (no error responses) — **or** a bag
`['preset' => <string>, 'errors_shape' => 'map'|'pointer-list']`, where `errors_shape` chooses how a
422 body models its `errors`: `map` (field → messages, the default) or `pointer-list` (a list of
`{detail, pointer}` objects, JSON-Pointer style). `tags.default_strategy` chooses how an operation
with no `#[Group]` gets its default tag: `controller` (the controller's short name → `tags.map`, the
default) or `none` (no default tag); an unknown value coerces to `controller` and emits a
`config.unknown-tag-strategy` info diagnostic. `tags.definitions` entries are full OAS 3.2 Tag
Objects (`name` + optional `summary`/`description`/`parent`/`kind`) plus Docuccino's own `weight`,
which orders the emitted array (weight, then name) and is not emitted. Parents are resolved AFTER
that sort, so the result never depends on definition order: a `parent` naming no defined tag emits
`config.unknown-tag-parent`, one that would close a cycle emits `config.tag-parent-cycle`, and in
both cases the offending LINK alone is dropped (the tag stays) so the hierarchy is always a forest.
`summary`/`parent`/`kind` are 3.2-only — the 3.1 downlevel drops each with its own
`downlevel.tag-*` warning. Setting `enabled` on one of the always-on producers
(validation / form_request / framework_errors / problem_details / inferred_handler) has no effect and
emits a `config.enabled-ignored` info diagnostic — only the toggleable integrations honour `enabled`.
Integration config lives under one `integrations.<name>` bag per integration — there is no
back-compat read of the old flat `security.sanctum` / `passport` / `query_builder` locations
(pre-launch).

**Determinism — paths are stored base-path-relative.** `DocumentConfig::hash()` digests the whole raw
config bag and that digest is EMITTED as `document.configHash`, so an absolute path anywhere in a
document's config would fold the generating machine's filesystem layout into a committed artifact: two
checkouts of the same app at different paths could never emit the same bytes. `ConfigPaths` — the
single owner of the path-key table (`content.dir`, `export.path`, `info.description.file`, `overlays`)
— therefore rewrites every path-like value that sits INSIDE the app base path to its base-relative
form at config-read time, in `DocumentConfigFactory::make()`, the one choke point every command, the
viewer and the pipeline share. The hash then follows a path's MEANING, not its spelling:
`base_path('resources/docs/api')` and `'resources/docs/api'` hash identically. Resolution is unchanged
(a relative value is joined back against the base path by `Paths::absolute()` /
`ConfinedPath::resolve()`), and containment is decided lexically — no `realpath()` — so a glob obeys
exactly the same rule as a file. A path genuinely OUTSIDE the app is kept verbatim (rewriting it would
break the read) and emits a `config.machine-dependent-path` info diagnostic naming the key and the
path, so the machine dependence is stated rather than silently baked into the output. `cache.path` and
`engine.project_paths` are app-level, never part of a document bag, so they reach no emitted byte.

**The same rule for a published value.** A path only churns the `configHash`; a value a producer reads
out of framework config and PUBLISHES is acted on by the client. `MachineDependentValue` is the one
rule for those: a URL whose host is loopback or a reserved local-development name, a value no config
key answered for, or an opaque value nothing pins that the framework derives from the environment, all
raise `config.machine-dependent-value` — same family, same fix (pin it), but **Warning**, because what
was published is arbitrary rather than merely spelled oddly, and `--fail-on=warning` should stop it
reaching a release. Nothing is unbuildable or malformed, which is why it is not an Error; the sibling
`config.machine-dependent-path` stays **Info** because an absolute path only churns the `configHash`
while these are published for a client to act on. The value is always emitted anyway: Passport's flow
URLs and Sanctum's cookie name are contract-bearing, and OAS requires the former. The report is raised
through the component registry so it rides the operation fragment and a warm build replays it (§10).

Each kind of value is judged on the strongest signal available for it. A URL has a host, and a
loopback or reserved local-development host is positive evidence that no consumer of the published
document can reach it — while a public host is equally positive evidence the value is fine, so it is
not reported. A value no config key answered for is arbitrary by construction: a hard-coded default
stood in. An opaque value (a cookie name) offers neither signal, so the only thing left to go on is
that nothing pinned it and the framework key it came from is one the environment supplies. The rule
lives under `Laravel\Support` rather than beside its callers because those sit on both sides of the
Extensions/Integrations line and an extension may not import an integration.

## 10. Fragment caching

Unit = OperationFragment (operation + registered components + diagnostics + document-level notes +
provenance, serialized as UIR JSON fragments). Key = sha256(tool ver ‖ spec ver ‖ identity-algo ver ‖
doc configHash ‖ environment digest ‖ build fingerprint ‖ resolved extension list (FQCNs +
package versions) ‖ route cache-signature ‖ sha256 of each file in
`ActionAnalysis::$dependencyFiles`). Assembly → canonicalize → validate always run fresh.
Watch mode later = loop incremental build + SSE push.

The route cache-signature (`RouteDescriptor::cacheSignature()`) is method + URI + NAME + resolved
action + normalised middleware + any scalar `cacheInputs` a resolver folds in — the name is in
because it is the default `operationId` and a rename touches no file the fragment depends on. The
build fingerprint (`Laravel\Pipeline\BuildFingerprint`) is the environment the engine runs in:
which engine resolved, whether the engine package is installed, the output-shaping half of
the `engine` config (everything but `memory_limit`, a process ceiling that reaches no emitted byte),
and the app's `composer.lock` hash — installing the engine or upgrading the analyser changes what
inference recovers without touching one analysed file. Tool ver additionally carries this package's
own installed source reference where Composer can answer for it, so a `path`/dev checkout edited in
place — the maintainer's loop, invisible to the app's lock file — doesn't share fragments with the
release it was checked out from. The store itself is emptied by `docuccino:clear --fragments`.

Two consequences of the cache being the fast path. A build resolves its `TypeEngine` before it
starts, but a fully warm one asks it nothing, so the adapter hands out an `Engine\LazyTypeEngine`
that builds the real engine on the first question — the analyser boots exactly where it did, ahead
of any analysis, and not at all for a build that recovers nothing. That is why the fingerprint names
the engine (`TypeEngineFactory::engineIdentity()`) instead of reading the class off an instance: the
key is computed before the first route, and asking would cost the boot it exists to avoid. And
freshness hashing goes through `Core\Pipeline\FileDigests`: one build hashes each dependency file
once — the same file sits in many routes' lists — and sees one view of it, and the memo dies with
the build.

**A dependency file is where a fact was WRITTEN, not where it was asked for.** Almost everything the
build recovers about a class is answered by inheritance: public properties and their docblocks, a
promoted property's constructor `@param`, a static `$wrap`, a `render()`, an action's traits, a model's
`$casts`. PHP reports a trait-imported member as the USING class's, so the file that actually holds it
is reachable only by walking the trait list. `Core\Extensions\Schema\DeclarationFiles` is the one answer
to that question — own file, every parent, every trait flattened into any of them — and every recovery
site that records a hierarchy-derived fact goes through it, including `ClassMetadataFactory`, which
folds it into the `dependencyFiles` every consumer already forwards. An enum counts separately: its
CASES are copied into the recovered type and into any rule quoting its backing values, so
`EnumReflection::file()` joins the list wherever a case list is read. Erring upward here is deliberate —
a file too many costs a rebuild, a file too few serves a stale schema — and it stays proportional, since
a parent invalidates its subclasses and nothing else.

**A fragment carries the security schemes its operation names.** `components.securitySchemes` is
document-level, but nothing rebuilds it: on a warm hit no extension runs, so a fragment that carried
only its `$ref` closure came back holding a `security` requirement for a scheme the document no longer
had. The requirement names its scheme as a KEY rather than through a `$ref`, so the closure walk misses
it — `OperationFragment::componentSecuritySchemes` carries it explicitly instead, and the warm restore
re-registers it under the name it was cached with, repointing the requirement if that name has since
been taken (two routes referencing scopes the other doesn't build two different `passport` schemes).

**A finding the DOCUMENT reports travels on the fragment too.** Some findings are discovered one route
at a time and belong to the whole document — a render callback whose body would not fold is one line
naming the callback, not one per route that threw through it. Held as a running total inside the
extension, such a finding is simply absent from a warm build: a cached route runs no extension, so
nothing adds to the total, and the document says less than a cold one's for the same code. The seam is
`RouteContext::notes()` — a bag of `(channel, key, value)` strings that rides `OperationFragment::$notes`
— plus the gated `RouteNoteCollector` chain, which owns the aggregate. `DocumentGenerator::collectNotes()`
drains each fragment's notes into the matching collector for a fragment it just built and for one that came
back warm ALIKE, so there is one path into an aggregate rather than two that can drift, and the summary a
`DocumentTransformer` publishes is the same either way. `RouteNotes::all()` is sorted throughout and the
drain runs in route order, so the aggregate is a function of the route set. `forget()` runs before the
first route of each document, because a container-`scoped` collector outlives a build and an export of
several documents must not report the first document's findings against the second's.

A note is strings and nothing richer, because the fragment is JSON on disk — and that disk format is
stamped with `FragmentCache::FORMAT`. Bump it whenever a fragment gains something a warm build now needs:
an entry written before the member existed cannot distinguish "this route had none" from "this format
could not carry them", and reading it as the former is the silent degradation in a new place. A miss costs
one rebuild.

**A webhook is a fragment too.** `#[Webhook]` classes are discovered per document from
`webhooks.dir` (`Laravel\Webhooks\WebhookCollector`), and each one is built and cached exactly as a
route is: keyed on the declaration (`WebhookDeclaration::cacheSignature()`) against the same document
`configHash` and extension signature, with a dependency manifest of the class's `DeclarationFiles`
plus whatever the payload conversion recorded through `SchemaContext::dependsOn()`. The alternative —
rebuilding webhooks on every run because they are document-level — would re-run the analyser over
every payload class for a build that changed nothing. The split is deliberate: what the ATTRIBUTE says
(a blank name, an unrepresentable method, two classes claiming one name) is recomputed from reflection
on every build, because reading it costs nothing; what the ENGINE answered rides the fragment. The
discovery scan itself is never cached, so a webhook added or deleted is seen the run it happens.

**The extension signature is per INSTANCE.** Extensions are registrable as objects on every surface
there is (`Registrar::add`, `ExtensionRegistry::extend`, config), so `new MyExtension(mode: 'a')` and
`mode: 'b'` are two different builds under one class name. `ResolvedExtensions::cacheSignature()`
therefore emits one entry per resolved instance — class, owning package version, and a digest of the
instance's own properties, reading enum cases as cases and a closure as where it was written plus what
it captured. Its honest limit: it does not descend into a collaborator OBJECT a property holds (an
injected container would be an unbounded walk, and a collaborator is a dependency rather than a
setting), so two instances differing only inside one still key alike — hold the setting itself.

**Auth config is keyed unconditionally.** `Integrations\Support\AuthConfigDigestContributor` feeds
`auth.guards` and `auth.defaults.guard` into the environment digest whether or not Sanctum or Passport
is installed, because the guard→driver map is the framework's and is what decides which security
integration owns a route. Left to the per-package contributors, an app running only one of them was
covered by accident of which one that was; the package-specific contributors now carry only what is
genuinely theirs (Sanctum's `session.cookie`, Passport's URLs, scopes and grants).

## 11. Worked example (one operation)

```json
"paths": {
  "/api/v1/forms": {
    "get": {
      "x-docuccino": {
        "id": "op:v1:mfz3q8k2w9r7t1ua",
        "provenance": [
          { "producer": "inference", "layer": "inference", "fields": ["responses.200"],
            "source": { "file": "modules/Form/Http/Controllers/FormController.php", "line": 38, "symbol": "FormController::index" },
            "confidence": 0.95 },
          { "producer": "attribute", "layer": "attribute", "fields": ["summary"],
            "source": { "file": "modules/Form/Http/Controllers/FormController.php", "line": 34 }, "confidence": 1.0,
            "overrode": [ { "field": "summary", "value": "Index forms", "producer": "docblock" } ] }
        ]
      },
      "operationId": "forms.index",
      "summary": "List forms",
      "tags": ["Forms"],
      "parameters": [
        { "x-docuccino": { "id": "par:v1:ab12cd34ef56ab78",
            "provenance": [ { "producer": "integration:query-builder", "layer": "integration", "fields": ["*"],
              "source": { "file": "modules/Form/Queries/FormIndexQuery.php", "line": 22 }, "confidence": 0.9 } ] },
          "name": "filter[status]", "in": "query", "required": false,
          "description": "Exact-match filter. Accepts a comma-separated list of values (matched as `whereIn`).",
          "style": "form", "explode": false,
          "schema": { "type": "array", "items": { "type": "string", "enum": ["draft", "published", "archived"],
                      "x-enumDescriptions": { "draft": "Not yet visible", "published": "Live", "archived": "Read-only" } } } },
        { "x-docuccino": { "id": "par:v1:77aa88bb99cc00dd" },
          "name": "per_page", "in": "query", "required": false,
          "schema": { "type": "integer", "default": 15, "minimum": 1, "maximum": 100,
                      "x-docuccino": { "mock": { "faker": "numberBetween:1,100" } } } }
      ],
      "responses": {
        "200": { "x-docuccino": { "id": "res:v1:e1f2a3b4c5d6e7f8" }, "description": "Paginated list of forms",
                 "content": { "application/json": { "schema": { "$ref": "#/components/schemas/PaginatedFormData" } } } },
        "401": { "$ref": "#/components/responses/ProblemUnauthenticated" },
        "422": { "$ref": "#/components/responses/ProblemValidation" }
      }
    }
  }
}
```

## 12. Open questions carried forward

- Generic schema identity (`Paginated<FormData>`): FQCN+args tuple proposed; needs a
  normative cross-language rule in the spec before 1.0.
- Confidence semantics: recorded-only in v1; spec must document meaning now.
