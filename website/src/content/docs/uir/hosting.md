---
title: Schema hosting
description: Where Docuccino's JSON Schemas live, how they're versioned, and how to validate a document against them online or offline.
---

Every document Docuccino builds names the schema it was written against, inside the extension:

```json
"x-docuccino": {
  "generator": {
    "specVersion": "2.0.0",
    "schema": "https://spec.docuccino.app/uir/2.0/schema.json"
  }
}
```

That URL is a real, fetchable JSON Schema — a static file served at exactly the address it declares as
its own `$id`. Any JSON Schema tooling can retrieve and validate against it, with nothing special
required. Read the URL out of the document rather than hard-coding one, and a validation step keeps
checking each artifact against the schema it was actually built for.

## What the schemas are

From 2.0 there are two files, and the split is the point: `x-docuccino` is an OpenAPI extension, so the
half that describes it applies on top of *any* OpenAPI document.

| | |
| --- | --- |
| **Dialect** | JSON Schema draft 2020-12 |
| **Document schema** | `https://spec.docuccino.app/uir/2.0/schema.json` — a valid OpenAPI 3.2 document that additionally satisfies the extension schema |
| **Extension schema** | `https://spec.docuccino.app/uir/2.0/extension.schema.json` — the `x-docuccino` member alone, strict, applicable to any OpenAPI document |
| **Required root members** | `openapi`, `info`, `paths` — the OpenAPI Object's own, and nothing else |
| **External references** | The document schema references the extension schema by its absolute `$id`, so a validator needs both files |

That root member list is the short version of the whole claim: what Docuccino builds is an OpenAPI 3.2
document, and everything it records beyond OpenAPI lives under the one `x-` member the specification
reserves for exactly that.

Both files ship inside the `docuccino/core` package, and
[`docuccino:validate`](/laravel/reference/commands/#docuccinovalidate) resolves the reference between
them from that install — it never opens a network connection, whatever the `$id` says. If you vendor
the schemas into a build of your own, vendor **both** and tell your validator about the second one
(below): one handed only the document schema either fetches the extension schema over the network or
fails on an unresolved reference.

## Versioning

The schemas are versioned independently of the Docuccino packages, and the version is embedded in
the URL as `major.minor`:

```
https://spec.docuccino.app/uir/2.0/schema.json
```

- **Additive changes** (new optional members) are a **minor** bump, and because the URL carries the
  minor they get a new URL of their own. Every earlier URL stays served, so a document written against
  one keeps validating against the schema it was built for.
- **Structural changes** get a new **major** version, likewise at its own URL.

New members added in a minor revision are optional, so a document that predates them validates against
the newer schema too. Because the `x-docuccino` subtree is strictly closed to undefined members, growth
happens by versioning the schema — never by readers silently tolerating members they don't recognize.

`x-docuccino.generator.specVersion` (`"2.0.0"`) is the precise version a document was written against;
the URL carries only `major.minor`, so both `2.0.0` and a later `2.0.1` validate against `/uir/2.0/`.

| Version | Schemas | Added |
| --- | --- | --- |
| 2.0 | [schema.json](https://spec.docuccino.app/uir/2.0/schema.json), [extension.schema.json](https://spec.docuccino.app/uir/2.0/extension.schema.json) | `$schema` and `uir` move off the root into `x-docuccino.generator`, so the document is valid OpenAPI 3.2 exactly as built, and the extension gets a schema of its own |
| 1.1 | [schema.json](https://spec.docuccino.app/uir/1.1/schema.json) | `x-docuccino.workflows` — declared multi-step sequences over the document's own operations |
| 1.0 | [schema.json](https://spec.docuccino.app/uir/1.0/schema.json) | The initial document |

## Validating a document

Docuccino validates its own output against these schemas on every build, so in normal use you don't need
an external validator. Reach for one when you're building tooling that *consumes* these documents, or
checking an artifact someone else produced.

Export the full document, then point any draft 2020-12 validator at it:

```bash
php artisan docuccino:export --format=full --out=docs/api.full.json

# Python — pipx install check-jsonschema
check-jsonschema \
  --schemafile https://spec.docuccino.app/uir/2.0/schema.json \
  docs/api.full.json
```

That reads both files: the document schema names the extension schema by URL, and a validator that
resolves remote references follows it.

For CI or an air-gapped build, vendor both schemas and register the extension one under the `$id` the
document schema references. With ajv that is `-r`, and the file declares its own `$id`, so there is
nothing further to configure:

```bash
curl -O https://spec.docuccino.app/uir/2.0/schema.json
curl -O https://spec.docuccino.app/uir/2.0/extension.schema.json

npx ajv-cli validate --spec=draft2020 --strict=false \
  -s schema.json -r extension.schema.json \
  -d docs/api.full.json
```

`--strict=false` is ajv's requirement, not ours: the schemas carry an `x-canonicalOrder` annotation
recording the member order Docuccino writes, and ajv's strict mode refuses any keyword it doesn't know.

The `$id`s are stable, so a document validates identically whether the schemas are fetched or read from
disk — as long as both are there. Give ajv only `schema.json` and it stops at the unresolved reference;
give a fetching validator only a local copy and it goes to the network for the other half on every run.

:::note[Publish the OpenAPI export, keep the full one]
Both artifacts are valid OpenAPI 3.2, so a strict OpenAPI validator accepts either. What separates them
is disclosure: every provenance record in the full document names the `file`, `line` and `symbol` a fact
came from, which is a readable map of your codebase. Keep the full document in your repository, where
[`docuccino:diff`](/laravel/reference/commands/#docuccinodiff) and the viewer read it, and hand consumers
`docuccino:export --format=openapi-3.2` output — the same description with the extension stripped.
:::
