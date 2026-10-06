# Upgrading

One section per release that asks something of you, newest first. A release not listed here needs
nothing but the version bump — the [changelog](https://docs.docuccino.app/changelog/) carries the
full record either way.

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
