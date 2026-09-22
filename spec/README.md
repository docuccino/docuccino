# UIR specification

The versioned JSON Schemas for the **UIR** (Universal Intermediate Representation) — the document
Docuccino compiles application code into. From 2.0 it is a valid OpenAPI 3.2 document carrying one
reserved member, `x-docuccino`, and the schema comes in two halves: the **extension schema**, which
describes that member alone and so applies on top of any OpenAPI document, and the **document
schema**, which is an OpenAPI 3.2 document that additionally satisfies it.

The document schema is **self-contained**: it embeds the extension schema as a resource of its own,
so vendoring `schema.json` on its own is enough to validate a document offline, with nothing to fetch
and nothing to register. The extension schema stays published separately because that is the half a
third party applies to an OpenAPI document Docuccino did not write.

That embedded block — `$defs/extension` in the document schema — is **generated** from the extension
schema beside it, by `composer sync-schema` in the monorepo. It is a copy, so it is not the place to
change anything, and reordering or reformatting `$defs` by hand makes the committed bytes no longer
what the tool writes: edit `extension.schema.json` and re-run it.

Each schema is served as a static file at its exact `$id` URL:

| Version | `$id` |
| ------- | ----- |
| 1.0     | <https://spec.docuccino.app/uir/1.0/schema.json> |
| 1.1     | <https://spec.docuccino.app/uir/1.1/schema.json> |
| 2.0     | <https://spec.docuccino.app/uir/2.0/schema.json> |
| 2.0     | <https://spec.docuccino.app/uir/2.0/extension.schema.json> |

## This repository is read-only

It is a subtree split of the `spec/` directory of
[docuccino/docuccino](https://github.com/docuccino/docuccino), published so that GitHub Pages can
serve `spec.docuccino.app`. Commits pushed here are overwritten on the next release.

Open issues and pull requests on the monorepo. The schemas' authoring copies live under
`spec/uir/<version>/` there, and `php/core` ships a byte-identical package-relative copy of each so
`Validator` resolves them from a `vendor/` install rather than over the network — the `$id` above is
an identifier, not a runtime fetch.

Documentation: <https://docs.docuccino.app>
