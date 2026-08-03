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

The permission integration is **opt-in — off by default**, even when
[spatie/laravel-permission](https://spatie.be/docs/laravel-permission) is installed. Documenting
`role:` and `permission:` names would publish your application's internal authorization taxonomy into
the public API spec, so you enable it deliberately, per document:

```php
// config/docuccino.php → documents.default.integrations
'permission' => ['enabled' => true],
```

Once enabled, `role:`, `permission:`, and `role_or_permission:` middleware are documented two ways: a
machine-readable `x-permissions` extension, and a human-readable line appended to the operation's
description (for example, "Requires permission: invoices.view").

When the package is installed but the integration is left disabled, the build emits one
`integration.disabled` info diagnostic per document pointing you at the switch — so the opt-in is
discoverable without publishing anything. (Sanctum and Passport stay on by default: token schemes and
OAuth scopes _are_ the public contract, so they leak nothing internal.)

## Configuration

| Integration | Option | Default | Effect |
| --- | --- | --- | --- |
| _any_ | `enabled` | `true` (**`false` for `permission`**) | Turn the integration on/off for this document. |
| `sanctum` | `modes` | both | Which Sanctum schemes to expose. |
| `sanctum` | `cookie` | your session cookie | Stateful cookie name. |
| `passport` | `url` | your app URL | OAuth2 flow base URL. |
| `permission` | `enabled` | `false` | Opt in to document permission requirements. |

Beyond `enabled`, permissions have no options. Each integration is a no-op when its package isn't
installed, or when a route has no matching middleware.
