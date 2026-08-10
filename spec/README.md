# UIR specification

The versioned JSON Schemas for the **UIR** (Universal Intermediate Representation) — the
OAS-3.2-shaped, deterministic, identity-carrying document that Docuccino compiles application code
into.

Each schema is served as a static file at its exact `$id` URL:

| Version | `$id` |
| ------- | ----- |
| 1.0     | <https://spec.docuccino.app/uir/1.0/schema.json> |

## This repository is read-only

It is a subtree split of the `spec/` directory of
[docuccino/docuccino](https://github.com/docuccino/docuccino), published so that GitHub Pages can
serve `spec.docuccino.app`. Commits pushed here are overwritten on the next release.

Open issues and pull requests on the monorepo. The schema's authoring copy lives at
`spec/uir/<version>/schema.json` there, and `packages/core` ships a byte-identical package-relative
copy so `Validator` resolves it from a `vendor/` install rather than over the network — the `$id`
above is an identifier, not a runtime fetch.

Documentation: <https://docs.docuccino.app>
