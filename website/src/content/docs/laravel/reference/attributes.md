---
title: Attributes reference
description: The docuccino/attributes package — all 38 attributes with signatures and examples.
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

One word means more in an attribute than it does in a docblock: `object`. Read off your code it means
"an instance of something", whose wire shape a `JsonSerializable` may make anything — so inference stays
vague about it. Written by hand it is the JSON word, said about the wire by the one person who knows, so
it documents a free-form map: an object whose keys aren't enumerated, the same thing
`array<string, mixed>` says. `array` is not that word — a PHP array is a JSON array or a JSON object, so
say `list<T>` or `array<string, T>` for the one you mean.

## At a glance

All 38 attributes, grouped by what they do:

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
| [`#[Summary]`](#summary) | Set the one-line `summary` an API consumer reads. |
| [`#[Description]`](#description) | Set the `description`, inline or from a Markdown file. |
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
| [`#[Mock]`](#mock) | Hint how a mock server should fake a property. |
| [`#[Webhook]`](#webhook) | Publish a class as a webhook your API delivers. |
| [`#[ApiVersionChange]`](#apiversionchange) | Register one API version change, and the sentence consumers read about it. |
| [`#[RenamedResponseField]`](#renamedresponsefield) | Declare a response field that older versions publish under another name. |
| [`#[MadeResponseFieldRequired]`](#maderesponsefieldrequired) | Declare a response field that older versions did not promise to send. |
| [`#[MadeResponseFieldOptional]`](#maderesponsefieldoptional) | Declare a response field that older versions always sent. |
| [`#[MadeRequestFieldOptional]`](#maderequestfieldoptional) | Declare a request field that older versions demanded. |
| [`#[RemovedResponseField]`](#removedresponsefield) | Declare a response field older versions published that your code no longer has. |
| [`#[AppliesTo]`](#appliesto) | Narrow a version change to the operations it names. |

## Responses

### `#[Response]`

Targets `CLASS | METHOD | FUNCTION`, repeatable.

```php
public function __construct(
    public int $status = 200,
    public ?string $type = null,
    public ?string $description = null,
    public ?string $mediaType = null,
    public ?string $errorComponent = null,
)
```

Declares a documented response. Repeatable, so one action can document several statuses. A body with no
`mediaType:` publishes under `application/json`; naming one also **retires** any vaguer media type a
producer had documented the same body under, which the default deliberately does not.

```php
#[Response(status: 200, type: UserResource::class, description: 'The user')]
#[Response(status: 404, description: 'User not found')]
public function show(int $id): UserResource { /* … */ }
```

`errorComponent:` names the [shared component](/laravel/documenting/errors/#repeated-bodies-become-shared-components)
the status's error response publishes under. It and [`#[ErrorComponent]`](#errorcomponent) differ by what
they are *about*, not by which bodies they can reach: that one names an error where the error is defined,
so every operation answering with it publishes the same name, and this one names **one status of one
operation**, whatever produced the body — a body the operation declares itself, and equally one an
exception the action throws produced, where it wins as the declaration nearest the operation. It names the
response in `components.responses`, and the shape under it where the status states one representation;
`type:` already names the schema after the class it points at.

```php
#[Response(status: 422, type: SignInChallenge::class, mediaType: 'application/json', errorComponent: 'AuthenticationChallenge')]
public function completeMfa(Request $request): SuccessData { /* … */ }
```

Three things it does not do. It **renames a shared component; it does not create one** — a body only one
operation states stays inline, exactly as `#[ErrorComponent]` behaves. Below `400` nothing shares an
error body, so a name there names nothing — and says so, with
`attribute.error-component-unreachable`, as it does on a status a mapper answered with a `$ref` to a
component named elsewhere. And a response component covers *every* representation of a status, so the
name is the status's: where two declarations at one status name different components, the nearer one wins
— the method's over the controller's, and the first written where both are on the same target — exactly
as every other argument of the attribute settles. It outranks an `#[ErrorComponent]` on the exception
class the action throws, which is the specificity rule: the declaration nearest the operation wins.

A name outside `^[a-zA-Z0-9._-]+$` is refused with an `attribute.error-component-invalid` warning naming
the declaration that carried it, and the response keeps the name it would have had. A refused name never
takes the status's one claim on the way past, so a legal name beside it still wins.

### `#[ResponseHeader]`

Targets `CLASS | METHOD | FUNCTION`, repeatable.

```php
public function __construct(
    public string $name,
    public ?string $type = null,
    public ?string $description = null,
    public int $status = 200,
    public ?bool $required = null,
)
```

Documents a single response header on a given status code. Repeat it freely — headers are grouped and
merged per status.

The declaration says what you write and nothing else. Docuccino documents headers of its own — a
redirect's `Location`, the `Retry-After` and `X-RateLimit-*` on a
[throttled](/laravel/documenting/rate-limiting/) `429` — and a declaration naming one of those adds
the members you state to what is already there instead of replacing it:

```php
#[ResponseHeader(name: 'Retry-After', status: 429, description: 'Seconds to wait — we set this per plan.')]
```

leaves the header its recovered `integer` type and its `required: true`, and publishes your sentence
beside them. Write `type:` to change the type, `required:` to change the promise. Omit `type` on a
header nothing else documented and it is published as a string.

Set `required: true` when your server sends the header on *every* response at that status. A client
generated from the document can then type it non-optional, and
[`assertValidResponse()`](/laravel/guides/contract-testing/#assert-an-exchange) fails a response that
leaves it out. Write `required: false` to say the opposite — the header may or may not arrive — which
is also what an undeclared header means, so leaving it out promises nothing either way.

```php
#[ResponseHeader(name: 'X-Request-Id', type: 'string', description: 'Echoed on every response', required: true)]
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
    public ?string $format = null,
    public ?bool $required = null,
    public mixed $default = null,
    public mixed $example = null,
)
```

```php
#[QueryParameter(name: 'page', type: 'integer', description: 'Page number', default: 1, example: 2)]
#[QueryParameter(name: 'from', type: 'string', format: 'date-time', description: 'Only items created after this moment.')]
public function index(): AnonymousResourceCollection { /* … */ }
```

`required` is three-valued, here and on `#[HeaderParameter]` and `#[CookieParameter]`. `required: true`
says the server insists on the parameter and `required: false` says it does not — the declaration wins
over whatever a package integration worked out. Leaving it off is neither: it says nothing, so a
parameter an integration already proved required stays required. That is why the argument is `?bool` —
a declaration written to document a `type:` must not quietly de-require a parameter the server insists
on, which would publish a contract a generated client can build a rejected request from.

A bracketed `name` (`filter[status]`) patches a flat `filter[status]` parameter, or — when the
document uses the `deepObject` filter style — the `status` property of the `filter` object parameter.
The same attribute works in either representation. Placed on a **Spatie Query Builder custom filter
class**, `#[QueryParameter]` documents that filter (its `name` is ignored — the name comes from
`AllowedFilter::custom`), whether the filter is registered inline or through a factory of your own
that wraps it; see [Query Builder → custom filter classes](/laravel/packages/query-builder/#custom-filter-classes).

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

Path params are inherently required (no `required` param).

```php
#[PathParameter(name: 'uuid', type: 'string', description: 'User id', format: 'uuid', example: '9b1…')]
public function show(string $uuid): UserResource { /* … */ }
```

Alone among the parameter attributes it cannot **add** what it names, only refine it: OpenAPI requires
every `in: path` parameter to correspond to a template variable, so a `name:` that is no `{segment}` of
the route's own URI is withheld rather than published, and the action's own declaration says so
([`attribute.path-parameter-unmatched`](/laravel/reference/diagnostics/#attributes)). Publishing it
would make the document invalid, and it would describe nothing the server accepts — no request has
anywhere to put it — so leaving it out costs the reader nothing. A declaration inherited from a
controller is withheld in the same way and stays silent: a segment only some of the class's actions have
is the ordinary way a class-level one is written. A parameter that is not in the URI is
`#[QueryParameter]`, `#[HeaderParameter]` or `#[CookieParameter]`.

### `#[HeaderParameter]`

```php
public function __construct(
    public string $name,
    public ?string $type = null,
    public ?string $description = null,
    public ?string $format = null,
    public ?bool $required = null,
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
    public ?string $format = null,
    public ?bool $required = null,
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
    public ?string $format = null,
    public ?bool $required = null,
    public mixed $example = null,
)
```

Patches or adds a single property of the *inferred* request body schema.

```php
#[BodyParameter(name: 'nickname', type: 'string', description: 'Display name', example: 'Tom')]
public function update(UpdateUserRequest $request, int $id): UserResource { /* … */ }
```

The name is a field path, written the way a validation rule key is written. A `.` descends into an
object, a `*` names an element of an array, and `\.` is a dot that belongs to the field name itself:

```php
#[BodyParameter(name: 'meta.source', type: 'string', description: 'Where the order came from.')]
#[BodyParameter(name: 'lines.*.quantity', type: 'int', description: 'How many of this item.')]
#[BodyParameter(name: 'meta\.raw', type: 'string')] // one field, whose name is `meta.raw`
```

Containers on the way are created if the body doesn't have them, and `required: true` marks the field
required on the object that holds it — `meta.source` becomes a required member of `meta`, and the body
itself becomes required. `required: false` is the opposite statement and takes the field back off that
list, for the case where your rules make a field required that the endpoint really accepts without.

Leaving `required` off is neither: it says nothing, so a field your validation rules already made
required stays required. That is why the argument is `?bool` — a declaration written to document a
`type:` must not quietly de-require a field the server insists on.

Naming a key inside a container also settles what that container is. A bare `array` rule leaves a field
[undecided](/laravel/documenting/requests/#nested-and-array-fields) — Laravel has one word for both shapes —
and a declaration inside it answers the question, so `validation.container-undecided` stops firing for
that field. Only that question: a `nullable` field stays nullable. Naming the field itself answers it
too, as long as the `type:` says which shape it is — `object` for a free-form map, `list<int>` for a
list. `array` and `mixed` are the two that don't, so the notice keeps naming the field:

```php
#[BodyParameter(name: 'meta.scoring.scores', type: 'object', description: 'Scores keyed by criterion id.')]
```

A path only lands where the body can carry it. If the field it nests under is documented as a scalar,
as an `allOf`/`anyOf`/`oneOf`, or as a `$ref` to a shared component — where the property would appear
in every other operation using that component — nothing is written and
[`attribute.body-parameter-parent`](/laravel/reference/diagnostics/#attributes) says so. For a scalar,
document the parent as an object first:

```php
#[BodyParameter(name: 'meta', type: 'object')]
#[BodyParameter(name: 'meta.source', type: 'string')]
```

Order doesn't matter — a parent is applied before its children whichever way round you write them.

#### On the action, or on the request type

The two declaration sites say different things, and both are read.

On the **action** the declaration is that operation's. It patches that operation's body, which means
the body is written out in full there instead of pointing at the shared component the request class
would otherwise be published as.

On the **request class** — a Form Request, a DTO, a Data class — the declaration is the type's. A
free-form map whose keys no rule can enumerate is a fact about the type, identical on every endpoint
that accepts it, so it belongs on the class:

```php
#[BodyParameter(name: 'overrides', type: 'object', description: 'Arbitrary per-tenant overrides.')]
final class UpdateTenantRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required|string', 'overrides' => 'array'];
    }
}
```

The declaration goes into the `UpdateTenantRequest` component, so every operation accepting that
request keeps its `$ref` to it — and a client generated from the document keeps a single named type for
the shape, instead of one inline body per endpoint that mentioned it.

Write both and both apply: the class's first, the action's over the top of it for the fields it names.
An action class that is its own request class — a `laravel-actions` action — has one declaration site
for the two roles, and it keeps the action meaning it has always had.

Anything else a request class declares that only an action is read for — `#[Summary]`, `#[Response]`, a
parameter attribute — raises
[`attribute.schema-class-unread`](/laravel/reference/diagnostics/#attributes) and names where it does
belong.

A read route (`GET`, `HEAD`) documents its validation rules as query parameters rather than as a
request body, so a declaration on a request class only reaches something where the type is accepted at
a write verb somewhere. A class every route reads at a read verb raises
[`attribute.schema-class-unusable`](/laravel/reference/diagnostics/#attributes); one shared by a read
route and a write route is doing its job on the write one, and nothing is said about it.

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

A name that matches no property the schema publishes hides nothing and says so
([`attribute.hidden-unmatched`](/laravel/reference/diagnostics/#attributes)), listing what the schema
does publish so the typo is visible beside it — a subtraction leaves no evidence, so without that report
a name gone stale through a rename looks exactly like one that worked, while the field it was written to
keep out is published under the new spelling. The property form has no name to get wrong, and so cannot
miss. It is not raised for an Eloquent **model**: a model's documented columns are recovered from
`@property` tags, `$casts` and `$fillable` rather than declared, so a name outside them is far more
often a column nobody documented than a name anybody typed wrong — and deleting the deny-list entry
would be the one action that leaks the column the day somebody adds the tag.

`#[Hidden]` affects the **output** schema only. A property that is hidden from responses but still
accepted in the request is intentional (and the data-leakage lint surfaces it) — to drop a property
from the documented **request** body, use `#[HiddenFromRequest]` below.

**`#[Hidden]` is document-wide.** A class is one component, so a hidden property is hidden in every
response that references it — there's no per-status or per-operation form of the attribute, and no
argument that would add one. If a shared error class carries a property that belongs on `422` but not
on `403`, hiding it is the wrong lever. Reach for one of these instead:

| You want | Reach for |
| --- | --- |
| Laravel's stock `errors` member on `422` only, on a shared error shape | Build it in the handler branch that returns it. Each branch of your renderer is [read on its own](/laravel/documenting/errors/), so a `422` arm that adds `errors` is documented with it and the other statuses without. |
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

It is an **allow-list**, so a key naming no configured document does not fall back to including the
route — a declaration whose keys name no document that exists excludes the route from every one of them.
A key nobody configured is reported
([`attribute.in-docs-unknown`](/laravel/reference/diagnostics/#attributes)), once for the key however
many routes it covers, naming those routes and listing the documents that do exist. To keep a route out
of every document on purpose, use `#[ExcludeFromDocs]`.

### `#[IgnoreParam]`

Targets `CLASS | METHOD | FUNCTION`, repeatable.

```php
public function __construct(
    public string $name,
    public ?string $in = null,
)
```

Drops a documented parameter by name, optionally scoped to an `in` location — `cookie`, `header`,
`path` or `query`, in any case. Leave `in:` off to drop the name wherever it appears.

```php
#[IgnoreParam(name: 'internal_flag', in: 'query')]
public function index(): AnonymousResourceCollection { /* … */ }
```

It is the last word on the parameter, whatever documented it: a rule set recovered from a FormRequest,
a paginator key, the route's own path segment, or a parameter attribute on the controller class an
action opts out of. An `in:` that names no location drops nothing and says so
([`attribute.ignore-param-location`](/laravel/reference/diagnostics/#attributes)), and so does a
`name:` on the action that matches no parameter
([`attribute.ignore-param-unmatched`](/laravel/reference/diagnostics/#attributes)) — a subtraction
leaves no evidence, so without that report a typo'd or renamed name looks exactly like one that
worked, and the message lists what the operation does document so the difference is visible beside it.
A declaration inherited from the controller class stays silent: naming a key only some of its actions
document is the ordinary way a class-level declaration is written.

### `#[IgnoreResponse]`

Targets `CLASS | METHOD | FUNCTION`, repeatable.

```php
public function __construct(public int $status)
```

Drops a documented response by status code.

```php
#[IgnoreResponse(status: 500)]
public function show(int $id): UserResource { /* … */ }
```

It is the last word on that status, whatever documented it: an inferred return type, a `#[Response]` on
the same action, the `429` a [rate limiter](/laravel/documenting/rate-limiting/) documents, the `400`
[Query Builder](/laravel/packages/query-builder/) strict mode adds, or an
[error](/laravel/documenting/errors/) an exception the action throws produces. Every producer asks
before it builds anything, so a dropped status takes the components its body would have hoisted with it
rather than leaving them published and referenced by nothing.

It drops **exactly** the status it names and no other. There is no positive form, so a class-level and a
method-level declaration never contest each other — both apply, and the action drops the union. It
cannot name a range key such as `3XX`, since `status:` is an `int`, and that is the answer in both
directions: an ignore takes a status away and establishes nothing, so it neither retires the range a
member sits in nor narrows one. A `status:` on the action that no producer would ever have written drops nothing
and says so ([`attribute.ignore-response-unmatched`](/laravel/reference/diagnostics/#attributes)),
listing the statuses the operation does document — exactly as `#[IgnoreParam]`'s unmatched `name:`
does, and silent on an inherited declaration for the same reason.

## Metadata

### `#[Summary]`

Targets `CLASS | METHOD | FUNCTION`.

```php
public function __construct(public string $text)
```

Sets the one-line `summary` an API consumer reads, whatever the docblock above the action says.

```php
/**
 * Internal — dispatched by the queue worker, never call this directly.
 */
#[Summary('Create an invoice')]
public function store(StoreInvoiceRequest $request): InvoiceResource { /* … */ }
```

There is no `file:` form on purpose. A summary is one line; long prose is what `#[Description]` is
for, and that one does read a file.

There is no `PROPERTY` target either: a schema property has a `description` and no `summary`, so
there was never a field for one to write. Use [`#[Description]`](#description) for a property's one
line.

### `#[Description]`

Targets `CLASS | METHOD | FUNCTION | PROPERTY`, repeatable.

```php
public function __construct(
    public ?string $text = null,
    public ?string $file = null,
    public bool $request = false,
)
```

Sets the `description`, either inline or from a Markdown file. Give it exactly one of the two — a
declaration carrying both, or neither, is reported as
[`attribute.description-unusable`](/laravel/reference/diagnostics/#attributes) and writes
nothing.

Which `description` it sets is decided by where you write it, plus `request:`:

| Where you write it | What it describes | Lands on |
|---|---|---|
| On the action | What the endpoint does | `paths.…{method}.description` |
| On the action, with `request: true` | How to fill this endpoint's body in | `paths.…{method}.requestBody.description` |
| On a DTO, model or resource class | What the type is | `components.schemas.….description` |
| On a property | What that field is | the field's `description` in the schema |

An action may carry a plain declaration and a `request: true` one at the same time, which is why the
attribute is repeatable. Each of these is a different fact, and none of them is copied into another.

```php
#[Description(text: 'Creates a draft invoice for the authenticated tenant.')]
public function store(StoreInvoiceRequest $request): InvoiceResource { /* … */ }
```

```php
#[Description(file: 'resources/docs/invoices/store.md')]
public function store(StoreInvoiceRequest $request): InvoiceResource { /* … */ }
```

The `file:` path is read relative to your application root and cannot leave it. The file joins the
operation's cache dependencies whether or not it exists yet, so the description appears the moment
you write it, and editing it invalidates just that fragment. See
[symbol-anchored prose](/laravel/guides/narrative-content/#symbol-anchored-prose) for when to reach
for a file over a standalone guide page.

**On a property** it sets that field's `description` in the schema, over whatever the property's
docblock said — an attribute outranks a docblock here as it does on an action, so one docblock can go
on addressing whoever maintains the class.

```php
#[Description(text: 'The tenant that owns the invoice.')]
public string $tenant;
```

**With `request: true` on an action** it describes the request body — this operation's use of the
body, rather than the type behind it. That is where "send only the fields you're changing" belongs: it
is true of this endpoint and not of every endpoint that accepts the same shape.

```php
#[Description(text: 'Updates an invoice that has not been issued yet.')]
#[Description(text: 'Send only the fields you are changing.', request: true)]
public function update(UpdateInvoiceRequest $request): InvoiceResource { /* … */ }
```

`file:` works here too, since an action-level declaration has an application root to resolve against.
A declaration on an operation with no request body — including a `GET`, whose validation rules become
query parameters rather than a body — has nothing to describe, and is reported as
[`attribute.description-unusable`](/laravel/reference/diagnostics/#attributes) rather than falling
back to the operation. See [Prose for the body itself](/laravel/documenting/requests/#prose-for-the-body-itself).

**On a DTO, model or resource class** it describes the schema that class publishes — the component a
request body or a response `$ref`s, on both sides of the document:

```php
#[Description(text: 'A single retention policy, as the billing system holds it.')]
final class RetentionPolicyData extends Data { /* … */ }
```

The class *docblock* is deliberately not read for this. A docblock is where you explain a class to
whoever maintains it next, so it tends to name properties, attributes and internals that the consumer
of your document cannot see — and a description that misinforms costs a reader more than an absent one.
The attribute says, unambiguously, "publish this sentence". Your docblock stays yours.

A parent's declaration describes the parent, so a class inherits none of it: a shared base DTO doesn't
put one description on every shape beneath it.

Inline `text:` only, on a property or a class. A schema mapper has no application root to resolve a
path against, and a request body is one operation's use of a type rather than part of it — so a `file:`
or a `request:` declaration on either is reported as
[`attribute.property-unsupported`](/laravel/reference/diagnostics/#attributes) and writes nothing. A
second declaration beside it still publishes: the reader reports each one it cannot use and keeps the
first that says something a schema can hold.

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

`deprecated: true` is the fact a client generator and a viewer read; the reason is the why, and the
description is the only member OpenAPI gives it. So a reason joins the operation's description as its
own paragraph, marked:

```
Lists every user.

**Deprecated:** Use /v2/users instead
```

The `@deprecated` docblock tag is the same thing spelled another way: the tag marks the operation and
the text after it is the reason, published exactly as the attribute's is. Where both are written, the
attribute wins — as it does for every other field.

```php
/**
 * Lists every user.
 *
 * @deprecated Use /v2/users instead
 */
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
discovered in, so adding an unrelated endpoint never renames a component your generated clients
already use. Where a namespace walk can't tell two claimants apart — two classes
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

**Two anchors, and neither of them is the action.** `TARGET_METHOD` lets PHP accept the attribute on a
controller method, and nothing reads it there: it names an error where the error is *defined*, not where
an operation happens to answer with it. An action is where several errors meet and the attribute carries
no status, so there is nothing for it to name — a placement that does nothing is reported as
`attribute.error-component-unread`, for the action's own declaration. One inherited from a base
controller is silent: it would say the same thing on every route under it, and the names it fails to
change are the names they would have been anyway. To name **one status of one operation**, use
`#[Response]`'s [`errorComponent:`](#response) argument, which has the status and the media type written
beside it.

What it does not name is one body a response offers *beside* another. Where a response states two
representations — an RFC 9457 problem body under `application/problem+json` and a plain-JSON alternative,
say — each shape publishes under its status, and so does the response: a name standing for the whole
response cannot say which representation it means, so the response is named after the components its
representations reference instead. A name written on the operation with `#[Response(errorComponent:)]` *is*
about the whole response, and does name it.

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

Targets `METHOD | PROPERTY | FUNCTION`, repeatable.

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
whether they arrive through `value:` or as YAML's `.nan` and `.inf` in a `file:`. See the [diagnostics reference](/laravel/reference/diagnostics/).

**On a property** it pins that field's `example` in the schema, on any class the document hoists — a
plain DTO, a [Data class](/laravel/packages/spatie-data/), a model, a resource:

```php
#[Example('acme-corp')]
public string $tenant;

#[Example(value: false)]
public bool $settled;
```

An attribute argument is a real PHP value, so `false` stays a boolean. The `@example` docblock line
read on any property a schema publishes — a [Data class](/laravel/packages/spatie-data/) property, a
plain DTO's, a resource's where a real property backs the key — can only carry text, and the
attribute beats it where both are written:

```php
/**
 * The tenant that owns the invoice.
 *
 * @example acme-corp
 */
public string $tenant;
```

A property publishes one bare value, not an Example Object, so `name:`, `summary:`, `description:`,
`file:`, `externalValue:`, `status:`, `mediaType:`, `parameter:` and `request:` have nowhere to go
there and are reported as
[`attribute.property-unsupported`](/laravel/reference/diagnostics/#attributes). Two declarations on
one property leave the first standing. Everything else is what the action-level form is for.

There is no `PARAMETER` target. A promoted constructor property is reached through `PROPERTY`, so a
Data class's examples work as they read; and for an action's parameter there are two spellings that
do work: `#[Example(parameter: 'page', …)]` on the action, or the `example:` argument of
[`#[QueryParameter]`](#queryparameter) and its siblings.

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

## Versioning

Every other attribute on this page describes the shape your code has now. These describe the shape it
used to have. An older API version publishes a field your code no longer contains, so there is
nothing left to read it off — you declare the change once, on a class of its own, and the older
document is derived by applying that change backwards. The classes live in
`Docuccino\Attributes\Versioning`; the [API versioning guide](/laravel/guides/api-versioning/) walks
the whole loop.

### `#[ApiVersionChange]`

Targets `CLASS`.

```php
public function __construct(
    public string $since,
    public string $description,
)
```

Marks a class as one registered API version change. `since` is the version the change shipped in — the
first version whose document carries the new shape — and `description` is the sentence a consumer reads
when they are working out whether the upgrade touches them, so write it for someone who cannot see your
code.

Your code is always the newest version, so a change describes what the API did **before** `since`.
Documents older than that are derived by applying the change in reverse; nothing is applied to the
current one.

```php
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;

#[ApiVersionChange(
    since: '2026-09-01',
    description: 'Invoices publish `title` where they used to publish `name`.',
)]
#[RenamedResponseField(schema: InvoiceResource::class, from: 'name', to: 'title')]
final class InvoiceTitleReplacesName {}
```

### `#[RenamedResponseField]`

Targets `CLASS`, repeatable.

```php
public function __construct(
    public string $schema,
    public string $from,
    public string $to,
)
```

Declares that one field of `schema` goes by another name in the versions before the change. `to` is the
name in your code today; `from` is the name the older document publishes. Keep them in that order —
`from` the past, `to` the present — because the pair written the other way round renames the wrong end,
and the older document then ships a field nobody ever had.

`schema` is the class the response shape comes from — a resource, a Data class, a plain DTO — written as
`InvoiceResource::class`. Repeat the attribute once per field the change renames.

```php
#[ApiVersionChange(
    since: '2026-09-01',
    description: 'Invoices publish `title` and `amount_in_cents`.',
)]
#[RenamedResponseField(schema: InvoiceResource::class, from: 'name', to: 'title')]
#[RenamedResponseField(schema: InvoiceResource::class, from: 'total', to: 'amount_in_cents')]
final class InvoiceFieldsRenamed {}
```

Every argument is a plain string or a `::class` constant, which is what makes a change readable without
running any of your code. An argument Docuccino cannot read is reported as `attribute.unreadable` and
the declaration is skipped rather than guessed at.

### `#[MadeResponseFieldRequired]`

Targets `CLASS`, repeatable.

```php
public function __construct(
    public string $schema,
    public string $field,
)
```

Declares that a response field became always-present in this change's version. The versions before it
published the field without promising it, so their documents go on publishing the property and leave
it out of `required`.

`field` is the name your code spells today, the same way `#[RenamedResponseField]`'s `to:` is — every
verb but the rename names its field in the present tense, because the rename is the only one that
changes what a field is called.

```php
#[ApiVersionChange(
    since: '2026-09-01',
    description: 'Every invoice now carries `title`; before this it could be absent.',
)]
#[MadeResponseFieldRequired(schema: InvoiceResource::class, field: 'title')]
final class InvoiceTitleAlwaysSent {}
```

Dropping a field from `required` only ever widens what the older document accepts, so a per-version
contract test cannot fail on this one — it is safe by construction rather than by being checked. What
*is* checked is the declaration against your code: if the schema does not mark the field required
today, the change describes something that did not happen, and the build says so with
`versioning.change-target-unchanged`.

### `#[MadeResponseFieldOptional]`

Targets `CLASS`, repeatable.

```php
public function __construct(
    public string $schema,
    public string $field,
)
```

Declares that a response field became sometimes-absent in this change's version. The versions before it
always sent it, so their documents name it in `required`.

```php
#[ApiVersionChange(
    since: '2026-09-01',
    description: 'An invoice omits `settledAt` until it settles; before this it was always present, as null.',
)]
#[MadeResponseFieldOptional(schema: InvoiceResource::class, field: 'settledAt')]
final class InvoiceSettledAtBecameOptional {}
```

This is the one with a runtime half. The older document now promises the field is always there, and
that promise is only true if your application really puts it back for a caller pinned that far. Pin the
version in a contract test: a response that omits the field is refused against the older document, and
the failure names the field.

### `#[MadeRequestFieldOptional]`

Targets `CLASS`, repeatable.

```php
public function __construct(
    public string $schema,
    public string $field,
)
```

Declares that a request field became optional in this change's version. The versions before it demanded
it, so their documents name it in `required` and a request that leaves it out is refused at that
version — correctly, because that version really did demand it.

`schema` is the class your **request body** is recovered from — a form request, a Data class. That is a
different shape from the response one even where one class produces both, and this verb reaches only
the request half.

```php
#[ApiVersionChange(
    since: '2026-09-01',
    description: 'Creating an invoice no longer requires `currency`; it defaults to the account\'s.',
)]
#[MadeRequestFieldOptional(schema: StoreInvoiceRequest::class, field: 'currency')]
final class InvoiceCurrencyBecameOptional {}
```

There is no matching `#[MadeRequestFieldRequired]`, and that is the asymmetry rather than an omission:
`required` arriving narrows a request and moves nothing on a response, so the two sides of the wire do
not take the same pair of verbs.

### `#[RemovedResponseField]`

Targets `CLASS`, repeatable.

```php
public function __construct(
    public string $schema,
    public string $field,
    public string $type = '',
    public bool $required = false,
    public string $description = '',
)
```

Declares that a response field was **removed** in this change's version, so the versions before it
published the field and their documents put it back.

This is the one verb whose fact is genuinely gone. Every other verb names a field your code still has
and moves what the document says about it; here there is nothing left to read a deleted field's type
off, which is why you declare it. It is also the reason `field` runs the other way from the rest of the
vocabulary: it is the name the **older** versions published, because your code has no name for it at
all.

`type` is read three ways, in this order:

1. **A class this document already publishes a response schema for** — `type: AuthorResource::class`
   becomes a `$ref` to that component. Nothing about the shape is written down twice, and it composes:
   deriving a version rewrites the whole document, so the component the pointer names carries *that*
   version's shape rather than today's.
2. **One of OpenAPI's own type names** — `string`, `integer`, `number`, `boolean`, `object` or `array`,
   each optionally suffixed `[]` for a list of them and `?` for one that may be null. `string[]?` is a
   list of strings that may itself be null; `string?[]` is a list whose members may be.
3. **Anything else** — the field is published with no constraints at all and the build tells you with
   `versioning.type-unresolved`. A valid vague schema costs a consumer some type safety; a precise
   false one costs them a runtime failure.

Leave `type` out entirely and you get the same unconstrained field with nothing said about it, which is
how to spell "it was there, and nobody now knows what it held".

```php
#[ApiVersionChange(
    since: '2026-09-01',
    description: 'Invoices no longer publish `subtotal`; add the line items yourself.',
)]
#[RemovedResponseField(
    schema: InvoiceResource::class,
    field: 'subtotal',
    type: 'integer',
    required: true,
    description: 'The invoice total before tax, in cents.',
)]
final class InvoiceSubtotalRemoved {}
```

`required: true` says those versions always sent it, which makes their document **stricter** than
today's — and that is the half a per-version contract test can refuse. Pin the version, replay your
suite, and the assertion says whether your application really still sends the field to a caller pinned
that far back. It also means any example published beside that schema is now a body the schema itself
rejects, so an example that cannot carry the field is dropped and reported as
`versioning.example-dropped`. Leave `required` off and every example stands: an absent optional member
is valid.

Where the field lands in `properties` is counted from the names already there rather than from the
order you wrote the attributes in, so two removals on one schema come out the same way round either
way.

### `#[AppliesTo]`

Targets `CLASS`, repeatable.

```php
public function __construct(
    public string $operation,
)
```

Narrows a change to the operations you name. Leave it off and the change applies wherever the schema
it names is published, which is what you want when a shape changed and it changed everywhere.

Name an operation the way the document names it — the signature `GET /api/invoices`, or its
`operationId` — or use `*` for any run of characters, which is the same wildcard
[`routes.include`](/laravel/reference/configuration/#routes) uses. Repeat the attribute for more than
one.

```php
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RenamedResponseField;

#[ApiVersionChange(
    since: '2026-09-01',
    description: 'The invoice list publishes `title` where it published `name`.',
)]
#[AppliesTo('GET /api/invoices')]
#[AppliesTo('GET /api/customers/*/invoices')]
#[RenamedResponseField(schema: InvoiceResource::class, from: 'name', to: 'title')]
final class InvoiceListTitleReplacesName {}
```

Scoping has a consequence worth knowing before you reach for it. If a schema is published as a shared
component and your scope covers only some of the operations that publish it, those operations really do
have a different type from the rest in that version's document — so the older shape is written **inline**
at each of them and the shared component is left as your code has it. Nothing is renamed and no new
component name appears, because a component name becomes a type name in a generated client and it must
not depend on how many endpoints happened to share a body. Scope the change to every operation that
publishes the schema and there is no fork at all: the component itself is renamed, exactly as if you had
written no `#[AppliesTo]`.

A selector that names no operation the document publishes that schema for is reported as
`versioning.scope-matches-nothing`. It is worth reading: a route renamed long after the change was
written is how a declared change quietly stops applying.
