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
carelessness. The fifth arrived later, out of a shipped regression, and is the shape the tell below
does not catch.

- **Structurally incapable of failing.** `expect($surface)->not->toContain($a, $b)` inverts the whole
  call, so it passes as soon as ONE name is absent. The two names in it were covering for each other.
- **A universal proved over a sample.** An "every other type" test hand-picked 9 of 27 identifier
  keywords while the source of truth sat in the same file, unused. Forking 10 of the 27 passed green.
- **A sweep with nothing holding it.** A correct change applied to seven call sites had one pinned;
  reverting the other six passed the whole suite.
- **An invariant stated only in prose.** Reversing a `sort()` whose docblock forbade order-dependence
  passed all 8327 tests.
- **Aimed at the wrong answer.** A test written in the same change as the behaviour it asserts. A
  tier that had folded a body and a media type from the app's own handler declined on an unfoldable
  status, and the same change added a passing test named for that decline. It could fail, and it
  asserted something — the wrong thing — and three reviewers read it as a correct guard.

*The tell.* Ask what would have to break for this test to fail, then break it. If the suite stays
green, the test is decoration. A dataset proves the rows it lists, so a hand-maintained list owes a
separate guard that reads the source of truth. That question misses the fifth, which breaks fine and
is merely pointed at the wrong invariant: for a test added alongside a behaviour change, ask
separately whether the answer it asserts is right, not merely whether it can fail.

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

## A fixture that agrees with the vendor major it resolved

The sibling of the class above, one layer out: not the machine, the vendor tree. A fixture's expected
answer is decided by a package's phpdoc, that phpdoc differs across the majors the product supports, and
the run that proves the fixture installs exactly one of them.

*Instances.* `ThrowsController::nestedClosureThrownStatus` counts the closure hop's depth budget by
putting one throw three closures in and a second one deeper, and asserting only the first is read. It was
written with the counted throw AFTER the deeper closure. `Connection::transaction()` returns `mixed` up to
Laravel 12 and is generic over its callback's return from Laravel 13 on, so a closure that only ever
throws makes the call `never` and the statement after it unreachable: the row read 423 on the Laravel 12
legs and surfaced nothing at all on the Laravel 13 one. Every local run and every other CI leg was green.
The product side of the same class already has a rule in `CLAUDE.md` — an integration emitting its own
major's grammar rather than the resolved one.

*The tell.* A fixture whose assertion depends on what a VENDOR method's signature says, rather than on
what the fixture's own code says — a return type, a generic, a `@throws`, a by-reference parameter. Ask of
every real-engine row: which half of this answer is the fixture's, and which half is the installed
package's?

*The fix that worked.* Move the fact being counted out from behind the vendor's decision — here, write
the counted throw before the closure it is measured against, so no vendor return type governs whether it
is reachable — and say in the fixture why it is written that way, since the natural ordering is the one
that breaks. The matrix leg is the executor; nothing in a single-version run can catch it.
## A member reached through inheritance, read as though it were the class's own

PHP hands an inherited or trait-imported member back looking like the class's, and a reader that asks
reflection one question gets an answer about the wrong file — or a reader that scans only the class's own
declared code never sees half of what builds it. Both halves publish a confident answer for a class the
code contradicts.

*Instances.* `HttpExceptionStatus::agreed()` folded the `new`s a class writes of ITSELF out of its own
file alone, so a subclass with one factory at 413 under a base whose `new static(503)` also builds it
answered 413 — a precise false status, at exactly the throw points (a trait's guard, a `@throws`, a
rethrow) the read exists for. Its trait gate asked `getTraitNames()`, which reports the class's OWN traits,
so a base's trait was invisible to it. `FactoryStatus` refused an inherited factory outright, so the two
readers of one question answered differently about one class. And `Callee->file` is
`getDeclaringClass()->getFileName()`, which for a trait's method is the USING class's file: the `throw` and
the `@throws` a shared guard clause is written with were read from a file no fragment ever depended on.
`DeclarationFiles` exists in core because this had already been met once, on inherited properties.

*The tell.* A walk, a scan or a `getFileName()` that stops at the class the question was asked about,
beside a claim in the docblock that it covers everything the class does. Reflection will not object: it
answers about the class, and the class honestly reports the parent's member as its own.

*The fix that worked.* State ONE rule for what belongs to the class — for a construction, "written in its
own declared code or in a class it inherits from, with `new static` binding late and `new self` binding to
the class the line sits in" — and make every reader of it obey. Read the whole hierarchy or read none: an
ancestor whose file cannot be opened, or one using a trait, leaves a member unseen, and a partial set is an
answer the class may not have. Where the fact is a FILE, ask the member rather than the class
(`ReflectionMethod::getFileName()`, `DeclarationFiles`), and record both. The probes are the guard: a
subclass under a base that also builds it, a base building `self` rather than `static`, and a base carrying
a trait — each of which flips when the walk is removed.

## A node located by line, where the offset is its identity

A line is not a position. Two nodes written on one line are two nodes, and a map keyed by line silently
keeps the last of them — deterministically, so no golden and no byte comparison will ever see it.

*Instances.* `FileAnalyzer` harvested closures into `$closures[$node->getClosureExpr()->getStartLine()]`.
While the only consumer located a render callback by `ReflectionFunction`'s file+line the collision was
rare; the moment every closure argument at every throw point became a consumer,
`$this->guard(function () { throw A; }, function () { throw B; })` resolved both arguments to the second
body and A vanished from the document with no diagnostic. The same file's `scopeAtCall()` had already been
written the other way, with a docblock saying why: an offset is unique per node and survives a re-parse
where an object handle does not.

*The tell.* An AST map keyed by `getStartLine()` whose consumer holds the node itself — it has an offset
and is throwing it away. The related tell is a lookup that CANNOT hold the node (reflection gives file and
line and nothing else) and silently picks one of several matches.

*The fix that worked.* Key by `getStartFilePos()` for every consumer holding the node, and give the one
consumer that has only a line an explicit ask that DECLINES when the line carries more than one — the
degraded-but-true answer, since nothing at that call can tell them apart. Both halves are pinned by
fixtures that put two of the thing on one line: two closures at one call, and two render callbacks in one
`return`.
