---
title: Authentication & permissions
description: Auto-configure Sanctum and Passport security schemes, and document permission-protected endpoints.
sidebar:
  order: 6
---

Docuccino detects how your routes are protected from their middleware and documents the appropriate
security schemes and requirements. If you'd rather configure schemes yourself, declaring any
`security.schemes` in your config takes over and these auto-configurations step aside. Routes marked
[`#[Unauthenticated]`](/reference/attributes/#unauthenticated) are always documented as public.

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

## Permissions

Activates when [spatie/laravel-permission](https://spatie.be/docs/laravel-permission) is installed.
`role:`, `permission:`, and `role_or_permission:` middleware are documented two ways: a
machine-readable `x-permissions` extension, and a human-readable line appended to the operation's
description (for example, "Requires permission: invoices.view").

## Configuration

| Integration | Option | Default | Effect |
| --- | --- | --- | --- |
| `sanctum` | `modes` | both | Which Sanctum schemes to expose. |
| `sanctum` | `cookie` | your session cookie | Stateful cookie name. |
| `passport` | `url` | your app URL | OAuth2 flow base URL. |

Permissions have no options. Each integration is a no-op when its package isn't installed, or when a
route has no matching middleware.
