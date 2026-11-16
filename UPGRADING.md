# Upgrading

One section per release that asks something of you, newest first. A release not listed here needs
nothing but the version bump — the [changelog](https://docs.docuccino.app/changelog/) carries the
full record either way.

## v0.24.0

Six changes, each for a different reader, and a last part on what a diff across the upgrade
reports, for anyone who compares or gates a committed document.

### A pointer in `lint.leakage.allow`

Only if `lint.leakage.allow` lists a JSON pointer. Names are unaffected, and so is the response
recorder's redaction.

- **A data-leakage pointer is escaped, and names a parameter by `in:name`.** A token is escaped the
  way RFC 6901 says, so `/paths//api/users/get/…` is now `/paths/~1api~1users/get/…`, and a media
  type reads `application~1json`. A parameter in an operation's or a path item's list is named by its
  location and name, `parameters/query:api_key`, where it was named by its position, `parameters/0`.
  The position moved whenever the list was reordered, so an entry stopped silencing its parameter and
  began silencing whichever sensitive one took its place. A pointer under `components` with no `/` or
  `~` in it reads as before.
- **An entry spelled the old way silences nothing.** The finding comes back, and its help names the
  entry that stopped matching and the pointer to write instead. Replace the one with the other. If
  your parameters were reordered after you wrote the entry, check that it is the parameter you meant.

### An example its schema does not allow

Only if an `@example`, or a `#[RuleSchema]` example, is not one of the values its schema allows and
you kept it, under `lint.examples.allow` or past the `lint.example-mismatch` warning.

- **Such an example is no longer published.** One with the right type but outside the values its
  schema allows, such as `@example active` beside an enum of `draft` and `published`, used to be
  published and reported. Now it is left out, and `docblock.example-untypable` names the values that
  would be accepted. The allow entry that kept it keeps nothing: the example is gone from the
  document. On a validation field, an example generated from the field's other rules takes its place
  where they pin a value; elsewhere the member has no example.
- **Change the example to an allowed value.** The `lint.examples.allow` entry then has nothing left to
  accept, and you can remove it.
- **Some examples now come out as the value they spell.** An example is read against everything its
  schema admits, so `@example null` beside a nullable enum is `null`, and `@example 3` on a nullable
  integer is `3`, where they were the strings `"null"` and `"3"`. That needs nothing from you, and
  `docuccino:diff` never reads a changed example as breaking.

### An enum case in a choice rule

Only a trace visitor in an extension needs editing. But a document whose choice rules or filter
defaults name an enum case changes, so read the first point even if you write no extensions.

- **A choice rule publishes the value Laravel compares.** `Rule::in()`, `new In`, `Rule::notIn()`,
  `new NotIn` and `Rule::array()` given enum cases, a Query Builder filter's `->default()` given a case,
  and Spatie Data's `#[In]` and `#[NotIn]` given cases now publish each case's backing value: `'draft'`
  where it was `'Draft'`, and integers for an int-backed enum. That is the value Laravel compares a
  request against. A pure enum's case is still its name. A constant that names a case reads as that
  case, and a plain constant on an enum as its value, where both were the constant's name. A rule
  that lists every case of one enum refers to that enum's component.
- **A trace visitor reads `caseName()`.** Only if your extension implements `TraceVisitor`.
  `TypeScope::constantValueOf()` folds an enum case to a `ConstValue` of kind `case`, where it was a
  scalar holding the case's name: `isCase()` is true, `class` holds the enum and `caseName()` the
  case. A plain constant on an enum folds to its value. Read the name from `caseName()` rather than
  `scalar`.

### A `#[BodyParameter]` naming a tagged union

Only if a `#[BodyParameter]` names a sealed interface or abstract class over a tagged object, and your
rules limit one of its members further than the class does. Nothing needs editing, but a client
generated from the document changes.

- **The field lists the union's members.** It was a `$ref` to the union with the rules' limits beside
  it, which client generators drop, so a generated validator enforced none of them. It is now a `oneOf`
  of the members, each one the rules limit written as `allOf: [{$ref: member}, limits]`, with the
  union's own discriminator. The member components and the union's component are unchanged.
- **A generated client's type for that field is the members' union.** TypeScript generators write
  `CardPayment | (TransferPayment & {…})` where they wrote `Payment`; a `Payment` value is still
  assignable to it. A generator for a nominal language may mint a new type for the field. Code that
  builds the request from the member classes needs no change.
- **`docuccino:diff` reads it as no change.** The two spellings say the same thing, so a diff across
  the upgrade reports nothing for that field.

### A field's rules beside its description

Only an extension that reads the schema `ValidationRulesToSchema::convert()` returns, or a
`RuleTransformer` that writes a fact into a field's `description`, needs editing. But a document whose
fields you described changes, so read the first point even if you write no extensions.

- **A description you write keeps what the rules enforce beneath it.** A `#[QueryParameter]` or
  `#[BodyParameter]` description, a property docblock or `#[Description]`, and an enum's own
  description each lead, and a rule's note ("Must be a date after from.", "Required when …") follows
  as a paragraph of its own. The note was dropped, or run into the prose as one sentence. A Query
  Builder filter's `whereIn` and `null` notes stay beneath a `#[QueryParameter]` description the same
  way. Nothing changes where nobody wrote a description, and `docuccino:diff` never reads a changed
  description as breaking.
- **A converted schema carries its notes apart.** The schema `convert()` returns keeps each field's
  notes under `x-docuccino.notes`, and its `description` holds only prose. `RecoveredRequest::apply()`
  renders them for you, and so does publishing the schema as a request body or a component. An
  extension that hands it to the document another way renders it first with `SchemaNotes::rendered()`,
  or takes a field's notes with `SchemaNotes::take()` and declares them with the draft's
  `declareDescriptionNote()`.
- **A rule transformer states a fact with `note()`.** `ValidationField::get('description')` no longer
  includes the notes earlier rules stated; `notes()` reads them. A transformer that appends its fact to
  the description still publishes it, but as prose that a description written later replaces. Write
  it with `$field->note()` instead.

### A date in a Spatie Data class

Nothing needs editing. But a document whose Data classes put a cast or a transformer on a date property,
or whose `data.date_format` is a list, changes, and a client generated from it changes with it.

- **A response date is documented as laravel-data writes it.** That is the property's
  `#[WithTransformer]`, else the transformer in `data.transformers`, which formats with
  `data.date_format`, or the first format when it is a list. A `#[WithCast(DateTimeInterfaceCast::class,
  format: 'U')]` property was documented as an integer in the response too, and is now the configured
  string the server sends; the request still takes the integer. `#[WithTransformer(…, format: 'Y-m-d')]`
  is `format: date` where the app's format stood. A transformer of your own, on the property or in
  `data.transformers`, now leaves the property untyped rather than documented as a date string it may
  not be.
- **A request date is documented as laravel-data reads it.** A list of formats, in `data.date_format` or
  a `#[WithCast(DateTimeInterfaceCast::class, format: [...])]`, names every format the cast tries, where
  the list was documented as the ISO default. A cast of your own, on the property or in `data.casts`, and
  a property typed `DateTimeInterface`, which laravel-data's cast cannot build, now leave the property
  untyped. A `!` or `|` in a cast's format is left out of the format named and the example, so `!Y-m-d`
  is `format: date`. Laravel's `date_format:Y-m-d,d/m/Y` names both patterns, where it named the first.
  Most of these only widen; a `format` arriving or changing is read as breaking.
- **An Eloquent `timestamp` column says what it counts.** In a response it is still an integer, now
  described as a Unix timestamp in seconds.
- **`docuccino:diff` reads a date whose type changes as breaking.** The `U` cast's integer becoming a
  string is a changed type. Your API did not change: the document now says what your server already
  sent.

### What `docuccino:diff` reports across this upgrade

Only if you diff a document committed before the upgrade against a build after it, or gate it with
`--enforce`. Eight fixes make a choice rule publish what Laravel checks, three more do the same for
Spatie Data's rule strings, its validation attributes and the attributes that build a rule object, one
more for a required rule on a literal condition, and the differ reads each as breaking where the rule is
used:

- **`new Enum` and `new In` publish their values.** Both were dropped, so the field published no
  `enum`. An `enum` arriving where nothing constrained the field (`schema.enum-added`) is a narrowing,
  and a narrowing is breaking.
- **`Rule::notIn()` and `new NotIn` publish the values they exclude.** They were dropped too. A `not`
  arriving (`schema.not-added`) is a narrowing in the same way.
- **An enum case publishes its backing value**, as above. The case's name leaving and its value
  arriving are reported together, and a value leaving an `enum` (`schema.enum-value-removed`) is
  breaking.
- **A choice list beside a type rule publishes its values in that type.** `string|in:1,2` publishes
  `"1"` and `"2"` where it published `1` and `2`, and `string` beside `Rule::enum()` of an int-backed
  enum lists the cases' strings where it referred to the enum's component. A value of no such type
  leaves the list (`integer|in:1,any` publishes `[1]`), and a value leaving an `enum` is breaking.
- **A rule object in a Spatie Data attribute publishes its values.** `#[Rule(new Enum(…))]`, a
  `new In`/`new NotIn` in a `#[Rule([…])]` list, and the object an `#[Enum]`, `#[In]` or `#[NotIn]` takes
  in place of its parameters were dropped, so the field published no `enum` or `not`; one arriving is a
  narrowing. An `#[Enum]` with `only:` or `except:` listed every case, and the cases they leave out now
  leave the `enum`. An `#[Exclude(new ExcludeIf(false))]` excludes nothing, so the rules beside it,
  which it dropped, now publish. An `#[Enum]` refers to the enum's component, as `Rule::enum()` does,
  where it listed the same values inline: the differ reports the component and its case descriptions
  arriving, neither of them breaking.
- **A Spatie Data rule string is split as laravel-data splits it.** `#[Rule('string|max:3')]`, a piped
  string in a `#[Rule([…])]` list, and one in a list in `rules()` were read as one rule named
  `string|max`, so `max:3` was dropped and only a `validation.rule-unhandled` note named it. Each rule
  now publishes, and a `maxLength` arriving is a narrowing. A `rules()` string holding `regex:` stays
  whole, so `'regex:/^(a|b)$/'` publishes its `pattern`, where it was split at the `|` and published
  none.
- **A choice list is read as Laravel reads it.** A rule string's list is a CSV row, so `in:"x,y",z`
  lists `x,y` where it listed `"x` and `y"`, and the empty entry in `boolean|in:1,` is kept, so `false`
  joins the `enum`. Spatie Data's `#[In]` and `#[NotIn]` list each value they are given as one entry:
  `#[In(['a,b', 'c'])]` lists `a,b` where it listed `a` and `b`, and `#[In([true, false])]` beside a
  `bool` lists both where it published `not: {}`, refusing every value; `#[NotIn([false])]` now excludes
  `false`. An entry leaving an `enum` and a `not` arriving are breaking, as above. A `regex` holding a
  comma publishes it as written, where `/^a, b$/` published `^a,b$`; the differ reads a changed `pattern`
  as breaking.
- **A choice list beside `array` lists the elements.** `array|in:a,b` published `enum: [a, b]` on the
  array itself, refusing every array the server takes; the `enum` now sits on `items`, typed by any
  `field.*` rule beside it. The differ reports it leaving the field (`schema.enum-removed`) and arriving
  on `items` (`schema.enum-added`), a narrowing, so breaking. `array|not_in:a,b` published a `not` that
  excluded no array; it now refuses the arrays whose every element is listed, `[]` among them, and its
  new list arriving is breaking too.
- **A choice attribute on a Spatie Data array property lists the elements.** laravel-data adds `array`
  to every property typed `array`, so an `#[In]`, `#[NotIn]` or `#[Rule('in:…')]` on one reads as the
  bullet above, a map's values being its elements. `#[In('a', 'b')]` on a `list<string>` published
  `enum` on the array, and on an `array<string, string>` on the object, refusing every value the server
  takes; the `enum` now sits on `items`, or on `additionalProperties` for a map. `#[NotIn]` now
  refuses the arrays or maps whose every value is listed. The differ reads both as the bullet above.
- **A Spatie Data validation attribute publishes the rule laravel-data writes for it.** That rule is the
  attribute's own keyword, so `#[GreaterThan(5)]` is `gt:5`, where it was read as `greater_than:5`, a
  rule Laravel does not have, and only a `validation.rule-unhandled` note named it. `#[GreaterThan]`,
  `#[GreaterThanOrEqualTo]`, `#[LessThan]` and `#[LessThanOrEqualTo]` given a number now publish their
  bound, and `#[ListType]` makes the field an array; each arriving is a narrowing. Arguments are read in
  the order the attribute declares them, so `#[Between(max: 10, min: 1)]` publishes `minimum: 1` and
  `maximum: 10`, where it published the two swapped and refused every value; bounds moving outward are
  not breaking.
- **A Spatie Data attribute that builds a rule object publishes what that object checks.**
  `#[Password(min: 10)]` was read as `password:10`, a rule Laravel does not have, and published no
  constraint; it now publishes the `minLength: 10` of the `string|min:10` a password is validated with
  first, a narrowing. `#[Dimensions(minWidth: 10)]` described the image as `10`, and now as
  `min_width=10`, which is not breaking.
- **A required or prohibited rule on a literal condition publishes what it settles to.**
  `Rule::requiredIf(true)` and `new RequiredIf(true)` were left out with a `validation.rule-unrecoverable`
  note, so the field was optional; it is now required (`schema.required-added`), which is breaking.
  `Rule::prohibitedIf(true)` and `new ProhibitedIf(true)` now leave the field out, as `prohibited` does,
  which is not breaking. The rest only widen. A Spatie Data `#[Required(new RequiredIf(false))]` replaces
  the `required` the property's type implied, as it does in laravel-data, so the property is no longer
  required. A `#[Prohibited(new ProhibitedIf(false))]` property was left out and is now documented. A
  closure condition is no longer called during the build, so the field is optional, or sendable.

So a gate run with `--enforce` fails on the upgrade. Your API did not change: in each case the
document now says what your server already did. Upgrade in a pull request of its own, so the
changeset holds only what the upgrade moved, and read it before you merge.

## v0.23.0

Only if you read uploaded documents through `Docuccino\Core\Reader`. Nothing about a generated document
changes.

- **`read()` refuses YAML whose merge keys could cost more than its size allows.** Where the text could
  spell a merge key (`<<`), a document may name at most 524,288 ÷ (its separators + 1) aliases to a map
  or a list, and never more than 128, a separator being a comma, a bracket, a brace or a line break. Up
  to 4,095 separators that is the 128 it always was. Past it the document is refused with
  `ReadFailure::AliasExpansion`, and so is one past 128, which was `ReadFailure::Syntax`. Its remedy, if
  you word your own: remove the merge keys, or upload the document as JSON.
- **`read()` refuses YAML whose tags or merge keys say what they cannot.** A `!!float` on what is not a
  number, a merge key naming anything but a map or a list of maps, a merge symfony/yaml reads otherwise
  than YAML does (a list named by an alias, a map with a `0` key, an anchored map on the merge key's own
  line), and a key written twice after a merge, or over a null on symfony/yaml 7.4, are refused with
  `ReadFailure::Syntax`. Each was read as something the document did not say: `!!float abc` as 0, a merged
  list as a map of its positions.
- **And reads some YAML as it was written.** An impossible timestamp (`2024-02-30`) is that string, where
  it was moved into the next month or refused; a timestamp written as a key is its text, where it was a
  count of seconds; `true`, `false`, `null`, `.inf` and `.nan` in a case YAML does not spell them (`tRUE`),
  and whatever YAML's non-specific tag `!` is on, are strings; and `!!float "1.5"` is 1.5, where it was 0.
- **`nodes()` keys a custom operation by where it is written.** An operation under OpenAPI 3.2's
  `additionalOperations` is keyed `additionalOperations/COPY /forms/{p0}`, where it was `COPY /forms/{p0}`,
  and the keys of its parameters and responses open the same way. Its method keeps its case, so a custom
  `get` or `Get` is no longer keyed `GET /forms/{p0}` beside the fixed `get`, and a custom `get` no longer
  takes the fixed `get`'s place: that operation and its nodes are listed again. A `~` or `/` in the method
  is escaped as its pointer escapes it, and a space, which only a document OpenAPI calls invalid can hold,
  as `~2`. A fixed method's nodes, and every node of a document without `additionalOperations`, keep their
  keys, and a custom operation's nodes keep their pointers, ids, labels and digests. If you store keys, a
  custom operation's stored ones match nothing now: match each to its new key by its pointer, or it reads
  as one node removed and another added.

## v0.22.0

Only if you read uploaded documents through `Docuccino\Core\Reader` or call the differ yourself.
Nothing about a generated document changes.

- **A validation's findings are only findings.** `ReadDocument::validate($max)` no longer ends a list
  it cut with a line saying "more than N findings". The list holds at most `$max` places the
  document fails, and `isTruncated()` says whether there were more — now also when the meta-schema
  check cut its own list, which used to come back as a finding with `isTruncated()` false. If you
  showed that line, show your own when `isTruncated()` is true.
- **`read()` refuses one more kind of document.** A document whose node index would hold more nodes
  than the document takes bytes as compact JSON — paths sharing one path item that lists its
  parameters for every operation — is refused with `ReadFailure::NodeExpansion`. Whitespace, comments
  and YAML layout are no part of that size, so the JSON `json()` writes reads back under the limit
  the document was read under. A `match` over `ReadFailure` needs the case.
- **`IncomparableDocumentsException` says why.** Its `$failure` is a `DiffFailure`:
  `AlgorithmMismatch` for the refusal it always made, and `ReferenceExpansion` when comparing the
  two documents would read their shared nodes far more often than any document needs. Every place
  that catches it already handles both. Constructing one with `new` now takes the failure first.

What you can stop writing by hand: `DocumentNode::toArray()`, `DocumentGenerator::toArray()`,
`Syntax::mediaType()`, and `DocumentInspection::$uirVersion` — the major.minor UIR version
`validate()` picks the extension's schema by.

## v0.21.0

The full artifact (`--format=full`) is now valid OpenAPI everywhere it shares a component. When an
operation uses a shared response or parameter through a `$ref`, that use's own `x-docuccino` — its id,
its provenance, its facts — used to sit beside the `$ref`, where OpenAPI does not allow an extension and
a strict reader refuses the whole document. It now sits on the operation:

```json
"get": {
  "x-docuccino": {
    "id": "op:v1:…",
    "uses": {
      "responses": { "404": { "id": "res:v1:…", "provenance": […] } },
      "parameters": { "header": { "Api-Version": { "id": "par:v1:…" } } }
    }
  },
  "responses": { "404": { "$ref": "#/components/responses/NotFound" } }
}
```

The extension spec is 2.1 (`x-docuccino.generator.specVersion`, served at
`https://spec.docuccino.app/uir/2.1/`), and the 2.1 schema refuses the old position.

### What you do not have to do

- **Nothing to your code or your config.** Regenerate and commit the artifact as usual.
- **Nothing to your diffs.** Ids did not change, only where they are written, and `docuccino:diff`
  reads a 2.0 artifact and a 2.1 one as the same document, so a versioning gate pairs across the
  upgrade by identity with nothing to re-baseline.
- **Nothing to an OpenAPI export.** `openapi-3.2`, `openapi-3.1` and `openapi-3.0` never carried
  `x-docuccino`, and are unchanged byte for byte.

### What changes

- **`contentHash` changes once** for a document that shares a response or parameter, because the hash
  is of the document as published and the published document moved. Ids and diffs are unaffected.
- **Anything of yours that reads the full artifact directly** finds a use's node under its operation's
  `x-docuccino.uses` rather than beside the `$ref`: a response by status, a parameter by `in` and then
  `name`.
- **A shared response's use no longer carries `facts.component`.** It repeated the name of the component
  its `$ref` points at, and in the cases where they differed it was the name the use had asked for rather
  than the one it got. The `$ref` now answers which component a use resolves to, and
  `facts.claimedComponent` appears only where a claim did not land, with the name that was asked for.
- **The same goes for `facts.componentDescription`:** it leaves a use whose sentence the shared schema
  publishes, and stays as `facts.claimedComponentDescription` where it does not.
- **`facts.examplePlaceholders` moves to the shared response component**, keyed by the example it
  describes — `example`, or the key in an `examples` map — because that is where the example is
  published. A use of a shared response no longer carries it.

### Code that calls the spec checks

Only if you call `Docuccino\Core\SpecValidation` yourself. Nothing about a generated document changes.

- **A finding is data now.** `OpenApiMetaSchema::findings()` and its siblings return
  `SpecValidation\Finding` objects instead of strings. Each has `pointer`, `keyword`, `message` and
  `schemaPointer`, and `(string) $finding` is the line the string used to be.
- **`ValidationError` is gone.** `ValidationResult::$errors` holds `Finding`s, so `pointer` and `message`
  read as before; `messages()` now renders each as `<pointer> <keyword>: <message> (schema …)`.
- **Every failure is reported, not only the first.** Up to 50, then a last finding saying there were
  more. A document invalid in several places gets one `document.schema-invalid` diagnostic per place.

## v0.20.0

Docuccino now calls its output what it has always been: an OpenAPI 3.2 document carrying one vendor
extension, `x-docuccino`. Nothing your document *means* changes in this release. The same
operations, the same schemas, the same identities, the same hashes. Two root members move, one
export format is renamed, and the vocabulary follows both.

### `--format=uir` is now `--format=full`

The id names the artifact by what it keeps rather than by a version: the same OpenAPI 3.2 document
as `openapi-3.2`, with the `x-docuccino` extension retained rather than stripped.

Rename it wherever you spell it — the `--format` flag, and any `export.targets` entry:

```yaml
documents:
  default:
    export:
      targets:
        - { format: 'openapi-3.2', path: 'docs/openapi.json' }
        - { format: 'full', path: 'docs/api.full.json' }
```

There is no deprecated alias. `uir` is not a format any more, and asking for it — on the flag or in
an `export.targets` entry — fails before the build starts. The error ends by naming the replacement,
`"uir" is now "full".`, so a CI pipeline that still passes the old id stops with an answer rather
than a puzzle.

### Two root members moved into the extension

| Before, at the document root | Now |
| --- | --- |
| `$schema` | `x-docuccino.generator.schema` |
| `uir` | `x-docuccino.generator.specVersion` — which already carried the same value |

Anything of yours that reads either member at the root reads it under `generator` instead. Both are
facts about the tool that built the document rather than about your API, which is where they now
sit.

If you validate against a pinned schema URL in CI, read the URL out of
`x-docuccino.generator.schema` rather than hard-coding one. That member always names the schema the
document in your hand was built against.

That schema is now **two files**. `schema.json` describes the whole document and references
`extension.schema.json` — the `x-docuccino` member on its own — by its absolute URL. Fetching the
first fetches the second, so an online check needs no change. A vendored copy does: take both files,
and register the extension one under the `$id` it declares (`-r` with ajv). Given only `schema.json`,
a validator either reaches out to `spec.docuccino.app` on every run or stops at an unresolved
reference. [Schema hosting](https://docs.docuccino.app/uir/hosting/) has the worked commands.

Nothing forces you to rename the artifact itself. The documentation now spells the full document
`docs/api.full.json`, matching the format id; an existing `docs/api.uir.json` target keeps working,
and the path was always yours to choose.

### Your `contentHash` does not move

Not one document's. `x-docuccino.generator` has never been part of `contentHash` — that is the whole
reason these two members were put there rather than anywhere else — so relocating them changes no
hash anywhere.

What does change is bytes: a committed full artifact has the two members in a new place, so the
first build after upgrading shows a diff. `docuccino:diff` reads it for what it is and reports no
change to your API.

### Your diff history is preserved

Node identities are computed from meaning — never from a document-level member — and the identity
algorithm version is untouched. So `docuccino:diff` still pairs a v0.19 artifact against a v0.20 one
by identity, exactly as it did before, and your versioning gate keeps working across the upgrade
with nothing to re-baseline.

### What you do not have to do

- **Nothing to your code.** No attribute, docblock, config key or extension API changes.
- **Nothing to your OpenAPI exports.** `openapi-3.2`, `openapi-3.1` and `openapi-3.0` are unchanged
  byte for byte — they already stripped both members.
- **No re-baselining.** See above: identities and the algorithm version are untouched.
