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

*The test that recognises each — and the one that does not.* The first four are mechanical, so the tell
is executable and a guard can hold it: mutate the code the test covers and watch the suite. The fifth
has **no test, and cannot have one**, which is worth stating rather than leaving as a gap. Every other
entry in this file names a guard because the thing being checked is a property of the CODE; "the
asserted answer is the wrong answer" is a disagreement between a test and the contract, and the
contract is not in the repository in a form anything can compare against — a guard for it would need
the correct behaviour written down somewhere the test is not, which is just the test again. What
carries it instead is a review question, asked where the risk is concentrated: a test born in the same
change as the behaviour it asserts inherits that change's misunderstanding, and neither its author nor
a reader diffing the two can see it, because they agree. Three reviewers passed the shipped instance.
So for that population the reviewer restates the contract from the design docs BEFORE reading the
assertion, and the four mechanical siblings above are the ones the tell is allowed to settle.

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

The implicit `403` is the same shape one layer over. Three producers each ask whether an authorization
gate's body could ever refuse, and each answered a body it could not read differently: the FormRequest
arm asked the ENGINE and read silence as a gate that never refuses, `GateDenial` asked the SOURCE for a
single literal `return true;` — a strictly weaker read, privately re-implemented — and read the same
silence as a gate that CAN refuse, and the Laravel Actions arm asks nothing at all. Nothing said the
three disagreed, and the weakest of them was a copy of the strongest. `GateBody` is the seam now, with
the three rows written down in its own docblock; `GateBodyTest` is what recognises a producer that
stops asking through it.

*The tell.* One fact computed independently at more than one site, where the only guard holding the
sites together reads their INPUTS. A second tell is prose: this rule was stated in five places, and
three of those paragraphs certified behaviour the code did not have. Where the callers' defaults
legitimately differ, the seam answers three-valued and each caller collapses it at its own call site —
in the schema-diff case all four disagreeing sites were wrong, while in the gate case two opposite
defaults are both right, because one decides whether to PUBLISH an error and the other whether to
REPORT on one.

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

The second instance moved a whole TABLE, and the vendor's contribution was not a signature but a
traversal order. `TraceDependencyTest`'s bound frontier asserts which facts the trace proves at a file
budget of 1, 2, 3 and 4, and the budget is spent on the two links of the traced action's chain,
`(new ExportIndexQuery)->query()->paginateList(25)`. php-parser reports one start offset for both links —
the receiver's — so the descent's sort by source position TIED, and a tie is decided by whoever handed the
nodes over: PHPStan's node-callback order for a chained expression, the very order the sort exists to
neutralise. The two fixture-matrix legs resolve different analyser versions, they answer that order
differently, so the query class and the custom terminal changed places and two rows read their facts at
swapped budgets — 8 of 8 on Laravel 12, 6 of 8 on Laravel 13, on a table whose whole job was to prove the
budget arithmetic had not moved. It had never once run on the plain leg: the frontier was written after the
last verification anyone did there.

The third was not an assertion at all but a DECLARATION, and so it failed harder. The row proving that
whatever the Gate's policy-resolution step raises is answered with silence provoked the raise by
subclassing the framework's `Gate` and overriding `getPolicyFromAttribute()` to throw. That method is
protected — not a promise the framework made to anyone, and a framework changes protected members in
patch releases — and a patch gave it a second, optional parameter. The one-parameter child then fails
PHP's own compatibility check when the class is DECLARED, which is a fatal rather than a failed
expectation: the paratest worker dies, the leg reports a crashed worker naming a file, and there is no
assertion to read. Every locked leg stayed green because the lockfile pinned the older framework, so the
two legs that re-resolve the tree went red on pull requests that had touched none of it — and the next
`composer update` here would have done the same.

*The tell.* A fixture whose assertion depends on what a VENDOR method's signature says, rather than on
what the fixture's own code says — a return type, a generic, a `@throws`, a by-reference parameter. Ask of
every real-engine row: which half of this answer is the fixture's, and which half is the installed
package's? Ask it of a COUNT and of an ORDER too, not only of a type: a row whose expected value is how
many files a walk had room for, or which of two hops it took first, is answering with whatever the vendor
tree contributes to that number. The sharp version, which would have caught the second instance where the
signature question does not: any tie in an ordering the product introduced to be deterministic — a sort
whose key two candidates can share hands the decision straight back to the library that produced them.
The declaration form has a tell of its own, and it is visible without running anything: a double that
RE-declares a member of a vendor type is asserting that member's signature, and a NON-PUBLIC one is an
assertion no vendor ever agreed to. Ask what the double is standing in for — a resolution step that can
raise is far more often provoked by data the step chokes on than by an override of it.

*The fix that worked.* Move the fact being counted out from behind the vendor's decision — for the first,
write the counted throw before the closure it is measured against, so no vendor return type governs
whether it is reachable — and say in the fixture why it is written that way, since the natural ordering is
the one that breaks. The second was fixed in the PRODUCT rather than the fixture, because the tie was the
product's: `SourceOrder` positions a call at its own name instead of at the receiver offset every link of
a chain shares, so a chain descends left-to-right on any analyser and the delegation is gone. The fixture
then restates its frontier from what the bounds MEAN, with a row beside it asserting that every file in
the counted path is one this repo writes and none of them sits under `vendor/` — the tell, executed. The
matrix leg is the executor; nothing in a single-version run can catch either, and a single-version run is
what a new table gets by default.

The third was fixed by deleting the double. A model annotated twice with a non-repeatable attribute is a
shape PHP accepts in the source and refuses only when something instantiates the attribute — which is
exactly what resolving a policy does — so the raise now comes out of the framework's own method on every
supported version, and the fixture declares nothing of the vendor's. Beside it, a model whose attribute
CAN be read resolves to the policy that attribute names, so the row cannot go quiet by ceasing to reach
the step at all, and the conventional policy the resolution falls through to is there and named, so the
silence cannot be a model nothing answers for.

*The guard that was not built, and why.* An arch test can find this shape statically: parse the test tree,
resolve each declaration's vendor parent, and ask reflection whether a redeclared method is one the parent
declares concretely. Run over the 1276 files of the test tree it finds 76 redeclarations — 63 public, of
which 32 are constructors, which PHP exempts from the compatibility check entirely, and the rest documented
extension points; and 13 non-public: this defect, plus 12 that cannot be written any other way, because the
product's own recovery reads the very method the fixture overrides (`Data::calculateResponseStatus`,
`Model::casts`, `Model::serializeDate`) or because it is the harness's own documented API (testbench's
`defineRoutes`, `defineEnvironment`, `getPackageProviders`). A guard whose allow-list is twelve times its
catch fires mostly where the reader can do nothing but excuse it, and the twelve carry the same exposure the
guard claims to remove: if one of those vendors moves a signature the product moves with it, and the fixture
failing loudly is the correct outcome rather than the one to suppress. Detection was never the gap either —
PHP checks every declaration against the installed parent on every leg, so the re-resolving legs ARE the
detector. What was missing is legibility, and that is paratest reporting a crashed worker rather than
anything a test of ours can state.
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
`DeclarationFiles` exists in core because this had already been met once, on inherited properties. Then
`Tracer` recorded the same `Callee->file` as its whole dependency set, so an allow-list or a page-size
written in a shared concern was harvested and published while no fragment depended on the file it was
written in. It stayed that way one round longer than the throw analyzer's half because `$visitedFiles`
answered two questions at once — the dependency set AND the traversal budget — so recording the second
file would have bought a shorter trace; `TraceFiles` separates charging from recording, and
`ResponseShapeRefiner` still owes the same split (`DescentBudget::$files` is both ledgers, and a shape
recovered from a trait-written body names only the using class's file).

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

## An illustration read against fewer keywords than its schema states

A generated example is published BESIDE the schema it illustrates, so whatever validates the document
holds it to that schema — here the build's own `ExampleAudit` (`lint.example-mismatch`) and the vendored
OAS meta-schemas. A producer deriving its value from a SUBSET of the keywords therefore does not degrade
gracefully into vagueness: it publishes a body the server refuses, and the build then reports the
mismatch to an author who never wrote the example and cannot correct it. Every instance so far has been
one producer reading `type` and stopping.

*Instances.* The error-example fill read `type` alone, four times over: `type: object` illustrated by a
PHP `[]`, which writes back as the JSON list `[]`; a member the document declares as a backed `enum` or
as a `date-time` illustrated `"string"`; a member carrying a numeric bound illustrated `0` beside a floor
of 5; and a member described by an `allOf` with no readable type at all, falling through to `"string"`,
which every branch of it rejects. Core's collection exporter had the same defect one keyword along — it
read `minimum` and nothing else, so `exclusiveMinimum: 0` published `0`, six times across the corpus.

*The tell.* Two producers computing "one representative value for this schema", one reading more keywords
than the other. What separates a readable keyword from the rest is whether it NAMES a value: `const`,
`example`, `default`, `enum`, `format` and the four numeric bounds each name one, so each has a legal
illustration to reach for. `pattern` and the length bounds constrain without naming, no constant satisfies
an arbitrary regex, and there the lint is the backstop rather than the bug.

*The fix that worked.* One table per fact, shared rather than reimplemented — `Core\Support\FormatSamples`
for a format, `Core\Support\BoundedNumber` for a set of bounds — and `ExampleValueAgreementTest` beside
them, which states the ladder independently of every implementation, puts every row to all three
producers, and pins the differences that deliberately stand as rows of their own. A keyword read at one
site and not the other then fails a test instead of reaching a document. The bounds table then had three
defects of its own, which is the next class below: one table per fact narrows what has to be right, and
does not make it right.

## An arithmetic step chosen for determinism, never checked against the set it had to land in

A value derived from constraints is derived by *steps*, and each step is chosen because it can be named
deterministically. That says nothing about whether it lands in the set the constraints describe — and
where it does not, the code reports the set EMPTY, which is the one answer that cannot be checked against
the constraints it came from.

*Instances*, all three in the one 140-line ladder from a set of numeric bounds to a value they admit.
The step off an exclusive `number` bound was `x + 1`, legal and deterministic and a whole unit too far:
`{exclusiveMinimum: 0, exclusiveMaximum: 1}` — a probability, inhabited by 0.5 — came back "no number
validates", and an exported collection drops a query parameter whose schema admits nothing, so the
consumer never learned the parameter existed. The step onto a `multipleOf` was `ceil()` alone, so a
value dropped to a CEILING was rounded away from the range: `{minimum: -10, maximum: -5, multipleOf: 3}`
reported empty with -6 legal. And nothing re-asked whether the finished value was a number at all, so
two finite bounds a step apart overflowed to INF — which one emitter refuses outright and another
stringifies to `"INF"` — while the `integer` arm cast it and emitted a PHP warning from a shipped code
path, an exception under any warnings-to-`ErrorException` handler.

*The tell.* Not a missing oracle: every row of that ladder's dataset was validated against the keywords
by a JSON Schema validator that knows nothing about the implementation. The rows were the whole set of
*answers a producer had been seen to need*, and an emptiness answer is where a wrong step and a genuinely
empty set are indistinguishable — so the missing rows were the inhabited sets nobody had reached yet.
Ask, of any `null`/"no value" branch: is this a fact about the INPUT, or about the walk that gave up?

*The fix that worked.* The emptiness rows assert the other way round — every value the ladder could have
arrived at, the bounds it states and the midpoint between them, is put to the validator and refused, so
`null` is proven to be a fact about the bounds. The step off an exclusive bound became "one whole unit,
or half the room to the opposite bound where a unit would leave the range", and the step onto a multiple
tries both directions, taking the one away from the pressure first. And the `null` contract was split in
the docblock, because it had grown a second meaning: a set nothing inhabits, and a value only an
unrepresentable double could be. `BoundedNumberTest` is the oracle; `ExampleValueAgreementTest` holds the
three producers that read the ladder to one answer.

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

## A middleware read by one of its two spellings

A route names a middleware by its registered alias or by the middleware's own class name, and those are
two spellings of one thing. Every static constructor the framework ships for a middleware that takes
arguments renders `static::class.':'.$arguments` — `Authenticate::using('web')`, `Authorize::using()`,
`ValidateSignature::relative()`, `EnsureEmailIsVerified::redirectTo()`, and a route may simply list
`Middleware::class` besides. A reader that knows one spelling therefore sees no middleware at all on a
route written the other way, and the route is documented as if the middleware were absent.

*Instances.* The authorization signal read `can` only, so a `403` the route really enforces went
missing; `signed` and `verified` the same, and the reachability check then reported a `403` the signature
genuinely denies. Worse, the authentication signal read the `auth` alias only — in three separate readers
(the `auth_middleware` wildcard, Sanctum's mode detection, and the guard→driver resolution behind
both Sanctum and Passport) — so a route behind `Authenticate::using('web')` published no `401` and no
security scheme: not an under-described error but a misdescribed endpoint, read by a consumer as public
and by a generated client as needing no credential. The subtraction side had it too: a
`withoutMiddleware()` exclusion was subtracted by literal string, so opting out of the authenticator in
the spelling the group did not use left the `401` on a route that really does run unauthenticated.

*Which spellings those are is the APPLICATION's fact, not the framework's.* An application registers
`auth` against its own `Authenticate` subclass — the Laravel ≤10 skeleton does, and every application
upgraded from one carries it — and a reader holding the framework's alias map then gets both directions
wrong at once: the subclass spelled by class name is a middleware nobody recognises, so the route is
published public; and the framework's own authenticator is no longer what `auth` resolves to, so an
exclusion naming it removes nothing while a document that subtracted it anyway drops a `401` the server
does enforce. `class_exists` is a presence check and a hardcoded family is a guess at a map: the map has
to be read off the router.

*And the map cannot be the answer either, because normalising INTO one vocabulary is the same defect
inside out.* Rewriting each gathered entry to the alias the application registered for its class fixed
the authenticator and broke everything else at once: a route naming Sanctum's `CheckAbilities` under the
application's own `token-abilities` alias came out under a name no reader has a row for, so the
abilities, the scopes and the role all disappeared while the server went on enforcing them. A rewrite is
lossless only for a reader that speaks the vocabulary it rewrites into, and these readers speak two.
What the rewrite was reaching for was a fact about the CLASS — a middleware extending the framework's
authenticator authenticates the way its parent does — which needs no map at all and so answers the same
in the console context where the router holds none.
*The tell.* A comparison against a middleware string — `===`, `str_starts_with($entry, 'x:')`, an
`fnmatch` over a pattern written in alias vocabulary — where the name being matched is an alias and no
class name sits beside it. The related tell is a user-facing pattern over that vocabulary: it cannot be
asked to spell an FQCN, so the fix is to match it against every spelling of the middleware rather than to
widen the pattern. And a third: an equivalence used for both of the two questions here, which are not
the same question. "Does this string name authentication?" wants the generous reading, since a name that
authenticates owes a `401` however it is spelled. "Are these two strings the same middleware?" wants the
framework's own, which compares its resolved names with their arguments attached and gates its subclass
fallback on `class_exists` — so `auth` and `auth:` are two middleware to it, and reading them as one
subtracted a `401` nothing had excluded.

*The fix that worked.* One reader of the grammar (`MiddlewareName`: alias or class name, bare or
`:args`, a leading `\` trimmed), and one list per middleware read through it — `CanGate` for the
authorization middleware, `AuthMiddlewareNames` for the authentication family, where every question is
a function of `spellings()` so no two of them can answer one string differently. For the equivalence
question, `MiddlewareResolution` mirrors `Router::resolveMiddleware()` through the application's alias
map, which also brought the `throttle` and `can` exclusions — the same defect, never reported — into
agreement with the framework. The datasets assert both spellings against ONE expectation rather than
each separately, because a reader that answers them differently is the defect; the hand-maintained
family is read against the framework's own alias map so a fourth `auth*` alias cannot leave it short;
the pattern is read with the product's one wildcard grammar rather than `fnmatch`, which treats a `\` in
the pattern as an escape; and the entry a route wrote is what is handed on, so the vocabulary is never
narrowed on the way to a reader. What recognises the class is the corpus rather than a unit assertion:
the two spellings of one middleware side by side under byte-lock, a differential against the real
`Router` for every exclusion shape (`WithoutMiddlewareTest`), and a published-document guard per family
of reader for a middleware the application aliased. See also
[A partition that covers everything and agrees on nothing](#a-partition-that-covers-everything-and-agrees-on-nothing):
the fix here is that entry's fix — one seam, every reader through it — and the family predicate that
read the map a second way is exactly its tell.
## A digest that normalises what its reader walks in order

A cache key exists to say "this build is the same build". Sorting or deduping the records it hashes is
how it stops churning on a change nobody can see — and it is also how it stops seeing the one property
the reader actually consumes. Where the thing being keyed is resolved by walking a collection and
taking the FIRST match, or by mutating through it in sequence, order is not noise: it is the answer.

*Instances.* Policy resolution ends in `getPolicyFor()`'s subclass walk, which takes the first
registration whose subject the model is a subclass of; morph aliasing ends in `array_search($fqcn,
morphMap(), true)`, which takes the first alias for a class. Both mirror the framework correctly, both
were keyed by a digest that sorted its records first, so two registration orders produced one digest and
a warm build replayed a fragment computed under the other resolution — and the morph half reaches
published bytes, since the alias it resolves to is a discriminator mapping key. The third is core's own:
`ResolvedExtensions::cacheSignature()` sorted one entry per resolved instance while every chain reading
those instances is first-match-wins (`RouteContext`'s six resolvers, `SchemaConverter`'s mappers) or
sequential mutation (`OperationPipeline`). Its docblock had already closed identity and multiplicity
deliberately; order was the property left open, and `ExtensionSorter` decides it from the registration
index whenever two instances are of one class — which is every such pair, because `ExtensionOrder` is
`TARGET_CLASS` and `before`/`after` name classes. The fourth was found by sweeping core for the tell and
publishes a NAME rather than keying a cache: `ComponentNames::award()` sorted claims by discriminant
alone, so two claims agreeing on it — one identity claimed twice, or two unidentified claims of one body
— tied, and `usort` being stable handed the plain name to whichever registered first and the `_2` tail
to the other. The class owning the rule that a published name is never a function of arrival was
deciding one that way, and its own docblock said the tail was already settled by the contesting set.

*The tell.* A `sort()`, `ksort()` or `array_unique()` immediately before a `hash()`, with a `foreach` in
some other file that `return`s out of its first match over the same collection. The sharper form asks it
of the ordering itself: where a sort's key can TIE, whatever produced the input decides — and a key
derived from arrival is that tie by construction, so a docblock promising order-independence beside a
tie-break on the original index is falsified by its own sentence. See also
[A node located by line, where the offset is its identity](#a-node-located-by-line-where-the-offset-is-its-identity),
which is the multiplicity half of the same shape: a key that collapses two records the reader needs apart.

*The fix that worked.* Carry order only where order can be observed, because dropping the normalisation
outright makes every reorder a cold rebuild for the overwhelming majority of applications, where it
changes nothing. For the walks, that is the registrations the walk can reach at all; for the extension
signature, the members of a same-class run, each carrying its position in that run while every other
entry stays order-free. Anything undecidable answers yes: over-keying costs a rebuild, under-keying
serves a stale document. Both directions need holding, and by separate guards — a fix that keys every
order passes the recognising test and fails the product. `ExtensionSignatureTest` states the pair: two
differently-configured instances of one class in both registration orders key differently and reach
different fragment keys, while two instances of DIFFERENT classes, and two indistinguishable instances
of one class, key alike whichever order they arrived in — asserted on the signature directly, since
putting them through the sorter would pass whether the signature read order or not. Beside them, an
entry no sibling contests is pinned as BYTES, so nobody pays a cold rebuild for a run they do not have.
`ExtensionSorterTest` holds the other half, that the residual order is real and author-controlled, so
the day the sort becomes arrival-free it says the position has gone redundant.

Where the answer is a published NAME the trade-off does not apply, because there is no cache to churn:
give the comparison somewhere intrinsic to fall through to instead. `ComponentNames::award()` reads the
claim's content and then the registration name it arrived under — both data the claims map holds, the
second being its keys — so the ordering is total over the set with nothing left to arrive. Its guard is
a dataset of the pairs that actually tie, and it stands where no golden can: the registry upstream
cannot form such a pair, so the reachable seam is the public `mint()` the test calls. A guard written
over claims with different identities, which is what was there, never reaches the tie-break at all.

## A map the test harness fills that the product's own context leaves empty

The adapter reads facts off live framework objects, and which facts are ON those objects depends on how
the application was booted. Testbench boots one way and `artisan` boots another, so a reader can be
correct in every test and blind in production without a single test disagreeing with it. The corpus is
then not under-covered but SILENT: the population the product runs in is unrepresented, and no route
added to the standard harness reaches it.

The concrete asymmetry is `Illuminate\Foundation\Http\Kernel::__construct()`, which is what calls
`syncMiddlewareToRouter()` and so what writes the alias map, the middleware groups and the middleware
priority onto the router. Testbench resolves that kernel before the first test. A documentation build is
an artisan command and resolves the CONSOLE kernel, which writes none of it — measured on a stock
Laravel 12 application booted that way: every route present, no aliases and no groups at all.

*Instances.* The alias map first: a route naming its authenticator by class where the application had
aliased its own subclass came out public, and a `withoutMiddleware()` written in the other spelling
subtracted nothing. Then the group map, which is the same asymmetry one layer out and worse, because a
group's contents are the application's own data and there is no default table to fall back on — so a
route INHERITING its middleware, which is the idiomatic shape, had that middleware read as an opaque
name. Measured on a conventional API surface: six of nine routes lost everything they inherited — the
`api` group's `throttle` and therefore the `429` and its rate-limit headers, Sanctum's stateful
middleware and therefore its scheme, `auth:sanctum` and therefore the `401` and the security
requirement. The one route writing its middleware itself was unaffected, which is exactly why the
defect survived: the harness's routes wrote theirs.

*The tell.* A read of a live framework object whose contents were put there by a lifecycle step, where
the step that puts them there is not the step the product performs. `$router->getMiddleware()`,
`$router->getMiddlewareGroups()` and `$router->middlewarePriority` are the three this kernel writes;
anything the kernel holds and never syncs — its global middleware — is invisible to both contexts alike
and is a feature gap rather than an instance of this. The second tell is a golden that MOVED when the
fix landed only under a boot no committed document performs: stillness across the corpus is then
evidence that the corpus has no fixture in the population, not evidence that nothing changed.

*The fix that worked.* Perform the lifecycle step rather than reconstruct what it would have written:
`MiddlewareRegistrations` resolves the HTTP kernel once, for its effect on the router, and reads both
maps off the ROUTER afterwards — which is also where a service provider's own registrations land, and
which keeps the grammar a function of the version the application resolved rather than of a table copied
into the adapter. A resolution that cannot be performed degrades to whatever the router holds and says
so, because a route published with no middleware because a map could not be read is a confident false
claim rather than a vague one. What recognises the class is the harness rather than an assertion:
`refreshWithoutHttpKernel()` rebuilds the application in the product's own boot state and ASSERTS that
state against what a real console boot was measured to hold, and the suite standing in the population
reads one application twice — once unsynced, once synced — against one golden, so a reader that only
comes out right when something else constructed a kernel first cannot pass.
## A digest that normalises away a distinction its reader keeps

A digest stands in for a thing, and the normalisation that makes it stable is a claim about what does
not matter. Where a reader downstream treats one of the dropped members as significant, the digest hands
one name to two things — deterministically, so no byte comparison and no golden will ever see it — and
the reader answers about whichever of the two it happened to meet.

*Instances.* The id every id-less `components.schemas` entry published was minted by the INLINE-schema
rule, which strips `description`, `title` and `example` and sorts `required` so an inline schema survives
a cosmetic edit; the registry that decides whether two registrations are one component compares their
bytes in full, so a pair differing only in prose stayed two published nodes under one id, and
`ContractIndex::identities()` can address only one of them — a consumer asking which code produced a
component is told about the other. See also
[A digest that normalises what its reader walks in order](#a-digest-that-normalises-what-its-reader-walks-in-order),
the sibling axis: there the dropped member is ORDER rather than content, and the reader walks the
collection instead of comparing it.

*The tell.* Two sites, one hashing and one comparing, with two statements of what makes two things
different. The mint's own docblock is often the evidence: `IdentityGenerator::publishedSchemaId()` said
in as many words that the inline mint "cannot serve here", beside a caller that used it. Ask, of any
digest: name the reader, then name the member the digest drops that the reader keeps.

*The fix that worked.* One statement of the difference, and every site derived from it.
`ComponentRegistry::claim()` is the whole of what makes two registrations two components — the name asked
for, the identity behind it, the bytes published — and the merge decision, the published name and the
node id all read it, so a member added there reaches all three. The guard is stated off the DOCUMENT
rather than off either site (`ComponentIdentityTest`): every entry of `components.schemas` is a published
node, so an id two of them carry addresses neither, asserted over a dataset of pairs differing on each
axis in turn, with rows for what the registry DOES merge so a mint that simply numbered its registrations
would fail too. A guard that asks either site for its own rule agrees with whatever that site does.

## A cached answer keyed by the name its author was resolved by

A fragment holds what a collaborator ANSWERED, and the key holds how that collaborator was NAMED. The two
move independently: swapping the name moves the key and the answer together, so the obvious edit is
caught, while editing the collaborator's body — the edit its author makes far more often — moves the
answer and nothing else. Determinism is untouched and every golden holds, because a golden is one build's
bytes and this is the second build disagreeing with them.

*Instances.* `documents.*.tags.mapper` names a class the container resolves, and `mapTag()` runs inside an
`OperationExtension`, so the mapped tags live in the operation fragment; the config bag hashed into the key
held the class-STRING, which an edit to that class never moves — a warm build published the tags the old
body produced. The sibling is `ResolvedExtensions::cacheSignature()`, which pairs each resolved extension
with its composer package's version as a proxy for its behaviour: sound for a package, and inert for a
class in the application's own tree, whose "package" is the root and whose version does not move when the
file is saved. Neither is the same defect as an input that reaches NO key input at all — `lint.leakage`
decides whether a recorded example is published at all, and it is neither in a document's config bag nor
in any digest contributor.

*The tell.* Name the thing whose output a fragment holds, then ask what in the key changes when its code
changes — not when its NAME or its VERSION changes. A collaborator resolved by string, a class-string in
config, a `Closure` in a config bag (`Json::stable()` collapses any object to its class, and reads a
closure as file plus line span), a package version standing in for a body: each answers "nothing".

*The fix that worked.* Key the fragment on where the answer is WRITTEN, at the point the answer was read.
`TagMapperKeying::record()` puts the mapper's `DeclarationFiles` — its own file, its parents', its traits'
— into the route's dependency manifest, and it is called from the two places a tag actually goes through
the mapper, so a route the mapper never answered for records nothing and stays warm. Where the mapper's
declaration cannot be hashed back (`eval()`'d code reports a file no `is_file()` matches), the fragment is
refused the cache rather than keyed on nothing: a manifest records an absent file as ABSENT, which
compares FRESH for as long as it stays absent, so recording an unhashable path looks keyed and is not.
The guard reads the manifest the cache STORED and the freshness the cache itself answers
(`TagMapperCacheTest`), because a rebuild count cannot say which fragments an edit retired.

*The half a file cannot hold.* Keying on where the answer is written closes the edit and leaves the
CONSTRUCTION open: two instances of one class share every file there is, so a collaborator resolved from
a container binding — the shape `tags.mapper` documents, and the only shape a mapper needing anything but
constructor DI can take — answers differently on every value with a byte-identical key behind it. What an
instance was handed is a VALUE, and a dependency manifest holds only files, because it is validated by
re-hashing what it names. So the two halves go in two places: files into the route's manifest at the point
of use, state into the document-level part of the key (`ConfigurationDigest`, the digest
`ResolvedExtensions::cacheSignature()` already read every extension's own properties with). Files stay
local and state cannot be — the collaborator is resolved once per document, and nothing before the lookup
knows which routes it will answer for, so over-keying there buys a rebuild where under-keying publishes
the old answer. Neither digest reaches an emitted byte: an anonymous class names the absolute file it was
written in and a closure names its line span, which is a cache key on one machine and never a document.
`DocumentCollaboratorKeyingTest` reads the collaborator set off `DocumentConfig`'s own constructor and
makes each member state what keys it, so a third one arrives as a failure rather than as a member nobody
asked.
