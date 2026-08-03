---
title: Laravel Actions
description: Document lorisleiva/laravel-actions classes used as controllers — the real handle()/asController() signature, request body, and authorization response.
sidebar:
  order: 8
---

Activates automatically when [`lorisleiva/laravel-actions`](https://laravelactions.com) is installed.
When you register an action as a route, Docuccino documents it exactly as the package runs it — no
annotations, no wrapper controller.

## What it documents

An action registered as a controller doesn't run through `__invoke` directly — the package
dispatches `asController()` if you've defined one, otherwise `handle()`. Docuccino resolves the same
method, so everything is read from the real signature rather than the trait's generic forwarder:

- **The operation summary** comes from the resolved method's docblock.
- **The request body** comes from the action's own `rules()` method, turned into schema constraints
  through the same validation pipeline Form Requests use.
- **A `403` response** is documented whenever the action defines `authorize()`, rendered in the same
  style as the rest of your error responses (framework defaults, or the Problem Details preset).
- **The response body** is inferred from the resolved method's return type, like any other endpoint.

```php
// app/Actions/PublishArticle.php
class PublishArticle
{
    use AsController;

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'body'  => ['required', 'string'],
        ];
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('publish', Article::class);
    }

    /** Publish a draft article. */
    public function handle(): ArticleResource
    {
        // ...
    }
}
```

```php
// routes/api.php
Route::post('/articles', PublishArticle::class);
```

This documents a `POST /articles` operation with the summary "Publish a draft article.", a JSON
request body with required `title` and `body`, an `ArticleResource` response, and a `403`.

## How the dispatched method is resolved

The method Docuccino reads matches the package's own precedence:

1. `asController()`, if the action defines it;
2. otherwise `handle()`;
3. otherwise `__invoke()`.

If you register a specific method explicitly — `Route::post('/articles', [PublishArticle::class, 'handle'])`
— that method is honored as-is.

## Refining the output

Everything here is inferred at the integration layer, so the usual
[attributes](/reference/attributes/) still apply — add a `#[Group]`, an `#[Example]`, or an extra
`#[Response]` on the action method and it wins over the inferred values.
