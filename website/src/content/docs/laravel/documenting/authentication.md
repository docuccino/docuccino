---
title: Authentication
description: Auto-configure Sanctum and Passport security schemes from your route middleware.
sidebar:
  order: 6
---

Docuccino detects how your routes are protected from their middleware and documents the appropriate
security schemes and requirements. If you'd rather configure schemes yourself, declaring any
`security.schemes` in your config takes over and these auto-configurations step aside. Routes marked
[`#[Unauthenticated]`](/laravel/reference/attributes/#unauthenticated) are always documented as public.

## Sanctum

Activates when [Laravel Sanctum](https://laravel.com/docs/sanctum) is installed. Docuccino documents
both of Sanctum's modes based on the middleware a route uses:

- **Token** (`auth:sanctum`) → an HTTP bearer scheme.
- **Stateful SPA** (`EnsureFrontendRequestsAreStateful`) → a cookie-based scheme, with the CSRF/XSRF
  handshake explained in prose.

```php
// config/docuccino.php → documents.default.integrations
'sanctum' => [
    'modes'  => ['token', 'stateful'], // which schemes to expose (default: both)
    'cookie' => 'myapp_session',        // stateful cookie name (default: your session cookie)
],
```

## Passport

Activates when [Laravel Passport](https://laravel.com/docs/passport) is installed. Routes protected
by `auth:api` (or `scope:` / `scopes:` middleware) are documented with an OAuth2 scheme, including the
authorization-code, client-credentials, and password flows, and per-operation scopes read from the
middleware.

```php
// config/docuccino.php → documents.default.integrations
'passport' => [
    'url' => 'https://auth.example.com', // OAuth2 flow base URL (default: your app URL)
],
```

## Authorization: roles & permissions

Authenticating a request is one thing; *authorizing* it — the `role:` / `permission:` middleware from
`spatie/laravel-permission` — is documented by a separate, **opt-in** integration, because permission
names expose your internal authorization taxonomy. See
[Spatie Laravel Permission](/laravel/packages/laravel-permission/).

## Configuration

| Integration | Option | Default | Effect |
| --- | --- | --- | --- |
| _any_ | `enabled` | `true` | Turn the integration on/off for this document. |
| `sanctum` | `modes` | both | Which Sanctum schemes to expose. |
| `sanctum` | `cookie` | your session cookie | Stateful cookie name. |
| `passport` | `url` | your app URL | OAuth2 flow base URL. |

Each integration is a no-op when its package isn't installed, or when a route has no matching
middleware. Sanctum and Passport stay on by default — token schemes and OAuth scopes *are* the public
contract, so they leak nothing internal.
