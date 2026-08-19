---
title: Attributes reference
description: The docuccino/attributes package — all 30 attributes with signatures and examples.
---


Attributes let you say the things your code can't. They live in the dependency-free
`docuccino/attributes` package under the namespace `Docuccino\Attributes` (e.g.
`use Docuccino\Attributes\Response;`).

You reach for them only where inference falls short — everything else is documented automatically.
When an attribute and inference describe the same thing, the attribute wins, **field by field**: for
example `#[Response(status: 201, description: 'Created')]` sets just the description of the `201`
response and leaves the inferred body schema intact.

Attributes apply to controllers, actions, form requests, Data classes, enum cases, and closure
routes, as each attribute's targets allow. Put one on the controller to cover every action in it; the
same attribute on an action is more specific and wins.

A `type:` string (where present) is parsed by the same grammar as your docblocks, so unions
(`InvoiceResource|null`), lists (`list<InvoiceResource>`) and array shapes
(`array{id: int, total: int}`) all work. Unqualified class names resolve against the controller
file's own `use` statements and namespace, so `#[Response(type: 'InvoiceResource')]` finds the class
you'd expect without a `::class` reference.

## At a glance

All 30 attributes, grouped by what they do:

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
| [`#[ErrorComponent]`](#errorcomponent) | Name the shared component an error publishes under, on the exception or on the method that renders it. |
| [`#[Example]`](#example) | Pin example payloads — one, or several named ones — on a response, the request body or a parameter. |
| [`#[CaseDescription]`](#casedescription) | Describe an enum case (`x-enumDescriptions`). |
| [`#[DescriptionFromFile]`](#descriptionfromfile) | Load a Markdown file into `description`. |
| [`#[Mock]`](#mock) | Hint how a mock server should fake a property. |
| [`#[Webhook]`](#webhook) | Publish a class as a webhook your API delivers. |

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
Eloquent models use, where the properties are reflected rather than declared one by one. On a property
it drops that property. Both forms work on any class Docuccino hoists, plain PHP DTOs included. If they
between them hide every property, the class isn't published at all.

```php
#[Hidden('password_hash', 'remember_token')] // on the model: merged with $hidden
class Customer extends Model {}

#[Hidden] // on a Data-class property
public string $internalRiskScore;
```

`#[Hidden]` affects the **output** schema only. A property that is hidden from responses but still
accepted in the request is intentional (and the data-leakage lint surfaces it) — to drop a property
from the documented **request** body, use `#[HiddenFromRequest]` below.

**`#[Hidden]` is document-wide.** A class is one component, so a hidden property is hidden in every
response that references it — there's no per-status or per-operation form of the attribute, and no
argument that would add one. If a shared error class carries a property that belongs on `422` but not
on `403`, hiding it is the wrong lever. Reach for one of these instead:

| You want | Reach for |
| --- | --- |
| Laravel's stock `errors` member on `422` only, on a shared error shape | The [Problem Details preset](/laravel/documenting/errors/#the-problem-details-preset-opt-in) — it already emits the `allOf` of the shared problem schema plus `errors`. |
| Your own class, correct on every status | A dedicated type for the odd status, plus [`#[Response(status: 422, type: …)]`](#response) on the actions that return it. |
| One class, the property merely not always present | `array\|Optional $errors` on a Data class — documented, but not `required`. |
| A spec-side one-off you don't want in the code | [Vary one response from a shared component](/laravel/guides/customizing-output/#vary-one-response-from-a-shared-component) with an Overlay. |

### `#[HiddenFromRequest]`

Targets `PROPERTY`. Marker (no constructor).

Excludes a Data-class property from the documented request body without touching the response
schema — the request-side counterpart to `#[Hidden]`, for a server-populated value clients never
send.

```php
#[HiddenFromRequest]
public string $capturedIp;
```

A form request's body comes from its validation rules, so drop a field there by removing its rule —
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
convention SDK generators and doc filters read to keep it out of public output. The `PROPERTY` target
is accepted but has no effect on a schema today; use [`#[Hidden]`](#hidden) to drop a property.

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

A component is named after its class's **short** name, so two classes in different namespaces that
share one contest the same `#/components/schemas/…` slot. Both shapes are still published, and
neither keeps the contested name: each takes a name derived from its own namespace, walking up only
as far as it takes to tell them apart. An `App\DTOs\Schema\Authentication\SSOConnectionData` and an
`App\DTOs\Data\SSO\SSOConnectionData` publish as `AuthenticationSSOConnectionData` and
`SSOSSOConnectionData`.

Those names depend only on what the schemas are, never on the order your routes happen to be
discovered in — which matters, because a positional `Foo`/`Foo_2` would hand the plain name to
whichever route sorted first, and adding an unrelated route later could silently swap what `Foo`
means in every generated client. Where a namespace walk can't tell two claimants apart — two classes
in one namespace, or a `#[SchemaId]` pin carrying no namespace to walk — each takes a short hash of
its own identity instead (`UserData_x7ztb6hq`). Stable, but not descriptive: that is a name to
replace, and the warning below says so.

One class accepted as a request body and returned as a response is **two shapes**, not one, so they
never contest a name: the body publishes as `<Name>Request` (a name that already ends in `Request` is
left alone) and the plain name belongs to the class's own shape. Adding the read endpoint therefore
can't rename the write endpoint's component, or the other way round.

The build reports a `components.name-collision` warning naming both FQCNs and the name each was
published under, because an automatic name is rarely the best one. `#[SchemaName]` on either class is
how you settle it — on a plain PHP DTO as much as on a resource, model or Data class. Two classes
choosing the *same* `#[SchemaName]` contest that name in exactly the same way, and are reported the
same way.

### `#[ErrorComponent]`

Targets `CLASS`, `METHOD`.

```php
public function __construct(public string $name)
```

Names the shared component an error is published under, so a client catches a `ResourceMissing` rather
than an `Error404`. On an **exception class** it names the error that class stands for; on a **render
method** it names the body that method answers with, which is the only way to tell apart several bodies
one exception class produces.

```php
#[ErrorComponent('ResourceMissing')]
final class InvoiceNotFoundException extends RuntimeException {}

final class ProblemRenderer
{
    #[ErrorComponent('InvoiceRejected')]
    private function renderRejection(ApiException&HasInvalidFields $e): JsonResponse { /* … */ }
}
```

Unlike PHP's own attribute lookup, the class anchor is **inherited**: a base your API errors extend names
them all at once, and a subclass carrying its own attribute wins over the base. The method anchor
inherits the way PHP does, since an unoverridden method still belongs to the parent that declared it.
Either applies wherever the error is shared — `components.schemas`, `components.responses`, and the type
name in any generated client — and changes nothing else about the response, including whether it is
shared at all: an error only one operation states stays inline and has no component to name until a
second operation states it too.

Where several methods on one render path carry it, the one **nearest the answer** wins: the arm that
returned the body beats the helper that built it, so marking a shared `problem()` helper names only the
arms that said nothing themselves. Attributes cannot go on `match` arms, so a `match (true)` renderer
needs a method per body it wants named.

The name replaces the [default one derived from the
status](/laravel/documenting/errors/#shared-errors-are-named-after-the-error), and a name on the render
method replaces one on the exception class, because the method that built the body knows which body it
is. A registered `ExceptionToResponse` ordered ahead of the inferred-handler tier outranks both. A name
outside `^[a-zA-Z0-9._-]+$` is refused with an `attribute.error-component-invalid` warning naming the
class or method that declared it, and the response keeps the name it would have had.

## Content & examples

### `#[Example]`

Targets `METHOD | PROPERTY | FUNCTION | PARAMETER`, repeatable.

```php
public function __construct(
    public mixed $value = null,
    public ?string $name = null,
    public ?string $summary = null,
    public ?string $externalValue = null,
    public ?string $description = null,
    public ?string $file = null,
    public int|string|null $status = null,
    public ?string $mediaType = null,
    public ?string $parameter = null,
    public bool $request = false,
)
```

Pins the example payloads a reader copies. Without a `name:` it sets the singular `example`; with one
it adds an entry to the `examples` map, so an endpoint can show several — an empty cart beside a full
one — each with its own `summary` and `description`.

```php
#[Example(value: ['id' => 42, 'total' => 19900, 'currency' => 'GBP'])]
public function show(Invoice $invoice): InvoiceResource { /* … */ }
```

```php
#[Example(name: 'paid', summary: 'A settled invoice', value: ['id' => 42, 'status' => 'paid'])]
#[Example(name: 'overdue', summary: 'One past its due date', value: ['id' => 43, 'status' => 'overdue'])]
public function show(Invoice $invoice): InvoiceResource { /* … */ }
```

**Where the payload comes from.** Exactly one of `value:`, `file:` or `externalValue:`. `file:` reads a
`.json`, `.yaml` or `.yml` file relative to your application root — the way to keep a realistic payload
out of an attribute argument — and the file joins the build's dependencies, so editing it regenerates
that endpoint. `externalValue:` publishes a URL for the payload instead of the payload itself, and
needs a `name:`, as do `summary:` and `description:`.

```php
#[Example(name: 'full-cart', file: 'docs/examples/full-cart.json', summary: 'Three lines and a discount')]
public function show(Cart $cart): CartResource { /* … */ }
```

**What it illustrates.** By default the success response — the lowest `2xx` the operation documents —
in that response's first media type. At most one of these redirects it:

| Argument | Illustrates |
| --- | --- |
| `status:` | That response instead (`status: 404`). |
| `request:` | The request body. |
| `parameter:` | The named parameter, wherever it lives — path, query, header or cookie. |
| `mediaType:` | Combines with the others: which content of the response or request body. |

```php
#[Example(name: 'minimal', value: ['name' => 'Acme Ltd'], request: true)]
#[Example(name: 'not-found', value: ['message' => 'No such invoice'], status: 404)]
#[Example(name: 'second-page', value: 2, parameter: 'page')]
public function store(StoreInvoiceRequest $request): InvoiceResource { /* … */ }
```

A node carries `example` or `examples`, never both, so where you name one example on a node, name them
all — a nameless declaration sharing a node with named ones is dropped with a diagnostic.

A declaration Docuccino can't place — a status the operation doesn't document, a parameter it doesn't
have, a file it can't read — is dropped the same way, with a diagnostic naming the action, never
guessed at. So is a payload no JSON document can hold: `INF`, `-INF` and `NAN` have no JSON form,
whether they arrive through `value:` or as YAML's `.nan` and `.inf` in a `file:`. See [Reading
diagnostics](/laravel/guides/troubleshooting/#reading-diagnostics).

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

### `#[Mock]`

Targets `CLASS | PROPERTY`, repeatable.

```php
public function __construct(
    public ?string $faker = null,
    public ?string $seedGroup = null,
    public ?string $property = null,
)
```

Records how a mock server should fake one property, as `x-docuccino.mock` on that property's schema.
`faker` is the expression it evaluates; `seedGroup` names properties whose values should correlate,
so a mocked `first_name` and `email` can belong to the same imaginary person. Either parameter alone
is a complete hint.

On a property the attribute applies to that property. On a class it needs `property`, naming a member
the schema publishes — the form for an Eloquent column, a `toArray()` key or a validated field, none
of which have a PHP property to carry one — and repeats for as many members as you need.

```php
final readonly class CustomerData
{
    public function __construct(
        #[Mock(faker: 'uuid')]
        public string $id,
        #[Mock(faker: 'safeEmail', seedGroup: 'customer')]
        public string $email,
        #[Mock(faker: 'name', seedGroup: 'customer')]
        public string $fullName,
    ) {}
}

#[Mock(faker: 'safeEmail', property: 'email')]
#[Mock(faker: 'dateTimeThisYear', property: 'created_at')]
final class Customer extends Model { /* … */ }
```

A hint is metadata, never a value: Docuccino stores the expression and evaluates nothing, so no
generated data ever reaches your document. The expression itself is passed through untouched —
whoever consumes the hint defines its grammar — so nothing checks that a formatter exists; only an
empty one is refused, with an `attribute.mock-invalid` warning. An attribute naming a property the
schema does not publish is dropped with `attribute.mock-unknown-property`.

The UIR always carries the hints. OpenAPI artifacts drop them unless
[`export.mock_faker_key`](/laravel/reference/configuration/#export) names the member to publish them
under — conventionally `x-faker`. See
[Mock data hints](/laravel/documenting/schemas/#mock-data-hints).

## Webhooks

### `#[Webhook]`

Targets `CLASS`.

```php
public function __construct(
    public string $name,
    public string $method = 'post',
    public ?string $payload = null,
    public string $mediaType = 'application/json',
)
```

Publishes the annotated class under the document's `webhooks` as an operation your API promises to
**call** — the outbound side of the contract, which no route describes. `name` is the key consumers
subscribe to; `method` is the HTTP method their endpoint must implement. The annotated class is the
delivered body unless `payload` names another type, and the type string is read by the same grammar
as everywhere else.

```php
/**
 * An invoice was paid.
 *
 * Delivered once payment has settled, and retried until your endpoint answers 2xx.
 */
#[Webhook('invoice.paid')]
#[Group('Billing')]
final readonly class InvoicePaid
{
    public function __construct(
        public int $invoiceId,
        public int $amountInCents,
    ) {}
}
```

The class docblock becomes the summary and description, and `#[Group]`, `#[Response]`,
`#[DeprecatedOperation]`, `#[Internal]`, `#[InDocs]` and `#[ExcludeFromDocs]` read on it exactly as
they read on a controller. Classes are discovered from
[`webhooks.dir`](/laravel/reference/configuration/#webhooks); see
[Documenting webhooks](/laravel/documenting/webhooks/) for the whole picture.
