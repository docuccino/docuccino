---
title: Spec hosting
description: Where the UIR JSON Schema lives, how it's versioned, and how to validate a document against it online or offline.
sidebar:
  order: 2
---

Every UIR document declares its schema with a `$schema` URL:

```json
"$schema": "https://spec.docuccino.app/uir/1.0/schema.json"
```

That URL is a real, fetchable JSON Schema — a static file served at exactly the address it declares as
its own `$id`. Any JSON Schema tooling can retrieve and validate against it, with nothing special
required.

## What the schema is

| | |
| --- | --- |
| **Dialect** | JSON Schema draft 2020-12 |
| **`$id`** | `https://spec.docuccino.app/uir/1.0/schema.json` |
| **Required root members** | `uir`, `openapi`, `info`, `paths` |
| **External references** | None — every `$ref` is internal, so one file is the whole schema |

Being self-contained is deliberate: you can vendor the file into an air-gapped build and validation
behaves identically to fetching it. The same file ships inside the `docuccino/core` package, which is
what [`docuccino:validate`](/laravel/reference/commands/#docuccinovalidate) uses.

## Versioning

The UIR format is versioned independently of the Docuccino packages, and the version is embedded in
the URL as `major.minor`:

```
https://spec.docuccino.app/uir/1.0/schema.json
```

- **Additive changes** (new optional members) are a minor bump on the same URL.
- **Structural changes** get a new major version at a new URL, so documents written against an older
  version keep validating against the schema they were built for.

New members added in a minor revision are optional, so a document that predates them still validates.
Because the `x-docuccino` subtree is strictly closed to undefined members, growth happens by
versioning the schema — never by readers silently tolerating members they don't recognize.

A document's `uir` member (`"1.0.0"`) is the precise format version it was written against; the URL
carries only `major.minor`, so both `1.0.0` and a later `1.0.1` validate against `/uir/1.0/`.

## Validating a document

Docuccino validates its own output against this schema on every build, so in normal use you don't need
an external validator. Reach for one when you're building tooling that *consumes* UIR documents, or
checking an artifact someone else produced.

Export a UIR document, then point any draft 2020-12 validator at it:

```bash
php artisan docuccino:export --format=uir --out=docs/api.uir.json

# Python — pipx install check-jsonschema
check-jsonschema \
  --schemafile https://spec.docuccino.app/uir/1.0/schema.json \
  docs/api.uir.json
```

For CI or an air-gapped build, vendor the schema and validate against the local copy — ajv needs the
dialect named explicitly:

```bash
curl -o uir-1.0.schema.json https://spec.docuccino.app/uir/1.0/schema.json

npx ajv-cli validate --spec=draft2020 -s uir-1.0.schema.json -d docs/api.uir.json
```

The `$id` is stable, so a document validates identically whether the schema is fetched or read from
disk.

:::note[OpenAPI validators want the OpenAPI export]
A UIR document carries members OpenAPI doesn't define (`$schema`, `uir`, and the `x-docuccino` tree), so
a strict OpenAPI validator will reject it. Validate UIR against this schema, and validate
`docuccino:export --format=openapi-3.2` output with your OpenAPI tool of choice.
:::
