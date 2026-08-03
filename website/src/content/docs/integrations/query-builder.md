---
title: Query Builder
description: Document Spatie Query Builder filters, sorts, includes, and pagination automatically — at any call depth.
sidebar:
  order: 5
---

Activates automatically when [Spatie Query Builder](https://spatie.be/docs/laravel-query-builder)
is installed. It documents the query parameters your list endpoints accept — without you writing a
single parameter annotation.

## What it documents

Docuccino follows your controller's query as it's built and turns the allowed operations into query
parameters:

- **Filters** — one `filter[<name>]` parameter per `allowedFilter`, with a description of what it
  filters.
- **Sorts** — a `sort` parameter, documenting the `-name` convention for descending order.
- **Includes** — an `include` parameter for `allowedIncludes`.
- **Sparse fieldsets** — `fields[<type>]` for `allowedFields`.
- **Pagination** — `page` and `per_page` (or `cursor` and `per_page` for cursor pagination), added
  when the query actually paginates.

Crucially, this works even when the query builder is assembled several method calls deep — for
example behind a reusable base query class. Docuccino traces through your helper methods to recover
the real list of allowed filters and sorts.

```php
// app/Http/Controllers/InvoiceController.php
public function index(): AnonymousResourceCollection
{
    $invoices = QueryBuilder::for(Invoice::class)
        ->allowedFilters(['status', 'customer_id'])
        ->allowedSorts(['issued_at', 'total'])
        ->allowedIncludes(['customer', 'lines'])
        ->paginate();

    return InvoiceResource::collection($invoices);
}
```

This produces `filter[status]`, `filter[customer_id]`, `sort`, `include`, `page`, and `per_page`
parameters, plus a paginated response — all inferred.

## Configuration

If your app paginates through a custom helper method, tell Docuccino its name so pagination
parameters are still added:

```php
// config/docuccino.php → documents.default.integrations
'query_builder' => [
    'pagination_terminals' => ['paginateList'],
],
```

`paginate`, `simplePaginate`, and `cursorPaginate` are recognized out of the box.

## When it can't tell

If an allowed filter or sort is computed in a way that can't be resolved statically, Docuccino emits
a warning diagnostic naming the exact expression — it never silently drops a parameter. You can then
document that one parameter with [`#[QueryParameter]`](/reference/attributes/#queryparameter). Values
you set with attributes or docblocks always take precedence over what's inferred.
