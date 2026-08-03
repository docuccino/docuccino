---
title: Rate limiting
description: Document 429 responses and rate-limit headers from throttle middleware.
sidebar:
  order: 7
---

Any route with Laravel's `throttle` middleware is documented with a `429 Too Many Requests` response
and the accompanying `Retry-After` and `X-RateLimit-*` headers. This is on by default — no package or
configuration required.

## What it documents

Docuccino reads the throttle configuration from the middleware:

| Middleware | Documented limit |
| --- | --- |
| `throttle:60,1` | 60 requests per 1 minute. |
| `throttle:60` | 60 requests per minute. |
| `throttle:api` (a named limiter) | The `429` response and headers, without concrete numbers — plus an info diagnostic. |

```php
// routes/api.php
Route::middleware('throttle:60,1')->get('/invoices', [InvoiceController::class, 'index']);
```

## Configuration

None.

## Named limiters

A named limiter (`throttle:api`) registers its rate inside a closure — `RateLimiter::for('api', fn () => ...)`
— which Docuccino never executes. The `429` response and its headers are still documented, but the
concrete numbers can't be recovered, so Docuccino emits an info diagnostic noting the limit came from a
named limiter. Inline limits (`throttle:60,1`) carry their numbers straight through.
