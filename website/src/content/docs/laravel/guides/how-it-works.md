---
title: How it works
description: The Docuccino pipeline — route discovery, static analysis that never runs your code, the UIR, emitters, and the viewer.
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
`routes.include` / `routes.exclude` globs and an optional `closure`. Each surviving route carries its
real context: the URI template and its path parameters, route-model bindings, middleware, and the
controller method behind it.

## 2. Static analysis

This is the heart of Docuccino, and the reason it's safe to run anywhere. An embedded static-analysis
engine (PHPStan + Larastan) reads the **types** in your code — controller return types, validation
rules, resource `toArray()` shapes, exception handlers, query builders — and infers the contract.

It **never runs your code**. No controller is invoked, no database is touched, no queue job fires, no
email is sent. That's the trust argument: documenting a payment endpoint can't charge a card, and
generating docs in CI needs no seed data and has no side effects. It's also what makes the output
deterministic — there's no runtime state to vary between runs.

Because the analysis follows types rather than executing calls, it traces through helper methods at any
depth — a query builder assembled three methods deep is still fully understood.

## 3. UIR

Everything inferred is assembled into the [UIR](/uir/) — an OpenAPI-shaped document that also carries a
stable identity for every operation and schema, and a record of where each detail came from. Several
sources can describe the same operation (an inferred type, an integration, a docblock, an attribute);
they're merged by a fixed order of precedence, field by field:

```
fallback  <  inference  <  integration  <  docblock  <  attribute  <  overlay  <  config
```

Higher layers win individual fields without discarding the rest, and every contribution is recorded in
the document's provenance — so you can always answer *why* a detail is documented the way it is.

## 4. Emitters

Emitters transcode the UIR into what you ship: OpenAPI 3.2 (or a 3.1 downlevel), JSON or YAML, or the
raw UIR itself. Because the UIR is canonically ordered and free of timestamps, identical code always
produces byte-for-byte identical output — which is what makes the [semantic diff](/laravel/reference/commands/#docuccinodiff)
and CI version gating possible.

## 5. Viewer & export

Finally the document is written to disk by [`docuccino:export`](/laravel/reference/commands/#docuccinoexport),
and served by the bundled [Scalar viewer](/laravel/guides/viewer/) straight from your own app. From
there it's yours to commit, diff, and publish — see [Deploying to production](/laravel/guides/production/).

:::note
You never *have* to think about these stages to use Docuccino — install it and export. But knowing the
pipeline never runs your code is worth the two minutes: it's why the output is trustworthy and
reproducible.
:::
