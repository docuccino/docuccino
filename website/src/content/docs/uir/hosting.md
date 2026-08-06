---
title: Spec hosting
description: Where the UIR JSON Schema lives, how it's versioned, and how to validate against it.
sidebar:
  order: 2
---

A UIR document declares its schema with a `$schema` URL:

```json
"$schema": "https://spec.docuccino.app/uir/1.0/schema.json"
```

That URL is a real, fetchable JSON Schema, served as a static file at exactly that address. Any
JSON Schema tooling can retrieve and validate against it — nothing special required.

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
Because the `x-docuccino` subtree is strictly closed, this growth happens by versioning the schema —
never by readers silently tolerating members they don't recognize — so additive growth never breaks a
document validating against the version it declares.

## Validating a document

To validate a UIR document you exported (`docuccino:export --format=uir`), point any JSON Schema
validator at the `$schema` URL — or at a local copy of the schema for offline/air-gapped use. The
`$id` is stable, so a document validates identically whether the schema is fetched remotely or read
from disk.

:::tip
Docuccino validates its own output against this schema during `docuccino:validate`, so in normal use
you don't need to run an external validator — this is here for when you're building tooling that
consumes UIR documents.
:::
