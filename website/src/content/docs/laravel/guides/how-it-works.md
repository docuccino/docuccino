---
title: How it works
description: The Docuccino pipeline — route discovery, static analysis that never runs your code, the UIR, emitters, and the viewer — plus the precedence ladder that decides which source wins.
sidebar:
  order: 1
---

Docuccino turns your application into documentation in five stages. The most important thing to know
about that pipeline is what it **doesn't** do: it never executes your application code.

<figure>
<svg viewBox="0 0 960 120" role="img" aria-label="Pipeline: route discovery, then static analysis, then UIR, then emitters, then viewer" style="width:100%;height:auto;font-family:inherit">
  <g fill="none" stroke="currentColor" stroke-width="1.5">
    <rect x="12" y="34" width="150" height="52" rx="8"/>
    <rect x="204" y="34" width="150" height="52" rx="8"/>
    <rect x="396" y="34" width="150" height="52" rx="8"/>
    <rect x="588" y="34" width="150" height="52" rx="8"/>
    <rect x="780" y="34" width="168" height="52" rx="8"/>
  </g>
  <g stroke="currentColor" stroke-width="1.5" fill="none">
    <path d="M168 60 H198 M192 55 L198 60 L192 65"/>
    <path d="M360 60 H390 M384 55 L390 60 L384 65"/>
    <path d="M552 60 H582 M576 55 L582 60 L576 65"/>
    <path d="M744 60 H774 M768 55 L774 60 L768 65"/>
  </g>
  <g fill="currentColor" text-anchor="middle" font-size="14">
    <text x="87" y="56">Route</text><text x="87" y="72">discovery</text>
    <text x="279" y="56">Static</text><text x="279" y="72">analysis</text>
    <text x="471" y="64">UIR</text>
    <text x="663" y="64">Emitters</text>
    <text x="864" y="56">Viewer &amp;</text><text x="864" y="72">export</text>
  </g>
</svg>
</figure>

## 1. Route discovery

Docuccino asks Laravel's router which routes exist, then keeps the ones your document selects — the
`routes.include` / `routes.exclude` globs and an optional `closure` filter. Routes whose controller
lives under `vendor/` are skipped unless you set `routes.include_vendor`, matching
`php artisan route:list --except-vendor`.

Each surviving route carries its real context: the URI template and its path parameters,
route-model bindings, middleware, and the controller method behind it. A route registered for
several verbs becomes one operation per method, so `GET` and `POST` on the same URI are documented
independently.

## 2. Static analysis

This is the heart of Docuccino, and the reason it's safe to run anywhere. An embedded static-analysis
engine (PHPStan + Larastan) reads the **types** in your code — controller return types, validation
rules, resource `toArray()` shapes, exception handlers, query builders — and infers the contract. It
ships as a separate dev dependency, `docuccino/inference-phpstan`, because analysis is a build-time
job: production serves the finished document with no analyser installed.

It **never runs your code**. No controller is invoked, no database is touched, no queue job fires, no
email is sent. That's the trust argument: documenting a payment endpoint can't charge a card, and
generating docs in CI needs no seed data and has no side effects. It's also what makes the output
deterministic — there's no runtime state to vary between runs.

Because the analysis follows types rather than executing calls, it traces *through* your helper
methods: a query builder assembled a few methods deep is still understood, an inline
`Validator::make(...)` still yields real parameters, and an exception thrown by a service the
controller calls still becomes a documented error response. The walk is bounded and stays inside your
own code — `engine.project_paths` says where to descend, and `vendor/` is never analyzed.

Each route is analyzed in isolation, so one route that can't be understood never breaks the build.
It leaves behind a skeleton operation and an error diagnostic (or is dropped entirely, with
`on_route_error: 'omit'`).

## 3. UIR

Everything inferred is assembled into the [UIR](/uir/) — an OpenAPI-shaped document that also carries a
stable identity for every operation and schema, and a record of where each detail came from. This is
also where your [Overlays](/laravel/guides/customizing-output/) and your
[Markdown content tree](/laravel/guides/narrative-content/) are folded in, and where the document's
content hash is stamped.

Several sources can describe the same operation, so they're merged by a fixed order of precedence,
field by field:

<figure>
<svg viewBox="0 0 960 158" role="img" aria-label="Precedence ladder from lowest to highest: fallback 5, inference 10, integration 20, docblock 30, attribute 40, overlay 45, config 50" style="width:100%;height:auto;font-family:inherit">
  <g stroke="currentColor" stroke-width="1.5" fill="none" opacity="0.35">
    <path d="M20 148 H940 M930 143 L940 148 L930 153"/>
  </g>
  <g fill="currentColor" font-size="11" opacity="0.6">
    <text x="20" y="139">lower precedence</text>
    <text x="940" y="139" text-anchor="end">higher precedence — wins the field</text>
  </g>
  <g fill="none" stroke="currentColor" stroke-width="1.5">
    <rect x="20" y="86" width="118" height="26" rx="6"/>
    <rect x="152" y="72" width="118" height="40" rx="6"/>
    <rect x="284" y="58" width="118" height="54" rx="6"/>
    <rect x="416" y="44" width="118" height="68" rx="6"/>
    <rect x="548" y="30" width="118" height="82" rx="6"/>
    <rect x="680" y="16" width="118" height="96" rx="6"/>
    <rect x="812" y="2" width="128" height="110" rx="6"/>
  </g>
  <g fill="currentColor" text-anchor="middle" font-size="13">
    <text x="79" y="103">fallback</text>
    <text x="211" y="96">inference</text>
    <text x="343" y="89">integration</text>
    <text x="475" y="82">docblock</text>
    <text x="607" y="75">attribute</text>
    <text x="739" y="68">overlay</text>
    <text x="876" y="61">config</text>
  </g>
  <g fill="currentColor" text-anchor="middle" font-size="11" opacity="0.6">
    <text x="79" y="127">5</text>
    <text x="211" y="127">10</text>
    <text x="343" y="127">20</text>
    <text x="475" y="127">30</text>
    <text x="607" y="127">40</text>
    <text x="739" y="127">45</text>
    <text x="876" y="127">50</text>
  </g>
</svg>
</figure>

| Layer | Comes from | Typical use |
| --- | --- | --- |
| `fallback` | Docuccino's own defaults | A route-name operation id, an `OK` response description, an untyped path parameter |
| `inference` | The static-analysis engine | Return types, validation rules, resource shapes, thrown exceptions |
| `integration` | A package integration, e.g. `integration:query-builder` | Filters and sorts, pagination envelopes, security schemes, `Data` object schemas |
| `docblock` | PHPDoc on the action or class | A summary and description, `@param`/`@return` detail |
| `attribute` | A Docuccino [attribute](/laravel/reference/attributes/) | `#[Response]`, `#[QueryParameter]`, `#[Group]`, `#[Hidden]` |
| `overlay` | An [OpenAPI Overlay](/laravel/guides/customizing-output/) file | Corrections to routes you don't own, spec-side polish |
| `config` | `config/docuccino.php` | `info`, `servers`, security schemes, tag definitions, representation policies |

Higher layers win individual fields without discarding the rest, and every contribution is recorded
in the document's provenance — including the value it replaced — so you can always answer *why* a
detail is documented the way it is.

## 4. Emitters

Emitters transcode the UIR into what you ship: OpenAPI 3.2, a 3.1 or 3.0 downlevel, or the raw UIR
itself, as JSON or YAML. Because the UIR is canonically ordered and free of timestamps, identical code always
produces byte-for-byte identical output — which is what makes the
[semantic diff](/laravel/reference/commands/#docuccinodiff) and CI version gating possible.

The OpenAPI emitters strip every `x-docuccino` member on the way out, so provenance and identities
stay an internal detail and what you publish is a clean, standard spec.

## 5. Viewer & export

Finally the document is written to disk by [`docuccino:export`](/laravel/reference/commands/#docuccinoexport),
and served by the bundled [Scalar viewer](/laravel/guides/viewer/) straight from your own app. From
there it's yours to commit, diff, and publish — see [Deploying to production](/laravel/guides/production/).

## Rebuilds

A full build re-analyzes every route, which on a large application is the slow part. Turn on the
fragment cache (`cache.enabled`) and Docuccino stores each operation's result keyed by the route,
your config, the resolved extensions, and a content hash of every file the analysis actually read.
Edit one controller and only that operation is rebuilt; change a file it depends on three hops away
and the key changes too, so a stale fragment can't survive.

:::note
You never *have* to think about these stages to use Docuccino — install it and export. But knowing the
pipeline never runs your code is worth the two minutes: it's why the output is trustworthy and
reproducible.
:::
