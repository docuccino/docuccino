---
title: Request bodies & validation
description: Document request bodies and query parameters from Form Requests, inline validation, and Spatie Data objects.
sidebar:
  order: 3
---

Docuccino documents what your endpoints accept by reading your validation rules and Data objects —
**statically**. It never executes your rules or constructs your objects, so generating docs has no
side effects.

Whichever way you validate, the result is expressed consistently: write verbs (`POST`, `PUT`,
`PATCH`) get a request body; read verbs (`GET`) get query parameters. The media type becomes
`multipart/form-data` automatically once a file rule appears.

## Form Requests & inline validation

A Form Request's `rules()` — or an inline `$request->validate([...])` / `Validator::make(...)` in the
action — becomes the request body schema. Rules map to JSON Schema: presence to `required`, types to
`type`, `min`/`max`/`between`/`size` to length and range keywords, `in`/enum rules to `enum`, dates
to `format`, and cross-field rules like `confirmed` are honored.

```php
// app/Http/Requests/StoreInvoiceRequest.php
public function rules(): array
{
    return [
        'customer_id' => ['required', 'integer'],
        'currency'    => ['required', 'in:GBP,USD,EUR'],
        'due_at'      => ['nullable', 'date'],
        'attachment'  => ['nullable', 'file', 'mimes:pdf'], // → multipart/form-data
    ];
}
```

This documents a request body with `customer_id`, `currency` (as an enum), `due_at`, and
`attachment`, with the right required fields and formats.

## Spatie Data objects

When [Spatie Data](https://spatie.be/docs/laravel-data) is installed, a `Data` class used as a
response is documented as a reusable schema, and a `Data` class type-hinted as an action parameter is
documented as the request body (recovered from its properties and validation attributes):

```php
// app/Data/CreateInvoiceData.php
class CreateInvoiceData extends Data
{
    public function __construct(
        public int $customerId,
        public string $currency,
        public ?string $dueAt,
        #[Hidden]
        public ?string $internalNote,
    ) {}
}
```

Docuccino respects Spatie's own conventions: `#[Hidden]` drops a property, `Optional` and `Lazy`
make properties non-required, name-mapping attributes rename keys, nested Data recurses, and
`DataCollection` becomes an array. Paginated Data wrappers produce a shared pagination envelope
schema.

## Configuration

None. Both paths work out of the box; the Spatie Data support activates only when the package is
installed.

## When it can't tell

If a rule set or Data class can't be resolved statically, Docuccino contributes what it can and
leaves the rest to your annotations — a [`#[BodyParameter]`](/reference/attributes/#bodyparameter) or
a docblock always takes precedence over what's inferred.
