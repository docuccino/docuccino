# Defect classes

Patterns this codebase has hit more than once, with the test that recognises each. The binding rules
live in [`CLAUDE.md`](../../CLAUDE.md); this is the catalogue a reader consults when something feels
familiar. Add a class when a second instance turns up, not on the first.

## A subtraction leaves no evidence

An **additive** declaration that reaches nothing shows up as an absent node — you can see it missing.
A **subtractive** one produces exactly the document a working one produces, so the failure is
invisible by construction.

*Instances.* `#[IgnoreParam]` and `#[IgnoreResponse]` dropped nothing on a stale name and said nothing.
`#[Hidden]` was worse: a name matching no property hides nothing and so **publishes** the field the
author marked as not-for-publication. `#[InDocs]` inverts — a key naming no configured document
excludes the route from *every* document rather than pinning it to one.

*The tell.* An author-supplied name reaching a minting or mutating accessor with no `has*` guard.
`#[Example]` is the counter-example that gets it right: it calls `hasParameter()` first and reports
`attribute.example-target-missing` on the fall-through.

*The fix that worked.* Say the declaration as written, say it took no effect, and name what the
operation *does* document so the typo is visible beside it. Judge matches BEFORE the removal, or the
second declaration naming one parameter reads as having reached nothing.

## Does null carry two meanings?

The discriminating test for an accessor. `producerFor(): ?string` returns null only for "unset" — safe.
`ValidationField::type(): ?string` returned null for "untyped" **or** "several", the caller could not
tell, and it caused three separate defects before the method was removed.
`ContractParameter::schema()` carried three meanings: no member, a `content`-typed parameter, and a
member that would not decode.

*The fix that worked.* `list<T>` where the model holds several; a kind enum where the question is
"why is there no answer". Never a nullable getter with an opt-in boolean beside it — forgetting the
boolean is silent, which is the defect again. Make the enforcement mechanical: a private node behind a
method the right arm calls, so the careless read is a compile error rather than a convention.

*Related.* An accessor that answers with LESS than the model holds is the same class one step along —
`Exchange::header()` returned one value where a request may carry several, while the response half of
the same class already returned a list.

## A test that proves less than it claims

Four instances, from four different agents, in one stack — which is what makes it a class rather than
carelessness.

- **Structurally incapable of failing.** `expect($surface)->not->toContain($a, $b)` inverts the whole
  call, so it passes as soon as ONE name is absent. The two names in it were covering for each other.
- **A universal proved over a sample.** An "every other type" test hand-picked 9 of 27 identifier
  keywords while the source of truth sat in the same file, unused. Forking 10 of the 27 passed green.
- **A sweep with nothing holding it.** A correct change applied to seven call sites had one pinned;
  reverting the other six passed the whole suite.
- **An invariant stated only in prose.** Reversing a `sort()` whose docblock forbade order-dependence
  passed all 8327 tests.

*The tell.* Ask what would have to break for this test to fail, then break it. If the suite stays
green, the test is decoration. A dataset proves the rows it lists, so a hand-maintained list owes a
separate guard that reads the source of truth.

## A key the document publishes that the runtime would not return

*Instances.* `$appends` and `$with` relations bypassed the model deny-list entirely; the
`$visible`/`$hidden` precedence was implemented backwards; and six framework bookkeeping properties
(`timestamps`, `incrementing`, `exists`, …) were published as required columns on every model.

*The tell.* Several readings of one question. The fix is one predicate every key-adding site calls,
taking the whole facts bundle so no caller can pass a half-merged deny-list — which is how the
class-level list came to be forgotten in the first place.

*Where it hides.* A golden pins what it contains. Removing the framework-property filter left 112
golden tests passing, because the workbench engine never reported them — that half needed a
fixture-group pin against the real analyser.

## Two derived guards, each keyed to its own subset

A guard that reads the source of truth is only as wide as the SUBSET it derives from, and two of them
side by side cover their two subsets and nothing between.

*Instances.* The schema diff's composition guard derives its keyword set from the subschema positions, so
sixteen refinements sat unread and no test failed — a tightened `maxLength` passed `--enforce` as safe.
The refinement guard closed that and derived from the refinements, after which five keywords in NEITHER
subset — `discriminator`, `nullable`, `$id`, `$anchor`, `$schema` — were still read by nothing, and a
repointed `discriminator` mapping broke every polymorphic client while the gate reported no changes.

*The tell.* Two guards whose sets are both derived and both partial, with no assertion that the union is
everything. Each passes forever while the gap between them grows.

*The fix that worked.* Assert the UNION against the model itself — `SchemaReadingDiffTest` holds all three
decision tables against `SchemaKeywords::knows()`, in both directions, with an anti-vacuity floor and a
partition check so nothing is answered twice. A member that owes no comparison still owes a ROW saying so,
because "read elsewhere" and "deliberately unread" are decisions a guard can see and a gap is not.

## A partition that covers everything and agrees on nothing

Proving that several tables COVER a domain says nothing about whether they AGREE within it. Coverage
and agreement are different properties, and a guard over the inputs proves only the first.

*Instances.* The schema diff's three decision tables partition every keyword the draft model knows —
asserted in both directions, with an anti-vacuity floor and a check that nothing is answered twice.
Beneath that guard, the same three-valued direction (narrowed / widened / indeterminate) was turned
into a verdict at eight separate sites, and four of them disagreed: a widening on a RESPONSE was
breaking where `enum`, the union keywords and the refinements decided it, and safe where `allOf`
leaving, `not` leaving and the `contains` bounds decided it. So `maxItems: 2 → 9` failed `--enforce`
while `maxContains: 2 → 9` passed it — same document, same gate, same day. Three of the four wrong
sites were pinned by tests whose comments asserted the behaviour was deliberate.

*The tell.* One fact computed independently at more than one site, where the only guard holding the
sites together reads their INPUTS. A second tell is prose: this rule was stated in five places, and
three of those paragraphs certified behaviour the code did not have.

*The fix that worked.* One function from the direction to the verdict, owned by the single reader of
all three tables — and the tables return the DIRECTION rather than a pre-collapsed boolean, because a
`…IsBreaking()` that answers a move is a lie about what it knows. Then a guard derived from the tables
that drives every path through the real comparator and holds each published verdict against the rule
stated INDEPENDENTLY in the test: a guard that asks the code for its own rule agrees with whatever the
code does. Beside it, a discrimination check — two deliberately wrong rules must each disagree with
the corpus on at least one row, or the corpus is not proving anything. See also
[Two derived guards, each keyed to its own subset](#two-derived-guards-each-keyed-to-its-own-subset),
which is the same shape one layer up; closing that one is what made this one visible.

## A test that agrees with the host it ran on

A test that reads the machine and asserts an answer the machine's own shape decides passes for
whatever the developer happens to be running, and states nothing.

*Instances.* `MessagePathsTest` asserted that `sys_get_temp_dir()` is redacted out of a diagnostic.
That is five segments deep on a mac and `/tmp` on a Linux runner — and one segment is a depth
`machineRoots()` deliberately REFUSES, so that `Route /tmp/upload is documented` survives in prose. The
row therefore asserted the redacted answer where the host was deep and the untouched one where it was
shallow. It passed locally and failed on CI. The same file had already met this once: a row reading the
machine's own `include_path` proved something about a two-segment prefix on one install and a
three-segment one on another, and was rewritten to take the prefix as input, with a comment saying why.
The braced row reintroduced it a few tests later.

*The tell.* A test calling `sys_get_temp_dir()`, `getenv()`, `php_uname()` or `get_include_path()` and
asserting on what comes back. Using the temp directory as a PLACE to write is fine; asserting that its
DEPTH produces a particular answer is not.

*The fix that worked.* Make the machine fact an input and state every answer as a row, so the deep and
the shallow case are both asserted on every machine. The shallow row is the valuable one — it pins the
behaviour CI observed as a positive claim rather than leaving it to be discovered as a failure.
