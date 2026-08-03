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
| `throttle:api` (a named limiter) | The limits registered for that limiter. |

```php
// routes/api.php
Route::middleware('throttle:60,1')->get('/invoices', [InvoiceController::class, 'index']);
```

## Configuration

None.

## When it can't tell

If a named limiter's rate is defined in a closure that can't be read statically, the `429` response
is still documented — just without the specific numbers — and Docuccino notes this with a
diagnostic so you know whether it's a closure-defined limit or an unregistered limiter name.
