---
title: JSON:API resources (timacdonald/json-api)
description: Document timacdonald/json-api resources as JSON:API documents — the pre-13 package Laravel's first-party resources came from.
sidebar:
  order: 10
---

Activates automatically when [`timacdonald/json-api`](https://github.com/timacdonald/json-api) is
installed. This is the package Laravel 13's first-party JSON:API resources were upstreamed from, so
if you're on an earlier Laravel — or simply prefer the package — your resources are documented
identically.

## What it documents

A resource extending `TiMacDonald\JsonApi\JsonApiResource` becomes a JSON:API document schema, read
from the same methods the framework's first-party resources expose:

- **`toId()` / `toType()`** — the always-present `id` and `type` strings.
- **`toAttributes()`** — the `attributes` object.
- **`toRelationships()`** — the `relationships` object.
- **`toLinks()` / `toMeta()`** — the `links` and `meta` objects, when present.

Endpoints returning one of these resources (or a collection of them) also gain the JSON:API
`include` and `fields[TYPE]` query parameters.

```php
// app/Http/Resources/UserResource.php
class UserResource extends JsonApiResource
{
    public function toAttributes(Request $request): array
    {
        return [
            'name'  => $this->name,
            'email' => $this->email,
        ];
    }

    public function toRelationships(Request $request): array
    {
        return [
            'team' => fn () => TeamResource::make($this->team),
        ];
    }
}
```

This produces a `{ data: { id, type, attributes, relationships } }` document schema, hoisted into a
reusable component, plus `include` and `fields[users]` query parameters on the endpoints that return
it.

## Which one runs

If your app is on Laravel 13 with the first-party JSON:API resources, that support handles them;
this integration handles the `timacdonald/json-api` base class. Both produce the same document
shape, so you can migrate from the package to the framework resources without your documentation
changing.
