# Defect classes

Patterns this codebase has hit more than once, with the test that recognises each. The binding rules
live in [`CLAUDE.md`](../../CLAUDE.md); this is the catalogue a reader consults when something feels
familiar. Add a class when a second instance turns up, not on the first.

## A diagnostic that asserts an outcome it never reads

A producer knows what IT recovered. It does not know what the document ends up saying, because a
later layer — a validation rule, a docblock, an attribute, another integration, an overlay — can
still answer for the same node. A message written in the document's voice (*"is documented as…"*,
*"is omitted from…"*, *"publishes no…"*) from a producer that cannot see the finished node is a
claim that will eventually be false, and the reader it lies to is the one who already did what the
help asked.

The failure mode is worse than a wrong sentence. A report whose recommended remedy is **already
applied** cannot be cleared by any edit, so a team that gates CI on diagnostics has two honest
options: accept a permanently noisy code, or stop reading the channel. Both cost them the true
positives, which is how one false report takes the useful ones down with it.

*Instances.* `query-builder.untyped-filter` decided from the Spatie filter kind that a parameter
would reach the document untyped, and said so, while a form-request rule and a route-level
`#[QueryParameter]` were still to land — measured at 5 false of 16 firings on a real application,
two of them naming the very attribute the help asks for, already present on the same action.
`eloquent.no-columns` tested the property list before appends, accessors and eager loads had each
had their chance to fill it — contradicted not by a later layer but by the rest of its own method.
`validation.rule-unrecoverable` said a field "is omitted from the request schema" while
`#[BodyParameter]` published it; its class-based twin had been hardened for other producers and
still could not see that one. `eloquent.custom-date-serialization` is the variant with no later
layer at all: it fired on the override flag alone, without checking that the model published a date
attribute for the claim to be about.

*The tell.* The message is in the document's voice, and the raise site is below the layer that
decides. Grep the message, not the condition: *documented as*, *omitted from*, *publishes*, *is not
documented*. Then ask two questions — can any later producer write this node, and is there an edit
the reader could make that leaves the report standing?

*The fix that worked.* Three different answers, and which one applies is decided by what the
producer holds, never by preference:

- **Read the outcome.** Record the candidate while the producing pass runs, and report from a
  `Finalize` pass that reads the node as it finally stands (`QueryBuilderUntypedFilterExtension`).
  It needs somewhere to look, and the address is the PUBLISHER's to state rather than something both
  sides re-derive: the note carries where the value was actually written. The first attempt had the
  two passes compute one address from one shared helper, which is the same defect one layer down —
  and it fails in the worst direction, because a report addressed at a node nobody wrote finds
  nothing and stays SILENT. It survived its own review: at the package's default parameter names
  both computations agree, so every test and every golden passed, and only a build under renamed
  config showed the report vanishing.
- **Consult the layer that could answer.** Cheaper and correct where exactly one later producer
  exists, and the repo already did this in four places before the class was named —
  `PathParametersExtension::declaresType()`, `RecoveredRequest::declaredOn()`,
  `InferredResponsesExtension::named()`, `ImplicitResponsesExtension`'s post-synthesis
  `hasResponse('403')`. Where several producers answer, compose the reading ONCE beside the writer's
  own grammar (`RecoveredRequest::declarationsReaching()`), or the notes drift apart one at a time.
- **Say what the producer knows.** When the payload carries no key to look anything up by, an
  outcome check is not merely unwarranted, it is impossible: `query-builder.unresolved-entry` names
  a call site precisely because the expression that would have named the entry is the one that did
  not fold. The loss to the recovery is still knowable, still unfalsifiable, and still what the
  reader acts on. Change the sentence, keep the condition.

*The trap in miniature.* `publishesNoType()` — a predicate named for the document, computed from one
integration's own output, with a docblock calling itself "the one reading of it". One reading, of
the wrong thing. It is now `typesNothing()`, which is what it actually answers.

*The tests that recognise it.* `QueryBuilderUntypedFilterTest` (a filter typed by an attribute and
by a form-request rule, against one typed by nothing, through a real build),
`QueryBuilderUnresolvedEntryTest` (asserts the document publishes the parameter, so the old sentence
would have been false there), `DeclaredFieldNoticesTest`, and the appends-only / eager-load-only /
inherited-override fixtures in `EloquentTest`. Each was executed against the pre-fix code first: a
guard nobody watched fail is a guard nobody has.

*Not every one of these is a defect.* `query-builder.partial-on-enum` makes the same shape of claim
and was deliberately left: an author who types a partial filter as an enum publishes a set NARROWER
than the server accepts, so the nudge is still right, and standing it down on the outcome would hide
a real under-description. What exempts it is that the claim keeps its point once falsified, never
that the help mentions the overlay: the help is where a reader learns about a trade, not where a
sentence gets to be false. The other exemption is a producer that decides the node itself —
`webhook.method-unknown` substitutes the method in the same expression, and the content of a bodyless
status is dropped at the write for EVERY producer, so `attribute.body-on-bodyless-status` states an
invariant rather than a guess about a later layer.

*The sweep the entry cost.* Eight sentences moved into the producer's voice once the class was
written down: the three the engine raises (`inference.response-shape-truncated`,
`inference.http-exception-status-unread`, `inference.descend-scope-narrowed`), each falsified by a
`#[Response]` the engine is structurally unable to see; `webhook.payload-unresolved`; the three
spatie-data wrap and mapper notices; and the two branches of `config.format-sample-rejected` that
spoke for the field. The config-side branch of that last code kept its wording — it reports a setting
being ignored, which is its own act rather than a node somebody else may still write.

Three more followed, and the last one shows why the outcome has to be reproduced rather than reasoned
about. `query-builder.legacy-package-version` claimed the sort/include/fields lists were "documented as
plain strings" while an `in:` rule in a form request publishes an enum over any of the three.
`eloquent.custom-date-serialization` claimed, at its path-segment site, that "the parameter is documented
as a plain string" — and a build proved the document publishing `format: date-time` on that very
parameter from a `#[PathParameter]`, with the notice still speaking. Its HELP was the worse half: "no
annotation puts one back" was written for a response column, where it is true, and carried to a path
segment, where the attribute it denies is the one remedy. The model-schema site says the same thing
about a component nothing but an overlay can answer for, and it moved too — one code may not speak in
two voices depending on which site raised it. What did NOT move is `eloquent.no-columns` beside it:
that one is asserted against the FINISHED property set, which is the first fix, not this defect.

## A subtraction leaves no evidence

An **additive** declaration that reaches nothing shows up as an absent node — you can see it missing.
A **subtractive** one produces exactly the document a working one produces, so the failure is
invisible by construction.

*Instances.* `#[IgnoreParam]` and `#[IgnoreResponse]` dropped nothing on a stale name and said nothing.
`#[Hidden]` was worse: a name matching no property hides nothing and so **publishes** the field the
author marked as not-for-publication. `#[InDocs]` inverts — a key naming no configured document
excludes the route from *every* document rather than pinning it to one. And the variant where the name
is right and the GRAMMAR is short: under `representation.filters: deepObject` a filter is a member of
one `filter` object rather than a parameter of its own, so `#[IgnoreParam(name: 'filter[opaque]')]`
reached a parameter that does not exist and the filter stayed published — the report was honest and the
capability was simply absent, which is the same document either way.

*The tell.* An author-supplied name reaching a minting or mutating accessor with no `has*` guard.
`#[Example]` is the counter-example that gets it right: it calls `hasParameter()` first and reports
`attribute.example-target-missing` on the fall-through.

*The fix that worked.* Say the declaration as written, say it took no effect, and name what the
operation *does* document so the typo is visible beside it. Judge matches BEFORE the removal, or the
second declaration naming one parameter reads as having reached nothing.

Where the grammar was short, the fix is that the subtractive reading and the additive one are ONE
reading of where a name lands (`DeepObjectMembers`), so a declaration cannot be a member for the
producer that writes it and a parameter for the pass that drops it — and the report lists the members
beside the parameters, since the reader's typo is in the half the old list never showed. Two things
travel with a removed member: the parent's `required` list, which is the only lie a removal can tell
(a request required to carry a value the document does not describe), and hence the container's own
requiredness, which is derived from that list at one site rather than restated. A subtraction is
applied at freeze rather than written through the guard, so nothing outranks it — the reading a whole
parameter's removal already had, for the same reason.

*The tests that recognise it.* `DeepObjectMemberRemovalTest` (the removal, the `required` reconciliation,
a nested member, a member published as one declared keyword rather than as a draft, and a row per shape
of "no such member" asserting the operation is BYTE-identical afterwards, because the additive walk mints
what it does not find), and the deepObject rows of `IgnoreParamTest` — a whole build, with a committed
golden pair standing in the affected population, since the corpus had no deepObject route an author had
subtracted from.

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

The fourth was the product's own copy of one of these steps, and it failed in the quiet direction.
`GateInternals::policyClassFor()` mirrors `Gate::getPolicyFor()` so a build can learn which policy backs
a `->can()` gate without constructing one, and it walked the four branches Laravel 12 resolves through.
Laravel 13 added a fifth — the `#[UsePolicy]` step again, this time over the model's PARENTS — so a model
whose policy comes only from a base class's attribute resolves in the framework and answered nothing in
the mirror. Nothing anywhere went red. A mirror gone short degrades to silence, which is the safe
direction and publishes no false claim, and silence is also what a gate with no policy at all looks like:
the locked legs were right to be green, since on 12 that silence IS the framework's answer, and the
re-resolving legs were green too because no row asked the question on either version.

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
The product form's tell is a COUNT and not a signature: a mirror of a vendor algorithm carries a number
of steps, and that number is a fact about the version the application resolved rather than about the one
the mirror was written against. Ask of any such mirror how many branches the installed vendor walks, and
what in the tree would notice if the answer grew — degrading to silence is the answer that no leg reports.

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

The fourth was fixed by reading the branch off the installed GRAMMAR rather than off a version: 13's
`getPolicyFromAttribute()` takes an `includeParents` flag and 12's takes none, so the signature the
framework shipped decides whether the fifth branch runs. `class_exists()` is a presence check both majors
pass, `method_exists()` on a known class the analyser folds to constant true, and a lockfile lookup would
answer about a package name the container was never asked for — the class that resolves may come from a
bare `illuminate/auth` and may be a subclass of either. The branches then became an ordered LIST rather
than four `if`s, so the mirror states its own count in code, and a guard reads `Gate::getPolicyFor()`'s
source for the framework's — every branch there ends in `return $this->resolvePolicy(...)` — and fails
naming both numbers when they disagree. A sixth branch is now a red row on whichever leg resolves it. No
diagnostic came with it: on 12 the silence is correct, so a notice would have fired only where the
document was already right.

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

A whole sub-family of it reads the class's own FILE as the vendor test — "the method is declared
somewhere other than here, so it must be the package's" — which is true only of an application that
writes no base classes and no traits. It landed twice over one vendor. A Data class taking
`calculateResponseStatus()` from an application base documented spatie's default, so a POST published
201 where the server sends 202; and a FormRequest whose `authorize()` sits on a shared base published
no 403 at all, on a framework that declares no `authorize()` anywhere and so had no default to mistake
it for. Under the ANALYSER the same test fails the other way round: PHPStan reports the class that
DECLARES an inherited override, whose own file is the method's file, so a base never looked like the
vendor — but PHP flattens a trait into the using class while reflection still names the trait's file,
which is indistinguishable from the package's own concern. Both Data return-type extensions therefore
spoke for a response an application had written once on a shared trait, publishing spatie's default
envelope and losing the status, media type and payload the refiner reads out of a hand-written
`new JsonResponse(...)`. Same misreading, opposite hierarchy — which is why the fix names the concern
rather than the shape of the hierarchy.
Beside those, class ATTRIBUTES: spatie's attribute collection walks the parent chain from the concrete
class, and the name-mapping read asked the class DECLARING the property instead — which is neither
end of the hierarchy — so an inherited property under a mapped subclass published a key no request or
response carries, in both directions and in both the request body and the response schema.

*The tell.* A walk, a scan or a `getFileName()` that stops at the class the question was asked about,
beside a claim in the docblock that it covers everything the class does. Reflection will not object: it
answers about the class, and the class honestly reports the parent's member as its own. For the
vendor-test form the tell is sharper and reads off one line: a file comparison whose right-hand side is
the SUBJECT's file rather than the vendor declaration's. Ask what the comparison would say for a class
whose base wrote the method — every wrong site answers "the vendor's".

*The fix that worked.* State ONE rule for what belongs to the class — for a construction, "written in its
own declared code or in a class it inherits from, with `new static` binding late and `new self` binding to
the class the line sits in" — and make every reader of it obey. Read the whole hierarchy or read none: an
ancestor whose file cannot be opened, or one using a trait, leaves a member unseen, and a partial set is an
answer the class may not have. Where the fact is a FILE, ask the member rather than the class
(`ReflectionMethod::getFileName()`, `DeclarationFiles`), and record both. The probes are the guard: a
subclass under a base that also builds it, a base building `self` rather than `static`, and a base carrying
a trait — each of which flips when the walk is removed. For the vendor test, name the vendor's own declaration and
compare against THAT — `DataResponseStatus::concernFile()`, `VendorConcern::provides()` — and where the
framework declares nothing at all, having the method is the whole answer. The probe is a four-row set:
the vendor supplies it, the class replaced it, a BASE replaced it, a TRAIT replaced it; which of the
last two moves depends on whether reflection reports the declaring class or the using one, so a probe
short of either row proves nothing about the other.

*One more trap under the analyser.* Take every path you compare from the SAME reflection. PHPStan spells
a vendor file as composer's autoloader recorded it (`vendor/composer/../spatie/...`) and PHP spells it
canonically, so one file looked up across the two providers is never string-equal — a test that holds
in isolation and answers false for every class in a real analysis.

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
body produced. `ResolvedExtensions::cacheSignature()` paired each resolved extension with its composer
package's version as a proxy for its behaviour: sound for a package, and inert for a class in the
application's own tree, whose "package" is the root and whose version does not move when the file is
saved — so an author edited their own extension, rebuilt, and was served the previous output, on the
primary extension point. The version and the source digest turn out to be complementary rather than
alternatives, and telling "a version that can move" from one that cannot needs no heuristic at all: a
digest over CONTENT is inert exactly where the version is informative, since a release nobody edited
reinstalls byte-identically, and informative exactly where the version is inert.

The other half of the class is an input that reaches NO key input at all, which is a different fix rather
than a milder version of the same one. `lint.leakage`'s safelist and heuristics decide whether a recorded
example is PUBLISHED — the recorder withholds a body redaction still finds a credential in — and it is a
VALUE, not a file: `lint.*` is deliberately top-level, so no document's config bag holds it, and the
extension carries the options inside a collaborator object, which the configuration digest reads as
nothing but a class name. A dependency manifest can only name files, so the instrument there is a digest
contributor (`LeakageDigestContributor`) and not a recorded path. Two more found by sweeping the same
question across every fragment-level extension: `QueryBuilderConfig::$recovered` and
`JsonApiPaginateConfig::$recovered` each gate a per-route diagnostic, and `vendor:publish` writes the
package's own DEFAULTS — so publishing the config moves not one value the contributor digested, and an
author who followed that diagnostic's own advice rebuilt and was told it again.

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

An extension's answer is written the same way and keyed the same way, one level up: the source digest goes
into the signature rather than into a manifest, because an operation extension is run over every operation
and nothing records which of them its answer reached — an extension that wrote nothing still ran, and
withholding a value is an answer too. So the locality the mapper fix has is unobtainable here, and saying
so is the fix: an edit retires the whole document, which is the blast radius the paired package version
always had rather than a new one. The refusal follows the key's scope for the same reason — an extension
declared in no file leaves the DOCUMENT uncacheable, with one `extension.unhashable` diagnostic — and the
refusal reads the same instance set the signature does, or a fragment keyed on an entry nothing checked is
keyed on nothing again.

*The guard the instrument dictates.* Manifest freshness answers nothing about a key input: the entry filed
under the old key stays on disk and reads FRESH forever, it is simply never addressed again. So a row for
a key input counts what the second build had to WRITE (`array_diff(fragmentKeys(), $before)`), and a row
for a manifest input asks the cache's own freshness. Getting these the wrong way round is a guard that
passes on the unfixed code. The cost direction needs executing too, and can be: the same bytes written
again with a newer timestamp must leave every fragment warm, which is what makes `composer install` free,
and a re-ordered safelist must leave them warm, which is what the sort is for.

## A message that names where a setting used to live

Configuration moves. When it does, the reader that reads it is rewritten and every SENTENCE about it is
not: a diagnostic, a console line or a page still names the old file, and the author who follows it edits
a key nothing reads. That is worse than a stale doc page, because the message arrived from the tool
itself and so carries the tool's authority — the author has no reason to doubt it, and the setting they
just wrote is silently ignored.

The asymmetry that makes it survive is that nothing fails. The reader is correct, the tests of the reader
pass, and the string is a string: no type, no call, nothing an analyser or a golden can disagree with. So
the population is not under-covered, it is unrepresented — a message is only ever wrong to the person
holding it.

*Instances.* Six printed strings survived the split between `docuccino.yaml` and `config/docuccino.php`:
`docuccino:explain`'s top-rung hint, the `config.accept-unused` report's own header, the out-of-memory
notice's two levers, the contract assertions' unknown-document failure, and the response recorder's
"say where recordings live" — which also printed a PHP array for a YAML file, so following it produced
neither the right file nor the right syntax. Thirty-two documentation pages carried the same defect, and
three of them named no file at all, which is the shape a scan for the OLD filename walks straight past.

*The tell.* A filename or a config path spelled as a literal inside a message. Every one of the six was
a literal; not one of them had asked the reader that owns the setting where it lives. The second tell is
a mechanism whose whole premise was the old location: `docuccino:watch` warned that `config:cache` bakes
`DOCUCCINO_FRAGMENT_CACHE`, which was true while `cache.enabled` was in the config repository and became
unreachable the moment it left — so the warning, its check, and both of its tests were asserting a state
the product can no longer be in.

*The fix that worked.* Name the file off the reader that owns it — `ConfigFile::NAME` — so a message
cannot disagree with the file it points at, and delete the mechanisms whose premise moved rather than
rewording them. For the pages, `DocsConfigSplitTest` reads the KEYS in every `php` fenced block on the
site and refuses one that shows a setting the build reads from the YAML, with the boot surface derived
from the shipped framework config rather than listed in the guard; the guard also asserts it agrees with
`ConfigSplit::FRAMEWORK_KEYS`, because the build checks a leftover key against that list and a surface
tightened in one place only would leave a page admitted here and reported by a build.

The tell is a config PATH, not the token `docuccino.`. The emitted document's own extension keys
(`x-docuccino.id`, `x-docuccino.provenance`, `x-docuccino.diagnostics`) share the prefix and are a
different namespace, and a live `config('docuccino.enabled')` names a key that really is still there —
so a find-and-replace on the prefix corrupts three readers to fix one comment.

## A default sized to one application shape, and a template that pins it

A default is the product. Where it is written as a literal rather than derived from what the application
declares, it is right for the shape somebody had in mind and silently wrong for every other — and a
shipped config template that writes the key LIVE makes the code default unreachable for everyone who
already installed, so fixing the default fixes nobody.

*Instances.* `engine.project_paths` defaulted to `['app']`, which is the descend scope for throw
classification and inline rules. A modular application maps its own `Modules\…` roots, so a throw written
a hop below a controller there is a bare-`Throwable` layer-3 point whose hop descent declines — and the
document then publishes no response at all for an error the application really raises. Two fixture
actions were ledgered as "unsurfaced" on exactly that boundary. The second half is the template: the
shipped `docuccino.yaml` wrote `project_paths: ['app']` live, so every install had it pinned and a
code-default change would have reached none of them.

*The tell.* A default written as a literal beside a reader that already derives the same fact for a
neighbouring purpose. `primePaths()` had been deriving every PSR-4 root from `composer.json` for the
priming question all along; the descend question sat two lines away answering `['app']`. The second tell
is a shipped config key that is not commented out, which is a default the code no longer owns — and the
third is a fixture harness pinning the OLD default, so no test could have noticed: the engine runner
hardcoded `descendPaths: [app/]` and the reconciliation ledger wrote the boundary down as `app/` rather
than reading it off the autoload map, which would have gone on excusing a silence the build had stopped
having.

*The fix that worked.* Derive both scopes from the one reader, with the sections askable separately —
`Psr4Namespaces::roots()` for prime (both sections: a helper a test root declares still has to reflect)
and `::shipped()` for descend (`autoload` only: a test root is not the API surface). Comment the shipped
key out so the derived default is reachable. Then MEASURE before moving it, and state the benefit as a
number: identical analysed-file count and identical live walks for a stock skeleton, +6 walks and +2 MB
for the modular root, and 2 error responses that had been missing from the document entirely
(inference-embedding.md §6c). And tell the population the template cannot reach, only where the narrowing
cost them: `inference.descend-scope-narrowed` fires per declined hop into the application's own declared
code — 7 firings on the fixture corpus with descent pinned, 7 actionable, 0 not, and 0 at the derived
default. The guard is `NarrowedDescendScopeTest`, which runs the same corpus through the same engine at
both scopes and asserts the delta literally rather than counting it.

*The other half nobody keys.* Deriving a scope from `composer.json` makes its `autoload` map a build
input, and `composer.lock` cannot stand in for it — composer's content hash does not cover `autoload`, so
`dump-autoload` after mapping a new root moves no locked byte. `BuildFingerprint` digests the map.

## One flag answering two questions: how far to walk, and whose code this is

A build has two different reasons to ask about a file. **How far may I walk into it** is a containment
bound with a cost behind it, and it is configured (`engine.project_paths`). **Whose declaration is this**
is an identity question with no cost behind it at all — the file is already primed, its bodies intact —
and it is not configured, because every local PSR-4 root in the application's `composer.json` is the
answer. One `ProjectFilter` answered both, so a class in a modular root was treated as a package's.

*Instances.* The response-shape refiner declined to fold a render helper written in a `Modules\…` root,
which the prime-scoped filter in `PhpStanEngineFactory` fixed for the refiner and the enum folder alone.
Three more sites went on asking the descend scope: `HttpExceptionStatus`/`FactoryStatus` refused to read
the status a modular exception class pins on itself, so the document published a placeholder 500 for an
exception whose 409 was two lines into a file the build was holding open; `UnreadStatus`'s actionability
called that class foreign, so `ForeignClass` — the one reason with no remedy — dropped the notice and the
reader got the placeholder with no explanation; and `ThrowSignal` read a modular guard's `@throws` as
vendor plumbing and demoted the error to `internal`, so the document carried no response for it at all.

*The tell.* A read gated on the descend filter whose comment says "project" or "vendor" rather than "how
far to descend". If the sentence that justifies the gate is about PRIMING — bodies stripped, the analysed
set growing, a recorded walk discarded — then it reaches vendor and stops there, and applying it to a
primed root buys nothing. A second tell is a fix landing on one consumer of the wider filter while the
others keep the narrow one: the filter was introduced for the refiner and left three sites unswept.

*The fix that worked.* Two filters with names that say which question they answer — `projectFilter` for
the descend scope, `appFilter` for the application's own source — handed to the reads by role rather than
by habit, and the claim behind the cost argument MEASURED rather than repeated: the analysed-file count
over one build of the fixture's throw corpus is identical either way (163), so the wider read discards no
recording and costs one extra file walk. The guard is `UnplacedStatusReconciliationTest`, whose corpus is
now asserted to hold a controller on BOTH sides of the descend scope — the defect had survived a green
suite because the sweep's denominator was one directory — and whose four columns are asserted to partition
every swept action, so a throw the document carries nothing for, or demotes, can no longer sit in the gap
between two scans.

## One table answering both directions of the wire

A schema fragment for a declaration answers one of two questions, and which one depends on where the
fragment is published, not on what the declaration says. **What does the server WRITE for this?** is a
response body's question, settled by the bytes the framework emits. **What may a client PUT IN for
this?** is a parameter's — a filter value, a scope argument, a bound path segment — settled by what the
server will match. For most declarations the two answers coincide, which is exactly why one table gets
read for both and nobody notices until a row where they come apart. Then one direction is silently
served the other's answer, and because a `format` is a CONSTRAINT, the wrong direction is not vague —
it is a precise claim the server's own traffic contradicts.

*Instances.* `CastSchema::forCast()` answered both directions of every Eloquent cast. An Eloquent
`date` cast rounds its value to start-of-day and then serialises it through `serializeDate()` like any
other date, so the response sends `2024-01-01T00:00:00.000000Z` — while the table published
`format: date`, which a client validating that response rejects. The same table's hook predicate had
the sibling defect one level down, in grammar rather than direction: it matched the cast BASE where the
framework matches the whole cast value, so `datetime:d/m/Y` counted as hook-governed, and a model
overriding `serializeDate()` had that column's format stripped and a notice raised for a loss that
could not happen — the framework formats a parameterised cast with its parameter and never reaches the
hook. `custom_datetime` sat on the same list for the same reason: it is the framework's INTERNAL cast
type name, reaches no branch of `addCastAttributesToArray()`, and an override never touches it either.

The split itself is not the fix, and the follow-up instance is the reason to say so. Having separated
the two readings, the `date`/`immutable_date` row went on DISCARDING its `:FORMAT` parameter while the
`datetime` row beside it honoured one, so `date:d/m/Y` published `format: date` over bytes of
`02/01/2024` — a full-date validator rejects them, and the `date:c` sibling published a full-date
keyword over a value carrying a time. The docblock added in the same change asserted that the guard
"cannot recognise fewer forms than the fragment it decides", which is the sentence that tells the next
reader not to look: the guard read the parameter and the fragment did not, so the file documented an
invariant one of its own rows broke.

*The tell.* One lookup whose call sites are split between a response mapper and a parameter resolver,
and whose docblock says what a value "serialises to" while half its callers are asking what a request
may send. The second tell is a guard beside such a table that unwraps fewer forms than the fragment it
decides — a base where the framework reads a whole value. The third is two rows of one family reading
their parameter differently: a fix scoped to the row that was reported leaves its siblings answering the
old way, and a prose invariant written in the same change then certifies them. Sibling of
[one flag answering two questions](#one-flag-answering-two-questions-how-far-to-walk-and-whose-code-this-is):
same shape, different axis.

*The fix that worked.* Two named readings over ONE table — `CastSchema::written()` and
`CastSchema::accepted()` — rather than a direction argument each call site could pass wrongly, or two
tables whose 25 identical rows would drift. The divergent rows are the only ones that branch, and there
`written()` returns null and hands the response direction to the single date policy that already reads
the hook. The guard is `CastSchemaTest`, which states the rule from Laravel rather than from the table:
two fixtures differing by `serializeDate()` alone are serialised and the BYTES compared, so a cast
belongs to the hook when and only when replacing that method changes what the column emits, and the
row set is asserted against the fixture's own casts so a new form cannot go unread. The parameter
follow-up is one reading for all five date casts, called by the table and by the response direction
both, and a guard that walks the WHOLE ladder: every cast form the fixture carries is serialised and the
published `format` checked against the bytes by an RFC 3339 reader, so a row cannot agree with the
table by construction, and the two producers' answers are asserted as a union so no form falls between
them.

## A declaration trusted for more than it declares

A docblock, a default or a name states one fact, and a reader that acts on it acts on two. `@throws
ManifestRejectedException` says WHICH class a call raises. It says nothing about which STATUS that class
carries at this call, because the author never wrote one there — and layer 1 took the declaration and
`continue`d, so the deeper read that would have found the construction never ran. An application that
documents its guards therefore lost every per-factory status: the throw point became the CALL, no
construction presented itself, the class's three factories disagreed, and the document published
`UNPLACED_STATUS` — a placeholder 500 — for a rejection whose 409 was two lines into the callee. The
notice beside it asked for an edit that could not be made: pinning one status in a class that has three
would make the document lie, and "write the status at each `throw`" is impossible through the private
constructor that makes named factories worth having.

*Instances, and the two that already carry their gate.* The `@throws` short-circuit is the one that
shipped. A constructor parameter's DEFAULT is the same shape — it states what a call leaving the slot
empty passes, not what every instance carries — and `HttpExceptionStatus` only reads it as a pin where the
class controls every construction (private constructor, no trait, no write to the parameter). A bare
METHOD NAME is the third: `KnownThrowers` is keyed on one, which is a guess about a callee rather than a
fact, so it speaks only for callees this build cannot read. Two of the three were already gated; the tell
is that nobody had asked what the third one's declaration actually claimed.

`JsonSerializable` on a date class is the same shape in the schema layer. The interface states that the
class decides its own JSON form; it says nothing about WHICH form, and the date-time mapper read it as
evidence of RFC 3339 and published `{type: string, format: date-time}` for every implementation. Three
classes with that identical declaration send `"2024-01-02T03:04:05.000000Z"`, `"02/01/2024"` and
`1704164645`, so for two of them the document published a `format` the server contradicts and for one it
published the wrong TYPE — a client's deserialiser fails at runtime on a value the document called a
string. The same reading claimed too little in the other direction: a property typed at the bare
`DateTimeInterface` states no form, so it fell to the class mapper's `type: object`, which is true only
when the value is one of PHP's own and false for the Carbon a Laravel application almost always puts
there.

*The tell.* A reader that stops at a declaration and a reader that stops at a VALUE look identical in the
code — both are an early return with the answer in hand — and only the first is trusting something. Ask
what sentence the author wrote, and what sentence the code is now acting on. Where they differ, the extra
claim is a guess, and the fix is to go and read the thing the author really did write. `class_exists()` as
a version check is the same defect outside this file: presence is what it states, and a grammar is what an
integration then emits from it. A fixture is the quiet accomplice: `SerialisingDate` was written to
satisfy the predicate — a subclass rendering RFC 3339 — so the suite proved the predicate fires and
never that it was right, and dropping half the predicate failed exactly one test.

*The fix that worked.* Split the two claims rather than the reader: take the declared CLASS from layer 1
and read the STATUS one hop on, off the callee's own `throw` (`ThrowAnalyzer::inDeclaringCallee()`), with
the same `atThrowSite()` grammar the direct throw uses so one construction cannot mean two answers a hop
apart. The hop is a READ and not a walk — descent decides which errors the document carries, this decides
only what the error it already carries says — so it is gated on the application's own source rather than
the descend scope, and MEASURED: 163 analysed files either way, two extra live file walks. The remedy text
was rewritten in the same change, because a diagnostic asking for an impossible edit is its own defect,
and `UnstatedByClass` had no member of its population left afterwards, so the fixture that stands in the
narrowed one (`rethrownAgreementStatus`) was written as part of the fix.

The date-time mapper's fix is the same move: publish a form only where the BYTES have been read, which
is a named list of the declarations whose `jsonSerialize()` was encoded in a test, matched as the
DECLARING class of that method so a subclass inheriting one is covered and one restating it is not.
Everything else the domain holds — another stated form, or the bare interface any of them may stand
behind — is widened to `{}`, which claims nothing and is therefore true; `lint.vacuous-union` already
reports the widening where it meets a nullable arm, so the author is told rather than left reading an
emptied schema. The catalogue guard instantiates every concrete name on the list and compares its bytes
to the one date policy's form, so an entry cannot be added without its bytes being read, and the two
fixtures that separate the cases are a declaration writing a string and an identical one writing an
integer.

## An invariant every producer has to remember

A string an application chose — a route, a class, a config key, a message something threw — reaches a
published sentence, and something in it steers whatever renders that sentence: an ANSI sequence
recolours a terminal, a direction override reverses the line, a line separator forges a line of a log
rendered as a page. `PlainText` has always known how to make that safe. What went wrong is WHERE the
call sat: at the construction site, so every producer of a diagnostic owed the same one line, and the
ones that forgot were silent about it.

The asymmetry is that forgetting produces a passing test. A message with a raw escape in it reads
correctly in every assertion, and the only reader who sees the difference is one holding a terminal or an
artifact with a hostile name in it. So instances close one at a time, each as its own report.

*Instances.* `PlainText::of` was hand-called at scores of construction sites spread over two packages,
which is what let a site be missed at all; three misses had already been found and fixed one by one.
Two more were pinned in place by tests that asserted the RAW text was correct
(`SharedErrorResponsesTest`, `ErrorComponentAttributeTest`), each reasoning that `json_encode` escapes on
the way into the document. It does not: the canonical writer uses `JSON_UNESCAPED_UNICODE`, so a C1
introducer, a direction override and U+2028 all reach the artifact whole — only the ASCII controls are
escaped, and only they were what those two tests happened to hold. The whole engine package was a sixth:
seven diagnostics, every one naming a class or quoting a thrown message, and not one call.

*The tell.* A neutralising call at a construction site rather than in the constructor — a rule about a
KIND of value, restated once per place the value is made, so that being right depends on remembering. The
second tell is a docblock arguing the escaping belongs at a RENDER boundary — true of anything only one
renderer can interpret (Symfony's markup), and false the moment the same value is also published, since
a published document has no render boundary of ours at all.

*The fix that worked.* `Diagnostic` makes its own `code`, `message` and `help` safe at construction, so a
producer cannot forget; the producer-side calls that had become redundant came out in the same change,
because a reader cannot tell a load-bearing call from a habit. It rests on `PlainText` being idempotent,
which is now a row rather than a claim — the sharp test being a WARM build, since `fromArray()` is how a
diagnostic comes back off a fragment-cache hit and comes back through that constructor. `help` keeps its
line breaks (`PlainText::lines()`): a console writer indents them, and a newline is the one control
character every destination handles.

*The one exemption, and why it is not a hole.* `routeSignature` is left whole. It is a key rather than a
sentence — sorted on, and compared against the signature a live route answers with — so escaping only the
published copy would make a diagnostic name a route nothing can find. It also removes nothing: the
signature's bytes are the route's URI, which the document already publishes verbatim as the `paths` key,
and it has to, because that key is the URL a client sends. Escaping there would publish an endpoint the
server does not answer on, which is the confidently-wrong answer rather than the vague-but-true one. So
the line the class draws is not "text we escape" against "text we don't": it is a SENTENCE we wrote about
an application's text, which we own and neutralise, against a KEY that has to stay equal to the thing it
names.

*The other half of a render boundary: shape, not just characters.* Escaping stops a value steering a
terminal; it does not stop a value being mistaken for a line the tool asserted. `help` keeps its line
breaks by design, so a newline in it adds a line — and a help line printed in the same indent, colour and
format as the reference link under it is a link a reader is invited to follow. The forged-diagnostic-line
half of this was already closed by indenting help past a diagnostic line; the reference line was the same
defect one row down. The general rule is that where a renderer prints OUR lines beside THEIRS, the marker
has to sit on theirs: a marker on ours is one their content can reproduce, and a marker on theirs is one
their content cannot remove. `RendersDiagnostics` therefore gutters every help line.

The guard is `DiagnosticEscapingTest`, which registers a producer written the careless way, gives it a
route whose own path carries every hazard, and holds the published diagnostic to carrying none of them
outside that key. A guard listing the producers that remembered would be the defect again. It reads the
DECODED document rather than the raw bytes: `json_encode` escapes `\x1B` and U+2028 as transport and hands
them back whole on the way out, so a bytes-only row is vacuous for exactly those two — which is how two of
its four rows once passed with nothing escaping them at all.

## Independent reasons, each trusted to be sufficient on its own

A decision that changes published bytes is taken by several reasons in a row, each of which can answer
alone, and they are not the same strength: one settles the question, another is only suggestive, and
the code cannot tell them apart. The reasons compose by accident of writing order rather than by a
rule, so the fix for each new symptom is another hand-applied `if` — and the next symptom arrives.

*Instances.* Four in `MessagePaths` in one release cycle, each found by fixing the one before it. A
wrapper scheme was trusted to prove a local file, and `compress.zlib://` can name a host. A recognised
root was trusted to prove a machine word, and a one-segment root is a route mount as readily as a
container's checkout — `Unknown route /app/users/profile` was published as `users/profile`. A brace and
a backslash were trusted to refuse a run outright, so a proven path in the same sentence was published
whole. And the wrapper table going short published the machine path an unlisted scheme named, which is
the sibling class — table completeness — rather than this one, and was closed by a guard that reads
`stream_get_wrappers()`.

*The tell.* Ask each reason what it PROVES rather than whether it proves. Where two reasons in one
decision answer that question differently — "this run is a path" and "this prefix is a machine word"
are different claims, and only the second licenses removing text on the prefix's own account — a
decision that treats them as interchangeable will authorise a rewrite on the weaker one. The second
tell is an exclusion spelled as a veto: a brace is strong evidence of a template and weak evidence
against a path, and the moment it refuses outright somebody has to special-case a way past it.

*Where it hides.* Every guard in front of it read a TABLE — is the wrapper list short, is a registered
scheme undecided, is a route method missing — and table completeness is not composition. Adding a
fifth reason to the shipped class, uncorroborated, left the whole suite green: measured, not assumed
— of seven such reasons tried, four never fired on the corpus at all and proved nothing either way,
two were caught by a pinned row, and one (a run five segments deep authorises a reduction) changed the
published answer for two runs with 11690 tests passing.

*The fix that worked.* Give every reason and every objection a case of its own that declares the CLAIM
it makes and whether it settles that claim alone (`PathReason`, `PathObjection`, `PathClaim`), and
state the composition once: a claim stands when a reason proves it and no conclusive objection denies
it, and a rewrite is authorised by a claim that stands and covers the text it removes. What a reason
does not prove then becomes a member rather than silence — a nested scheme and a shallow root are
objections, which is those two defects written down. The guard is `MessagePathsLadderTest`, in three
parts, because each catches what the others cannot: the two tables restated in the test's own words,
so a case added with no claim fails; the composition driven through the real class over every cell of
the strength grid, with the rule written independently and two deliberately wrong rules that must each
mispredict a row; and a reflection scan of the six methods where the ladder composes, holding their
call sets, because a fifth reason does not have to arrive as a case — added as one more `if` it
answers alone exactly as the four defects did, and every table guard still passes.

## A channel with a renderer and no gate

A CLI gate reads a list, and the list is whatever the call site happened to pass it. Every producer
whose reports reach the console by another route is then outside the gate — and outside it silently,
because the reports still print. `--fail-on` says "anything reported at that severity or louder makes
the exit code non-zero"; what it read was one producer's list.

*Instances.* `docuccino:export` gated on the build's diagnostics alone. The emit report — everything
an emitter says while writing an artifact, which is the whole `downlevel.*`, `server.*` and
`postman.*` surface — was rendered and dropped. It closed one severity at a time: the ERROR half went
first, as the artifact-validity check, because that one had to fail below the floor's reach; the
warning and info half stayed outside for a release, so `--fail-on=warning` could not see a dropped
`webhooks` section and `diagnostics.accept` could not quiet one. `config.export-path-ignored` was a
second instance one layer earlier — reported by reading the export configuration, before any build,
so no build list could ever have carried it.

*The tell.* Two ways for a report to reach the reader and one way for it to reach the exit code. The
second tell is a sentence in the docs granting the exemption in the tool's own voice ("these are
reported, not enforced"), which reads as design and is really the shape of the code being described
back. And the third is corroboration the mechanism itself offers: the console already printed
`Accepted, so --fail-on ignores them` over emit-report codes and already counted them as used when
deciding an acceptance was stale — so two of the three mechanisms treated the channel as gated and
only the gate did not.

*The fix that worked.* Make printing and gating one act. `RendersDiagnostics` records every
diagnostic it prints, and `FailsOnSeverity::withSeverityGate()` reads THAT set once at the end,
so a channel is gated by being shown rather than by a call site remembering. A report a command
treats as fatal on its own terms — an artifact that is not a valid document of its own format —
still fails below the floor, which is the one exception and runs the other way.

The guard is `FailOnReachTest`, which drives the real command over both out-of-build channels: an
emitter warning at `--fail-on=warning`, an emitter info at `info` and not at `warning`, and the
configuration info raised before the build starts. Each row states the floor from the commands
reference rather than from the code, pairs the failing run with the accepted one so acceptance is
shown carving into a gate that was really closed, and every test narrows to a route whose build is
silent — so a green row is the gate staying quiet rather than the build being loud elsewhere. The
last row is the other half: every floor, up to `hint`, stays green for the OpenAPI 3.2 target the
shipped configuration writes, which is what keeps widening the gate from being a way to fail
everybody's pipeline.

## A check that runs where the side effect is, not where the question is

A check gets written inside whatever produces the thing it checks. The emitted-artifact check went
into the emitters, so it answered wherever something emitted — an export writing files, a viewer
serving a page. Which entry points run it is then a function of which ones happen to have that side
effect, and that is unrelated to which ones are being ASKED the question the check answers.

*Instances.* `docuccino:validate` is the command whose entire job is to say whether a document is
sound, and it never ran the artifact check, because it writes nothing. It held the UIR document to
its schema — the model — and said nothing about the bytes a consumer receives. Measured before
acting, on the workbench: with an overlay-written `$ref` that names nothing, `export` exits 1 with
`document.openapi-invalid` and `validate` exits 0 printing "valid against UIR 1.0.0". With a 3.0
target configured, `export` reports four `downlevel.*` codes and `validate` reports none. With an
unreadable `export.targets`, `export` exits 1 and `validate` exits 0. `docuccino:cache` was the
second instance and the more subtle one: it emits the payload the viewer is served, so the check DID
run — and the finding went to the log, which is the right channel for a request and the wrong one for
an operator watching a deploy step. A check that runs and reports where nobody is looking is the same
defect as one that does not run.

*The tell.* Ask what the check is a function of. If the answer is "whichever call site produces the
artifact" rather than "whichever call site is being asked whether the artifact is sound", the
population is an accident. The corroborating tell is an asymmetry that reads backwards when written
down: the command with `validate` in its name catching strictly less than the one with `export` in
its name. And the third is a report suggesting a flag — a `--format` mode that emits to a temporary
target — which is the shape of the defect asking to be made configurable instead of fixed.

*The fix that worked.* Make the check a function of the fact rather than of the side effect.
`docuccino:validate` emits every target the document configures, in memory, purely to read the bytes
back; nothing is written, so there is no temporary path to leak into output and no artifact left
behind. Not a flag: an option here would be an admission that the default command could not answer
its own question. What the command checks is what the application SHIPS — its configured export
targets, not a format the command picks — so a pipeline writing 3.0 is told about the 3.0 file.
`docuccino:cache` prints its emit report on the console and exits non-zero for an invalid payload,
keeping the log for the request path. And a target with no published schema behind it — `uir`,
`postman` — SAYS so per target rather than staying silent, because silence beside a checked target
reads as the clean answer.

The guard is `ArtifactSoundnessReachTest`, and it is a union table rather than a set of per-command
tests: every entry point that can be asked whether a document is sound carries a row, including the
ones that owe no answer, with the reason in the row. Two scans supply the denominator — the command
set read out of Artisan, and every place in the adapter source that emits an artifact — each with a
plausible minimum beside it so a scan that stops matching fails instead of passing over nothing. The
rows are then EXECUTED against a document whose artifact really is out of spec, including the rows
claiming to say nothing, which is where the gap lived. Two facts one owner apiece:
`DocumentEmitOptions::canonical()` states the bytes a bare export writes, with an executed
byte-comparison per carrier because a YAML target checked as JSON would report a clean run over the
wrong bytes; and `Formats::checksEmittedArtifact()` states which formats have a published schema at
all, guarded by probing the behaviour of every format rather than by asking the table about itself.

## A containment relation read in one direction only

A note that stands down where the author has already declared the field has to ask whether a
declaration reaches it — and "reaches" is a relation between two paths, which has two directions. The
reading gets written for the direction the example had: a declaration AT the field, or naming a key
inside it. The other direction — a declaration naming the CONTAINER the field sits in — is the one the
writer beside it acts on hardest, because the declared node goes in whole and the field under it is
gone whatever rules are written. So the note keeps firing, and its remedy ("express the field with
recoverable rules") changes nothing: the unclearable report, arriving through the channel built to
remove unclearable reports.

*Instances.* `validation.rule-unrecoverable` named `meta.tags` as omitted from the request schema while
`#[BodyParameter(name: 'meta', type: 'object')]` had replaced `meta` entirely. The container note's own
reader carried the identical asymmetry, so `validation.container-undecided` said a field was
"documented as either" when the field was not in the document at all. Both readers spelled the same
two-clause predicate separately, which is how one could have been fixed and the other left behind.

*The second half of the class.* The relation is not the only thing that is a function of the writer;
WHICH declarations reach at all is too. The same three notes read `#[BodyParameter]` at every verb,
while a read verb sends the rules to query parameters — so `#[QueryParameter('search')]` published the
parameter and the note still called the field omitted "from the request schema", a false report with
the wrong location word attached. The tell is a message naming a part of the document that the verb
decides, written as a constant.

*The tell.* Ask what the WRITER does with a declaration, then check the guard recognises exactly that.
A writer that replaces a subtree makes the ancestor direction load-bearing; a writer that mints one
independent parameter per name makes it meaningless, and reading the relation the same way at both is
wrong in one of them. A guard whose containment clause can be replaced by `===` with the suite still
green is the same thing said in the other direction — the branch that justifies the reading is never
executed.

*The fix that worked.* One reader for both questions, in the class that owns the body-or-query
decision, with the layer difference stated once as the flag it is: `DeclaredFields`, built by
`RecoveredRequest::declaredFields()` from whichever attribute writes where these rules land. The notes
ask `publishes()`, the container note asks `decidesContainer()`, and the location word comes from
`RecoveredRequest::destination()` rather than from a string in each message. The guard is
`DeclaredFieldNoticesTest`'s table — a row per direction, per layer and per verb, each asserting the
location word beside the field — plus `RuleSetNormalizerTest`'s table for the container half.
