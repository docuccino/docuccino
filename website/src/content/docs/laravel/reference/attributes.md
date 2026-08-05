---
title: Attributes reference
description: The docuccino/attributes package — all 25 attributes with signatures and examples.
---


Attributes let you say the things your code can't. They live in the dependency-free
`docuccino/attributes` package under the namespace `Docuccino\Attributes` (e.g.
`use Docuccino\Attributes\Response;`).

You reach for them only where inference falls short — everything else is documented automatically.
When an attribute and inference describe the same thing, the attribute wins, **field by field**: for
example `#[Response(status: 201, description: 'Created')]` sets just the description of the `201`
response and leaves the inferred body schema intact.

Attributes apply to controllers, actions, Form Requests, Data classes, enum cases, and closure
routes, as each attribute's targets allow. A `type:` string (where present) accepts the same type
syntax you use in docblocks.

## Responses

### `#[Response]`

Targets `CLASS | METHOD | FUNCTION`, repeatable.

```php
public function __construct(
    public int $status = 200,
    public ?string $type = null,
    public ?string $description = null,
    public string $mediaType = 'application/json',
)
```

Declares a documented response. Repeatable, so one action can document several statuses.

```php
#[Response(status: 200, type: UserResource::class, description: 'The user')]
#[Response(status: 404, description: 'User not found')]
public function show(int $id): UserResource { /* … */ }
```

### `#[ResponseHeader]`

Targets `CLASS | METHOD | FUNCTION`, repeatable.

```php
public function __construct(
    public string $name,
    public ?string $type = null,
    public ?string $description = null,
    public int $status = 200,
)
```

Documents a single response header on a given status code.

```php
#[ResponseHeader(name: 'X-RateLimit-Remaining', type: 'integer', description: 'Calls left', status: 200)]
public function index(): JsonResponse { /* … */ }
```

## Parameters

Each parameter attribute targets `CLASS | METHOD | FUNCTION` and is repeatable. They **patch or
add** the named parameter — inference fills the rest.

### `#[QueryParameter]`

```php
public function __construct(
    public string $name,
    public ?string $type = null,
    public ?string $description = null,
    public bool $required = false,
    public mixed $default = null,
    public mixed $example = null,
)
```

```php
#[QueryParameter(name: 'page', type: 'integer', description: 'Page number', default: 1, example: 2)]
public function index(): AnonymousResourceCollection { /* … */ }
```

A bracketed `name` (`filter[status]`) patches a flat `filter[status]` parameter, or — when the
document uses the `deepObject` filter style — the `status` property of the `filter` object parameter.
The same attribute works in either representation. Placed on a **Spatie Query Builder custom filter
class**, `#[QueryParameter]` documents that filter (its `name` is ignored — the name comes from
`AllowedFilter::custom`); see [Query Builder → custom filter classes](/laravel/packages/query-builder/#custom-filter-classes).

### `#[PathParameter]`

```php
public function __construct(
    public string $name,
    public ?string $type = null,
    public ?string $description = null,
    public ?string $format = null,
    public mixed $example = null,
)
```

Path params are inherently required (no `required` param); adds an OAS `format`.

```php
#[PathParameter(name: 'uuid', type: 'string', description: 'User id', format: 'uuid', example: '9b1…')]
public function show(string $uuid): UserResource { /* … */ }
```

### `#[HeaderParameter]`

```php
public function __construct(
    public string $name,
    public ?string $type = null,
    public ?string $description = null,
    public bool $required = false,
    public mixed $example = null,
)
```

```php
#[HeaderParameter(name: 'X-Tenant', type: 'string', description: 'Tenant slug', required: true, example: 'acme')]
public function store(StoreRequest $request): JsonResponse { /* … */ }
```

### `#[CookieParameter]`

```php
public function __construct(
    public string $name,
    public ?string $type = null,
    public ?string $description = null,
    public bool $required = false,
    public mixed $example = null,
)
```

```php
#[CookieParameter(name: 'session_id', type: 'string', description: 'Session token', required: true)]
public function me(): UserResource { /* … */ }
```

### `#[BodyParameter]`

```php
public function __construct(
    public string $name,
    public ?string $type = null,
    public ?string $description = null,
    public bool $required = false,
    public mixed $example = null,
)
```

Patches or adds a single property of the *inferred* request body schema.

```php
#[BodyParameter(name: 'nickname', type: 'string', description: 'Display name', example: 'Tom')]
public function update(UpdateUserRequest $request, int $id): UserResource { /* … */ }
```

## Visibility & inclusion

### `#[Hidden]`

Targets `CLASS | PROPERTY`.

```php
public function __construct(string ...$properties) // stored as list<string> $properties
```

Removes properties from a schema — on a property it drops that property; on a class it drops the
named properties (the Eloquent-model / DTO form).

```php
#[Hidden('password', 'remember_token')] // on the class
class User extends Model {}

#[Hidden] // on the property
public string $internalToken;
```

### `#[ExcludeFromDocs]`

Targets `CLASS | METHOD | FUNCTION`. Marker (no constructor). Excludes a route — or every route on
a controller — from the documentation entirely.

```php
#[ExcludeFromDocs]
public function debug(): JsonResponse { /* … */ }
```

### `#[Internal]`

Targets `CLASS | METHOD | FUNCTION | PROPERTY`. Marker. Flags the node as internal, surfaced as
`x-internal: true` (kept in the doc but flagged; SDK generators honor it).

```php
#[Internal]
public function purgeCache(): JsonResponse { /* … */ }
```

### `#[InDocs]`

Targets `CLASS | METHOD | FUNCTION`.

```php
public function __construct(string ...$documents) // stored as list<string> $documents
```

Pins a route (or whole controller) to one or more named output documents.

```php
#[InDocs('public-api', 'partner-api')]
public function webhook(Request $request): JsonResponse { /* … */ }
```

### `#[IgnoreParam]`

Targets `CLASS | METHOD | FUNCTION`, repeatable.

```php
public function __construct(
    public string $name,
    public ?string $in = null,
)
```

Drops an auto-inferred parameter by name, optionally scoped to an `in` location.

```php
#[IgnoreParam(name: 'internal_flag', in: 'query')]
public function index(): AnonymousResourceCollection { /* … */ }
```

### `#[IgnoreResponse]`

Targets `CLASS | METHOD | FUNCTION`, repeatable.

```php
public function __construct(public int $status)
```

Drops an auto-inferred response by status code.

```php
#[IgnoreResponse(status: 500)]
public function show(int $id): UserResource { /* … */ }
```

## Metadata

### `#[Group]`

Targets `CLASS | METHOD | FUNCTION`, repeatable.

```php
public function __construct(
    public string $name,
    public ?string $description = null,
)
```

Assigns an operation to an OAS tag/group (repeatable to place it under several tags).

```php
#[Group(name: 'Users', description: 'User management endpoints')]
class UserController {}
```

### `#[OperationId]`

Targets `METHOD | FUNCTION`.

```php
public function __construct(public string $id)
```

Overrides the human-readable `operationId`.

```php
#[OperationId('users.show')]
public function show(int $id): UserResource { /* … */ }
```

### `#[DeprecatedOperation]`

Targets `CLASS | METHOD | FUNCTION`.

```php
public function __construct(public ?string $reason = null)
```

Marks an operation (or every operation on a controller) deprecated, with an optional reason.

```php
#[DeprecatedOperation(reason: 'Use /v2/users instead')]
public function legacyIndex(): AnonymousResourceCollection { /* … */ }
```

### `#[Unauthenticated]`

Targets `CLASS | METHOD | FUNCTION`. Marker. Clears any inferred security requirement.

```php
#[Unauthenticated]
public function health(): JsonResponse { /* … */ }
```

## Security

These declare (or relax) an operation's security requirement where middleware detection can't see it
— a Gate/policy check, or a `tokenCan()` guard in the action body. They apply over inferred security,
**field by field**, at the attribute precedence layer.

### `#[Security]`

Targets `CLASS | METHOD | FUNCTION`, repeatable.

```php
public function __construct(
    public string $scheme,     // a scheme name from security.schemes config or an integration
    array $scopes = [],        // scopes/abilities required against that scheme (all-of)
)
```

Declares an explicit security requirement referencing a registered scheme by name. Repeat it to model
an **OR-list** — any one alternative satisfies the operation; several scopes in one attribute are an
all-of within that scheme.

```php
// Either an OAuth2 token with `reports.read`, or an API key:
#[Security('oauth2', ['reports.read'])]
#[Security('apiKey')]
public function reports(): JsonResponse { /* … */ }
```

### `#[OptionallyAuthenticated]`

Targets `CLASS | METHOD | FUNCTION`. Marker. Makes the operation usable anonymously **or**
authenticated: the security becomes `[{}, …]` — the empty (anonymous) requirement followed by
whatever was inferred from middleware or declared with `#[Security]`.

```php
#[OptionallyAuthenticated] // works signed-out; richer response when a token is present
public function feed(): JsonResponse { /* … */ }
```

### `#[Abilities]`

Targets `CLASS | METHOD | FUNCTION`.

```php
public function __construct(string ...$abilities) // stored as list<string> $abilities
```

Declares the Sanctum token abilities an operation requires when the check lives in the action body
rather than in `abilities:`/`ability:` middleware. Surfaced as an `x-abilities` extension member and a
"Requires token ability: …" description line (bearer tokens can't carry abilities as OAS scopes).

```php
#[Abilities('posts:publish')]
public function publish(int $id): JsonResponse { /* … */ }
```

## Identity & naming

### `#[SchemaId]`

Targets `CLASS`.

```php
public function __construct(public string $id)
```

Pins a class's stable diff identity (`sch:` id) so renames don't break the schema's identity.

```php
#[SchemaId('user-v1')]
class UserResource extends JsonResource {}
```

### `#[SchemaName]`

Targets `CLASS`.

```php
public function __construct(public string $name)
```

Sets a class's component display name — distinct from its diff identity.

```php
#[SchemaName('User')]
class UserResource extends JsonResource {}
```

## Content & examples

### `#[Example]`

Targets `METHOD | PROPERTY | FUNCTION | PARAMETER`, repeatable.

```php
public function __construct(
    public mixed $value = null,
    public ?string $name = null,
    public ?string $summary = null,
    public ?string $externalValue = null,
)
```

Attaches an example value — optionally named, summarized, or referenced by an `externalValue` URL.
Repeatable for several named examples.

```php
#[Example(value: 'acme-corp', name: 'default', summary: 'Typical tenant slug')]
public string $tenant;
```

### `#[CaseDescription]`

Targets `CLASS_CONSTANT` (enum cases).

```php
public function __construct(public string $description)
```

Describes a single enum case, surfaced as `x-enumDescriptions` on the enum schema.

```php
enum Status: string {
    #[CaseDescription('Awaiting review by a moderator')]
    case Pending = 'pending';
}
```

### `#[DescriptionFromFile]`

Targets `CLASS | METHOD | FUNCTION | PROPERTY`.

```php
public function __construct(public string $path)
```

Loads a symbol-anchored markdown file into the `description` field. The file content is hashed into
the fragment-cache key, so edits invalidate correctly.

```php
#[DescriptionFromFile('docs/users/show.md')]
public function show(int $id): UserResource { /* … */ }
```
