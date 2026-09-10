# Spectral's `oas` ruleset, audited

Status: **decided, 2026-09-10. Adopted nothing.** A dated snapshot, and deliberately only that: it says
what all 56 rules of Spectral's `oas` ruleset were at one version, what Docuccino published at one
build, and why none of the rules earned a place here. It does not track Spectral's releases, nothing
downstream reads it, and nothing on the website mentions Spectral at all. It ages in both directions —
see [Redoing it](#redoing-it).

Audited against:

| | |
| --- | --- |
| Package | `@stoplight/spectral-rulesets` |
| Version | 1.22.7 |
| Ruleset | `oas` (`package/dist/oas/index.js`), 56 rules |
| Tarball | `https://registry.npmjs.org/@stoplight/spectral-rulesets/-/spectral-rulesets-1.22.7.tgz` |
| Integrity | `sha512-cT1B6Ly21923lvr235lu7iIgmcnoCTJaN3ANOyTqr4D5GA/4p2k0+yhjhdfj/1ks/Os3kUzSFnFkzpWH7WszFA==` |

Those last two are here so this record stands on its own: a future reader re-opening the question
fetches that tarball, checks it against npm's own hash, and re-runs the audit against whatever is
current rather than trusting this page to have kept up.

## Why the audit happened

Somebody asked whether Docuccino supports Spectral. It reads a finished OpenAPI document and reports
what is wrong with it, which is a job Docuccino's own document lints already do — so the interesting
question was never compatibility. It was whether Spectral, which has had years of contact with real
API documents, knows about defects we do not, and whether any of its rules are worth having as lints
of our own.

Answering that from the size of the ruleset is worthless, because Spectral was built for documents
people write by hand. A missing `operationId` in a hand-written document is a typo; in a compiled one
it is a fact about the application's routes, and that one turned out to be a defect in our own default
rather than a rule worth adopting. So the question had to be answered rule by rule, and this is that
reckoning.

The unit of the answer is a bucket: what a reader could *do* if the rule fired against a document
Docuccino built.

## The four buckets

| Bucket | Rules | What a firing means |
| --- | --- | --- |
| 1. Impossible by construction | 14 | Nothing. We cannot emit the shape the rule looks for. |
| 2. A Docuccino bug if it fires | 14 | Ours to fix. The reader authored none of it and cannot change their application to make it go away. |
| 3. The application's, and actionable | 18 | The reader's, and reachable from their own code or configuration. |
| 4. A house style choice | 10 | A convention with no right answer, which is why we stay quiet about all ten. |

Bucket 1 is Swagger 2.0 and nothing else: we emit OpenAPI 3.2, 3.1 and the 3.0 downlevel, never Swagger
2.0, so a rule scoped to that format has nothing to read. Those 14 are derived rather than judged —
they are exactly the rules the ruleset declares as `formats: ['oas2']`, and they are the only part of
this table a re-run can carry forward without re-making the judgement.

Bucket 2 is the load-bearing one for the decision. A dangling `$ref`, an unused component, a duplicate
path parameter: none of those were authored by the reader. Telling them to go and fix a document they
did not write is the anti-pattern the diagnostic rule is about, so closing that bucket is our job and
not a lint anyone should have to run.

One caveat covers the whole of bucket 2 and is stated here rather than repeated down the table. It
says the BUILD cannot produce the shape, which is not the same as the document never carrying it: an
overlay is the reader's own file applied at precedence 45, and what it writes is published as written.
The pipeline does not re-derive a member an overlay set, nor supply one it removed. So any bucket-2
rule can be provoked by an overlay, and when it is, the overlay is both the cause and the one place to
fix it — which is what the `document.openapi-invalid` help text tells a reader to check first.

Bucket 4 is the population an org-lint feature would configure. It fires five times between them across
the whole workbench application, never more than twice for any one rule.

## What we already report, and how much quieter

Six bucket-3 rules have a counterpart among the shipped document lints, and each counterpart was
deliberately narrowed. `lint.missing-description` fires on an operation with neither a summary nor a
description; Spectral's `operation-description` fires on every operation that has a summary and no
description. `lint.undocumented-tag` says nothing until the document declares tags at all;
`operation-tag-defined` does not have that guard. The narrowing is the same rule everywhere: a
diagnostic earns its place by where it fires.

One bucket-2 rule is not quieter but louder, and it is not a lint at all. `oas3-schema` is now the
product's own check: every OpenAPI artifact a build emits is validated against the published
meta-schema for the version it declares, unconditionally, and each finding is a
`document.openapi-invalid` error that fails `docuccino:export` whatever `--fail-on` says. Two
conditions ride along with it that no meta-schema can carry, because JSON Schema cannot state either
about an instance: an `operationId` two operations share, and a local `$ref` naming nothing the
document defines. Running Spectral for `oas3-schema` is therefore running a second copy of a check the
build has already failed on.

## Every rule

Alphabetical. The reading column is what the rule would be telling us about a document we built.

| Rule | Bucket | Reading |
| --- | --- | --- |
| `array-items` | 3 | An array whose element type we could not recover is published without `items`, rather than with a guessed one. An annotation on the property — a cast, an `@var`, a resource docblock — is what produces the item schema. |
| `contact-properties` | 4 | Whether `info.contact` carries all of `name`, `url` and `email`. Every OAS `info` member passes through from `info`. |
| `duplicated-entry-in-enum` | 2 | We publish each enum value once, whatever it was read from. |
| `info-contact` | 4 | Whether a contact is published at all. |
| `info-description` | 3 | The document has no `info.description`. Set under `info`, inline or from a markdown file. |
| `info-license` | 4 | Whether a license is published at all. |
| `license-url` | 4 | Only reachable once a license is published, and whether that carries a `url` is the application's call. |
| `no-$ref-siblings` | 2 | Members beside a Reference Object are illegal in 3.0, and the downlevel emitter is what has to keep them out. Spectral reads any object holding a `$ref` this way, so it also reports a Link Object's `requestBody`, where the members beside it are data and perfectly legal — a false positive on its side, not a defect on ours. |
| `no-eval-in-markdown` | 3 | Descriptions come from docblocks, attributes and markdown pages, so this is about what the application wrote. |
| `no-script-tags-in-markdown` | 3 | As above. |
| `oas2-anyOf` | 1 | Swagger 2.0 only. |
| `oas2-api-host` | 1 | Swagger 2.0 only. |
| `oas2-api-schemes` | 1 | Swagger 2.0 only. |
| `oas2-discriminator` | 1 | Swagger 2.0 only. |
| `oas2-host-not-example` | 1 | Swagger 2.0 only. |
| `oas2-host-trailing-slash` | 1 | Swagger 2.0 only. |
| `oas2-oneOf` | 1 | Swagger 2.0 only. |
| `oas2-operation-formData-consume-check` | 1 | Swagger 2.0 only. |
| `oas2-operation-security-defined` | 1 | Swagger 2.0 only. |
| `oas2-parameter-description` | 1 | Swagger 2.0 only. |
| `oas2-schema` | 1 | Swagger 2.0 only. |
| `oas2-unused-definition` | 1 | Swagger 2.0 only. |
| `oas2-valid-media-example` | 1 | Swagger 2.0 only. |
| `oas2-valid-schema-example` | 1 | Swagger 2.0 only. |
| `oas3-api-servers` | 3 | The document publishes no servers. Written under `servers`. |
| `oas3-callbacks-in-callbacks` | 2 | We never nest one callback inside another. |
| `oas3-examples-value-or-externalValue` | 2 | Every example we publish carries a value. |
| `oas3-operation-security-defined` | 3 | A requirement under `security` names a scheme `security.schemes` never declares. |
| `oas3-parameter-description` | 3 | A parameter with no description. Route bindings take theirs from the action's docblock; `#[PathParameter]` and `#[QueryParameter]` state one directly. |
| `oas3-schema` | 2 | The emitted document must answer to the OpenAPI meta-schema for the version it declares — which the build now checks itself on every emission, as an error that fails the export. OpenAPI 3.0 closes its Schema Object, so the downlevel drops a member 3.0 has no word for with a `downlevel.unsupported-keyword` note: a 3.0 consumer loses the member rather than the document. |
| `oas3-server-not-example.com` | 4 | Whether an example server URL is acceptable in a published document. |
| `oas3-server-trailing-slash` | 3 | A `servers` entry the application wrote ends in a slash. |
| `oas3-server-variables` | 3 | A `{variable}` in a server URL the entry never declares, or a declared one the URL never uses. |
| `oas3-unused-component` | 2 | We mint a component only where something references it. |
| `oas3-valid-media-example` | 3 | Covered by `lint.example-mismatch`, which checks every published example against the schema beside it and names the pointer. |
| `oas3-valid-schema-example` | 3 | Covered, as above. |
| `oas3_1-callbacks-in-webhook` | 2 | We publish no callbacks on a webhook. |
| `oas3_1-servers-in-webhook` | 2 | We publish no servers on a webhook. |
| `openapi-tags` | 4 | Whether the document declares a top-level `tags` array at all. Declaring a few navigation parents while the rest derive from controllers is a deliberate shape, not a hole. |
| `openapi-tags-alphabetical` | 4 | We order `tags.definitions` by weight and then name, so this fires on any ordering somebody chose on purpose. |
| `openapi-tags-uniqueness` | 2 | Two entries in the top-level `tags` array share a name. Definitions sharing one merge into the single entry OAS allows — a member only one of them states is carried, a member two state differently is published by neither — so no configuration can put a name in the array twice, and each merge is reported. |
| `operation-description` | 3 | Covered, in a narrower shape: `lint.missing-description` needs both a summary and a description to be absent, because a summary is documentation too. |
| `operation-operationId` | 2 | An operation publishing no `operationId`. Every operation a build produces carries one: where the configured strategy has nothing to read — an unnamed route, a closure with no controller — the id is minted from the operation's own method and path, and the skeleton emitted for a route that failed to build is named the same way. |
| `operation-operationId-unique` | 3 | Two operations share an `operationId`. Reported twice: `route.duplicate-operation-id` where the second is met, naming both routes — a better vantage point than the finished document has — and `document.openapi-invalid` against the emitted artifact, which no meta-schema could state. A minted id is unique by construction, so a collision is either two names somebody pinned or the `controller-method` strategy over routes sharing an action. |
| `operation-operationId-valid-in-url` | 3 | Covered by `lint.operation-id-style`, which reads the alphabet a generated client can name a method from rather than a URL's. |
| `operation-parameters` | 2 | Two parameters share a name and location. `OperationDraft` keys its drafts by exactly that pair, so the build mints one each. The canonicalizer orders the list without deduping it, so this is the caveat above in its clearest form: a duplicate an overlay or a transformer writes is published as written, rather than half the edit disappearing on the way to the file. |
| `operation-singular-tag` | 4 | How many tags an operation carries. `#[Group]` decides. |
| `operation-success-response` | 3 | An operation documenting no 2xx or 3xx response — usually a route whose return we could not read. `#[Response]` states it. |
| `operation-tag-defined` | 3 | Covered, and quieter: `lint.undocumented-tag` says nothing unless the document declares tags at all, because a tag derived from a controller name is not a hole. |
| `operation-tags` | 4 | Whether every operation carries a tag. Closure routes are never tagged automatically. |
| `path-declarations-must-exist` | 2 | We mint path templates from the router. |
| `path-keys-no-trailing-slash` | 3 | A route URI ending in a slash. |
| `path-not-include-query` | 3 | A route URI carrying a query string. |
| `path-params` | 2 | We declare every template we publish, once, as required. |
| `tag-description` | 4 | Whether `tags.definitions` entries carry descriptions. One is also absent where two definitions of a name state descriptions that contradict each other, since the merge publishes neither and says so. |
| `typed-enum` | 2 | We publish the type we read beside the values we read. |

## What follows

Three decisions, all recorded in [tool-owned-config.md](./tool-owned-config.md):

- **No `.spectral.yaml` reader and no Spectral-compatible rule engine.** Reading the format means
  carrying JSONPath and Spectral's function library in order to configure rules that mostly cannot fire
  against a compiled document, or that report something we should be fixing.
- **No `extends`.** `lint` is already a top-level key shared by every document in a build, so the "share
  it across documents" half of the ask was never real, and the cross-repository half would configure
  bucket 4.
- **No rule vocabulary.** The seven org-config candidates in bucket 4 collapse to a table of required
  members keyed by document position plus a min/max pair on operation tag count — scalars and one list,
  for which `lint.leakage.patterns` is the precedent. No expression language is needed.

The audit also measured a default, which has since been settled rather than left open. `route-name`
answered nothing for a route with no name, which is every one of the workbench application's 31
operations, so the whole document published no `operationId` anywhere. Whichever strategy is
configured now falls through to an id minted from the operation's own method and path.
`controller-method` is not the answer to reach for instead: it mints one id per controller action, and
several routes sharing an action — nine do in this repository's own workbench — then share an id,
which the emitted-artifact check reports as an error and the export fails on.

## Redoing it

Fetch the tarball above (or whatever npm serves now), and read the rule names, `recommended` and
`formats` out of `package/dist/oas/index.js` — the rules sit in a single `rules: {}` object, one
8-space-indented quoted key each. The only mechanical part of the audit is bucket 1, which is
`formats: ['oas2']` and nothing else; the other three buckets are judgements about what a reader could
do, and have to be made again rather than carried forward.

A bucket is a judgement pinned to a version of BOTH tools, not a fact derived from either, so the
table ages in two directions and a re-run has to decide both for itself.

On Spectral's side: a rule added since 1.22.7 is not in the table above at all, and a rule that kept
its name while changing what it matches sits under a bucket that no longer describes it. The version
and the hash are what a re-fetch settles that against.

On ours: every bucket above 1 is a claim about what Docuccino publishes, so a change to the product
can move a row with nothing in the ruleset having moved. That is the faster of the two directions, and
the one with no artifact pinning it — a re-run that only re-fetches Spectral has done half the job.
Read each row's claim against what the build does now before trusting the bucket beside it.

This was originally guarded: the bundle was vendored into the repository and a parser held the two
tables below to it. That machinery was removed with the record's audience. It was written in the same
change as the table it checked, its input was frozen bytes that no commit here could alter, and it was
blind to the only way this list actually goes stale — Spectral shipping a rule, which it cannot see
without a re-fetch. A guard that can only fire when someone deliberately re-vendors is a guard whose
occasion to fire is the act of feeding it. The version and the hash above do the same job for a
fraction of the weight.
