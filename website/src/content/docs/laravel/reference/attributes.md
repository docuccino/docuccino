---
title: Attributes reference
description: The docuccino/attributes package — all 27 attributes with signatures and examples.
---


Attributes let you say the things your code can't. They live in the dependency-free
`docuccino/attributes` package under the namespace `Docuccino\Attributes` (e.g.
`use Docuccino\Attributes\Response;`).

You reach for them only where inference falls short — everything else is documented automatically.
When an attribute and inference describe the same thing, the attribute wins, **field by field**: for
example `#[Response(status: 201, description: 'Created')]` sets just the description of the `201`
response and leaves the inferred body schema intact.

Attributes apply to controllers, actions, Form Requests, Data classes, enum cases, and closure
routes, as each attribute's targets allow. Put one on the controller to cover every action in it; the
same attribute on an action is more specific and wins.

A `type:` string (where present) is parsed by the same grammar as your docblocks, so unions
(`InvoiceResource|null`), lists (`list<InvoiceResource>`) and array shapes
(`array{id: int, total: int}`) all work. Unqualified class names resolve against the controller
file's own `use` statements and namespace, so `#[Response(type: 'InvoiceResource')]` finds the class
you'd expect without a `::class` reference.

## At a glance

All 27 attributes, grouped by what they do:

| Attribute | Does |
| --- | --- |
| [`#[Response]`](#response) | Declare or refine a response for a status. |
| [`#[ResponseHeader]`](#responseheader) | Document a response header on a status. |
| [`#[QueryParameter]`](#queryparameter) | Add or patch a query parameter. |
| [`#[PathParameter]`](#pathparameter) | Refine a path parameter (type, format, example). |
| [`#[HeaderParameter]`](#headerparameter) | Add or patch a request header parameter. |
| [`#[CookieParameter]`](#cookieparameter) | Add or patch a cookie parameter. |
| [`#[BodyParameter]`](#bodyparameter) | Add or patch one property of the request body. |
| [`#[RuleSchema]`](#ruleschema) | Document what a custom validation rule accepts. |
| [`#[Hidden]`](#hidden) | Remove properties from the output schema. |
| [`#[HiddenFromRequest]`](#hiddenfromrequest) | Remove a Data-class property from the request body only. |
| [`#[ExcludeFromDocs]`](#excludefromdocs) | Drop a route (or controller) from the docs. |
| [`#[Internal]`](#internal) | Flag an operation `x-internal: true`. |
| [`#[InDocs]`](#indocs) | Restrict a route to named output documents. |
| [`#[IgnoreParam]`](#ignoreparam) | Drop an inferred parameter by name. |
| [`#[IgnoreResponse]`](#ignoreresponse) | Drop an inferred response by status. |
| [`#[Group]`](#group) | Assign an operation to an OAS tag. |
| [`#[OperationId]`](#operationid) | Override the `operationId`. |
| [`#[DeprecatedOperation]`](#deprecatedoperation) | Mark an operation deprecated. |
| [`#[Unauthenticated]`](#unauthenticated) | Clear the inferred security requirement. |
| [`#[Security]`](#security) | Declare a security requirement (repeatable OR-list). |
| [`#[OptionallyAuthenticated]`](#optionallyauthenticated) | Allow anonymous **or** authenticated access. |
| [`#[Abilities]`](#abilities) | Declare required Sanctum token abilities. |
| [`#[SchemaId]`](#schemaid) | Pin a class's stable diff identity. |
| [`#[SchemaName]`](#schemaname) | Set a class's component display name. |
| [`#[Example]`](#example) | Pin the success response's example body. |
| [`#[CaseDescription]`](#casedescription) | Describe an enum case (`x-enumDescriptions`). |
| [`#[DescriptionFromFile]`](#descriptionfromfile) | Load a Markdown file into `description`. |

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

Documents a single response header on a given status code. Repeat it freely — headers are grouped and
merged per status. Omit `type` and the header is documented as a string.

```php
#[ResponseHeader(name: 'X-RateLimit-Remaining', type: 'integer', description: 'Calls left this window')]
#[ResponseHeader(name: 'Retry-After', type: 'integer', description: 'Seconds to wait', status: 429)]
public function index(): AnonymousResourceCollection { /* … */ }
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

### `#[RuleSchema]`

Targets `CLASS`, not repeatable.

```php
public function __construct(
    public ?string $type = null,
    public ?string $format = null,
    public ?string $pattern = null,
    public ?array $enum = null,
    public int|float|null $min = null,
    public int|float|null $max = null,
    public ?string $description = null,
    public string|int|float|bool|null $example = null,
)
```

Documents what a custom validation rule accepts, once, on the rule class — so every field validated by
it is documented, wherever the rule object appears. Each field maps onto the rule vocabulary (`type` → a
type rule, `enum` → `in:…`, `min`/`max` → the size rules), so the result is identical to writing those
rules by hand. The attribute is the contract: the class needn't implement any interface, and the
constructor arguments at the call site are ignored.

```php
#[RuleSchema(type: 'string', pattern: '[0-9]{2}-[0-9]{2}-[0-9]{2}', description: 'A UK sort code.')]
final class SortCode implements ValidationRule { /* … */ }
```

See [documenting a custom rule](/laravel/documenting/requests/#documenting-a-custom-rule) for the
field-by-field mapping.

## Visibility & inclusion

### `#[Hidden]`

Targets `CLASS | PROPERTY`.

```php
public function __construct(string ...$properties) // stored as list<string> $properties
```

Removes properties from an output schema. On a class it drops the properties you name — the form
Eloquent models and Data classes use, where the properties are reflected rather than declared one by
one. On a property it drops that property, for a Data class whose properties you can annotate
directly.

```php
#[Hidden('password_hash', 'remember_token')] // on the model: merged with $hidden
class Customer extends Model {}

#[Hidden] // on a Data-class property
public string $internalRiskScore;
```

`#[Hidden]` affects the **output** schema only. A property that is hidden from responses but still
accepted in the request is intentional (and the data-leakage lint surfaces it) — to drop a property
from the documented **request** body, use `#[HiddenFromRequest]` below.

### `#[HiddenFromRequest]`

Targets `PROPERTY`. Marker (no constructor).

Excludes a Data-class property from the documented request body without touching the response
schema — the request-side counterpart to `#[Hidden]`, for a server-populated value clients never
send.

```php
#[HiddenFromRequest]
public string $capturedIp;
```

A Form Request's body comes from its validation rules, so drop a field there by removing its rule —
or patch the inferred body with [`#[BodyParameter]`](#bodyparameter).

### `#[ExcludeFromDocs]`

Targets `CLASS | METHOD | FUNCTION`. Marker (no constructor). Excludes a route — or every route on
a controller — from the documentation entirely.

```php
#[ExcludeFromDocs]
public function debug(): JsonResponse { /* … */ }
```

### `#[Internal]`

Targets `CLASS | METHOD | FUNCTION | PROPERTY`. Marker. On an action or controller it sets
`x-internal: true` on the operation — the operation stays in the document, flagged, which is the
convention SDK generators and doc filters read to keep it out of public output.

```php
#[Internal]
public function purgeCache(): JsonResponse { /* … */ }
```

To remove an operation from the document altogether, use
[`#[ExcludeFromDocs]`](#excludefromdocs) instead.

### `#[InDocs]`

Targets `CLASS | METHOD | FUNCTION`.

```php
public function __construct(string ...$documents) // stored as list<string> $documents
```

Restricts a route (or whole controller) to the named documents. In a multi-document setup it's how
you keep a partner-only endpoint out of your public spec.

```php
#[InDocs('public-api', 'partner-api')]
public function webhook(Request $request): JsonResponse { /* … */ }
```

It **narrows, it doesn't rescue**: the attribute is applied after each document's
`routes.include` / `routes.exclude` patterns and closure filter, so a route those already excluded
stays excluded no matter what you list here. To add a route to a document, widen the document's route
patterns.

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

Assigns an operation to an OAS tag/group. Repeatable, to place one operation under several tags. On a
controller it tags every action in it.

```php
#[Group(name: 'Invoices')]
class InvoiceController {}
```

The name goes through the document's [`tags.map`](/laravel/reference/configuration/#tags) before it
lands in the document, and an operation with no `#[Group]` is tagged by the
`tags.default_strategy`. Descriptions for the OpenAPI top-level `tags` array come from
`tags.definitions` in config — which is also what orders them — so that one description lives in one
place rather than being repeated on every controller.

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

Pins the example body for an action's success response: the first `#[Example]` with a non-null `value`
becomes the `example` on the `200` response's `application/json` content. Inference supplies examples
for error bodies on its own — this is how you fix the shape of a success payload it can't know.

```php
#[Example(value: ['id' => 42, 'total' => 19900, 'currency' => 'GBP'])]
public function show(Invoice $invoice): InvoiceResource { /* … */ }
```

For a field-level example, an `@example` docblock line on the property is read when the schema comes
from a [Data class](/laravel/packages/spatie-data/):

```php
/**
 * The tenant that owns the invoice.
 *
 * @example acme-corp
 */
public string $tenant;
```

The `name`, `summary` and `externalValue` arguments are part of the attribute's signature; today only
`value` reaches the emitted document.

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
