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
